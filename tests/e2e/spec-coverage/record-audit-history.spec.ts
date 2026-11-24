/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * record-history-on-every-detail-page, tasks.md 4.1.
 *
 * SCENARIOS COVERED
 * -----------------
 * openspec/specs/record-audit-history/spec.md
 *   Scenario: Opening an employee leaves a read entry
 *   Scenario: A changed IBAN shows in the history
 *   Scenario: The newest entries read as sentences
 *   Scenario: The full trail opens filtered
 * The two other scenarios of that spec carry a reason-bearing `@e2e exclude`.
 *
 * WHAT THIS CLOSES THAT THE UNIT SUITE CANNOT
 * -------------------------------------------
 * `tests/js/auditTrail.test.mjs` renders entries the test wrote. It cannot
 * show that OpenRegister really writes a `read` entry when a person opens the
 * employee page, that the readable list narrowed by `register` returns it to
 * an administrator, or that the section's link lands on OpenRegister's trail
 * with the same filter.
 *
 * WHY A SECOND, PLAIN ACCOUNT
 * ---------------------------
 * The read is made by an HR adviser who is not an administrator, from an
 * EMPTY storage state, so the admin session cannot ride along and make the
 * read entry name the wrong person. The account and the employee are created
 * under a run-scoped name and removed in the teardown.
 *
 * PRECONDITIONS
 * -------------
 * The humaniq register and its schemas are imported (tests/e2e/ci-seed.sh),
 * audit trails are on (OpenRegister's default), and the base URL comes from
 * the environment (base-url.ts).
 */

import type { APIRequestContext, Browser, Page } from "@playwright/test";

import { expect, request, test } from "@playwright/test";
import { randomUUID } from "node:crypto";
import { ADMIN_CREDENTIALS, resolveBaseURL } from "../base-url.ts";

const NC_URL = resolveBaseURL();
const OR_API = `${NC_URL}/index.php/apps/openregister/api`;
const OCS_USERS = `${NC_URL}/ocs/v2.php/cloud/users`;
const REGISTER = "humaniq";
const HR_GROUP = "humaniq-hr";

const RUN_ID = randomUUID().slice(0, 8);
const ADVISER = {
	username: `e2e-hr-${RUN_ID}`,
	password: `E2e-hr-${RUN_ID}-pass!`,
	displayName: `Adviseur ${RUN_ID}`,
};

const HEADERS = { "OCS-APIRequest": "true", Accept: "application/json" };

/** Read an object's id out of either payload shape OpenRegister returns. */
function idOf(row: Record<string, unknown> | undefined): string {
	const self = (row?.["@self"] || {}) as Record<string, unknown>;
	return String(self.id || row?.id || "");
}

/** An object's audit entries, newest first, as the admin sees them. */
async function trailOf(api: APIRequestContext, id: string): Promise<Array<Record<string, unknown>>> {
	const response = await api.get(`${OR_API}/objects/${REGISTER}/Employee/${id}/audit-trails`);
	expect(response.ok(), "the record's audit trail must be readable by an administrator").toBeTruthy();
	const body = (await response.json()) as { results?: Array<Record<string, unknown>> } | Array<Record<string, unknown>>;

	return Array.isArray(body) ? body : (body.results ?? []);
}

/** Log a fresh, empty browser context in as the given account. */
async function loginAs(browser: Browser, account: { username: string; password: string }): Promise<Page> {
	const context = await browser.newContext({ storageState: { cookies: [], origins: [] }, baseURL: NC_URL });
	const page = await context.newPage();
	await page.goto("/index.php/login", { waitUntil: "domcontentloaded" });
	await page.locator('input#user, input[name="user"]').first().fill(account.username);
	await page.locator('input#password, input[name="password"]').first().fill(account.password);
	await Promise.all([
		page.waitForURL((url) => !url.pathname.endsWith("/login"), { timeout: 60_000 }),
		page.locator('button[type="submit"]').first().click({ noWaitAfter: true }),
	]);

	return page;
}

test.describe.serial("every humaniq record shows who viewed or changed it", () => {
	let api: APIRequestContext;
	let employeeId = "";

	test.beforeAll(async () => {
		api = await request.newContext({ extraHTTPHeaders: HEADERS, httpCredentials: ADMIN_CREDENTIALS });

		const user = await api.post(`${OCS_USERS}?format=json`, {
			form: { userid: ADVISER.username, password: ADVISER.password, displayName: ADVISER.displayName, "groups[]": HR_GROUP },
		});
		expect(user.ok(), "the HR adviser account must be created").toBeTruthy();

		const created = await api.post(`${OR_API}/objects/${REGISTER}/Employee`, {
			data: {
				firstName: "Audit",
				lastName: `Fixture ${RUN_ID}`,
				startDate: "2026-01-01",
				iban: "NL91ABNA0417164300",
			},
		});
		expect(created.ok(), "the employee fixture must be created").toBeTruthy();
		employeeId = idOf(await created.json());
		expect(employeeId, "the employee fixture must have an id").not.toBe("");
	});

	test.afterAll(async () => {
		if (employeeId !== "") {
			await api.delete(`${OR_API}/objects/${REGISTER}/Employee/${employeeId}`).catch(() => undefined);
		}

		await api.delete(`${OCS_USERS}/${ADVISER.username}?format=json`).catch(() => undefined);
		await api.dispose();
	});

	/**
	 * @spec openspec/specs/record-audit-history/spec.md#scenario-opening-an-employee-leaves-a-read-entry
	 */
	// @e2e record-audit-history::opening-an-employee-leaves-a-read-entry
	test("opening an employee leaves a read entry naming the adviser @spec REQ-RAH-001", async ({ browser }) => {
		const page = await loginAs(browser, ADVISER);
		await page.goto(`/index.php/apps/humaniq/employees/${employeeId}`, { waitUntil: "domcontentloaded" });
		await expect(page.getByText(`Fixture ${RUN_ID}`).first()).toBeVisible({ timeout: 30_000 });
		await page.context().close();

		const reads = (await trailOf(api, employeeId)).filter((entry) => entry.action === "read");
		expect(
			reads.map((entry) => entry.user),
			"the adviser's page visit is recorded as a read by the adviser, not by the admin or a job",
		).toContain(ADVISER.username);
	});

	/**
	 * @spec openspec/specs/record-audit-history/spec.md#scenario-a-changed-iban-shows-in-the-history
	 */
	// @e2e record-audit-history::a-changed-iban-shows-in-the-history
	test("a changed IBAN shows in the record's history @spec REQ-RAH-002", async ({ page }) => {
		const read = await api.get(`${OR_API}/objects/${REGISTER}/Employee/${employeeId}`);
		const current = (await read.json()) as Record<string, unknown>;
		delete current["@self"];
		const changed = await api.put(`${OR_API}/objects/${REGISTER}/Employee/${employeeId}`, {
			data: { ...current, iban: "NL02RABO0123456789" },
		});
		expect(changed.ok(), "the IBAN change must be saved").toBeTruthy();

		const updates = (await trailOf(api, employeeId)).filter((entry) => entry.action === "update");
		expect(
			updates.some((entry) => entry.changed !== null && typeof entry.changed === "object" && "iban" in (entry.changed as object)),
			"the change entry names the field that changed",
		).toBe(true);

		await page.goto(`/index.php/apps/humaniq/employees/${employeeId}`, { waitUntil: "domcontentloaded" });
		await expect(page.getByText(`Fixture ${RUN_ID}`).first()).toBeVisible({ timeout: 30_000 });
		const historyTab = page.getByRole("tab", { name: /audit|history|geschiedenis/i }).first();
		await expect(historyTab, "the detail page's sidebar offers the record's history").toBeVisible({ timeout: 30_000 });
		await historyTab.click();
		await expect(page.getByText(/iban/i).first(), "the history lists the IBAN change").toBeVisible({ timeout: 30_000 });
	});

	/**
	 * @spec openspec/specs/record-audit-history/spec.md#scenario-the-newest-entries-read-as-sentences
	 */
	// @e2e record-audit-history::the-newest-entries-read-as-sentences
	test("the Audittrail section shows the newest entries as sentences @spec REQ-RAH-003", async ({ page }) => {
		await page.goto("/index.php/settings/admin/humaniq", { waitUntil: "domcontentloaded" });
		const section = page.getByTestId("humaniq-admin-audit-trail");
		await expect(section).toBeVisible({ timeout: 30_000 });
		await section.scrollIntoViewIfNeeded();

		const entries = section.locator("ol.humaniq-admin__audit > li");
		await expect(entries.first()).toBeVisible({ timeout: 30_000 });
		expect(await entries.count(), "at most ten entries").toBeLessThanOrEqual(10);
		await expect(
			entries.filter({ hasText: ADVISER.username }).or(entries.filter({ hasText: ADVISER.displayName })).first(),
			"the adviser's read is among the newest entries, as a sentence with who and when",
		).toContainText(/viewed|bekeek/);
	});

	/**
	 * @spec openspec/specs/record-audit-history/spec.md#scenario-the-full-trail-opens-filtered
	 */
	// @e2e record-audit-history::the-full-trail-opens-filtered
	test("the full trail opens in OpenRegister filtered to the humaniq register @spec REQ-RAH-003", async ({ page }) => {
		const register = await api.get(`${OR_API}/registers/${REGISTER}`);
		expect(register.ok()).toBeTruthy();
		const registerId = String(((await register.json()) as Record<string, unknown>).id ?? "");
		expect(registerId).not.toBe("");

		await page.goto("/index.php/settings/admin/humaniq", { waitUntil: "domcontentloaded" });
		const section = page.getByTestId("humaniq-admin-audit-trail");
		await expect(section).toBeVisible({ timeout: 30_000 });

		const full = section.getByRole("link", { name: /full audit trail|volledige audittrail/i });
		await expect(full).toHaveAttribute("href", new RegExp(`/apps/openregister/audit-trails\\?register=${registerId}$`));
		const download = section.getByRole("link", { name: /^(download|downloaden)$/i });
		await expect(download).toHaveAttribute("href", new RegExp(`/apps/openregister/api/audit-trails/export\\?register=${registerId}&format=csv$`));

		await full.click();
		await expect(page).toHaveURL(new RegExp(`/apps/openregister/audit-trails\\?register=${registerId}`));
	});
});
