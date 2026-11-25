/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * nextcloud-vue's api-call dispatcher sends an action's `url` through
 * `generateUrl()`, which adds `/index.php` and nothing else. A url written as
 * `/api/...` therefore posts to `/index.php/api/...`, outside this app, and
 * 404s: found on the live check of documents-employer-statement-from-the-contract
 * 3.2, where "Make employer statement" never reached humaniq. Every api-call url
 * must name the app (`/apps/humaniq/...`) or be absolute.
 *
 * @spec openspec/specs/humaniq-docudesk-documents/spec.md#REQ-HDD-011
 */
import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'
import { test } from 'node:test'

test('every api-call action url names the app or is absolute', async () => {
	const manifest = JSON.parse(await readFile(new URL('../../src/manifest.effective.json', import.meta.url), 'utf8'))
	const urls = []
	const walk = (node) => {
		if (Array.isArray(node)) {
			node.forEach(walk)
		} else if (node !== null && typeof node === 'object') {
			if (node.type === 'api-call' && typeof node.url === 'string') {
				urls.push(node.url)
			}
			Object.values(node).forEach(walk)
		}
	}
	walk(manifest)

	assert.ok(urls.length >= 45, `the manifest declares its api-call actions (found ${urls.length})`)
	const stray = urls.filter((url) => !url.startsWith('/apps/') && !/^https?:\/\//i.test(url))
	assert.deepEqual(stray, [], 'api-call urls that would post outside the app')
})
