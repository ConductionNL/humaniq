#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// verify-manifest-parity.js — the effective manifest computed from the
// fragment pipeline (base + src/manifest.d/*.json + the REAL shared
// buildManifest() from @conduction/nextcloud-vue, including pageTemplates/
// pageInstances expansion) is observably identical to the snapshot in
// tests/fixtures/manifest-baseline/.
//
// The baseline STARTED as the pre-split monolith, to prove the
// humaniq-manifest-fragment-pipeline change lost no functionality. That is
// long since shipped, and the baseline drifted 19 diffs behind the manifest
// while nothing ran this check. It is now a DRIFT GUARD: the fixtures track
// the current manifest and this check runs in CI, so an unintended change to
// a page, a menu entry or a route is a failure rather than a discovery
// months later.
//
// A legitimate manifest change updates the fixtures: `node
// tests/verify-manifest-parity.js --update`, in the same commit.
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

"use strict";

const fs = require("fs");
const path = require("path");

const REPO_ROOT = path.resolve(__dirname, "..");
const FIXTURE_DIR = path.join(
	REPO_ROOT,
	"tests",
	"fixtures",
	"manifest-baseline",
);
const MANIFEST_D = path.join(REPO_ROOT, "src", "manifest.d");

// The effective manifest, SHIPPED. `GET /apps/humaniq/api/manifest` used to
// serve src/manifest.json, which is the base: 11 pages, before the 33
// manifest.d fragments and the menu-layout relocations are merged in. The SPA
// never noticed because it does that merge itself at boot, from the bundle. Any
// other reader got a manifest that did not describe this app.
//
// The merge is buildManifest()'s, and it stays buildManifest()'s: this file
// already computes the exact effective manifest webpack would, so it emits it
// rather than PHP re-implementing the merge and drifting from it.
//
// It is COMMITTED, not built, so a fresh checkout and a packaged release both
// have it, and `check:manifest-parity` fails in CI the moment it drifts.
const SHIPPED_EFFECTIVE = path.join(REPO_ROOT, "src", "manifest.effective.json");

/**
 * Deep-sort an arbitrary JSON value's object keys so two structurally equal
 * values serialise identically (array order is preserved — it is meaningful
 * for menu children, widgets and layout).
 *
 * @param {*} value Any JSON value.
 * @return {*} The same value with all object keys sorted.
 */
function canonical(value) {
	if (Array.isArray(value)) return value.map(canonical);
	if (value !== null && typeof value === "object") {
		const out = {};
		for (const key of Object.keys(value).sort())
			out[key] = canonical(value[key]);
		return out;
	}
	return value;
}

const stableStringify = (value) => JSON.stringify(canonical(value));

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
		"node_modules",
		"@conduction",
		"nextcloud-vue",
		"src",
		"utils",
		"buildManifest.js",
	);
	const { buildManifest } = require(buildManifestPath);
	const base = JSON.parse(
		fs.readFileSync(path.join(REPO_ROOT, "src", "manifest.json"), "utf8"),
	);
	const menuLayout = JSON.parse(
		fs.readFileSync(
			path.join(REPO_ROOT, "src", "menu-layout.json"),
			"utf8",
		),
	);
	const fragments = fs
		.readdirSync(MANIFEST_D)
		.filter((name) => name.endsWith(".json"))
		.sort()
		.map((name) =>
			JSON.parse(fs.readFileSync(path.join(MANIFEST_D, name), "utf8")),);
	// JSON round-trip: buildManifest()'s merge can leave `children: undefined`
	// on merged menu leaves — invisible to the runtime and to JSON.stringify,
	// but a present-with-undefined key to Ajv (menuItemLeaf forbids it). The
	// effective manifest IS a JSON value; validate/compare its JSON form.
	return JSON.parse(
		JSON.stringify(buildManifest(base, fragments, menuLayout)),
	);
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
	const { createRouter, createMemoryHistory } = require("vue-router");
	const routes = manifest.pages.map((page) => ({
		name: page.id,
		path: page.route,
		component: {},
		props: page.route.includes(":"),
	}));
	routes.push({ path: "/:pathMatch(.*)*", redirect: "/timesheets" });
	const router = createRouter({ history: createMemoryHistory(), routes });
	return router.resolve(routePath).name;
}

/**
 * Rewrite the baseline fixtures from the current effective manifest.
 *
 * The baseline is a SNAPSHOT, and a snapshot that can only be produced by
 * hand is a snapshot that stops being produced. Regenerating is therefore a
 * supported operation (`--update`), so the next legitimate manifest change
 * updates the fixtures the same way every time instead of by whatever the
 * author improvised.
 *
 * @param {object} effective The effective manifest.
 * @return {void}
 */
function writeBaseline(effective) {
	const pages = effective.pages.map((p) => ({ id: p.id, route: p.route }));
	fs.writeFileSync(
		path.join(FIXTURE_DIR, "pages.json"),
		JSON.stringify(pages, null, "\t") + "\n",
	);
	fs.writeFileSync(
		path.join(FIXTURE_DIR, "menu.json"),
		JSON.stringify(effective.menu, null, "\t") + "\n",
	);
	fs.writeFileSync(
		path.join(FIXTURE_DIR, "manifest-canonical.json"),
		JSON.stringify(canonical(effective), null, "\t") + "\n",
	);
	if (Array.isArray(effective.deepLinks)) {
		fs.writeFileSync(
			path.join(FIXTURE_DIR, "deeplinks.json"),
			JSON.stringify(effective.deepLinks, null, "\t") + "\n",
		);
	}
	fs.writeFileSync(
		SHIPPED_EFFECTIVE,
		JSON.stringify(canonical(effective), null, "\t") + "\n",
	);
	console.log(
		`[parity] baseline rewritten: ${pages.length} pages, ${effective.menu.length} menu roots`,
	);
	console.log(
		`[parity] shipped effective manifest rewritten: src/manifest.effective.json (${effective.pages.length} pages)`,
	);
}

/**
 * Print the first line where two canonical JSON renderings differ.
 *
 * @param {string} label What is compared, as it appears in the message.
 * @param {*} before The baseline value.
 * @param {*} after The effective value.
 * @return {void}
 */
function printFirstDiff(label, before, after) {
	const a = JSON.stringify(canonical(before), null, 1).split("\n");
	const b = JSON.stringify(canonical(after), null, 1).split("\n");
	for (let i = 0; i < Math.max(a.length, b.length); i++) {
		if (a[i] !== b[i]) {
			console.error(
				`[parity]   ${label} at canonical line ${i}:\n    baseline:  ${a[i]}\n    effective: ${b[i]}`,
			);
			break;
		}
	}
}

/**
 * Count the menu entries that navigate somewhere, children included.
 *
 * @param {object[]} nodes Menu nodes.
 * @return {number} The number of entries with a route, href or action.
 */
function countNavigable(nodes) {
	return nodes.reduce(
		(n, e) =>
			n +
			(e.route !== undefined ||
			e.href !== undefined ||
			e.action !== undefined
				? 1
				: 0) +
			(Array.isArray(e.children) ? countNavigable(e.children) : 0),
		0,
	);
}

/**
 * Check 1: the (id, route) pairs equal the baseline's.
 *
 * @param {object} effective The effective manifest.
 * @param {string[]} failures Failures so far; appended to.
 * @return {void}
 */
function checkPagePairs(effective, failures) {
	const baselinePairs = JSON.parse(
		fs.readFileSync(path.join(FIXTURE_DIR, "pages.json"), "utf8"),
	);
	const baselineSet = new Map(baselinePairs.map((p) => [p.id, p.route]));
	const effectiveSet = new Map(effective.pages.map((p) => [p.id, p.route]));
	if (effective.pages.length !== effectiveSet.size) {
		failures.push(
			`duplicate page ids in effective manifest (${effective.pages.length} pages, ${effectiveSet.size} distinct ids)`,
		);
	}
	for (const [id, route] of baselineSet) {
		if (!effectiveSet.has(id))
			failures.push(`page LOST by the split: ${id} (${route})`);
		else if (effectiveSet.get(id) !== route)
			failures.push(
				`route CHANGED for ${id}: ${route} -> ${effectiveSet.get(id)}`,
			);
	}
	for (const [id, route] of effectiveSet) {
		if (!baselineSet.has(id))
			failures.push(`page INVENTED by the split: ${id} (${route})`);
	}
	console.log(
		`[parity] check 1 — (id, route) pairs: baseline=${baselineSet.size} effective=${effectiveSet.size} ${failures.length === 0 ? "EQUAL" : "NOT EQUAL"}`,
	);
}

/**
 * Check 2: the menu tree is structurally equal to the baseline's.
 *
 * @param {object} effective The effective manifest.
 * @param {string[]} failures Failures so far; appended to.
 * @return {void}
 */
function checkMenuTree(effective, failures) {
	const baselineMenu = JSON.parse(
		fs.readFileSync(path.join(FIXTURE_DIR, "menu.json"), "utf8"),
	);
	const menuEqual =
		stableStringify(effective.menu) === stableStringify(baselineMenu);
	if (!menuEqual) {
		failures.push(
			"menu tree differs from baseline (structure, order, or entry content)",
		);
		printFirstDiff("first menu diff", baselineMenu, effective.menu);
	}
	console.log(
		`[parity] check 2 — menu tree: ${effective.menu.length} top-level nodes, ${countNavigable(effective.menu)} navigable entries — ${menuEqual ? "STRUCTURALLY EQUAL" : "DIFFERS"}`,
	);
}

/**
 * Check 3: every page, including every expanded pageInstance, deep-equals
 * its baseline page.
 *
 * @param {object} effective The effective manifest.
 * @param {string[]} failures Failures so far; appended to.
 * @return {void}
 */
function checkPageContent(effective, failures) {
	const baselineManifest = JSON.parse(
		fs.readFileSync(
			path.join(FIXTURE_DIR, "manifest-canonical.json"),
			"utf8",
		),
	);
	const baselineById = new Map(baselineManifest.pages.map((p) => [p.id, p]));
	let deepEqual = 0;
	for (const page of effective.pages) {
		const original = baselineById.get(page.id);
		if (!original) continue; // already reported by check 1
		if (stableStringify(page) === stableStringify(original)) {
			deepEqual++;
			continue;
		}
		failures.push(`page CONTENT differs for ${page.id}`);
		printFirstDiff(`${page.id} first diff`, original, page);
	}
	console.log(
		`[parity] check 3 — full-page deep equality: ${deepEqual}/${effective.pages.length} pages byte-identical (canonical form)`,
	);
}

/**
 * The static routes that sit under a parent which also has a dynamic `/:id`
 * sibling, as [route, page id] pairs.
 *
 * DERIVED from the effective manifest, not hardcoded. The list used to name
 * the six approval routes literally, and those pages are now `menu[].query`
 * presets over their canonical index (ADR-097 Decision 5). A hardcoded list
 * that outlives its routes does not fail — it silently checks nothing — so
 * the pairs are computed: every static route whose parent segment also has
 * a dynamic `/:id` sibling, which is exactly the shape that can be shadowed.
 *
 * @param {object} effective The effective manifest.
 * @return {Array<[string, string]>} The pairs.
 */
function collisionPairsOf(effective) {
	const dynamicParents = new Map();
	for (const pg of effective.pages) {
		if (!pg.route || !pg.route.includes(":")) continue;
		dynamicParents.set(pg.route.slice(0, pg.route.lastIndexOf("/")), pg.id);
	}
	return effective.pages
		.filter(
			(pg) =>
				pg.route
				&& !pg.route.includes(":")
				&& pg.route.split("/").length > 2
				&& dynamicParents.has(pg.route.slice(0, pg.route.lastIndexOf("/"))),
		)
		.map((pg) => [pg.route, pg.id]);
}

/**
 * Check 4: every static route that a dynamic sibling could shadow resolves
 * to its static page.
 *
 * @param {object} effective The effective manifest.
 * @param {string[]} failures Failures so far; appended to.
 * @return {void}
 */
function checkCollisionRoutes(effective, failures) {
	const collisionPairs = collisionPairsOf(effective);
	let resolved = 0;
	for (const [routePath, expectedName] of collisionPairs) {
		const name = resolveRouteName(effective, routePath);
		if (name === expectedName) resolved++;
		else
			failures.push(
				`route ${routePath} resolves to ${String(name)}, expected ${expectedName}`,
			);
	}
	if (collisionPairs.length === 0) {
		console.log(
			"[parity] check 4 — static/dynamic collision routes: NONE to check. No static route sits under a parent that also has a dynamic sibling, so nothing could be shadowed. This is not a pass, it is an empty set.",
		);
	} else {
		console.log(
			`[parity] check 4 — static/dynamic collision routes: ${resolved}/${collisionPairs.length} resolve to the static page`,
		);
	}
}

/**
 * Check 5: the SHIPPED effective manifest is the effective manifest.
 *
 * Without this the file is a snapshot nobody re-cuts, and the endpoint goes
 * back to serving something that is not the app. A missing file is a
 * failure, not a reason to fall back: a silent fallback to the base
 * manifest is the defect this check exists to prevent.
 *
 * @param {object} effective The effective manifest.
 * @param {string[]} failures Failures so far; appended to.
 * @return {void}
 */
function checkShippedManifest(effective, failures) {
	if (fs.existsSync(SHIPPED_EFFECTIVE) === false) {
		failures.push(
			"src/manifest.effective.json is missing — the manifest endpoint has nothing correct to serve. Run `node tests/verify-manifest-parity.js --update`.",
		);
		return;
	}
	const shipped = fs.readFileSync(SHIPPED_EFFECTIVE, "utf8");
	const expected =
		JSON.stringify(canonical(effective), null, "\t") + "\n";
	if (shipped !== expected) {
		failures.push(
			"src/manifest.effective.json is STALE — it no longer matches the manifest this build produces. Run `node tests/verify-manifest-parity.js --update`.",
		);
		return;
	}
	console.log(
		`[parity] check 5 — shipped effective manifest: current (${effective.pages.length} pages)`,
	);
}

function main() {
	const failures = [];
	const effective = buildEffectiveManifest();

	if (process.argv.includes("--update")) {
		writeBaseline(effective);
		process.exit(0);
	}

	checkPagePairs(effective, failures);
	checkMenuTree(effective, failures);
	checkPageContent(effective, failures);
	checkCollisionRoutes(effective, failures);
	checkShippedManifest(effective, failures);

	if (failures.length > 0) {
		console.error(`\n[parity] FAIL — ${failures.length} discrepancy(ies):`);
		for (const f of failures) console.error(`  - ${f}`);
		process.exit(1);
	}
	console.log(
		`\n[parity] PASS — effective manifest matches the baseline (${effective.pages.length} pages, ${countNavigable(effective.menu)} navigable menu entries).`,
	);
	process.exit(0);
}

module.exports = { buildEffectiveManifest, canonical };

if (require.main === module) main();
