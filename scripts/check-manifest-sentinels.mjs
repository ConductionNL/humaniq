#!/usr/bin/env node
/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * check-manifest-sentinels.js — assert every optional `@workspace.<key>?`
 * filter clause RESOLVES OR DROPS, and never reaches the API as a literal.
 *
 * WHY THIS EXISTS
 * ---------------
 * Measured on the deployed app, 2026-08-19:
 *
 *   GET /api/objects/humaniq/Employee?_limit=20&administrationId=%40workspace.activeAdministrationId%3F
 *   -> 200 OK, total: 0
 *
 * The sentinel reached OpenRegister as the literal string. No row carries that
 * value, so every administratie-scoped index page rendered "No items found"
 * while the data existed — 44 clauses across the manifest, and the app's
 * primary navigation surface.
 *
 * The defect's whole danger is that its symptom is indistinguishable from
 * "nothing seeded yet". An empty list is a legitimate state; an empty list
 * caused by an unsubstituted token is not, and over HTTP they are the same
 * 200 with the same body shape.
 *
 * WHAT THIS CHECKS
 * ----------------
 * It imports the resolver from the INSTALLED @conduction/nextcloud-vue — not a
 * local reimplementation — and asserts that against an EMPTY workspace context
 * every `@workspace.*?` clause drops out of the resulting filter map. A local
 * copy of the logic would agree with itself no matter what shipped, which is
 * the instrument-built-from-the-same-source trap.
 *
 * WHAT IT CANNOT CATCH, AND WHY THE BUNDLE CHECK EXISTS
 * -----------------------------------------------------
 * This check reads SOURCE. On 2026-08-19 it would have PASSED while the app
 * was broken, because the source was already correct and the fault lay in a
 * stale compiled bundle. `check-bundle-freshness.js` is the half that catches
 * that; neither is sufficient alone. Running only this one and reading it as
 * "the sentinels are fine" is exactly the mistake it is documenting.
 */

import fs from 'node:fs'
import path from 'node:path'
import process from 'node:process'
import { fileURLToPath } from 'node:url'
// CJS module; its default export carries buildEffectiveManifest (base +
// src/manifest.d/*.json fragments + page-template expansion). Since
// humaniq-manifest-fragment-pipeline the base manifest.json holds only 3 shell
// pages — scanning it alone would silently narrow this check from 40+
// @workspace.*? clauses to ~0 and report an empty PASS.
import parityHarness from '../tests/verify-manifest-parity.js'

const REPO_ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..')
const OPTIONAL_SENTINEL = /^@workspace\..+\?$/

/**
 * Load the installed library's own token resolver.
 *
 * The module is ESM (`export function …`), so `require()` cannot read it — an
 * earlier cut of this script fell back to a grammar-only check for exactly
 * that reason and said so rather than claiming a pass it had not earned.
 * Dynamic `import()` loads it properly.
 *
 * @return {Promise<{resolve: (filter: object, ctx: object) => object}|null>} The resolver, or null when unavailable.
 */
async function loadResolver() {
	const file = path.join(REPO_ROOT, 'node_modules', '@conduction', 'nextcloud-vue', 'src', 'utils', 'resolveFilterTokens.js')
	if (!fs.existsSync(file)) {
		return null
	}
	try {
		const mod = await import(`file://${file}`)
		const resolveTokens = mod.resolveFilterTokens
		const dropOptional = mod.dropOptionalUnresolved
		if (typeof resolveTokens !== 'function' || typeof dropOptional !== 'function') {
			return null
		}
		// The same composition CnIndexPage's useSelfFetchList applies:
		// resolve, then drop whatever stayed an optional sentinel.
		return { resolve: (filter, ctx) => dropOptional(resolveTokens(filter, ctx)) }
	} catch {
		return null
	}
}

const manifest = parityHarness.buildEffectiveManifest()
const clauses = []

for (const page of manifest.pages || []) {
	const filters = [['config.filter', page.config && page.config.filter]]
	for (const [idx, qf] of Object.entries((page.config && page.config.quickFilters) || {})) {
		filters.push([`config.quickFilters[${idx}].filter`, qf && qf.filter])
	}
	for (const [where, filter] of filters) {
		if (!filter || typeof filter !== 'object') {
			continue
		}
		for (const [key, value] of Object.entries(filter)) {
			if (typeof value === 'string' && OPTIONAL_SENTINEL.test(value)) {
				clauses.push({ page: page.id, where, key, value })
			}
		}
	}
}

console.log(`[check-manifest-sentinels] found ${clauses.length} optional @workspace.*? clause(s)`)

if (clauses.length === 0) {
	console.log('[check-manifest-sentinels] PASS — nothing to check.')
	process.exit(0)
}

const resolver = await loadResolver()
const failures = []

if (resolver === null) {
	// Structural fallback. It is strictly weaker than driving the real
	// resolver, and says so rather than reporting a pass it did not earn.
	console.warn('[check-manifest-sentinels] WARN — could not load the installed resolver;')
	console.warn('  falling back to a structural check (grammar only, not behaviour).')
	for (const c of clauses) {
		if (!c.value.startsWith('@workspace.') || !c.value.endsWith('?')) {
			failures.push(c)
		}
	}
} else {
	for (const c of clauses) {
		let out
		try {
			out = resolver.resolve({ [c.key]: c.value }, {}, {})
		} catch (e) {
			failures.push({ ...c, note: `resolver threw: ${e.message}` })
			continue
		}
		// With an EMPTY context the optional clause must be gone entirely.
		// Present-but-literal is the shipped defect; present-but-resolved is
		// impossible from an empty context and would mean the resolver
		// invented a value.
		if (out && Object.hasOwn(out, c.key)) {
			failures.push({ ...c, note: `key survived as ${JSON.stringify(out[c.key])}` })
		}
	}
}

if (failures.length > 0) {
	console.error(`[check-manifest-sentinels] FAIL — ${failures.length} of ${clauses.length} clause(s) do not drop:`)
	for (const f of failures) {
		console.error(`  ${f.page} ${f.where}.${f.key} = ${f.value}${f.note ? ` — ${f.note}` : ''}`)
	}
	console.error('  An unresolved sentinel reaches OpenRegister literally and matches nothing:')
	console.error('  HTTP 200, total 0 — indistinguishable from "no data yet".')
	process.exit(1)
}

console.log(`[check-manifest-sentinels] PASS — all ${clauses.length} clause(s) drop against an empty context.`)
console.log('  NOTE: this reads SOURCE. A stale compiled bundle can still ship the defect —')
console.log('  that is what check-bundle-freshness.js is for.')
