import { JSDOM } from 'jsdom'
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The Integrations page over integriq's connection registry, offline: the
 * page names integriq as the app it requires and scopes the rows to humaniq,
 * the menu entry hides without integriq while Access grants stays, the Add
 * integration handler leaves for integriq's link dialog, and the two
 * built-in formatters the columns name label all seven statuses and pass an
 * unknown one through. A column naming a formatter nobody provides, or a
 * header action naming an unregistered handler, renders as nothing without an
 * error, which is why this is checked here.
 *
 * @spec openspec/changes/platform-integrations-catalogue/specs/humaniq-integrations/spec.md#requirement-an-administrator-shall-add-an-integration-from-humaniq-req-hin-002
 */
import assert from 'node:assert/strict'
import fs from 'node:fs'
import { register } from 'node:module'
import { test } from 'node:test'

register(new URL('./support/nextcloudFakeHooks.mjs', import.meta.url))

// The two formatters translate through @nextcloud/l10n, which reads the
// browser session on import, so they need a document to load in.
const dom = new JSDOM('<!doctype html><html lang="en"><body></body></html>', { url: 'http://localhost/' })
globalThis.window = dom.window
globalThis.document = dom.window.document

const read = (rel) => JSON.parse(fs.readFileSync(new URL(rel, import.meta.url), 'utf8'))
const fragment = read('../../src/manifest.d/platform-integrations-catalogue.json')
const effective = read('../../src/manifest.effective.json')
const registrySource = fs.readFileSync(new URL('../../src/registry.js', import.meta.url), 'utf8')
const { createConnectionHandlers, INTEGRIQ_CONNECTIONS_PATH } = await import('../../src/services/connectionRegistry.js')
const { BUILT_IN_FORMATTERS } = await import('@conduction/nextcloud-vue/src/utils/builtInFormatters.js')

const page = effective.pages.find((p) => p.id === 'Integrations')
const configuratie = effective.menu.find((m) => m.id === 'ConfiguratieGroup')

test('the page reads integriq app_connection rows for humaniq and requires integriq', () => {
	assert.equal(page.type, 'index')
	assert.equal(page.route, '/configuratie/koppelingen')
	assert.equal(page.config.register, 'integriq')
	assert.equal(page.config.schema, 'app_connection')
	assert.deepEqual(page.config.filter, { app: 'humaniq' })
	assert.deepEqual(page.requiresApp, { id: 'integriq', name: 'Integriq' })
	assert.equal(page.permission, 'admin')
	assert.equal(page.config.actionToggles.showAdd, false)
	assert.deepEqual(fragment.pages[0], page)
})

test('the Configuratie menu offers Integrations only with integriq, and Access grants always', () => {
	const integrations = configuratie.children.find((c) => c.id === 'Koppelingen')
	const grants = configuratie.children.find((c) => c.id === 'Integraties')
	assert.equal(integrations.route, 'Integrations')
	assert.deepEqual(integrations.visibleIf, { appInstalled: 'integriq' })
	assert.equal(grants.label, 'Access grants')
	assert.equal(grants.route, 'IntegrationAccounts')
	assert.equal(grants.visibleIf, undefined)
	const accounts = effective.pages.find((p) => p.id === 'IntegrationAccounts')
	assert.equal(accounts.title, 'Access grants')
	assert.equal(accounts.route, '/configuratie/integraties')
})

test('Add integration is the one header action and its handler is registered', () => {
	assert.deepEqual(page.config.headerActions.map((a) => a.handler), ['openIntegriqConnections'])
	assert.match(registrySource, /openIntegriqConnections: \{\n\t\tkind: 'handler',\n\t\thandler: openIntegriqConnections,/)
})

test('Add integration leaves for integriq preset to humaniq with the link dialog open', () => {
	const visited = []
	const handlers = createConnectionHandlers({
		generateUrl: (path) => `/index.php${path}`,
		assign: (url) => visited.push(url),
	})
	handlers.openIntegriqConnections()
	assert.equal(INTEGRIQ_CONNECTIONS_PATH, '/apps/integriq/connections?app=humaniq&link=1')
	assert.deepEqual(visited, ['/index.php/apps/integriq/connections?app=humaniq&link=1'])
})

test('the columns name only formatters the library provides', () => {
	const named = page.config.columns.map((c) => c.formatter).filter(Boolean)
	assert.deepEqual(named, ['connectionStatus', 'connectionSettingsLabel'])
	for (const name of named) {
		assert.equal(typeof BUILT_IN_FORMATTERS[name], 'function')
	}
})

test('connectionStatus labels all seven statuses and passes an unknown one through', () => {
	const fmt = BUILT_IN_FORMATTERS.connectionStatus
	assert.deepEqual(
		['configured', 'limited', 'unconfigured', 'simulated', 'disabled', 'unavailable', 'error'].map((s) => fmt(s)),
		['Configured', 'Limited', 'Not configured', 'Simulated', 'Switched off', 'Not available', 'Error'],
	)
	assert.equal(fmt('rebooting'), 'rebooting')
	assert.equal(fmt(null), '')
})

test('connectionSettingsLabel offers a link only when the row has a settings URL', () => {
	const fmt = BUILT_IN_FORMATTERS.connectionSettingsLabel
	assert.equal(fmt('/settings/admin/humaniq'), 'Open settings')
	assert.equal(fmt(''), '')
	assert.equal(fmt(null), '')
})
