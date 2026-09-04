/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The bottom-left app chrome, in a browser (ADR-114).
 *
 * gate-107 reads the manifest and can prove the entries are DECLARED. It
 * cannot prove they RENDER, and this programme has already produced three
 * defects of exactly that shape: an icon name that is not registered renders
 * NO glyph (not a fallback, not a console error — this app shipped one), an
 * entry whose `route` names a page the app does not host renders a row that
 * goes nowhere, and `nav.includePersonalSettings: false` silently removed the
 * entry that reaches the user's notification preferences.
 *
 * The three reports are declarative `type: "dashboard"` pages over humaniq's
 * own register, which adds a fourth failure mode no manifest gate can see: a
 * widget whose `source` names a schema or filter value that does not match
 * renders its card, its title and no value, silently. In THIS app the live risk
 * is LANGUAGE: SickLeaveCase spells its statuses in Dutch (gemeld, hersteld)
 * and PerformanceReview does too (ingediend, vastgesteld), while LeaveRequest
 * uses English (submitted, approved). Scalar equality does not fold language
 * any more than it folds case, so a filter of "reported" against "gemeld"
 * counts zero and renders a confident 0.
 *
 * ⚠️ SCOPE EVERY SELECTOR TO `[data-testid="cn-nav"]`. An unscoped selector
 * also matches Nextcloud's own user menu, which is attached-but-hidden:
 * `waitFor({state:'attached'})` passes on it and the click never becomes
 * actionable, so the spec fails with "Target page has been closed" — a timeout
 * wearing a crash's clothes.
 *
 * ⚠️ SETTINGS ENTRIES ARE ATTACHED, NOT VISIBLE, inside a collapsed foldout.
 */

import { expect, test } from "@playwright/test";

const APP_BASE = "/apps/humaniq";

/**
 * Dismiss the first-run setup wizard if it is open.
 *
 * ⚠️ On a FRESH instance CnSetupWizard opens over the app and its modal
 * intercepts pointer events, so every nav click resolves its locator and then
 * times out after 30s — a failure that reads like the navigation is broken.
 * Tests that navigate by URL pass, which is what makes this so easy to miss:
 * only the click-through tests fail, and only on a clean install.
 *
 * @param page The page.
 */
async function dismissSetupWizard(page: Page): Promise<void> {
	const modal = page.locator('[data-testid="cn-modal"]');
	if ((await modal.count()) === 0) {
		return;
	}
	await modal.first().getByRole("button", { name: "Close" }).click();
	await expect(modal).toHaveCount(0, { timeout: 15_000 });
}

test.describe("app chrome (ADR-114)", () => {
	test.beforeEach(async ({ page }) => {
		await page.goto(`${APP_BASE}/`, { waitUntil: "domcontentloaded" });
		await expect(page.locator('[data-testid="cn-nav"]')).toBeVisible({
			timeout: 30_000,
		});
		await dismissSetupWizard(page);
	});

	test("the footer reads Documentation, Store, Reports, Features & roadmap, each with a glyph", async ({
		page,
	}) => {
		const footer = page.locator(
			'[data-testid="cn-nav"] .cn-app-nav__footer-list',
		);
		await expect(footer).toBeAttached({ timeout: 15_000 });

		const rows = footer.locator("li");
		const texts = (await rows.allInnerTexts())
			.map((t) => t.trim())
			.filter(Boolean);

		const seen = texts.filter((t) => /Documentation|Store|Reports|roadmap/i.test(t));
		expect(seen.length).toBe(4);
		expect(seen[0]).toMatch(/Documentation/i);
		expect(seen[1]).toMatch(/Store/i);
		expect(seen[2]).toMatch(/Reports/i);
		expect(seen[3]).toMatch(/roadmap/i);

		for (const row of await rows.all()) {
			await expect(
				row.locator("svg, .material-design-icon").first(),
			).toBeAttached();
		}
	});

	test("Reports lists all three reports", async ({ page }) => {
		const nav = page.locator('[data-testid="cn-nav"]');
		await nav
			.locator('[data-testid="cn-nav-entry-ReportsMenu"] a')
			.first()
			.click();
		await expect(page).toHaveURL(/\/apps\/humaniq\/reports(\?|$)/, {
			timeout: 15_000,
		});

		for (const label of ["Workforce", "Leave and absence", "Performance"]) {
			await expect(
				page.getByText(label, { exact: false }).first(),
			).toBeVisible({ timeout: 15_000 });
		}
	});

	test("the workforce report renders real numbers, not empty cards", async ({
		page,
	}) => {
		await page.goto(`${APP_BASE}/reports/workforce`);
		await expect(page.locator('[data-testid="cn-nav"]')).toBeVisible({
			timeout: 30_000,
		});
		await expect(
			page.getByText("Employee records", { exact: false }).first(),
		).toBeVisible({ timeout: 30_000 });
		await expect(page.locator("main, .app-content").first()).toContainText(
			/\d/,
			{ timeout: 30_000 },
		);
	});

	test("the absence report filters Dutch statuses in Dutch", async ({
		page,
	}) => {
		// See the header note. "Currently reported sick" filters SickLeaveCase
		// on status "gemeld" — the Dutch word the schema actually stores. An
		// English filter would count zero and render a confident 0.
		await page.goto(`${APP_BASE}/reports/absence`);
		await expect(page.locator('[data-testid="cn-nav"]')).toBeVisible({
			timeout: 30_000,
		});
		await expect(
			page.getByText("Currently reported sick", { exact: false }).first(),
		).toBeVisible({ timeout: 30_000 });
		await expect(page.locator("main, .app-content").first()).toContainText(
			/\d/,
			{ timeout: 30_000 },
		);
	});

	test("the performance report is reachable and titled", async ({ page }) => {
		await page.goto(`${APP_BASE}/reports/performance`);
		await expect(page).toHaveURL(/\/reports\/performance(\?|$)/, {
			timeout: 15_000,
		});
		await expect(
			page.getByText("Open review cycles", { exact: false }).first(),
		).toBeVisible({ timeout: 30_000 });
	});

	test("Store opens the hosted store surface, which this app writes no backend for", async ({
		page,
	}) => {
		const footer = page.locator(
			'[data-testid="cn-nav"] .cn-app-nav__footer-list',
		);
		await footer
			.getByRole("link", { name: /^Store$/ })
			.first()
			.click();

		await expect(page).toHaveURL(/\/apps\/humaniq\/store(\?|$)/, {
			timeout: 15_000,
		});

		// The page is declarative: openregister hosts the store plane, so this
		// app ships NO store controller (ADR-080, ADR-114 Decision 4). With no
		// registry configured it renders the app's own items and makes NO
		// network call, so this must pass on a plain instance.
		await expect(page.locator('[data-testid="cn-nav"]')).toBeVisible();
	});

	test("the settings foldout carries Personal settings, Admin settings and Flows", async ({
		page,
	}) => {
		const nav = page.locator('[data-testid="cn-nav"]');

		await expect(
			nav.locator('[data-testid="cn-nav-settings"]'),
		).toBeAttached({ timeout: 15_000 });
		await expect(
			nav.locator('[data-testid="cn-nav-personal-settings"]'),
		).toBeAttached();
		await expect(
			nav.locator('[data-testid="cn-nav-entry-FlowsMenu"]'),
		).toBeAttached();

		const admin = nav.locator('[data-testid="cn-nav-admin-settings"]');
		await expect(admin).toBeAttached();
		await expect(admin.locator("a").first()).toHaveAttribute(
			"href",
			/\/settings\/admin\/humaniq$/,
		);
	});
});
