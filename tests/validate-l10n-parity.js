#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// validate-l10n-parity.js — asserts that hrmq's locale catalogues actually
// cover the strings the app renders, and that the manifest holds ENGLISH
// source keys rather than Dutch literals.
//
// Why this exists (the defect it closes):
//
//   hrmq shipped NO l10n/ directory at all, and its manifest used Dutch
//   literals as translation KEYS. `CnAppNav` renders every menu entry through
//   `translate('hrmq', item.label)` — so the Dutch literal WAS the key, and
//   with no catalogue loaded `@nextcloud/l10n` returns the key unchanged.
//   Result: the menu rendered in Dutch in every locale, English included,
//   while `loadTranslations('hrmq', …)` in src/main.js 404'd on every boot
//   because there was nothing to serve. hydra ADR-007 forbids both halves:
//   "l10n/en.json and l10n/nl.json MUST exist in every app with a UI" and
//   "Dutch strings used as translation keys … are a violation — the English
//   equivalent must be the key."
//
//   The failure mode is silent by construction. A missing key does not throw;
//   `translate()` hands back the key, which for a Dutch literal looks exactly
//   like a working Dutch translation. Only a mechanical check can see it.
//
// WHAT THIS SCRIPT ENFORCES
//
//   1. l10n/en.json and l10n/nl.json exist, parse, and carry `translations`
//      plus a `pluralForm` (Nextcloud core's catalogue shape).
//   2. IDENTICAL KEY SETS, zero gaps in either direction (ADR-007).
//   3. en.json is IDENTITY-MAPPED (key === value) — it is the source
//      catalogue, so any divergence means a key was edited on one side only.
//   4. Every nl.json value is a non-empty string (an empty translation renders
//      as an empty menu label, not as a fallback).
//   5. Every user-visible string in the manifest surface (src/manifest.json +
//      src/manifest.d/*.json) is a key in BOTH catalogues.
//   6. Every `t('hrmq', '…')` key in src/** is a key in BOTH catalogues.
//   7. ZERO Dutch literals remain in the manifest surface, detected by Dutch
//      function words (de/het/een/…) that cannot occur in English prose. The
//      check is deliberately function-word based: Dutch statutory proper nouns
//      (WNT, WKR, TWK, Cao Gemeenten, UPA) legitimately survive translation,
//      Dutch SENTENCES do not.
//   8. Every SCHEMA-derived display string in lib/Settings/register.d/*.json
//      is a key in BOTH catalogues, and carries no Dutch literal.
//
//      This half was previously exempted on the theory that schema strings
//      "are rendered by OpenRegister, not by hrmq's manifest renderer". That
//      was wrong. `fieldsFromSchema()` runs every property `title` through the
//      injected `cnTranslate`, which CnAppRoot binds to this app's id — so a
//      schema title is a key in THIS catalogue, and an absent key renders the
//      English source in a Dutch session. The strings checked are the schema
//      `title` (dialog heading + Add button noun), each property `title`
//      (field label + column header), and the VALUES of `x-enum-labels`
//      (dropdown options + status badges).
//
//      The enum VALUES themselves are not checked: they are stored contract
//      values, several Dutch by design (`ingediend`), and are never rendered
//      once the property declares `x-enum-labels`.
//
// WHAT IT DELIBERATELY DOES NOT CHECK
//
//   - Schema property `description` — the helper text under a field. Those
//     strings are still written for a developer reading the schema rather
//     than for the person filling in the form, and rewriting them is the
//     forms-as-process copy pass, not a translation one. Translating ~730
//     descriptions that are already slated to be rewritten would be work
//     thrown away, so they are out of scope until that pass lands. Tracked
//     as the descriptions phase of the forms-as-process programme.
//   - Route paths, page/menu/widget ids, and lifecycle transition `action`
//     ids. Those are backend contract, not display text, and several are
//     Dutch by design (`"action": "indienen"`).
//   - Catalogue keys with no current call site. A key that outlives its last
//     use is harmless; a call site with no key is the bug.
//
// Usage:
//   node tests/validate-l10n-parity.js     (npm run check:l10n)
//
// Exit codes:
//   0 — catalogues are complete and the manifest is English-keyed
//   1 — at least one gap, identity break, or surviving Dutch literal

'use strict'

const fs = require('fs')
const path = require('path')
const vm = require('vm')

const REPO_ROOT = path.resolve(__dirname, '..')

/*
 * The app id, read from appinfo/info.xml rather than hardcoded.
 *
 * It used to be the literal 'hrmq' in three places here: the OC.L10N.register
 * assertion and the regex that harvests `t('<app>', …)` keys out of src/. The
 * hrmq -> humaniq rename moved the id everywhere EXCEPT this file, and the
 * regex was the dangerous half: it would have gone on searching for
 * `t('hrmq', …)`, matched nothing, and reported check 6 as passing over an
 * empty set — a check that validates nothing looks exactly like one that
 * passes. Deriving the id means the next rename cannot reintroduce that.
 */
const APP_ID = (() => {
	const info = fs.readFileSync(path.join(REPO_ROOT, 'appinfo', 'info.xml'), 'utf8')
	const m = info.match(/<id>([^<]+)<\/id>/)
	if (!m) {
		throw new Error('validate-l10n-parity: could not read <id> from appinfo/info.xml')
	}
	return m[1].trim()
})()

const L10N_DIR = path.join(REPO_ROOT, 'l10n')
const BASE_MANIFEST = path.join(REPO_ROOT, 'src', 'manifest.json')
const FRAGMENT_DIR = path.join(REPO_ROOT, 'src', 'manifest.d')
const SRC_DIR = path.join(REPO_ROOT, 'src')
const SCHEMA_DIR = path.join(REPO_ROOT, 'lib', 'Settings', 'register.d')

// The manifest properties that carry text a user reads. Everything else in a
// manifest node is an id, a route, a field name or a schema slug.
const DISPLAY_KEYS = [
	'label',
	'title',
	'description',
	'successMessage',
	'errorMessage',
	'dataTitle',
	'relatedTitle',
	'statusTitle',
	'caption',
	'emptyText',
	'text',
]

// `{{title}}` / `{{dataTitle}}` etc. in 00-templates.json are pageTemplate
// placeholders — expandPageTemplates substitutes the concrete page's own text
// before the renderer ever sees them, so they are never translated.
const PLACEHOLDER_RE = /^\{\{[^{}]+\}\}$/

// Dutch function words. None of these is an English word, so a hit means
// Dutch PROSE survived the conversion to English source keys. Statutory Dutch
// nouns (WNT, WKR, loonheffing, Cao Gemeenten) are intentionally absent from
// this list — they are proper nouns and stay.
const DUTCH_FUNCTION_WORDS = [
	'aan', 'als', 'bij', 'dat', 'de', 'deze', 'die', 'door', 'een', 'eigen',
	'geen', 'het', 'hier', 'je', 'jouw', 'kan', 'kunnen', 'met', 'naar',
	'niet', 'nog', 'ook', 'onder', 'op', 'te', 'uit', 'van', 'voor', 'waar',
	'wordt', 'worden', 'zijn', 'zonder',
]
const DUTCH_RE = new RegExp('(^|[^\\p{L}])(' + DUTCH_FUNCTION_WORDS.join('|') + ')([^\\p{L}]|$)', 'iu')

function loadJson(file) {
	return JSON.parse(fs.readFileSync(file, 'utf8'))
}

function manifestFiles() {
	const fragments = fs
		.readdirSync(FRAGMENT_DIR)
		.filter((f) => f.endsWith('.json'))
		.sort()
		.map((f) => path.join(FRAGMENT_DIR, f))
	return [BASE_MANIFEST, ...fragments]
}

/**
 * Collect every display string in one manifest document, remembering where it
 * came from so a failure names the file and the property.
 *
 * @param {object|Array} node - current manifest node
 * @param {string} file - the manifest file being walked, for reporting
 * @param {Map<string, string[]>} sink - value -> ["<file>:<property>", …]
 */
function collectDisplayStrings(node, file, sink) {
	if (Array.isArray(node)) {
		for (const item of node) collectDisplayStrings(item, file, sink)
		return
	}
	if (node === null || typeof node !== 'object') return
	for (const [key, value] of Object.entries(node)) {
		if (typeof value === 'string') {
			if (DISPLAY_KEYS.includes(key) === false) continue
			if (PLACEHOLDER_RE.test(value)) continue
			if (sink.has(value) === false) sink.set(value, [])
			const where = `${path.relative(REPO_ROOT, file)}:${key}`
			if (sink.get(value).includes(where) === false) sink.get(value).push(where)
		} else {
			collectDisplayStrings(value, file, sink)
		}
	}
}

/**
 * Collect every `t('hrmq', '…')` key under src/. The renderer resolves those
 * through the same catalogue as the manifest strings.
 *
 * @param {string} dir - directory to walk
 * @param {Map<string, string[]>} sink - key -> ["<file>", …]
 */
function collectTranslateKeys(dir, sink) {
	for (const entry of fs.readdirSync(dir, { withFileTypes: true })) {
		const full = path.join(dir, entry.name)
		if (entry.isDirectory()) {
			collectTranslateKeys(full, sink)
			continue
		}
		if (/\.(vue|js|ts)$/.test(entry.name) === false) continue
		const source = fs.readFileSync(full, 'utf8')
		const re = new RegExp(`\\bt\\(\\s*'${APP_ID}'\\s*,\\s*'((?:[^'\\\\]|\\\\.)*)'`, 'g')
		let match
		while ((match = re.exec(source)) !== null) {
			const key = match[1].replace(/\\'/g, "'").replace(/\\\\/g, '\\')
			if (sink.has(key) === false) sink.set(key, [])
			const where = path.relative(REPO_ROOT, full)
			if (sink.get(key).includes(where) === false) sink.get(key).push(where)
		}
	}
}

/**
 * Every schema fragment under lib/Settings/register.d/. These carry the other
 * half of the app's display text: the form field labels and the option labels
 * of every dropdown.
 *
 * @return {string[]} absolute paths, sorted
 */
function schemaFiles() {
	return fs
		.readdirSync(SCHEMA_DIR)
		.filter((f) => f.endsWith('.json'))
		.sort()
		.map((f) => path.join(SCHEMA_DIR, f))
}

/**
 * Collect the display strings a schema puts on screen, remembering where each
 * came from so a failure names the file.
 *
 * Three kinds, and only these three:
 *
 *   - a SCHEMA `title` — the heading of the create/edit dialog, and the noun
 *     in the index page's Add button.
 *   - a PROPERTY `title` — the field's label, and its column header.
 *   - the VALUES of a property's `x-enum-labels` — the option labels of a
 *     dropdown and the text of a status badge. The enum values themselves are
 *     deliberately NOT collected: they are stored contract values, several of
 *     them Dutch by design (`ingediend`), and are never rendered once the
 *     property declares its labels.
 *
 * Property `description` is deliberately NOT collected — see the header.
 *
 * @param {object|Array} node - current schema node
 * @param {string} file - the schema file being walked, for reporting
 * @param {Map<string, string[]>} sink - value -> ["<file>:<what>", …]
 */
function collectSchemaStrings(node, file, sink) {
	if (Array.isArray(node)) {
		for (const item of node) collectSchemaStrings(item, file, sink)
		return
	}
	if (node === null || typeof node !== 'object') return

	const where = path.relative(REPO_ROOT, file)
	const remember = (value, what) => {
		if (typeof value !== 'string' || value.trim() === '') return
		if (sink.has(value) === false) sink.set(value, [])
		const label = `${where}:${what}`
		if (sink.get(value).includes(label) === false) sink.get(value).push(label)
	}

	if (node.properties !== null && typeof node.properties === 'object' && Array.isArray(node.properties) === false) {
		remember(node.title, 'schema title')
		for (const [key, prop] of Object.entries(node.properties)) {
			if (prop === null || typeof prop !== 'object') continue
			remember(prop.title, `${key}.title`)
			for (const source of [prop, prop.items]) {
				if (source === null || typeof source !== 'object') continue
				const labels = source['x-enum-labels']
				if (labels === null || typeof labels !== 'object') continue
				for (const label of Object.values(labels)) remember(label, `${key}.x-enum-labels`)
			}
		}
	}

	for (const value of Object.values(node)) collectSchemaStrings(value, file, sink)
}

function main() {
	const failures = []

	// --- 1. catalogues exist and have core's shape -------------------------
	const catalogues = {}
	for (const locale of ['en', 'nl']) {
		const file = path.join(L10N_DIR, `${locale}.json`)
		if (fs.existsSync(file) === false) {
			failures.push(`l10n/${locale}.json is missing — ADR-007 requires both en and nl in every app with a UI.`)
			continue
		}
		let doc
		try {
			doc = loadJson(file)
		} catch (error) {
			failures.push(`l10n/${locale}.json does not parse as JSON: ${error.message}`)
			continue
		}
		if (doc.translations === null || typeof doc.translations !== 'object' || Array.isArray(doc.translations)) {
			failures.push(`l10n/${locale}.json has no "translations" object — @nextcloud/l10n cannot read it.`)
			continue
		}
		if (typeof doc.pluralForm !== 'string' || doc.pluralForm.length === 0) {
			failures.push(`l10n/${locale}.json has no "pluralForm" string (Nextcloud core catalogue shape).`)
		}
		catalogues[locale] = doc.translations
		checkJsCatalogue(locale, doc.translations, failures)
	}

	if (failures.length > 0) {
		report(failures, 0, 0, 0)
		return
	}

	const en = catalogues.en
	const nl = catalogues.nl
	const enKeys = Object.keys(en)
	const nlKeys = Object.keys(nl)

	// --- 2. identical key sets, zero gaps ----------------------------------
	const missingInNl = enKeys.filter((k) => Object.hasOwn(nl, k) === false)
	const missingInEn = nlKeys.filter((k) => Object.hasOwn(en, k) === false)
	for (const key of missingInNl) failures.push(`key present in en.json but MISSING from nl.json: ${JSON.stringify(key)}`)
	for (const key of missingInEn) failures.push(`key present in nl.json but MISSING from en.json: ${JSON.stringify(key)}`)

	// --- 3. en.json is identity-mapped -------------------------------------
	for (const key of enKeys) {
		if (en[key] !== key) {
			failures.push(`en.json is not identity-mapped: ${JSON.stringify(key)} -> ${JSON.stringify(en[key])}`)
		}
	}

	// --- 4. every nl value is a non-empty string ---------------------------
	for (const key of nlKeys) {
		if (typeof nl[key] !== 'string' || nl[key].trim() === '') {
			failures.push(`nl.json has an empty translation for ${JSON.stringify(key)} — it would render as blank, not as a fallback.`)
		}
	}

	// --- 5. every manifest display string is a key -------------------------
	const manifestStrings = new Map()
	for (const file of manifestFiles()) {
		collectDisplayStrings(loadJson(file), file, manifestStrings)
	}
	for (const [value, where] of manifestStrings) {
		if (Object.hasOwn(en, value) === false) {
			failures.push(`manifest string has no en.json key: ${JSON.stringify(value)} (${where.join(', ')})`)
		}
		if (Object.hasOwn(nl, value) === false) {
			failures.push(`manifest string has no nl.json key: ${JSON.stringify(value)} (${where.join(', ')})`)
		}
	}

	// --- 6. every t('hrmq', …) key is a key --------------------------------
	const translateKeys = new Map()
	collectTranslateKeys(SRC_DIR, translateKeys)
	for (const [key, where] of translateKeys) {
		if (Object.hasOwn(en, key) === false) {
			failures.push(`t('${APP_ID}', …) key has no en.json entry: ${JSON.stringify(key)} (${where.join(', ')})`)
		}
		if (Object.hasOwn(nl, key) === false) {
			failures.push(`t('${APP_ID}', …) key has no nl.json entry: ${JSON.stringify(key)} (${where.join(', ')})`)
		}
	}

	// --- 7. no Dutch literals left in the manifest surface -----------------
	for (const [value, where] of manifestStrings) {
		if (DUTCH_RE.test(value)) {
			failures.push(`Dutch literal still used as a source key: ${JSON.stringify(value)} (${where.join(', ')})`
				+ ' — the manifest string IS the translation key; write it in English and put the Dutch in l10n/nl.json.')
		}
	}

	// --- 8. every schema-derived display string is a key -------------------
	const schemaStrings = new Map()
	for (const file of schemaFiles()) {
		collectSchemaStrings(loadJson(file), file, schemaStrings)
	}
	for (const [value, where] of schemaStrings) {
		if (Object.hasOwn(en, value) === false) {
			failures.push(`schema string has no en.json key: ${JSON.stringify(value)} (${where.join(', ')})`)
		}
		if (Object.hasOwn(nl, value) === false) {
			failures.push(`schema string has no nl.json key: ${JSON.stringify(value)} (${where.join(', ')})`)
		}
		if (DUTCH_RE.test(value)) {
			failures.push(`Dutch literal still used as a source key: ${JSON.stringify(value)} (${where.join(', ')})`
				+ ' — a schema title IS the translation key; write it in English and put the Dutch in l10n/nl.json.')
		}
	}

	report(failures, enKeys.length, manifestStrings.size + schemaStrings.size, translateKeys.size)
}

/**
 * Every catalogue must ALSO exist as `l10n/<locale>.js`, and carry the same
 * pairs. The JSON half is read server-side by Nextcloud for PHP `$l->t()`;
 * the browser never sees it, because Nextcloud does not serve raw JSON out of
 * an app directory — `/custom_apps/<app>/l10n/nl.json` is a 404 (measured).
 * The client half is the `OC.L10N.register()` JS file, which IS served and is
 * how `t('hrmq', …)` resolves at runtime. Shipping one without the other is
 * the failure this check exists to prevent: a complete catalogue that never
 * reaches the user.
 *
 * @param {string} locale - the locale to check
 * @param {object} translations - the pairs from the JSON catalogue
 * @param {string[]} failures - collected failure messages
 */
function checkJsCatalogue(locale, translations, failures) {
	const jsPath = path.join(L10N_DIR, `${locale}.js`)
	if (fs.existsSync(jsPath) === false) {
		failures.push(`l10n/${locale}.js is missing — the JSON catalogue is server-side only;`
			+ ' the browser loads the OC.L10N.register() JS file, so translations would never render.')
		return
	}

	let registered = null
	const sandbox = { OC: { L10N: { register: (app, pairs) => { registered = { app, pairs } } } } }
	try {
		vm.runInNewContext(fs.readFileSync(jsPath, 'utf8'), sandbox)
	} catch (error) {
		failures.push(`l10n/${locale}.js does not execute: ${error.message}`)
		return
	}

	if (registered === null) {
		failures.push(`l10n/${locale}.js did not call OC.L10N.register().`)
		return
	}

	if (registered.app !== APP_ID) {
		failures.push(`l10n/${locale}.js registers app "${registered.app}", expected "${APP_ID}".`)
	}

	const jsKeys = Object.keys(registered.pairs).sort()
	const jsonKeys = Object.keys(translations).sort()
	if (jsKeys.join('\u0000') !== jsonKeys.join('\u0000')) {
		const onlyJson = jsonKeys.filter((k) => jsKeys.includes(k) === false)
		const onlyJs = jsKeys.filter((k) => jsonKeys.includes(k) === false)
		failures.push(`l10n/${locale}.js and l10n/${locale}.json have drifted:`
			+ ` ${onlyJson.length} key(s) only in JSON, ${onlyJs.length} only in JS.`)
		return
	}

	for (const key of jsonKeys) {
		if (registered.pairs[key] !== translations[key]) {
			failures.push(`l10n/${locale}: "${key}" differs between the JS and JSON catalogues.`)
			return
		}
	}
}

/**
 * Print the run summary and exit.
 *
 * @param {string[]} failures - collected failure messages
 * @param {number} keyCount - keys per catalogue
 * @param {number} manifestCount - distinct manifest display strings
 * @param {number} translateCount - distinct t('hrmq', …) keys
 */
function report(failures, keyCount, manifestCount, translateCount) {
	console.log(`[validate-l10n-parity] ${keyCount} keys per catalogue (en/nl),`
		+ ` ${manifestCount} distinct manifest + schema strings, ${translateCount} t('${APP_ID}', …) keys.`)

	if (failures.length > 0) {
		console.error('')
		console.error(`[validate-l10n-parity] FAIL — ${failures.length} problem(s):`)
		for (const failure of failures) console.error(`  - ${failure}`)
		process.exit(1)
	}

	console.log('[validate-l10n-parity] PASS — en/nl key sets are identical, en is identity-mapped,'
		+ ' every manifest and t() string is covered, and no Dutch literal survives as a source key.')
	process.exit(0)
}

main()
