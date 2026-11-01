/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Golden tests for the build and check scripts under scripts/. Each case
 * copies a script into a throwaway repository, runs it, and compares its exit
 * code, output and written files with tests/js/fixtures/buildScripts.golden.json.
 * Set RECORD_GOLDEN=1 to rewrite that file.
 *
 * @spec exclude build tooling, not product behaviour
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
const GOLDEN = path.join(HERE, 'fixtures', 'buildScripts.golden.json')
const RECORD = process.env.RECORD_GOLDEN === '1'
const recorded = RECORD || !fs.existsSync(GOLDEN) ? {} : JSON.parse(fs.readFileSync(GOLDEN, 'utf8'))
const results = {}

const json = (value) => JSON.stringify(value, null, 2)

const SCHEMAS = {
	'lib/Settings/humaniq_register.json': json({
		components: {
			schemas: {
				Employee: {
					title: 'Employee',
					properties: {
						name: { title: 'Name', description: 'Full name' },
						status: {
							title: 'Status',
							description: 'Full name',
							'x-enum-labels': { active: 'Active', left: 'Left' },
						},
						tags: { items: { 'x-enum-labels': { a: 'Tag A', b: ' ' } } },
						broken: null,
						count: 3,
						blank: { title: '', description: 42 },
					},
				},
				List: [{ properties: { item: { title: 'Item title' } } }, 'text'],
			},
		},
	}),
	'lib/Settings/register.d/leave.json': json({
		title: 'Leave',
		properties: { days: { title: 'Days', description: 'Name' } },
		nested: { properties: 'not an object', other: { properties: [] }, listed: { title: 'Array props', properties: [{ title: 'Array item' }] } },
	}),
	'lib/Settings/register.d/notes.txt': 'not json',
	'lib/Settings/register.d/bad.json': '{ broken',
}

const CATALOGUE = {
	'l10n/en.json': json({ translations: { Employee: 'Employee', Name: 'Name', 'Tag A': 'Tag A' } }),
}

const L10N = {
	'appinfo/info.xml': '<info>\n\t<id> humaniq </id>\n</info>\n',
	'l10n/en.json': json({ translations: { Hello: 'Hello', 'Say "hi"': 'Say "hi"' } }),
	'l10n/nl.json': json({ translations: { Hello: 'Hallo' }, pluralForm: 'nplurals=2; plural=(n > 1);' }),
	'l10n/.schema-l10n-baseline.json': json({ uncovered: 3 }),
	'l10n/README.md': 'not a catalogue',
}

const SRC = {
	'src/main.js': 'console.log(1)\n',
	'src/views/Page.vue': '<template><div /></template>\n',
}

/**
 * Write every file of a fixture under a root directory.
 *
 * @param {string} root - the throwaway repository
 * @param {object} files - repo-relative path -> content
 */
function writeFiles(root, files) {
	for (const [rel, content] of Object.entries(files)) {
		fs.mkdirSync(path.dirname(path.join(root, rel)), { recursive: true })
		fs.writeFileSync(path.join(root, rel), content)
	}
}

/**
 * Every file under a directory, as repo-relative path -> content.
 *
 * @param {string} root - the throwaway repository
 * @return {object} the tree
 */
function readTree(root) {
	const tree = {}
	const walk = (dir) => {
		for (const entry of fs.readdirSync(dir, { withFileTypes: true })) {
			const abs = path.join(dir, entry.name)
			if (entry.isDirectory()) {
				walk(abs)
			} else if (!abs.includes(`${path.sep}scripts${path.sep}`)) {
				tree[path.relative(root, abs).split(path.sep).join('/')] = fs.readFileSync(abs, 'utf8')
			}
		}
	}
	walk(root)
	return Object.fromEntries(Object.entries(tree).sort(([a], [b]) => a.localeCompare(b)))
}

/**
 * Run one script in a fresh repository built from a case description.
 *
 * @param {object} spec - { script, files, args, env }
 * @return {object} exit code, output and the files present afterwards
 */
function runCase(spec) {
	const root = fs.mkdtempSync(path.join(os.tmpdir(), 'hq-golden-'))
	try {
		writeFiles(root, { [`scripts/${spec.script}`]: fs.readFileSync(path.join(REPO, 'scripts', spec.script), 'utf8') })
		fs.cpSync(path.join(REPO, 'scripts', 'lib'), path.join(root, 'scripts', 'lib'), { recursive: true })
		writeFiles(root, spec.files)
		const env = { ...process.env, GIT_CEILING_DIRECTORIES: os.tmpdir() }
		delete env.CI
		Object.assign(env, spec.env)
		const run = spawnSync(process.execPath, [path.join(root, 'scripts', spec.script), ...(spec.args || [])], {
			cwd: root,
			env,
			encoding: 'utf8',
		})
		const normalise = (text) => text.replace(/(does not parse|is unreadable): .*/g, '$1: <message>')
		return { status: run.status, stdout: normalise(run.stdout), stderr: normalise(run.stderr), files: readTree(root) }
	} finally {
		fs.rmSync(root, { recursive: true, force: true })
	}
}

const sidecar = (info) => ({ 'js/build-info.json': typeof info === 'string' ? info : json(info) })
const OTHER_HASH = 'cdf62f3c03fbe8c3d1f8e8f1ebc1d8b5f3a3c0e0b6c79b1d4cbf3a0a2c4a1a01'

const CASES = {
	'schema-l10n: at the baseline': { script: 'check-schema-l10n.js', files: { ...SCHEMAS, ...CATALOGUE, 'l10n/.schema-l10n-baseline.json': json({ uncovered: 10 }) } },
	'schema-l10n: --list': { script: 'check-schema-l10n.js', args: ['--list'], files: { ...SCHEMAS, ...CATALOGUE, 'l10n/.schema-l10n-baseline.json': json({ uncovered: 10 }) } },
	'schema-l10n: --update': { script: 'check-schema-l10n.js', args: ['--update'], files: { ...SCHEMAS, ...CATALOGUE } },
	'schema-l10n: --update and --list': { script: 'check-schema-l10n.js', args: ['--list', '--update'], files: { ...SCHEMAS, ...CATALOGUE } },
	'schema-l10n: no baseline': { script: 'check-schema-l10n.js', files: { ...SCHEMAS, ...CATALOGUE } },
	'schema-l10n: grew past the baseline': { script: 'check-schema-l10n.js', files: { ...SCHEMAS, ...CATALOGUE, 'l10n/.schema-l10n-baseline.json': json({ uncovered: 5 }) } },
	'schema-l10n: fell below the baseline': { script: 'check-schema-l10n.js', files: { ...SCHEMAS, ...CATALOGUE, 'l10n/.schema-l10n-baseline.json': json({ uncovered: 12 }) } },
	'schema-l10n: no catalogue': { script: 'check-schema-l10n.js', files: { ...SCHEMAS, 'l10n/.schema-l10n-baseline.json': json({ uncovered: 13 }) } },
	'schema-l10n: catalogue without translations': { script: 'check-schema-l10n.js', files: { ...SCHEMAS, 'l10n/en.json': json({}), 'l10n/.schema-l10n-baseline.json': json({ uncovered: 13 }) } },
	'schema-l10n: no lib/Settings': { script: 'check-schema-l10n.js', files: { 'l10n/.schema-l10n-baseline.json': json({ uncovered: 0 }) } },

	'l10n-js: writes every catalogue': { script: 'build-l10n-js.js', files: L10N },
	'l10n-js: --check when current': { script: 'build-l10n-js.js', args: ['--check'], files: { ...L10N, 'l10n/en.js': 'OC.L10N.register(\n    "humaniq",\n    {\n        "Hello": "Hello",\n        "Say \\"hi\\"": "Say \\"hi\\""\n    },\n    "nplurals=2; plural=(n != 1);"\n)\n', 'l10n/nl.js': 'OC.L10N.register(\n    "humaniq",\n    {\n        "Hello": "Hallo"\n    },\n    "nplurals=2; plural=(n > 1);"\n)\n' } },
	'l10n-js: --check when stale': { script: 'build-l10n-js.js', args: ['--check'], files: { ...L10N, 'l10n/en.js': 'old\n' } },
	'l10n-js: rewrites only the stale one': { script: 'build-l10n-js.js', files: { ...L10N, 'l10n/nl.js': 'OC.L10N.register(\n    "humaniq",\n    {\n        "Hello": "Hallo"\n    },\n    "nplurals=2; plural=(n > 1);"\n)\n', 'l10n/en.js': 'old\n' } },
	'l10n-js: --check with one stale': { script: 'build-l10n-js.js', args: ['--check'], files: { ...L10N, 'l10n/nl.js': 'OC.L10N.register(\n    "humaniq",\n    {\n        "Hello": "Hallo"\n    },\n    "nplurals=2; plural=(n > 1);"\n)\n', 'l10n/en.js': 'old\n' } },
	'l10n-js: empty translations': { script: 'build-l10n-js.js', files: { 'appinfo/info.xml': '<id>humaniq</id>', 'l10n/de.json': json({ translations: {} }) } },
	'l10n-js: no catalogue': { script: 'build-l10n-js.js', files: { 'appinfo/info.xml': '<id>humaniq</id>', 'l10n/.schema-l10n-baseline.json': '{}' } },
	'l10n-js: catalogue does not parse': { script: 'build-l10n-js.js', files: { ...L10N, 'l10n/nl.json': '{ nope' } },
	'l10n-js: catalogue without translations': { script: 'build-l10n-js.js', files: { ...L10N, 'l10n/nl.json': json({ pluralForm: 'x' }) } },
	'l10n-js: info.xml without an id': { script: 'build-l10n-js.js', files: { ...L10N, 'appinfo/info.xml': '<info></info>' } },

	'bundle sidecar: missing': { script: 'check-bundle-freshness.js', args: ['--sidecar'], files: SRC },
	'bundle sidecar: unreadable': { script: 'check-bundle-freshness.js', args: ['--sidecar'], files: { ...SRC, ...sidecar('{ nope') } },
	'bundle sidecar: changed source': { script: 'check-bundle-freshness.js', args: ['--sidecar'], files: { ...SRC, ...sidecar({ sourceHash: OTHER_HASH, builtAt: 'B', appVersion: '1.0' }) } },
	'bundle sidecar: changed source, same listing': { script: 'check-bundle-freshness.js', args: ['--sidecar'], files: { ...SRC, ...sidecar({ sourceHash: OTHER_HASH, listedBy: 'walk', builtAt: 'B', appVersion: '1.0' }) } },
	'bundle sidecar: changed source, other listing': { script: 'check-bundle-freshness.js', args: ['--sidecar'], files: { ...SRC, ...sidecar({ sourceHash: OTHER_HASH, listedBy: 'git', builtAt: 'B', appVersion: '1.0' }) } },
	'bundle sidecar: non-string hash': { script: 'check-bundle-freshness.js', args: ['--sidecar'], files: { ...SRC, ...sidecar({ sourceHash: 1234567890123456, builtAt: 'B' }) } },
}

/**
 * The hash the script computes for SRC, so the matching cases record a PASS.
 *
 * @return {string} the sha256 over SRC in walk order
 */
async function srcHash() {
	const root = fs.mkdtempSync(path.join(os.tmpdir(), 'hq-hash-'))
	try {
		writeFiles(root, SRC)
		const { computeSourceHash } = (await import(path.join(REPO, 'scripts', 'lib', 'source-hash.js'))).default
		const before = process.env.GIT_CEILING_DIRECTORIES
		process.env.GIT_CEILING_DIRECTORIES = os.tmpdir()
		const { sourceHash } = computeSourceHash(root)
		if (before === undefined) delete process.env.GIT_CEILING_DIRECTORIES
		else process.env.GIT_CEILING_DIRECTORIES = before
		return sourceHash
	} finally {
		fs.rmSync(root, { recursive: true, force: true })
	}
}

const hash = await srcHash()
const matching = { sourceHash: hash, listedBy: 'walk', builtAt: '2026-11-01T00:00:00Z', appVersion: '1.2.3' }
Object.assign(CASES, {
	'bundle sidecar: matches': { script: 'check-bundle-freshness.js', args: ['--sidecar'], files: { ...SRC, ...sidecar({ ...matching, libVersion: '2.1.0', libSource: 'npm' }) } },
	'bundle sidecar: matches, nothing about the library': { script: 'check-bundle-freshness.js', args: ['--sidecar'], files: { ...SRC, ...sidecar(matching) } },
	'bundle sidecar: local library outside CI': { script: 'check-bundle-freshness.js', args: ['--sidecar'], files: { ...SRC, ...sidecar({ ...matching, libSource: 'local' }) } },
	'bundle sidecar: local library in CI': { script: 'check-bundle-freshness.js', args: ['--sidecar'], env: { CI: 'true' }, files: { ...SRC, ...sidecar({ ...matching, libSource: 'local' }) } },
	'bundle sidecar: npm library in CI': { script: 'check-bundle-freshness.js', args: ['--sidecar'], env: { CI: 'true' }, files: { ...SRC, ...sidecar({ ...matching, libSource: 'npm' }) } },
	'bundle sidecar: matches with another listing': { script: 'check-bundle-freshness.js', args: ['--sidecar'], files: { ...SRC, ...sidecar({ ...matching, listedBy: 'git' }) } },
})

for (const [name, spec] of Object.entries(CASES)) {
	test(name, () => {
		const actual = runCase(spec)
		results[name] = actual
		if (!RECORD) {
			assert.deepEqual(actual, recorded[name])
		}
	})
}

test('every recorded case still runs', () => {
	if (RECORD) {
		fs.mkdirSync(path.dirname(GOLDEN), { recursive: true })
		fs.writeFileSync(GOLDEN, JSON.stringify(results, null, '\t') + '\n')
		return
	}
	assert.deepEqual(Object.keys(recorded).sort(), Object.keys(CASES).sort())
})
