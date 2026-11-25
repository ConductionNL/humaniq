/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Employer statement from the contract page (gate-19 spec coverage) for the
 * OpenSpec change `documents-employer-statement-from-the-contract`.
 *
 * Every scenario of the change's spec delta is referenced here by its
 * verbatim name:
 *
 * openspec/specs/humaniq-docudesk-documents/spec.md
 *   Scenario: HR makes a statement for a mortgage
 *   Scenario: A user without access to the contract gets nothing
 *   Scenario: Without the template the outcome says so
 *   Scenario: A second statement a week later
 *
 * Two scenarios depend on what filinq offers: "HR makes a statement for a
 * mortgage" needs filinq WITH its employer statement template, "Without the
 * template the outcome says so" needs filinq WITHOUT it. Each skips, naming
 * what is missing, when the instance is the other shape, rather than
 * asserting against a half-instance. The other two hold on any instance:
 * the refusal happens before filinq is asked, and an employer statement is
 * never deduplicated whatever the outcome of a single attempt.
 *
 * PRECONDITIONS: the humaniq register is imported (tests/e2e/ci-seed.sh);
 * the admin may create users. Every fixture carries RUN_ID and is deleted in
 * afterAll.
 */

import type { APIRequestContext, Page } from "@playwright/test";

import { expect, request, test } from "@playwright/test";
import { randomUUID } from "node:crypto";
import { ADMIN_CREDENTIALS, resolveBaseURL } from "../base-url.ts";

const NC_URL = resolveBaseURL();
const OR_BASE = `${NC_URL}/index.php/apps/openregister/api/objects/humaniq`;
const GENERATE = `${NC_URL}/index.php/apps/humaniq/api/documents/generate`;
const HEADERS = {
	"OCS-APIRequest": "true",
	"Content-Type": "application/json",
	Accept: "application/json",
};

/** The template filinq's `templates-employer-statement` imports. */
const TEMPLATE_SLUG = "werkgeversverklaring-standaard";

/** Unique per run, so repeated runs never collide and cleanup is exact. */
const RUN_ID = `e2e-ws-${Date.now()}-${randomUUID().slice(0, 8)}`;

/** The history-mode router base, as the running app generates it. */
let _appBase: string | null = null;
async function appBase(page: Page): Promise<string> {
	if (_appBase) return _appBase;
	await page.goto("/index.php/apps/humaniq/", {
		waitUntil: "domcontentloaded",
	});
	const resolved = await page.evaluate(() =>
		(
			window as unknown as {
				OC?: { generateUrl?: (_p: string) => string };
			}
		).OC?.generateUrl?.("/apps/humaniq"),);
	if (!resolved) {
		throw new Error(
			"OC.generateUrl is not available on the humaniq page, so the router base cannot be resolved.",
		);
	}
	_appBase = resolved.replace(/\/+$/, "");
	return _appBase;
}

/** The id an OpenRegister write answered with. */
function idOf(body: Record<string, unknown> | null): string {
	const self = (body?.["@self"] ?? {}) as Record<string, unknown>;
	return String(body?.id ?? self.id ?? body?.uuid ?? "");
}

/** Create one object in the humaniq register as the admin. */
async function create(
	api: APIRequestContext,
	schema: string,
	data: Record<string, unknown>,
): Promise<string> {
	const res = await api.post(`${OR_BASE}/${schema}`, { headers: HEADERS, data });
	const text = await res.text();
	expect([200, 201], `create ${schema}: HTTP ${res.status()} ${text.slice(0, 300)}`).toContain(
		res.status(),
	);
	const id = idOf(JSON.parse(text));
	expect(id, `created ${schema} must carry an id`).not.toEqual("");
	return id;
}

/** The employer statements recorded for one contract, oldest first. */
async function statementsFor(
	api: APIRequestContext,
	contractId: string,
): Promise<Array<Record<string, unknown>>> {
	const res = await api.get(
		`${OR_BASE}/HrGeneratedDocument?contractId=${encodeURIComponent(contractId)}&documentType=werkgeversverklaring&_limit=100`,
		{ headers: HEADERS },
	);
	expect(res.ok(), `list HrGeneratedDocument: HTTP ${res.status()}`).toBeTruthy();
	const body = await res.json();
	const rows = (Array.isArray(body) ? body : (body.results ?? [])) as Array<Record<string, unknown>>;
	// A filter the instance ignores must not let other contracts' rows count.
	return rows.filter(
		(row) => row.contractId === contractId && row.documentType === "werkgeversverklaring",
	);
}

/**
 * The stored document an outcome names. The endpoint answers with the outcome
 * ({status, message, generatedDocumentId}), not the document itself, so the
 * document is read back from OpenRegister by that id.
 *
 * @param api     An admin context.
 * @param outcome The endpoint's reply.
 * @return The HrGeneratedDocument, or an empty object when the outcome names none.
 */
async function documentOf(api: APIRequestContext, outcome: Record<string, unknown>): Promise<Record<string, unknown>> {
	const id = String(outcome.generatedDocumentId ?? "");
	if (id === "") {
		return {};
	}
	const res = await api.get(`${OR_BASE}/HrGeneratedDocument/${id}`, { headers: HEADERS });
	expect(res.ok(), `read HrGeneratedDocument ${id}: HTTP ${res.status()}`).toBeTruthy();
	return (await res.json()) as Record<string, unknown>;
}

/** Whether filinq is enabled, and whether it lists the employer statement template. */
async function filinqShape(api: APIRequestContext): Promise<{ enabled: boolean; template: boolean }> {
	const apps = await api.get(`${NC_URL}/ocs/v2.php/cloud/apps?filter=enabled&format=json`, {
		headers: HEADERS,
	});
	const enabled = ((await apps.json().catch(() => null))?.ocs?.data?.apps ?? []).includes("filinq");
	if (!enabled) return { enabled, template: false };
	const res = await api.get(`${NC_URL}/index.php/apps/filinq/api/templates?namespace=hrmq`, {
		headers: HEADERS,
	});
	const text = res.ok() ? await res.text() : "";
	return { enabled, template: text.includes(TEMPLATE_SLUG) };
}

/** Run the contract page's "Make employer statement" action, confirmed. */
async function makeStatementFromThePage(page: Page, contractId: string): Promise<Record<string, unknown>> {
	const base = await appBase(page);
	await page.goto(`${base}/contracts/${contractId}`, { waitUntil: "domcontentloaded" });

	await page.getByRole("button", { name: "Actions" }).first().click();
	await page.getByRole("menuitem", { name: "Make employer statement" }).click();

	// The library's confirm gate (CnConfirmDialog), in its confirm phase.
	const dialog = page.locator('[data-testid-modal="cn-confirm-dialog"][data-testid-phase="confirm"]');
	await expect(dialog).toBeVisible();
	const posted = page.waitForRequest(
		(req) => req.url().includes("/api/documents/generate") && req.method() === "POST",
	);
	const answered = page.waitForResponse(
		(res) => res.url().includes("/api/documents/generate") && res.request().method() === "POST",
	);
	// The confirm button is not a descendant of the element that carries
	// data-testid-modal, so it is found on the page, not inside `dialog`.
	await page.getByTestId("cn-confirm-dialog-confirm").click();

	const body = (await posted).postDataJSON() as Record<string, unknown>;
	expect(body.contractId, "the action posts this contract").toBe(contractId);
	expect(body.documentType).toBe("werkgeversverklaring");

	const res = await answered;
	expect(res.status(), "the contract resolves for HR").toBe(200);
	return (await res.json()) as Record<string, unknown>;
}

test.describe.configure({ mode: "serial" });

test.describe("documents-employer-statement-from-the-contract", () => {
	let admin: APIRequestContext;
	let outsider: APIRequestContext;
	let employeeId = "";
	let contractId = "";
	const outsiderUid = `${RUN_ID}-reader`;
	const outsiderPassword = `Pw-${randomUUID()}`;

	test.beforeAll(async () => {
		admin = await request.newContext({
			baseURL: NC_URL,
			storageState: { cookies: [], origins: [] },
			httpCredentials: { ...ADMIN_CREDENTIALS, send: "always" },
		});

		employeeId = await create(admin, "Employee", {
			firstName: "Werkgeversverklaring",
			lastName: RUN_ID,
			startDate: "2024-01-01",
		});
		contractId = await create(admin, "EmploymentContract", {
			employeeId,
			type: "permanent",
			startDate: "2024-01-01",
			hoursPerWeek: 36,
		});

		const user = await admin.post(`${NC_URL}/ocs/v2.php/cloud/users?format=json`, {
			headers: HEADERS,
			data: { userid: outsiderUid, password: outsiderPassword },
		});
		expect(user.ok(), `create the second user: HTTP ${user.status()}`).toBeTruthy();

		// The outsider's own context: an EMPTY storage state, so it never rides
		// on the suite's admin session, and Basic sent up front because
		// Nextcloud does not answer with the 401 challenge Playwright waits for.
		outsider = await request.newContext({
			baseURL: NC_URL,
			storageState: { cookies: [], origins: [] },
			httpCredentials: { username: outsiderUid, password: outsiderPassword, send: "always" },
		});
		const who = await outsider.get(`${NC_URL}/ocs/v2.php/cloud/user?format=json`, { headers: HEADERS });
		expect((await who.json())?.ocs?.data?.id, "the second user is signed in as themselves").toBe(outsiderUid);
	});

	test.afterAll(async () => {
		for (const row of await statementsFor(admin, contractId).catch(() => [])) {
			await admin.delete(`${OR_BASE}/HrGeneratedDocument/${idOf(row)}`, { headers: HEADERS }).catch(() => null);
		}
		await admin.delete(`${OR_BASE}/EmploymentContract/${contractId}`, { headers: HEADERS }).catch(() => null);
		await admin.delete(`${OR_BASE}/Employee/${employeeId}`, { headers: HEADERS }).catch(() => null);
		await admin.delete(`${NC_URL}/ocs/v2.php/cloud/users/${outsiderUid}`, { headers: HEADERS }).catch(() => null);
		await outsider?.dispose();
		await admin?.dispose();
	});

	// @e2e humaniq-docudesk-documents::a-user-without-access-to-the-contract-gets-nothing
	test("A user without access to the contract gets nothing", async () => {
		const before = (await statementsFor(admin, contractId)).length;

		const res = await outsider.post(GENERATE, {
			headers: HEADERS,
			data: { contractId, documentType: "werkgeversverklaring" },
		});
		expect(res.status(), "an unreadable contract is 404").toBe(404);

		// filinq is not called: no attempt is recorded for the contract.
		expect((await statementsFor(admin, contractId)).length).toBe(before);
	});

	// @e2e humaniq-docudesk-documents::a-second-statement-a-week-later
	test("A second statement a week later", async () => {
		const first = await admin.post(GENERATE, {
			headers: HEADERS,
			data: { contractId, documentType: "werkgeversverklaring" },
		});
		expect(first.status()).toBe(200);
		const firstDoc = await documentOf(admin, (await first.json()) as Record<string, unknown>);
		const firstId = idOf(firstDoc);
		expect(firstId, "the first attempt is recorded").not.toEqual("");

		const second = await admin.post(GENERATE, {
			headers: HEADERS,
			data: { contractId, documentType: "werkgeversverklaring" },
		});
		expect(second.status()).toBe(200);
		const secondBody = await second.json();
		expect(secondBody.status, "an employer statement is never deduplicated").not.toBe("already-generated");
		const secondId = idOf(await documentOf(admin, secondBody as Record<string, unknown>));
		expect(secondId, "the second attempt is its own document").not.toEqual("");
		expect(secondId).not.toEqual(firstId);

		// The first is still there, as it was.
		const reread = await admin.get(`${OR_BASE}/HrGeneratedDocument/${firstId}`, { headers: HEADERS });
		expect(reread.status()).toBe(200);
		const again = await reread.json();
		expect(again.status).toBe(firstDoc.status);
		expect(again.contractId).toBe(contractId);
	});

	// @e2e humaniq-docudesk-documents::hr-makes-a-statement-for-a-mortgage
	test("HR makes a statement for a mortgage", async ({ page }) => {
		const shape = await filinqShape(admin);
		test.skip(!shape.enabled, "needs filinq installed and enabled");
		test.skip(!shape.template, `needs filinq's ${TEMPLATE_SLUG} template (filinq templates-employer-statement)`);

		const outcome = await makeStatementFromThePage(page, contractId);
		const doc = await documentOf(admin, outcome);
		expect(outcome.status).toBe("generated");
		expect(doc.documentType).toBe("werkgeversverklaring");
		expect(doc.employeeId, "made for the contract's employee").toBe(employeeId);
		expect(doc.status).toBe("generated");
		expect(String(doc.filePath ?? ""), "the PDF is stored").not.toEqual("");
	});

	// @e2e humaniq-docudesk-documents::without-the-template-the-outcome-says-so
	test("Without the template the outcome says so", async ({ page }) => {
		const shape = await filinqShape(admin);
		test.skip(!shape.enabled, "needs filinq installed and enabled");
		test.skip(shape.template, `needs filinq WITHOUT its ${TEMPLATE_SLUG} template`);

		const outcome = await makeStatementFromThePage(page, contractId);
		const doc = await documentOf(admin, outcome);
		expect(outcome.status).toBe("failed");
		expect(doc.status, "the attempt is recorded as failed").toBe("failed");
		expect(String(doc.errorMessage ?? ""), "with the reason").not.toEqual("");
	});
});
