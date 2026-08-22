#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// verify-manifest-parity.js — proves the humaniq-manifest-fragment-pipeline
// no-functionality-loss invariant: the effective manifest computed from the
// fragment pipeline (base + src/manifest.d/*.json + the REAL shared
// buildManifest() from @conduction/nextcloud-vue, including pageTemplates/
// pageInstances expansion) is observably identical to the pre-split monolith
// captured in tests/fixtures/manifest-baseline/.
//
// Checks (spec: openspec/changes/humaniq-manifest-fragment-pipeline):
//   1. (id, route) pair SET equality against the baseline pages dump.
//   2. Menu tree STRUCTURAL equality (same groups, same children, same order)
//      against the baseline menu dump.
//   3. FULL-PAGE deep equality: every effective page (concrete AND expanded
//      pageInstance) is deep-equal to the baseline page with the same id —
//      widgets, layout, _note prose, everything. This subsumes the
//      "every _note relocated, none dropped" requirement.
//   4. Route resolution for the static/dynamic collision pairs
//      (/timesheets/approval vs /timesheets/:id and siblings), through the
//      actual vue-router 4 matcher, built the way src/main.js builds it.
//
// The effective manifest is computed by requiring the library's OWN
// buildManifest (the exact module webpack bundles for the shipping app —
// Node >= 22.12 requires the ESM source directly), with fragments collected
// in the same sorted order src/main.js's require.context().keys().sort()
// produces. A probe must reach its subject the way shipping code does.
//
// Usage:  node tests/verify-manifest-parity.js
// Exit:   0 all checks pass; 1 any check fails.

'use strict'

const fs = require('fs')
const path = require('path')

const REPO_ROOT = path.resolve(__dirname, '..')
const FIXTURE_DIR = path.join(REPO_ROOT, 'tests', 'fixtures', 'manifest-baseline')
const MANIFEST_D = path.join(REPO_ROOT, 'src', 'manifest.d')

/**
 * Deep-sort an arbitrary JSON value's object keys so two structurally equal
 * values serialise identically (array order is preserved — it is meaningful
 * for menu children, widgets and layout).
 *
 * @param {*} value Any JSON value.
 * @return {*} The same value with all object keys sorted.
 */
function canonical(value) {
	if (Array.isArray(value)) return value.map(canonical)
	if (value !== null && typeof value === 'object') {
		const out = {}
		for (const key of Object.keys(value).sort()) out[key] = canonical(value[key])
		return out
	}
	return value
}

const stableStringify = (value) => JSON.stringify(canonical(value))

/**
 * Compute the post-change effective manifest exactly the way src/main.js
 * does at build time: bundled base + every src/manifest.d/*.json fragment in
 * sorted filename order + src/menu-layout.json, through the library's real
 * buildManifest() (which runs expandPageTemplates() as its final step).
 *
 * Exported for reuse by tests/validate-manifest.js (single implementation,
 * per the change's task 8.1).
 *
 * @return {object} The effective manifest (concrete pages only).
 */
function buildEffectiveManifest() {
	// The real shared implementation — the same source file webpack bundles
	// into the shipping app. Node >= 22.12 loads the ESM source via require()
	// (module-syntax detection); the util chain is Vue-free and dependency-free.
	const buildManifestPath = path.join(
		REPO_ROOT,
		'node_modules',
		'@conduction',
		'nextcloud-vue',
		'src',
		'utils',
		'buildManifest.js',
	)
	const { buildManifest } = require(buildManifestPath)
	const base = JSON.parse(fs.readFileSync(path.join(REPO_ROOT, 'src', 'manifest.json'), 'utf8'))
	const menuLayout = JSON.parse(fs.readFileSync(path.join(REPO_ROOT, 'src', 'menu-layout.json'), 'utf8'))
	const fragments = fs.readdirSync(MANIFEST_D)
		.filter((name) => name.endsWith('.json'))
		.sort()
		.map((name) => JSON.parse(fs.readFileSync(path.join(MANIFEST_D, name), 'utf8')))
	// JSON round-trip: buildManifest()'s merge can leave `children: undefined`
	// on merged menu leaves — invisible to the runtime and to JSON.stringify,
	// but a present-with-undefined key to Ajv (menuItemLeaf forbids it). The
	// effective manifest IS a JSON value; validate/compare its JSON form.
	return JSON.parse(JSON.stringify(buildManifest(base, fragments, menuLayout)))
}

/**
 * Replicate src/main.js's routesFromManifest() and resolve one path through
 * the real vue-router 4 matcher.
 *
 * @param {object} manifest The effective manifest.
 * @param {string} routePath The path to resolve.
 * @return {string|undefined} The matched route name.
 */
function resolveRouteName(manifest, routePath) {
	const { createRouter, createMemoryHistory } = require('vue-router')
	const routes = manifest.pages.map((page) => ({
		name: page.id,
		path: page.route,
		component: {},
		props: page.route.includes(':'),
	}))
	routes.push({ path: '/:pathMatch(.*)*', redirect: '/timesheets' })
	const router = createRouter({ history: createMemoryHistory(), routes })
	return router.resolve(routePath).name
}

function main() {
	const failures = []
	const effective = buildEffectiveManifest()

	// --- Check 1: (id, route) pair set equality -----------------------------
	const baselinePairs = JSON.parse(fs.readFileSync(path.join(FIXTURE_DIR, 'pages.json'), 'utf8'))
	const baselineSet = new Map(baselinePairs.map((p) => [p.id, p.route]))
	const effectiveSet = new Map(effective.pages.map((p) => [p.id, p.route]))
	if (effective.pages.length !== effectiveSet.size) {
		failures.push(`duplicate page ids in effective manifest (${effective.pages.length} pages, ${effectiveSet.size} distinct ids)`)
	}
	for (const [id, route] of baselineSet) {
		if (!effectiveSet.has(id)) failures.push(`page LOST by the split: ${id} (${route})`)
		else if (effectiveSet.get(id) !== route) failures.push(`route CHANGED for ${id}: ${route} -> ${effectiveSet.get(id)}`)
	}
	for (const [id, route] of effectiveSet) {
		if (!baselineSet.has(id)) failures.push(`page INVENTED by the split: ${id} (${route})`)
	}
	console.log(`[parity] check 1 — (id, route) pairs: baseline=${baselineSet.size} effective=${effectiveSet.size} ${failures.length === 0 ? 'EQUAL' : 'NOT EQUAL'}`)

	// --- Check 2: menu tree structural equality -----------------------------
	const baselineMenu = JSON.parse(fs.readFileSync(path.join(FIXTURE_DIR, 'menu.json'), 'utf8'))
	const menuEqual = stableStringify(effective.menu) === stableStringify(baselineMenu)
	if (!menuEqual) {
		failures.push('menu tree differs from baseline (structure, order, or entry content)')
		const a = JSON.stringify(canonical(baselineMenu), null, 1).split('\n')
		const b = JSON.stringify(canonical(effective.menu), null, 1).split('\n')
		for (let i = 0; i < Math.max(a.length, b.length); i++) {
			if (a[i] !== b[i]) {
				console.error(`[parity]   first menu diff at canonical line ${i}:\n    baseline:  ${a[i]}\n    effective: ${b[i]}`)
				break
			}
		}
	}
	const countNavigable = (nodes) => nodes.reduce((n, e) => n
		+ ((e.route !== undefined || e.href !== undefined || e.action !== undefined) ? 1 : 0)
		+ (Array.isArray(e.children) ? countNavigable(e.children) : 0), 0)
	console.log(`[parity] check 2 — menu tree: ${effective.menu.length} top-level nodes, ${countNavigable(effective.menu)} navigable entries — ${menuEqual ? 'STRUCTURALLY EQUAL' : 'DIFFERS'}`)

	// --- Check 3: full-page deep equality (all pages, incl. every expanded
	//     pageInstance vs its original page object) --------------------------
	const baselineManifest = JSON.parse(fs.readFileSync(path.join(FIXTURE_DIR, 'manifest-canonical.json'), 'utf8'))
	const baselineById = new Map(baselineManifest.pages.map((p) => [p.id, p]))
	let deepEqual = 0
	for (const page of effective.pages) {
		const original = baselineById.get(page.id)
		if (!original) continue // already reported by check 1
		if (stableStringify(page) === stableStringify(original)) {
			deepEqual++
			continue
		}
		failures.push(`page CONTENT differs for ${page.id}`)
		const a = JSON.stringify(canonical(original), null, 1).split('\n')
		const b = JSON.stringify(canonical(page), null, 1).split('\n')
		for (let i = 0; i < Math.max(a.length, b.length); i++) {
			if (a[i] !== b[i]) {
				console.error(`[parity]   ${page.id} first diff at canonical line ${i}:\n    baseline:  ${a[i]}\n    effective: ${b[i]}`)
				break
			}
		}
	}
	console.log(`[parity] check 3 — full-page deep equality: ${deepEqual}/${effective.pages.length} pages byte-identical (canonical form)`)

	// --- Check 4: static vs dynamic route collision pairs -------------------
	const collisionPairs = [
		['/timesheets/approval', 'TimesheetApproval'],
		['/timesheets/team-approval', 'TeamUrengoedkeuring'],
		['/expenses/approval', 'ExpenseApproval'],
		['/expenses/team-approval', 'TeamDeclaratiegoedkeuring'],
		['/leave-requests/approval', 'LeaveApproval'],
		['/leave-requests/team-approval', 'TeamVerlofgoedkeuring'],
	]
	let resolved = 0
	for (const [routePath, expectedName] of collisionPairs) {
		const name = resolveRouteName(effective, routePath)
		if (name === expectedName) resolved++
		else failures.push(`route ${routePath} resolves to ${String(name)}, expected ${expectedName}`)
	}
	console.log(`[parity] check 4 — static/dynamic collision routes: ${resolved}/${collisionPairs.length} resolve to the static page`)

	// --- Verdict ------------------------------------------------------------
	if (failures.length > 0) {
		console.error(`\n[parity] FAIL — ${failures.length} discrepancy(ies):`)
		for (const f of failures) console.error(`  - ${f}`)
		process.exit(1)
	}
	console.log(`\n[parity] PASS — effective manifest is observably identical to the pre-split baseline (${effective.pages.length} pages, ${countNavigable(effective.menu)} navigable menu entries).`)
	process.exit(0)
}

module.exports = { buildEffectiveManifest, canonical }

if (require.main === module) main()
