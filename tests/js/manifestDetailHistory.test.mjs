/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Every detail page shows the record's audit history: the manifest check names
 * a `type: "detail"` page without `sidebar.showMetadata: true`, and the
 * shipped effective manifest has none.
 *
 * @spec openspec/changes/record-history-on-every-detail-page/specs/record-audit-history/spec.md#requirement-every-humaniq-detail-page-shall-show-the-records-history-req-rah-002
 */
import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { createRequire } from 'node:module'
import { test } from 'node:test'
import { fileURLToPath } from 'node:url'

const require = createRequire(import.meta.url)
const { detailPagesWithoutHistory } = require('../manifest-detail-history.js')
const ROOT = fileURLToPath(new URL('../../', import.meta.url))

test('a detail page without showMetadata is named, other pages and complete detail pages are not', () => {
	const manifest = {
		pages: [
			{ id: 'EmployeeDetail', type: 'detail', config: { sidebar: { enabled: true, showMetadata: true } } },
			{ id: 'NoSidebar', type: 'detail', config: {} },
			{ id: 'HistoryOff', type: 'detail', config: { sidebar: { enabled: true, showMetadata: false } } },
			{ id: 'TruthyNotTrue', type: 'detail', config: { sidebar: { showMetadata: 'yes' } } },
			{ id: 'PageLevel', type: 'detail', sidebar: { showMetadata: true } },
			{ id: 'Employees', type: 'index', config: {} },
		],
	}

	assert.deepEqual(detailPagesWithoutHistory(manifest), ['NoSidebar', 'HistoryOff', 'TruthyNotTrue'])
})

test('a manifest without pages has nothing to name', () => {
	assert.deepEqual(detailPagesWithoutHistory({}), [])
	assert.deepEqual(detailPagesWithoutHistory(null), [])
})

test('every detail page of the shipped effective manifest shows the record history', () => {
	const effective = JSON.parse(readFileSync(ROOT + 'src/manifest.effective.json', 'utf8'))

	assert.ok(effective.pages.some((page) => page.type === 'detail'))
	assert.deepEqual(detailPagesWithoutHistory(effective), [])
})
