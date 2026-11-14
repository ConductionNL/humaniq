/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The role table of the admin settings page: the four roles of REQ-RFA-001
 * with their group and member count, for groups the server reported, groups
 * it did not report and a group that is missing. The page's script is
 * compiled from its SFC with stand-ins for the imports, and the computed
 * `roles` runs on a plain component state.
 *
 * @spec openspec/specs/humaniq-roles-and-field-access/spec.md#REQ-RFA-001
 */
import { parse } from '@vue/compiler-sfc'
import assert from 'node:assert/strict'
import { mkdirSync, readFileSync, writeFileSync } from 'node:fs'
import { join } from 'node:path'
import { test } from 'node:test'
import { fileURLToPath, pathToFileURL } from 'node:url'

const ROOT = fileURLToPath(new URL('../../', import.meta.url))
const BUILD = join(ROOT, 'node_modules/.cache/admin-settings-roles')

mkdirSync(BUILD, { recursive: true })
writeFileSync(join(BUILD, 'stubs.mjs'), `export const translate = (_app, text) => '[' + text + ']'
export const generateUrl = (path) => '/index.php' + path
export const loadState = (_app, _key, fallback) => fallback
export const defineComponent = (component) => component
export const CnAdminSettingsShell = {}
export const CnSettingsSection = {}
export const NcCheckboxRadioSwitch = {}
export const auditLinks = () => null
export const auditSentence = () => ''
export const auditUrls = () => ({})
export const loadAuditEntries = async () => ({ registerId: 0, entries: [] })
export const allowedAfterToggle = () => []
export const loadCategories = async () => []
export const saveCategories = async () => []
export default {}
`)

const source = join(ROOT, 'src/views/settings/AdminSettings.vue')
const stubs = pathToFileURL(join(BUILD, 'stubs.mjs')).href
let script = parse(readFileSync(source, 'utf8'), { filename: source }).descriptor.script.content
script = script.replace(/from '[^']+'/g, `from '${stubs}'`)
writeFileSync(join(BUILD, 'AdminSettings.mjs'), script)
const component = (await import(pathToFileURL(join(BUILD, 'AdminSettings.mjs')).href)).default

/**
 * The computed roles on a component state holding the given role groups.
 *
 * @param {object} roleGroups The initial-state role groups.
 * @return {Array<object>}
 */
function rolesWith(roleGroups) {
	const state = { roleGroups }
	for (const [name, method] of Object.entries(component.methods)) {
		state[name] = method.bind(state)
	}
	return component.computed.roles.call(state)
}

const MANAGER = { id: 'manager', label: '[Manager]', group: '[from the org chart]', may: '[own team: approve leave, hours and reviews]', members: '' }
const EMPLOYEE = { id: 'employee', label: '[Employee]', group: '[everyone]', may: '[own details, payslips, leave and expense claims]', members: '' }

test('reported groups show their id and member count', () => {
	assert.deepEqual(rolesWith({ hr: { id: 'hr-staff', exists: true, members: 3 }, payroll: { id: 'pay', exists: true, members: 0 } }), [
		{ id: 'hr', label: '[HR]', group: 'hr-staff', may: '[employees, contracts, leave, absence, GDPR requests]', members: '3' },
		{ id: 'payroll', label: '[Payroll administration]', group: 'pay', may: '[calculate and approve payroll runs, tax returns, payments]', members: '0' },
		MANAGER,
		EMPLOYEE,
	])
})

test('unreported groups fall back to the fixed ids and show as missing', () => {
	assert.deepEqual(rolesWith({ payroll: { exists: false } }), [
		{ id: 'hr', label: '[HR]', group: 'humaniq-hr', may: '[employees, contracts, leave, absence, GDPR requests]', members: '[group missing]' },
		{ id: 'payroll', label: '[Payroll administration]', group: 'humaniq-payroll', may: '[calculate and approve payroll runs, tax returns, payments]', members: '[group missing]' },
		MANAGER,
		EMPLOYEE,
	])
})
