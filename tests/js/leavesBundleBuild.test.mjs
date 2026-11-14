/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The leaves bundle (REQ-HLD-001): a production build of the `leaves` entry
 * produces `humaniq-leaves.js`, the exact name OpenRegister's
 * LeafScriptListener looks for, small enough to load on another app's page
 * and without the router, the store or the app shell.
 *
 * The ceiling is 512 KB. Measured 2026-11-14: the leaves bundle is 232 KB
 * built alone and 320 KB in the full build, against 16.6 MB for
 * `humaniq-main.js`; at 512 KB it is still more than thirty times smaller, so
 * the SPA leaking in through an import trips it long before that ratio is lost.
 *
 * @spec openspec/changes/hours-leaf-loads-on-a-consuming-page/specs/hours-leaf-delivery/spec.md#requirement-humaniq-ships-a-leaves-bundle-req-hld-001
 */
import assert from 'node:assert/strict'
import { spawnSync } from 'node:child_process'
import fs from 'node:fs'
import os from 'node:os'
import path from 'node:path'
import { test } from 'node:test'
import { fileURLToPath } from 'node:url'

const HERE = path.dirname(fileURLToPath(import.meta.url))
const REPO = path.resolve(HERE, '..', '..')
const APP_ID = /<id>([^<]+)<\/id>/.exec(fs.readFileSync(path.join(REPO, 'appinfo', 'info.xml'), 'utf8'))[1]
const EXPECTED = `${APP_ID}-leaves.js`
const CEILING_BYTES = 512 * 1024

// What must never reach another app's page.
const FORBIDDEN = ['vue-router', 'pinia', 'src/main.js', 'src/store', 'manifest.json', 'CnAppRoot']

test('a production build produces the leaves bundle the loader looks for', { timeout: 300000 }, () => {
	const out = fs.mkdtempSync(path.join(os.tmpdir(), 'humaniq-leaves-'))
	try {
		const build = spawnSync(process.execPath, [path.join(HERE, 'support', 'buildLeavesBundle.cjs'), REPO, out], { encoding: 'utf8' })
		assert.equal(build.status, 0, `the leaves build failed: ${build.stderr}`)

		const bundle = path.join(out, EXPECTED)
		const written = fs.readdirSync(out).filter((name) => name.endsWith('.js'))
		assert.ok(fs.existsSync(bundle), `expected ${EXPECTED}, the build wrote ${written.join(', ') || 'no script'}`)

		const size = fs.statSync(bundle).size
		assert.ok(size > 0, `${EXPECTED} is empty`)
		assert.ok(size < CEILING_BYTES, `${EXPECTED} is ${size} bytes, over the ${CEILING_BYTES}-byte ceiling`)

		const modules = JSON.parse(fs.readFileSync(path.join(out, 'modules.json'), 'utf8'))
		assert.ok(modules.some((name) => name.includes('src/integrations/registerHoursLeaf.js')), 'the hours leaf registration is not in the bundle')
		for (const forbidden of FORBIDDEN) {
			const found = modules.filter((name) => name.includes(forbidden))
			assert.deepEqual(found, [], `${EXPECTED} carries ${forbidden}`)
		}
	} finally {
		fs.rmSync(out, { recursive: true, force: true })
	}
})
