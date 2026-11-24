// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>

/**
 * The HR question assistant (self-service-policy-assistant 3.1).
 *
 * openspec/specs/hr-policy-assistant/spec.md
 *   Scenario: An employee asks about the train ticket rule
 *   Scenario: An unanswerable question goes to HR
 *   Scenario: How many holiday hours do I have left
 *
 * WHAT THE UNIT TESTS CANNOT SHOW. McpSurfaceTest proves which schemas carry
 * an MCP block in the register files, and OfferHrAssistantListenerTest proves
 * the template humaniq offers Hermiq. Neither shows the tool list
 * OpenRegister derives on a running instance, nor what the assistant answers.
 * The first is deterministic and runs on every instance; the answers need
 * Hermiq, an admin who reviewed the HR-vraagbaak template, and a language
 * model, so those tests run only when the instance says it has all three
 * (E2E_HR_ASSISTANT=1) and skip with that reason otherwise.
 *
 * Base URL and admin credentials from the environment (base-url.ts). Not run
 * when written.
 */

import type { APIRequestContext, Page } from '@playwright/test'

import { expect, request, test } from '@playwright/test'
import { ADMIN_CREDENTIALS, resolveBaseURL } from '../base-url.ts'

const NC_URL = resolveBaseURL()
const APP_URL = `${NC_URL}/index.php/apps/humaniq`
const MCP_URL = `${NC_URL}/index.php/apps/openregister/api/mcp`
const HEADERS = { 'OCS-APIRequest': 'true', Accept: 'application/json, text/event-stream', 'Content-Type': 'application/json' }

/** The policy sources the assistant may search and read (design D1). */
const POLICY_SCHEMAS = ['announcement', 'leavetype', 'cao', 'normfunctie']

/** Personal data no tool may read (REQ-HPA-002). */
const PERSONAL_SCHEMAS = ['leavebalance', 'leaverequest', 'sickleavecase', 'payslip', 'employee']

/** Whether this instance has Hermiq, the reviewed template and a model. */
const ASSISTANT_READY = process.env.E2E_HR_ASSISTANT === '1'

/**
 * The schema part of a derived tool name (`{slug}_{verb}`), without separators.
 *
 * @param name The tool name.
 * @return The slug, lower case, letters and digits only.
 */
function schemaOf(name: string): string {
	const cut = name.lastIndexOf('_')
	return (cut > 0 ? name.slice(0, cut) : name).toLowerCase().replace(/[^a-z0-9]/g, '')
}

/**
 * Read the tool names OpenRegister's MCP server offers the admin.
 *
 * @param admin The admin's request context.
 * @return The tool names.
 */
async function toolNames(admin: APIRequestContext): Promise<string[]> {
	const init = await admin.post(MCP_URL, {
		headers: HEADERS,
		data: { jsonrpc: '2.0', id: 1, method: 'initialize', params: { protocolVersion: '2025-03-26', capabilities: {}, clientInfo: { name: 'e2e', version: '1' } } },
	})
	expect(init.ok(), `open an MCP session: ${init.status()}`).toBeTruthy()
	const sessionId = init.headers()['mcp-session-id']
	expect(sessionId, 'the server names the session').toBeTruthy()

	const list = await admin.post(MCP_URL, {
		headers: { ...HEADERS, 'Mcp-Session-Id': sessionId },
		data: { jsonrpc: '2.0', id: 2, method: 'tools/list', params: {} },
	})
	expect(list.ok(), `list the tools: ${list.status()}`).toBeTruthy()
	const tools: Array<{ name?: string }> = (await list.json())?.result?.tools || []
	return tools.map((tool) => String(tool.name || ''))
}

/**
 * Ask the companion a question on Mijn HR and return its answer.
 *
 * @param page The signed-in page.
 * @param question What the employee asks.
 * @return The answer bubble.
 */
async function ask(page: Page, question: string) {
	await page.goto(`${APP_URL}/mijn`, { waitUntil: 'domcontentloaded' })
	await page.getByRole('button', { name: 'Open AI chat' }).click()
	const input = page.getByRole('textbox', { name: 'Message input' })
	await expect(input, 'the companion opens').toBeVisible({ timeout: 30_000 })
	await input.fill(question)
	await page.getByRole('button', { name: 'Send message' }).click()

	const answer = page.locator('.cn-ai-message-list__bubble--assistant').last()
	await expect(answer, 'the assistant answers').toBeVisible({ timeout: 120_000 })
	await expect(page.locator('.cn-ai-message-list__bubble--assistant[aria-live]'), 'the answer finishes').toHaveCount(0, { timeout: 120_000 })
	return answer
}

test.describe('the HR question assistant', () => {
	let admin: APIRequestContext

	test.beforeAll(async () => {
		admin = await request.newContext({ httpCredentials: ADMIN_CREDENTIALS, storageState: { cookies: [], origins: [] } })
	})

	test.afterAll(async () => {
		await admin?.dispose()
	})

	test('the policy sources are readable and no personal data is', async () => {
		const schemas = new Set((await toolNames(admin)).map(schemaOf))

		for (const schema of POLICY_SCHEMAS) {
			expect(schemas.has(schema), `a tool reads ${schema}`).toBeTruthy()
		}
		for (const schema of PERSONAL_SCHEMAS) {
			expect(schemas.has(schema), `no tool reads ${schema}`).toBeFalsy()
		}
	})

	// @e2e hr-policy-assistant::an-employee-asks-about-the-train-ticket-rule
	test('An employee asks about the train ticket rule', async ({ page }) => {
		test.skip(!ASSISTANT_READY, 'needs Hermiq, the reviewed HR-vraagbaak template and a model (E2E_HR_ASSISTANT=1)')

		const answer = await ask(page, 'Wordt een treinkaartje naar een cursus vergoed?')
		await expect(answer, 'the answer says yes').toContainText(/\bja\b|\byes\b/i)
		await expect(answer, 'the answer cites the expense policy').toContainText(/onkosten|declaratie|expense/i)
	})

	// @e2e hr-policy-assistant::an-unanswerable-question-goes-to-hr
	test('An unanswerable question goes to HR', async ({ page }) => {
		test.skip(!ASSISTANT_READY, 'needs Hermiq, the reviewed HR-vraagbaak template and a model (E2E_HR_ASSISTANT=1)')

		const answer = await ask(page, 'Mag ik mijn hond meenemen naar kantoor?')
		await expect(answer, 'the assistant offers to forward the question to HR').toContainText(/HR/)
		await expect(answer, 'the assistant says no source covers it').toContainText(/niet|geen|not|no source/i)
	})

	// @e2e hr-policy-assistant::how-many-holiday-hours-do-i-have-left
	test('How many holiday hours do I have left', async ({ page }) => {
		test.skip(!ASSISTANT_READY, 'needs Hermiq, the reviewed HR-vraagbaak template and a model (E2E_HR_ASSISTANT=1)')

		const answer = await ask(page, 'Hoeveel vakantie-uren heb ik nog?')
		await expect(answer.getByRole('link').filter({ hasText: /verlof|leave/i }), 'the answer links to the leave page').toHaveCount(1)
		const link = await answer.getByRole('link').filter({ hasText: /verlof|leave/i }).getAttribute('href')
		expect(link, 'the link opens Mijn verlof').toMatch(/\/apps\/humaniq\/leave-requests/)
		expect(await answer.innerText(), 'the answer gives no figure of hours').not.toMatch(/\d+([.,]\d+)?\s*(uur|uren|hours)/i)
	})
})
