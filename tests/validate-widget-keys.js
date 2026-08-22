#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// validate-widget-keys.js — asserts that every `widgetKey` used anywhere in
// src/manifest.json actually RESOLVES to a real component at runtime.
//
// Why this exists (the defect family it closes):
//
//   `npm run check:manifest` only checks that manifest.json is *schema-legal*
//   (Ajv against the v2 app-manifest schema). The schema has no idea whether
//   a `widgetKey` string names a component that actually exists — that is a
//   RENDERER-side fact, owned by @conduction/nextcloud-vue, not the schema.
//   Three real widgetKey defects shipped green through check:manifest + the
//   803-test unit suite because none of them touch schema legality:
//     - widgetKey:"audit"        x43  (real key is "audit-trail")            — PR #93
//     - widgetKey:"actions"      x14  (CnActionButtons wasn't a resolvable
//                                      built-in key until humaniq registered it) — PR #92
//     - widgetKey:"stat"         x31  (CnStatWidget self-registers via a
//                                      bare side-effect import that a
//                                      tree-shaking bundler is legally
//                                      allowed to drop from production)      — src/registry.js
//
// HOW WIDGETKEY RESOLUTION ACTUALLY WORKS (read from CnWidgetGrid.vue's own
// `resolvedWidgets` computed, @conduction/nextcloud-vue):
//
//   for each widget: effectiveRegistry[key] → BUILT_IN_WIDGETS[key] → getWidgetTypeEntry(key)
//
//   Three layers, in resolution order, each with a DIFFERENT reliability
//   profile:
//
//   (1) `effectiveRegistry` — the consumer's own `src/registry.js` (passed as
//       the `registry` prop to CnAppRoot). A plain, statically-built object
//       literal that humaniq owns outright. 100% reliable by construction: if a
//       key is a property of that object, it resolves, full stop — there is
//       no bundler decision involved. Checked here via static AST parse of
//       the file's default-exported object (no execution needed).
//
//   (2) `BUILT_IN_WIDGETS` (@conduction/nextcloud-vue's
//       components/CnWidgetGrid/builtInWidgets.js) — ALSO a plain object
//       literal, built from ordinary top-level `import Foo from
//       '../Foo/Foo.vue'` statements assigned directly as values (`data:
//       CnObjectDataWidget`, `'audit-trail': CnAuditTrailWidget`, …). There is
//       no bare side-effect import and no self-registration step involved —
//       the object itself IS the reachability path, so as long as the
//       *module* is reachable (guaranteed: CnWidgetGrid.vue imports it
//       directly, and CnWidgetGrid is reachable from every v2 page) every key
//       in it survives production bundling. Checked here via the same static
//       AST parse technique, against the file as it actually ships in
//       node_modules (dist/esm), not hand-knowledge of the library's API.
//
//   (3) `getWidgetTypeEntry(key)` → the shared `dashboardWidgetRegistry`
//       (@conduction/nextcloud-vue's cn-widget-library dashboard-widget
//       catalog) — THIS is the fragile layer, and the one that bit humaniq
//       ("stat"). Catalog widgets don't sit in a plain object literal; each
//       one self-registers via `registerDashboardWidget(key, {...})` as a
//       *side effect* of importing its own `index.js` (or
//       `dashboardRegistration.js`), and those imports are themselves bare
//       side-effect imports inside registerDashboardWidgets.js. Whether that
//       call survives into the production bundle depends on webpack's
//       tree-shaking decision (usedExports + the package.json `sideEffects`
//       declaration), which is a BUNDLER-TIME fact — you cannot determine it
//       by reading nc-vue's src/ (a gate that greps source would FALSE-PASS
//       "stat", which is exactly how this defect shipped). See
//       `layer3CheckDashboardCatalogAgainstRealBuild()` below for the method
//       this gate uses to establish that fact empirically, against the REAL
//       built output, every run.
//
// PROPS-CONTRACT CHECK (the adjacent trap — `integrationId` vs `only`):
//
//   Investigated and DELIBERATELY NOT IMPLEMENTED as an automated check.
//   `CnWidgetGrid` binds `v-bind="widget.props"` straight onto the resolved
//   component with no allowlist, so ANY key in a manifest widget's `props`
//   that isn't a component-declared prop lands as an inert Vue "extra
//   attribute" — sometimes that's a genuine silent bug (a required prop
//   dropped under the wrong name, e.g. `integrationId` instead of
//   `CnIntegrationWidget`'s real `only` prop), but sometimes it's
//   established, harmless convention: this manifest currently ships
//   `props.icon` / `props.content` on multiple BUILT_IN_WIDGETS placements
//   (`object-table`, `data`, `related`) whose components don't declare either
//   name. A blanket "flag every undeclared prop key" check would hard-fail
//   the CURRENT CLEAN manifest on that harmless usage — false positives on a
//   green tree are worse than not checking at all. Telling "silently wrong
//   config name" apart from "harmless decorative extra prop" requires
//   semantic knowledge of each widget's contract that isn't mechanically
//   derivable from the prop list alone. Documented here as a known
//   limitation rather than shipped as a check that would either cry wolf or
//   be quietly narrowed to a single hardcoded widgetKey.
//
// Usage:
//   node tests/validate-widget-keys.js
//
// Exit codes:
//   0 — every widgetKey used in src/manifest.json resolves.
//   1 — at least one widgetKey does not resolve anywhere, or the check
//       itself could not run to completion (fails CLOSED — an inconclusive
//       build/execution is NEVER reported as a pass).

'use strict'

const babelParser = require('@babel/parser')
const fs = require('fs')
const path = require('path')
const traverse = require('@babel/traverse').default

const REPO_ROOT = path.resolve(__dirname, '..')
const MANIFEST_PATH = path.join(REPO_ROOT, 'src', 'manifest.json')
const REGISTRY_PATH = path.join(REPO_ROOT, 'src', 'registry.js')
const WEBPACK_CONFIG_PATH = path.join(REPO_ROOT, 'webpack.config.js')

// ---------------------------------------------------------------------------
// Step 1 — collect every widgetKey used anywhere in the manifest, with enough
// location context to name names when one doesn't resolve.
// ---------------------------------------------------------------------------

/**
 * Recursively walk the manifest JSON tree collecting every `widgetKey`
 * occurrence, tagged with a human-readable location (page id + slot, where
 * available) so a failure can name exactly where the phantom key lives.
 *
 * @param {object} manifest the parsed manifest.json.
 * @return {Map<string, string[]>} widgetKey → list of location strings.
 */
function collectManifestWidgetKeys(manifest) {
	const usages = new Map()

	function record(key, location) {
		if (!usages.has(key)) usages.set(key, [])
		usages.get(key).push(location)
	}

	function walk(node, pageLabel) {
		if (Array.isArray(node)) {
			for (const item of node) walk(item, pageLabel)
			return
		}
		if (!node || typeof node !== 'object') return

		if (typeof node.widgetKey === 'string' && node.widgetKey.length > 0) {
			const slot = typeof node.slot === 'string' ? node.slot : '(no slot)'
			record(node.widgetKey, `${pageLabel} / slot:${slot}`)
		}

		// A widget def under `config.widgets[]` names its component with `type`,
		// not `widgetKey` — that is the shape CnDetailPage / CnDashboardPage
		// resolve (hrmq#112 moved all 154 detail-page body widgets onto it).
		// Without this the check silently narrowed from 236 widgets to the 3
		// still spelled `widgetKey`, and a phantom type would sail through
		// exactly like the three defects in the docblock above.
		//
		// Only entries of a `widgets` ARRAY count. `type` is an overloaded key
		// in this manifest — pages carry `type:"detail"`, and `headerActions[]`
		// entries carry `type:"api-call"` — and matching those turned every
		// action verb into a phantom widget.
		if (Array.isArray(node.widgets)) {
			for (const def of node.widgets) {
				if (def && typeof def === 'object'
					&& typeof def.type === 'string' && def.type.length > 0) {
					const label = typeof node.id === 'string' ? `page:${node.id}` : pageLabel
					record(def.type, `${label} / config.widgets:${def.id ?? '(no id)'}`)
				}
			}
		}

		const nextLabel = typeof node.id === 'string' && typeof node.route === 'string'
			? `page:${node.id}`
			: pageLabel

		for (const value of Object.values(node)) {
			walk(value, nextLabel)
		}
	}

	walk(manifest.pages, '(top-level)')
	return usages
}

// ---------------------------------------------------------------------------
// Layer 1 & 2 — static object-literal parsing (no bundler involved, no
// tree-shaking risk — see the module docblock for why these two are safe to
// check this way).
// ---------------------------------------------------------------------------

/**
 * Parse a JS source file and return the set of top-level string/identifier
 * keys of ONE particular plain object literal — either the file's
 * `export default { ... }` (pass no `varName`), or a top-level
 * `export const <varName> = { ... }` (pass `varName`).
 *
 * Used for both humaniq's own src/registry.js (default export) and nc-vue's
 * builtInWidgets.js (`export const BUILT_IN_WIDGETS = {...}`). Both are
 * ordinary object literals with no spreads/computed trickery in practice;
 * this is a real AST parse (via @babel/parser + @babel/traverse), not a
 * text/regex scrape, so it is robust to reformatting/comments.
 *
 * @param {string} filePath absolute path to the JS source file.
 * @param {{varName?: string}} [opts] optional named-export variable to target.
 * @return {Set<string>} the object literal's own top-level keys.
 */
function parseObjectLiteralKeys(filePath, opts = {}) {
	const source = fs.readFileSync(filePath, 'utf8')
	const ast = babelParser.parse(source, {
		sourceType: 'module',
		plugins: [],
	})

	let objectExpr = null

	traverse(ast, {
		ExportDefaultDeclaration(nodePath) {
			if (!opts.varName && nodePath.node.declaration.type === 'ObjectExpression') {
				objectExpr = nodePath.node.declaration
			}
		},
		VariableDeclarator(nodePath) {
			if (
				opts.varName
				&& nodePath.node.id.type === 'Identifier'
				&& nodePath.node.id.name === opts.varName
				&& nodePath.node.init
				&& nodePath.node.init.type === 'ObjectExpression'
			) {
				objectExpr = nodePath.node.init
			}
		},
	})

	if (!objectExpr) {
		const what = opts.varName ? `const ${opts.varName} = {...}` : 'a default-exported object literal'
		throw new Error(`[validate-widget-keys] could not find ${what} in ${filePath}`)
	}

	const keys = new Set()
	for (const prop of objectExpr.properties) {
		if (prop.type !== 'ObjectProperty') continue
		if (prop.key.type === 'StringLiteral') {
			keys.add(prop.key.value)
		} else if (prop.key.type === 'Identifier' && !prop.computed) {
			keys.add(prop.key.name)
		}
	}
	return keys
}

/**
 * Locate the installed @conduction/nextcloud-vue package directory. Resolved
 * via require.resolve (not a hardcoded node_modules path) so this works
 * whether the package is hoisted, nested, or symlinked.
 *
 * @return {string} absolute path to the package root.
 */
function resolveNcVuePackageDir() {
	const pkgJsonPath = require.resolve('@conduction/nextcloud-vue/package.json', { paths: [REPO_ROOT] })
	return path.dirname(pkgJsonPath)
}

// ---------------------------------------------------------------------------
// Layer 3 — the fragile one. Established EMPIRICALLY against the real
// production bundle, not by reading nc-vue's source.
// ---------------------------------------------------------------------------
//
// Method: build a tiny, throwaway webpack bundle that reproduces the EXACT
// reachability trigger the real app uses, run it through the app's REAL
// production webpack config (same resolve rules, same mode, same
// dependency versions — this repo's webpack.config.js, with USE_LOCAL_LIB
// forced off so it always tests the actual installed npm package, matching
// what a clean CI checkout / production deploy would use, never a
// developer's local sibling nc-vue checkout), then EXECUTE the resulting
// bundle under Node (with a minimal jsdom-backed DOM, since the widget
// catalog pulls in real browser-dependent libraries — leaflet, toast-ui —
// at module-evaluation time) and read back the LIVE, POST-TREE-SHAKING
// state of nc-vue's own `dashboardWidgetRegistry` via its exported
// `getWidgetTypeEntry()`.
//
// Why this is trustworthy and a source-grep is not:
//   - It does not care what registerDashboardWidgets.js's TEXT contains; it
//     cares whether the call it makes for a given key actually executed in
//     the artifact that would really ship. That is the only question that
//     matters, and no other technique answers it without either running a
//     bundler (this) or running the whole app in a browser (impractical for
//     a fast unit-level gate, and no more authoritative than this method).
//   - It was empirically validated during development of this gate: a probe
//     that imports ONLY `getWidgetTypeEntry` (no reachability trigger)
//     produces a 377-BYTE bundle with the dashboard catalog aggregator
//     entirely absent — proving webpack really does drop it when nothing
//     reaches it. Reproducing the real trigger
//     (`import '.../CnWidgetGrid/registerDashboardWidgets.js'`, the literal
//     statement CnDetailPage.vue carries) is what makes the probe faithful
//     to the real app, where every humaniq type:"detail" page renders through
//     CnDetailPage.
//   - The registration call itself survives minification under a
//     bundler-renamed local identifier, so grepping the built JS for the
//     literal text `registerDashboardWidget(` (as an earlier manual
//     investigation of this exact bug did) proves NOTHING either way — it
//     is absent for every catalog widget after minification, including ones
//     that plainly DO work. Worse: src/manifest.json itself is bundled
//     directly into humaniq-main.js (`import bundledManifest from
//     './manifest.json'` in src/main.js), so grepping the built JS for a
//     widgetKey STRING LITERAL (e.g. `"stat"`) is contaminated by the
//     manifest's own embedded copy of that string and would "confirm" a key
//     resolves merely because the manifest mentions it. Executing the
//     bundle and reading the actual registry object sidesteps both traps.
//
// What this CANNOT catch (documented honestly, per the task brief):
//   - It reproduces the reachability trigger CnDetailPage.vue carries today.
//     If a future refactor changed how/whether the catalog aggregator
//     becomes reachable in humaniq's real bundle (e.g. humaniq stopped using
//     type:"detail" pages entirely), this probe could diverge from the real
//     app's tree-shaking outcome. This is considered an acceptable,
//     documented approximation, not a silent one.
//   - It does not (and cannot, per @conduction/nextcloud-vue's own current
//     package.json `sideEffects` declaration) reproduce the HISTORICAL
//     "stat" tree-shaken-out defect on demand — see PROOF STEP 4 in the
//     accompanying report: as of the currently installed nc-vue version,
//     `**/Cn*Widget/index.js` is itself declared side-effect-full, so
//     CnStatWidget's self-registration now survives production bundling
//     independent of humaniq's src/registry.js override. Removing the
//     registry.js override no longer reproduces a dead "stat" widget. This
//     gate reports that finding truthfully (both layers agree the key
//     resolves) rather than fabricate a failure to match history.

const PROBE_KEYS_MAX_BUILD_MS = 180000

/**
 * Build a throwaway probe bundle and execute it, returning which of
 * `candidateKeys` resolve via nc-vue's dashboard-widget catalog in the real,
 * tree-shaken production output.
 *
 * @param {string[]} candidateKeys widgetKeys not already resolved by layer 1/2.
 * @param {string} ncVuePkgDir absolute path to the installed nc-vue package.
 * @return {Promise<Record<string, boolean>>} key → resolved.
 */
async function layer3CheckDashboardCatalogAgainstRealBuild(candidateKeys, ncVuePkgDir) {
	const registerDashboardWidgetsPath = path.join(
		ncVuePkgDir, 
'dist', 
'esm', 
'components', 
'CnWidgetGrid', 
'registerDashboardWidgets.js',
	)
	if (!fs.existsSync(registerDashboardWidgetsPath)) {
		throw new Error(`[validate-widget-keys] expected built nc-vue file missing: ${registerDashboardWidgetsPath}`)
	}

	// The probe entry MUST live inside the repo tree (not os.tmpdir()):
	// webpack's default module resolution walks UP the directory tree from the
	// requesting file looking for `node_modules`, mimicking Node's own
	// algorithm. A directory outside the repo would never reach this repo's
	// node_modules, and forcing it via an explicit `resolve.modules` override
	// was tried and rejected — an absolute-path entry in `resolve.modules`
	// disables the normal nested-package resolution semantics, which broke
	// @conduction/nextcloud-vue's own internal resolution of packages it
	// nests a pinned version of (surfaced as spurious "export not found"
	// errors for @nextcloud/l10n / @nextcloud/router during development of
	// this gate). Keeping the entry inside REPO_ROOT preserves the exact
	// resolution behaviour of a real build.
	const tmpDir = fs.mkdtempSync(path.join(REPO_ROOT, '.widget-probe-tmp-'))
	try {
		const entryPath = path.join(tmpDir, 'probe-entry.js')
		const entrySource = [
			'// Auto-generated by tests/validate-widget-keys.js — not part of the app.',
			`import ${JSON.stringify(registerDashboardWidgetsPath)}`,
			'import { getWidgetTypeEntry } from \'@conduction/nextcloud-vue\'',
			`const PROBE_KEYS = ${JSON.stringify(candidateKeys)}`,
			'const result = {}',
			'for (const k of PROBE_KEYS) {',
			'\tconst entry = getWidgetTypeEntry(k)',
			'\tresult[k] = !!(entry && entry.renderer)',
			'}',
			'globalThis.__WIDGET_PROBE_RESULT__ = result',
			'',
		].join('\n')
		fs.writeFileSync(entryPath, entrySource, 'utf8')

		const outFile = await buildProbeBundle(entryPath, tmpDir)
		return executeProbeBundle(outFile)
	} finally {
		fs.rmSync(tmpDir, { recursive: true, force: true })
	}
}

/**
 * Build the probe entry through the app's REAL webpack.config.js (same
 * resolve aliases, same loader stack, same mode) with two overrides: the
 * entry/output point at the throwaway probe, and USE_LOCAL_LIB is forced off
 * so this always exercises the installed npm package — the artifact that
 * actually ships — never a developer's local sibling nc-vue source checkout.
 * NODE_ENV=production is forced so real tree-shaking runs (this is the
 * entire point of the check).
 *
 * @param {string} entryPath absolute path to the generated probe entry.
 * @param {string} outDir absolute path to the throwaway output directory.
 * @return {Promise<string>} absolute path to the built, single-file bundle.
 */
function buildProbeBundle(entryPath, outDir) {
	return new Promise((resolve, reject) => {
		process.env.NODE_ENV = 'production'
		process.env.USE_LOCAL_LIB = 'false'

		// Fresh require every run — this script is always invoked as its own
		// `node tests/validate-widget-keys.js` process, so there is no stale
		// module cache to worry about, but delete it anyway for safety if this
		// function is ever called twice on the same process.
		delete require.cache[require.resolve(WEBPACK_CONFIG_PATH)]
		const webpackConfig = require(WEBPACK_CONFIG_PATH)
		const webpack = require('webpack')

		webpackConfig.entry = { probe: { import: entryPath, filename: 'probe.js' } }
		webpackConfig.output = {
			...webpackConfig.output,
			path: outDir,
			filename: 'probe.js',
			// A literal publicPath (rather than 'auto') avoids the runtime
			// needing `document.currentScript` at all when we execute the
			// bundle under Node — one less thing the jsdom shim has to fake.
			publicPath: '/',
		}
		webpackConfig.devtool = false
		webpackConfig.stats = 'errors-only'
		// Keep everything in ONE emitted file — this probe never actually
		// navigates to a lazy-loaded chunk, so vendor/runtime splitting only
		// adds files we'd have to stitch back together to execute.
		webpackConfig.optimization = {
			...webpackConfig.optimization,
			splitChunks: false,
			runtimeChunk: false,
		}

		const compiler = webpack(webpackConfig)
		const timer = setTimeout(() => {
			reject(new Error('[validate-widget-keys] probe build timed out'))
		}, PROBE_KEYS_MAX_BUILD_MS)

		compiler.run((err, stats) => {
			clearTimeout(timer)
			compiler.close(() => {})
			if (err) {
				reject(err)
				return
			}
			if (stats.hasErrors()) {
				reject(new Error(`[validate-widget-keys] probe build failed:\n${stats.toString('errors-only')}`))
				return
			}
			resolve(path.join(outDir, 'probe.js'))
		})
	})
}

/**
 * Execute the built probe bundle under Node and return the result object it
 * published to `globalThis.__WIDGET_PROBE_RESULT__`.
 *
 * The widget catalog pulls in real DOM-dependent libraries (leaflet,
 * toast-ui, icon pickers) that touch `document`/`window`/`HTMLElement` etc.
 * at module-evaluation time, so plain Node globals are not enough — this
 * spins up a minimal jsdom document and copies its window's own properties
 * onto Node's global (the standard "jsdom-global" shim pattern), which was
 * empirically the smallest change that got a real build of this exact
 * dependency graph to execute cleanly.
 *
 * @param {string} bundlePath absolute path to the built probe.js.
 * @return {Record<string, boolean>} the probe's reported key → resolved map.
 */
function executeProbeBundle(bundlePath) {
	 
	const { JSDOM } = require('jsdom')
	const dom = new JSDOM('<!doctype html><html><body><div id="content"></div></body></html>', {
		url: 'http://localhost/apps/humaniq/',
		runScripts: 'outside-only',
		pretendToBeVisual: true,
	})

	// Plain assignment first (matches Node's own global shape most closely);
	// only fall back to defineProperty for the handful of keys Node itself
	// ships as a read-only getter (e.g. `navigator`, on Node 21+), where a
	// plain assignment throws "Cannot set property ... which has only a
	// getter". Tried defineProperty-for-everything first — it works for the
	// build/probe/resolution mechanics identically, but jsdom's own
	// setTimeout/setInterval recurse into a "Maximum call stack size
	// exceeded" when copied that way; plain assignment (skipping on error)
	// does not have that problem and is what an actual empirical spike of
	// this technique validated end-to-end.
	const { window } = dom
	for (const key of Object.getOwnPropertyNames(window)) {
		if (key in global) continue
		try {
			global[key] = window[key]
		} catch (_) {
			try {
				Object.defineProperty(global, key, { value: window[key], configurable: true, writable: true, enumerable: true })
			} catch {
				// A handful of jsdom window getters throw when accessed detached
				// from a real browsing context (e.g. `frameElement`) — skip those.
			}
		}
	}
	global.window = window
	global.document = window.document
	global.self = window

	// require() (not a <script> tag) loads the bundle, so jsdom never sets
	// document.currentScript itself — only needed if some future change
	// reintroduces publicPath:'auto' for the probe; harmless to define anyway.
	Object.defineProperty(window.document, 'currentScript', {
		get() {
			return { src: 'http://localhost/apps/humaniq/js/probe.js', tagName: 'SCRIPT' }
		},
		configurable: true,
	})

	delete require.cache[bundlePath]
	require(bundlePath)

	const result = globalThis.__WIDGET_PROBE_RESULT__
	if (!result || typeof result !== 'object') {
		throw new Error('[validate-widget-keys] probe bundle executed but reported no result — treating as inconclusive (fail-closed).')
	}
	return result
}

// ---------------------------------------------------------------------------
// Main
// ---------------------------------------------------------------------------

async function main() {
	if (!fs.existsSync(MANIFEST_PATH)) {
		console.error(`[validate-widget-keys] manifest not found: ${MANIFEST_PATH}`)
		process.exit(1)
	}
	// Collect widgetKeys from the EFFECTIVE manifest (base + manifest.d
	// fragments + expanded page templates), not the base shell alone. Since
	// humaniq-manifest-fragment-pipeline the base carries only 3 pages; reading
	// it alone silently narrowed this gate from 9 distinct widget keys to 3
	// and turned a red baseline green — the exact silent-coverage-loss defect
	// tests/validate-manifest.js was fixed for. Same shared merge path.
	 
	const { buildEffectiveManifest } = require('./verify-manifest-parity.js')
	const manifest = buildEffectiveManifest()
	const usages = collectManifestWidgetKeys(manifest)
	const allKeys = [...usages.keys()].sort()
	console.log(`[validate-widget-keys] effective manifest (base + manifest.d + templates): ${(manifest.pages || []).length} pages`)
	console.log(`[validate-widget-keys] distinct widgetKeys used: ${allKeys.length} (${allKeys.join(', ')})`)

	// Layer 1
	const registryKeys = parseObjectLiteralKeys(REGISTRY_PATH)
	console.log(`[validate-widget-keys] layer 1 (src/registry.js consumer override): ${[...registryKeys].sort().join(', ') || '(none)'}`)

	// Layer 2
	const ncVuePkgDir = resolveNcVuePackageDir()
	const builtInWidgetsPath = path.join(ncVuePkgDir, 'dist', 'esm', 'components', 'CnWidgetGrid', 'builtInWidgets.js')
	const builtInWidgetsKeys = parseObjectLiteralKeys(builtInWidgetsPath, { varName: 'BUILT_IN_WIDGETS' })
	console.log(`[validate-widget-keys] layer 2 (nc-vue BUILT_IN_WIDGETS, static object — always safe): ${[...builtInWidgetsKeys].sort().join(', ')}`)

	const resolvedBy = new Map() // key -> layer name
	for (const key of allKeys) {
		if (registryKeys.has(key)) resolvedBy.set(key, 'layer1:registry.js')
		else if (builtInWidgetsKeys.has(key)) resolvedBy.set(key, 'layer2:BUILT_IN_WIDGETS')
	}

	const layer3Candidates = allKeys.filter((key) => !resolvedBy.has(key))

	let layer3Result
	if (layer3Candidates.length > 0) {
		console.log(`[validate-widget-keys] layer 3 candidates needing a real build check: ${layer3Candidates.join(', ')}`)
		console.log('[validate-widget-keys] building throwaway probe bundle against the REAL installed @conduction/nextcloud-vue (USE_LOCAL_LIB=false, NODE_ENV=production)…')
		try {
			layer3Result = await layer3CheckDashboardCatalogAgainstRealBuild(layer3Candidates, ncVuePkgDir)
		} catch (err) {
			// Fail CLOSED: an inconclusive build/execution is never a pass.
			console.error('[validate-widget-keys] layer 3 check could not complete — treating all pending keys as UNRESOLVED.')
			console.error(err.stack || err.message)
			layer3Result = Object.fromEntries(layer3Candidates.map((k) => [k, false]))
		}
		for (const key of layer3Candidates) {
			if (layer3Result[key]) resolvedBy.set(key, 'layer3:dashboardWidgetRegistry (built bundle, verified)')
		}
	}

	console.log('')
	console.log('[validate-widget-keys] resolution summary:')
	const unresolved = []
	for (const key of allKeys) {
		const layer = resolvedBy.get(key)
		if (layer) {
			console.log(`  ✓ "${key}" → ${layer}`)
		} else {
			console.log(`  ✗ "${key}" → UNRESOLVED`)
			unresolved.push(key)
		}
	}

	if (unresolved.length > 0) {
		console.error('')
		console.error('[validate-widget-keys] FAIL — the following widgetKey(s) do not resolve to any component:')
		for (const key of unresolved) {
			console.error(`  - "${key}" used at:`)
			for (const loc of usages.get(key)) {
				console.error(`      ${loc}`)
			}
		}
		process.exit(1)
	}

	console.log('')
	console.log(`[validate-widget-keys] PASS — all ${allKeys.length} widgetKey(s) resolve.`)
	process.exit(0)
}

main().catch((err) => {
	console.error('[validate-widget-keys] unexpected failure:')
	console.error(err.stack || err.message)
	process.exit(1)
})
