/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Every leaf humaniq registers server-side is carried by the leaves bundle a
 * consuming page loads (`src/leaves.js`), and a leaf registered on one side
 * only, or kept out of that bundle, fails the parity check naming its id.
 * The repository itself passes; small fixture trees show each refusal.
 *
 * @spec openspec/changes/hours-leaf-loads-on-a-consuming-page/specs/hours-leaf-delivery/spec.md#requirement-every-registered-leaf-is-reachable-from-the-bundle-req-hld-002
 */
import assert from 'node:assert/strict'
import { spawnSync } from 'node:child_process'
import { mkdirSync, mkdtempSync, rmSync, writeFileSync } from 'node:fs'
import { tmpdir } from 'node:os'
import { join } from 'node:path'
import { after, test } from 'node:test'
import { fileURLToPath } from 'node:url'

const SCRIPT = fileURLToPath(new URL('../../scripts/check-integration-parity.js', import.meta.url))
const ROOT = fileURLToPath(new URL('../..', import.meta.url))
const made = []

after(() => made.forEach((dir) => rmSync(dir, { recursive: true, force: true })))

/**
 * Run the parity check against a tree.
 *
 * @param {string} root The tree.
 * @return {{status: number, out: string}} Exit status and everything printed.
 */
function check(root) {
	const run = spawnSync(process.execPath, [SCRIPT, root], { encoding: 'utf8' })
	return { status: run.status, out: run.stdout + run.stderr }
}

/**
 * The PHP half of a leaf, as the checker reads it.
 *
 * @param {string} id The leaf id.
 * @return {string} The PHP source.
 */
function phpHalf (id) {
  return `<?php
class Leaf {
	public const LEAF_ID = '${id}';
	private const LABEL_SOURCE = 'Hours';
	private const ICON = 'Clock';
	private const GROUP = 'Time';
	private const REFERENCE_TYPE = 'hours';
	private const SURFACES = ['detail-page'];
	public function make() { return new LeafDescriptor(renderMode: LeafDescriptor::RENDER_MODE_MOUNT); }
}
`
}

/**
 * The JS half of a leaf, as the checker reads it.
 *
 * @param {string} id The leaf id.
 * @return {string} The JS source.
 */
function jsHalf (id) {
  return `const SURFACES = ['detail-page']
export function register() {
	window.OCA.OpenRegister.integrations.register({
	id: '${id}',
	label: t('humaniq', 'Hours'),
	icon: 'Clock',
	group: 'Time',
	referenceType: 'hours',
	renderMode: 'mount',
	surfaces: SURFACES,
	})
}
`
}

/**
 * A fixture tree.
 *
 * @param {Record<string, string>} files Path => content.
 * @return {string} The tree's root.
 */
function tree(files) {
	const root = mkdtempSync(join(tmpdir(), 'hq-parity-'))
	made.push(root)
	for (const [file, content] of Object.entries(files)) {
		mkdirSync(join(root, file, '..'), { recursive: true })
		writeFileSync(join(root, file), content)
	}
	return root
}

test('the repository carries every leaf it registers in its leaves bundle', () => {
	const { status, out } = check(ROOT)
	assert.equal(status, 0, out)
	assert.match(out, /humaniq-hours/)
	assert.match(out, /carried by src\/leaves\.js/)
})

test('a leaf whose registration the bundle reaches through another module passes', () => {
	const root = tree({
		'lib/Leaf.php': phpHalf('demo-hours'),
		'src/integrations/registerDemo.js': jsHalf('demo-hours'),
		'src/integrations/all.js': "export { register } from './registerDemo.js'\nimport './registerDemo.js'\n",
		'src/leaves.js': "import { register } from './integrations/all.js'\nregister()\n",
	})
	const { status, out } = check(root)
	assert.equal(status, 0, out)
})

test('a leaf registered only in the app bundle fails, naming the leaf', () => {
	const root = tree({
		'lib/Leaf.php': phpHalf('demo-hours'),
		'src/integrations/registerDemo.js': jsHalf('demo-hours'),
		'src/main.js': "import { register } from './integrations/registerDemo.js'\nregister()\n",
		'src/leaves.js': "import { loadTranslations } from '@nextcloud/l10n'\nloadTranslations('humaniq')\n",
	})
	const { status, out } = check(root)
	assert.equal(status, 1)
	assert.match(out, /'demo-hours': its client registration src\/integrations\/registerDemo\.js is not reachable from src\/leaves\.js/)
})

test('a second leaf registered server-side only fails, naming that leaf', () => {
	const root = tree({
		'lib/Leaf.php': phpHalf('demo-hours'),
		'lib/Second.php': phpHalf('demo-second'),
		'src/integrations/registerDemo.js': jsHalf('demo-hours'),
		'src/leaves.js': "import { register } from './integrations/registerDemo.js'\nregister()\n",
	})
	const { status, out } = check(root)
	assert.equal(status, 1)
	assert.match(out, /'demo-second': declared by lib\/Second\.php but by no JS half/)
	assert.doesNotMatch(out, /'demo-hours'/)
})

test('an app that declares leaves but ships no leaves bundle fails', () => {
	const root = tree({
		'lib/Leaf.php': phpHalf('demo-hours'),
		'src/integrations/registerDemo.js': jsHalf('demo-hours'),
	})
	const { status, out } = check(root)
	assert.equal(status, 1)
	assert.match(out, /src\/leaves\.js: missing/)
	assert.match(out, /demo-hours/)
})
