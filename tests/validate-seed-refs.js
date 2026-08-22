#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// validate-seed-refs.js — asserts that every object reference in humaniq's SEED
// data is written in a form OpenRegister can actually import.
//
// Why this exists (the defect it closes):
//
//   Seed objects live in lib/Settings/register.d/*.json under
//   `components.objects` and address each other by slug. The properties that
//   carry those references are declared `$ref: <Schema>` and are normally also
//   `format: uuid` (OpenRegister's own ValidateObject rewrites a `$ref`
//   relation property to a uuid-patterned string, so the relation IS a uuid by
//   OpenRegister's model). A literal slug therefore fails validation inside
//   saveObject() and the whole seed object is DROPPED — silently, as a log
//   line, while the import as a whole still reports success.
//
//   On 2026-08-21 that had eaten 103 of the 107 references in the seed set:
//   a fresh install (and every CI e2e run) came up with a largely empty
//   register. It surfaced only as an unrelated-looking e2e failure — the
//   seeded 2026-05 payslip never rendering on /mijn/loonstroken.
//
// THE SANCTIONED FORM (read from OpenRegister's
// lib/Service/Configuration/ImportHandler.php::resolveSeedReferenceTokens()):
//
//   "employeeId": "@ref:employee-jansen"                — by slug
//   "employeeId": "@ref:Employee:employee-jansen"       — by schema + slug
//
//   ImportHandler runs BEFORE the import loop. It mints/reuses a stable UUID
//   for every referenced target, writes it into that target's `@self.id`, then
//   replaces the tokens with that UUID — so validation never sees a slug. The
//   token must span the WHOLE string value (a token embedded in a longer
//   string is not recognised); it is matched recursively through nested
//   objects and arrays. A bare `@ref:<slug>` whose slug exists on more than
//   one object is AMBIGUOUS: ImportHandler logs a warning and leaves the token
//   unresolved, which then fails validation exactly like a bare slug did.
//
//   NOTE: ImportHandler only scans `components.objects`. Seed placed at the
//   top-level `objects` key is imported but its tokens are never resolved.
//
// WHAT THIS SCRIPT ENFORCES
//
//   For every seed object property whose schema declaration carries `$ref`:
//     1. a uuid value                      → OK (already resolved / real id)
//     2. `@ref:<slug>` / `@ref:<schema>:<slug>` that resolves to exactly one
//        seeded object                     → OK
//     3. `@ref:...` that resolves to nothing, or ambiguously → FAIL
//        (a dangling token is worse than a bare slug: it looks fixed)
//     4. a bare slug naming a seeded object → FAIL (must be promoted to
//        `@ref:`; this is the original defect)
//     5. a bare slug naming NOTHING        → FAIL, unless listed in
//        KNOWN_UNRESOLVED below. These are seed rows pointing at target
//        objects that were never authored; promoting them to `@ref:` would
//        only hide the missing target. The allowlist is exact and is itself
//        checked: a stale entry (the reference is gone, or its target now
//        exists) fails too, so the list cannot rot.
//
//   The merge is the same one SettingsService::loadConfiguration() performs —
//   base humaniq_register.json plus every register.d fragment in sorted order,
//   deep-merged, lists concatenated — because that is the document
//   ImportHandler actually receives. Checking a single fragment in isolation
//   would mis-resolve schemas that several fragments contribute to
//   (Employee, EmploymentContract and TimeEntry are each split across two).
//
// Usage:
//   node tests/validate-seed-refs.js
//
// Exit codes:
//   0 — every seed reference is importable
//   1 — at least one reference would be dropped or dangles

'use strict'

const fs = require('fs')
const path = require('path')

const REPO_ROOT = path.resolve(__dirname, '..')
const BASE_REGISTER = path.join(REPO_ROOT, 'lib', 'Settings', 'humaniq_register.json')
const FRAGMENT_DIR = path.join(REPO_ROOT, 'lib', 'Settings', 'register.d')

const UUID_RE = /^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$/
const REF_PREFIX = '@ref:'

// Seed references whose TARGET OBJECT DOES NOT EXIST anywhere in the seed set.
//
// DELIBERATELY EMPTY, and it should stay that way. This list briefly held 24
// entries: the employees De Vries, Bakker and De Groot were referenced by
// contracts, timesheets, bookings, expenses, leave balances, sick-leave cases,
// attendance and org assignments, and PayrollGLPost pointed at a payroll run —
// none of which had ever been authored (`git log -S` confirmed the references
// were born dangling). The four missing objects now exist in hr-seed.json and
// every reference resolves.
//
// Adding an entry here is an EXEMPTION, not a fix: the seed object will still
// be dropped on import. It exists only so a reference waiting on data that has
// to be authored elsewhere can be recorded honestly instead of being papered
// over with an `@ref:` token that resolves to nothing. Write the reason next
// to the entry, and delete it as soon as the target lands.
//
// Format: '<referring object slug>.<property> -> <missing target slug>'
const KNOWN_UNRESOLVED = []

function isPlainObject(value) {
	return value !== null && typeof value === 'object' && Array.isArray(value) === false
}

/**
 * Deep-merge an overlay onto a base, mirroring
 * SettingsService::deepMergeConfig() / mergeConfigValue() exactly: two arrays
 * concatenate, two objects merge key-wise, anything else overwrites.
 */
function deepMerge(base, overlay) {
	for (const [key, value] of Object.entries(overlay)) {
		const current = base[key]
		if (Array.isArray(value) && Array.isArray(current)) {
			base[key] = current.concat(value)
		} else if (isPlainObject(value) && isPlainObject(current)) {
			deepMerge(current, value)
		} else {
			base[key] = value
		}
	}
	return base
}

function loadJson(file) {
	return JSON.parse(fs.readFileSync(file, 'utf8'))
}

/** Build the effective register document the way SettingsService does. */
function buildEffectiveRegister() {
	const config = loadJson(BASE_REGISTER)
	const fragments = fs.readdirSync(FRAGMENT_DIR).filter((f) => f.endsWith('.json')).sort()
	// Remember which fragment each seed object came from, for readable output.
	const sourceBySlug = new Map()
	for (const fragment of fragments) {
		const data = loadJson(path.join(FRAGMENT_DIR, fragment))
		for (const object of (data.components && data.components.objects) || []) {
			const slug = object['@self'] && object['@self'].slug
			if (slug) sourceBySlug.set(slug, fragment)
		}
		deepMerge(config, data)
	}
	return { config, sourceBySlug, fragments }
}

/** The `$ref` target of a property declaration, or null when it is not a relation. */
function relationTarget(property) {
	if (isPlainObject(property) === false) return null
	if (typeof property.$ref === 'string') return property.$ref
	if (isPlainObject(property.items) && typeof property.items.$ref === 'string') return property.items.$ref
	return null
}

function main() {
	const { config, sourceBySlug, fragments } = buildEffectiveRegister()
	const schemas = (config.components && config.components.schemas) || {}
	const objects = (config.components && config.components.objects) || []

	// Top-level `objects` is imported but NEVER token-resolved by
	// ImportHandler, so seed placed there can only ever hold real uuids.
	if (Array.isArray(config.objects) && config.objects.length > 0) {
		console.error('[validate-seed-refs] FAIL — seed objects found at the top-level `objects` key.')
		console.error('  ImportHandler::resolveSeedReferenceTokens() only scans components.objects;')
		console.error('  @ref: tokens placed here are never resolved. Move them under components.objects.')
		process.exit(1)
	}

	// Slug -> the objects carrying it. More than one makes a bare @ref ambiguous.
	const bySlug = new Map()
	for (const object of objects) {
		const self = object['@self'] || {}
		if (!self.slug) continue
		if (bySlug.has(self.slug) === false) bySlug.set(self.slug, [])
		bySlug.get(self.slug).push(self.schema)
	}

	const failures = []
	const unresolvedSeen = new Set()
	let ok = 0
	let checked = 0

	for (const object of objects) {
		const self = object['@self'] || {}
		const schema = schemas[self.schema]
		if (isPlainObject(schema) === false) continue
		const properties = schema.properties || {}
		const where = `${sourceBySlug.get(self.slug) || '?'}  ${self.schema}/${self.slug}`

		for (const [name, raw] of Object.entries(object)) {
			if (name.startsWith('@')) continue
			if (relationTarget(properties[name]) === null) continue

			for (const value of Array.isArray(raw) ? raw : [raw]) {
				if (typeof value !== 'string' || value === '') continue
				checked++

				if (UUID_RE.test(value)) { ok++; continue }

				if (value.startsWith(REF_PREFIX)) {
					const reference = value.slice(REF_PREFIX.length)
					const colon = reference.indexOf(':')
					const slug = colon === -1 ? reference : reference.slice(colon + 1)
					const qualifier = colon === -1 ? null : reference.slice(0, colon)
					const targets = bySlug.get(slug) || []
					if (targets.length === 0) {
						failures.push(`${where}\n      ${name}: "${value}" — DANGLING: no seed object has slug "${slug}".`)
					} else if (qualifier === null && targets.length > 1) {
						failures.push(`${where}\n      ${name}: "${value}" — AMBIGUOUS: slug "${slug}" exists on ${targets.join(', ')}.`
							+ ` ImportHandler leaves this unresolved; use "@ref:<Schema>:${slug}".`)
					} else if (qualifier !== null && targets.includes(qualifier) === false) {
						failures.push(`${where}\n      ${name}: "${value}" — no "${qualifier}" object has slug "${slug}" (found on ${targets.join(', ')}).`)
					} else {
						ok++
					}
					continue
				}

				// A bare, non-uuid value on a relation property.
				const key = `${self.slug}.${name} -> ${value}`
				if (bySlug.has(value)) {
					failures.push(`${where}\n      ${name}: "${value}" — BARE SLUG on a $ref property.`
						+ ` "${value}" is a seeded object; write it as "@ref:${value}" or it fails validation and the object is dropped.`)
				} else if (KNOWN_UNRESOLVED.includes(key)) {
					unresolvedSeen.add(key)
					ok++
				} else {
					failures.push(`${where}\n      ${name}: "${value}" — bare value on a $ref property and no seed object has that slug.`
						+ ' Author the target and use "@ref:", or add it to KNOWN_UNRESOLVED with a reason.')
				}
			}
		}
	}

	// A stale allowlist entry is a failure of its own: it means the reference
	// was fixed or removed and the exemption was left behind.
	const stale = KNOWN_UNRESOLVED.filter((entry) => unresolvedSeen.has(entry) === false)

	console.log(`[validate-seed-refs] ${fragments.length} fragments, ${objects.length} seed objects,`
		+ ` ${checked} relation values checked.`)
	console.log(`[validate-seed-refs] ${KNOWN_UNRESOLVED.length} known-unresolved references allowlisted`
		+ ' (missing target objects — see KNOWN_UNRESOLVED).')

	if (failures.length > 0 || stale.length > 0) {
		console.error('')
		if (failures.length > 0) {
			console.error(`[validate-seed-refs] FAIL — ${failures.length} seed reference(s) will not import:`)
			for (const failure of failures) console.error(`  - ${failure}`)
		}
		if (stale.length > 0) {
			console.error('')
			console.error(`[validate-seed-refs] FAIL — ${stale.length} stale KNOWN_UNRESOLVED entr(ies); delete them:`)
			for (const entry of stale) console.error(`  - ${entry}`)
		}
		process.exit(1)
	}

	console.log(`[validate-seed-refs] PASS — all ${ok} seed relation values are importable.`)
	process.exit(0)
}

main()
