/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The ready-made payroll, turnover and leave reports and the user-built
 * reports (reporting-standard-reports-and-builder), read from the effective
 * manifest the app ships and from the register fragments: every report card
 * opens a dashboard page, the seven lists offer saved views and export, their
 * schemas are exportable, and My reports lists the saved views of exactly
 * those seven lists.
 *
 * @spec openspec/changes/reporting-standard-reports-and-builder/specs/hr-reports/spec.md
 */
import assert from 'node:assert/strict'
import { readdirSync, readFileSync } from 'node:fs'
import { join } from 'node:path'
import { test } from 'node:test'
import { fileURLToPath } from 'node:url'

const ROOT = fileURLToPath(new URL('../../', import.meta.url))
const manifest = JSON.parse(readFileSync(join(ROOT, 'src/manifest.effective.json'), 'utf8'))
const pages = Object.fromEntries(manifest.pages.map((page) => [page.id, page]))

const REPORTS = ['WageCostReport', 'PayrollRunsReport', 'TurnoverReport', 'LeaveBalanceReport', 'MyReports']
const LISTS = {
	Employees: 'Employee',
	EmploymentContracts: 'EmploymentContract',
	Payslips: 'Payslip',
	LeaveRequests: 'LeaveRequest',
	SickLeaveCases: 'SickLeaveCase',
	TimeEntries: 'TimeEntry',
	Expenses: 'Expense',
}

/**
 * The base definition (the one carrying its slug) of every register schema.
 *
 * @return {{[slug: string]: object}} The schemas by slug.
 */
function baseSchemas() {
	const dir = join(ROOT, 'lib/Settings/register.d')
	const out = {}
	for (const file of readdirSync(dir).filter((name) => name.endsWith('.json'))) {
		const schemas = JSON.parse(readFileSync(join(dir, file), 'utf8')).components?.schemas || {}
		for (const [slug, schema] of Object.entries(schemas)) {
			if (schema.slug === slug) out[slug] = schema
		}
	}
	return out
}

test('REQ-HRP-001: the Reports page offers each new report as a card that opens its dashboard', () => {
	const reports = pages.Reports.config
	const cards = Object.fromEntries(reports.cards.map((card) => [card.id, card]))
	for (const id of REPORTS) {
		assert.equal(cards[id]?.route, id, `${id} has a card routing to it`)
		assert.ok(reports.categories[cards[id].category], `${id}'s category is declared`)
		assert.equal(pages[id]?.type, 'dashboard', `${id} is a dashboard page`)
		const widgetIds = pages[id].config.widgets.map((widget) => widget.id)
		const placed = pages[id].config.layout.map((cell) => cell.widgetId)
		assert.deepEqual([...placed].sort(), [...widgetIds].sort(), `${id} places every widget exactly once`)
	}
	assert.equal(cards.MyReports.category, 'mine')
	assert.equal(reports.categories.mine, 'My reports')
})

test('REQ-HRP-001: wage costs sum the stored run totals per period, with no setup', () => {
	const charts = pages.WageCostReport.config.widgets.filter((widget) => widget.type === 'chart')
	const summed = charts.map((chart) => chart.content.dataSource.aggregate)
	assert.deepEqual(
		summed.map((agg) => [agg.groupBy, agg.metric, agg.sumField]),
		[
			['period', 'sum', 'totalGross'],
			['period', 'sum', 'totalEmployerCharges'],
			['period', 'sum', 'totalLoonheffing'],
			['period', 'sum', 'totalNet'],
		],
	)
	for (const chart of charts) {
		assert.equal(chart.content.dataSource.schema, 'PayrollRun')
		assert.equal(chart.content.dataSource.filter, undefined, 'no filter a user must set first')
	}
})

test('REQ-HRP-001: the leave balance report lists balances per employee and type with their expiry date', () => {
	const table = pages.LeaveBalanceReport.config.widgets.find((widget) => widget.type === 'object-table').content
	assert.equal(table.schema, 'LeaveBalance')
	assert.deepEqual(table.sort, { field: 'expiryDate', dir: 'asc' })
	const keys = table.columns.map((column) => column.key)
	for (const key of ['employeeId', 'leaveType', 'expiryDate', 'entitledHours', 'usedHours']) {
		assert.ok(keys.includes(key), `column ${key}`)
	}
})

test('REQ-HRP-001: turnover buckets starters and leavers per month within the chosen range', () => {
	const config = pages.TurnoverReport.config
	assert.equal(config.dateRange.enabled, true)
	const buckets = config.widgets.filter((widget) => widget.content.dataSource?.bucket).map((widget) => widget.content.dataSource.bucket)
	assert.deepEqual(buckets.map((b) => [b.field, b.interval]), [['startDate', 'month'], ['endDate', 'month']])
})

test('REQ-HRP-002: the seven lists offer saved views and export, and their schemas are exportable', () => {
	const schemas = baseSchemas()
	for (const [id, slug] of Object.entries(LISTS)) {
		assert.equal(pages[id].config.allowSavedViews, true, `${id} saved views`)
		assert.equal(pages[id].config.allowExport, true, `${id} export`)
		assert.equal(pages[id].config.schema, slug)
		assert.equal(schemas[slug].exportable, true, `${slug} exportable`)
	}
	assert.equal(pages.LeaveBalances.config.allowExport, undefined, 'a list the change does not name stays as it was')
})

test('REQ-HRP-002: My reports lists the own and shared saved views of exactly the seven lists', () => {
	const widgets = pages.MyReports.config.widgets
	assert.ok(widgets.every((widget) => widget.type === 'saved-views'))
	assert.deepEqual(
		Object.fromEntries(widgets.map((widget) => [widget.content.route, widget.content.schema])),
		LISTS,
	)
	assert.ok(widgets.every((widget) => widget.content.own === false), 'shared views show too')
})
