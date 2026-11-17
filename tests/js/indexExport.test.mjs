/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Every schema-bound humaniq list turns the full-list Export menu on; the
 * schema's own `configuration.exportable` then decides whether it shows.
 *
 * @spec openspec/changes/reporting-full-list-export/specs/hr-list-export/spec.md#requirement-every-humaniq-list-shall-offer-an-export-of-the-filtered-list-req-hle-001
 */
import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { test } from 'node:test'

const read = (path) => JSON.parse(readFileSync(new URL(path, import.meta.url), 'utf8'))
const { pages } = read('../../src/manifest.effective.json')
const indexPages = pages.filter((page) => page.type === 'index')
const schemaBound = indexPages.filter((page) => typeof page.config?.schema === 'string' && page.config.schema !== '')

test('there are schema-bound index pages to check', () => {
	assert.ok(schemaBound.length > 80, `expected the scaffolded lists, found ${schemaBound.length}`)
})

test('every schema-bound index page allows the export', () => {
	assert.deepEqual(schemaBound.filter((page) => page.config.allowExport !== true).map((page) => page.id), [])
})

test('the index template turns the export on for every list built on it', () => {
	const { pageTemplates } = read('../../src/manifest.d/00-templates.json')
	const scaffold = pageTemplates.find((template) => template.id === 'indexScaffold')
	assert.equal(scaffold.page.config.allowExport, true)
})

test('the concrete index pages turn the export on themselves', () => {
	for (const id of ['TimeEntries', 'MijnAanbevelingen', 'MijnUren']) {
		assert.equal(pages.find((page) => page.id === id)?.config.allowExport, true, id)
	}
})
