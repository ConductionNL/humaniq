#!/usr/bin/env node
// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2
//
// check-integration-parity.js — hydra gate-24 (`integration-parity`) checker.
//
// An OpenRegister leaf is registered TWICE (ADR-019 AD-11/AD-13, ADR-066
// decisions 4 and 7): a PHP half contributes a `LeafDescriptor` through
// `RegisterLeafProvidersEvent`, and a JS half registers the render surface on
// `window.OCA.OpenRegister.integrations`. The two are bound by nothing but a
// shared `id` string, and nothing at runtime compares them.
//
// This checker correlates them, in two passes:
//
//   1. ID SETS — every leaf declared on one side exists on the other. A half
//      without its partner is an orphan registration: the PHP-only leaf is
//      invisible in the UI, the JS-only leaf is invisible to every server-side
//      consumer that never loads an app bundle. Neither errors.
//
//   2. FIELDS — for each correlated leaf, the values both halves declare agree:
//      label, icon, group, referenceType, renderMode and the surfaces list.
//      Every way these drift is invisible. A changed `renderMode` makes the host
//      hand an SFC to a mount-mode leaf and the surface BLANKS with no console
//      message. A changed `label` renders one name in the app and another in the
//      admin leaf catalogue, so one leaf looks like two.
//
// hermiq's two halves drifted exactly this way and it went unnoticed. That is
// why both halves in this repo write their `surfaces` out explicitly instead of
// relying on a default: a set declared by OMISSION gives this checker nothing to
// compare, and it would report a pass having correlated nothing.
//
// NOTHING HERE SKIPS QUIETLY. Every way this script can fail to check something
// exits non-zero with a named reason — a gate whose absence looks exactly like
// its success is worse than no gate.

const fs = require('fs')
const path = require('path')

const ROOT = process.argv[2] ? path.resolve(process.argv[2]) : path.resolve(__dirname, '..')
const failures = []

/**
 * Every file under `dir` matching `re`, recursively.
 *
 * @param {string} dir Directory to walk.
 * @param {RegExp} re  Filename pattern.
 * @return {string[]} Absolute paths.
 */
function walk(dir, re) {
	if (!fs.existsSync(dir)) {
		return []
	}
	const out = []
	for (const entry of fs.readdirSync(dir, { withFileTypes: true })) {
		const p = path.join(dir, entry.name)
		if (entry.isDirectory()) {
			if (entry.name === 'node_modules' || entry.name === 'vendor') {
				continue
			}
			out.push(...walk(p, re))
		} else if (re.test(entry.name)) {
			out.push(p)
		}
	}
	return out
}

/** Leaves declared by the PHP half, keyed by id. */
const php = new Map()
for (const f of walk(path.join(ROOT, 'lib'), /\.php$/)) {
	const src = fs.readFileSync(f, 'utf8')
	if (!src.includes('new LeafDescriptor(')) {
		continue
	}
	const id = (src.match(/LEAF_ID\s*=\s*'([^']+)'/) || [])[1]
	if (!id) {
		failures.push(`${path.relative(ROOT, f)}: constructs a LeafDescriptor but declares no LEAF_ID `
			+ 'constant, so its id cannot be correlated with the JS half.')
		continue
	}
	php.set(id, {
		file: path.relative(ROOT, f),
		label: (src.match(/LABEL_SOURCE\s*=\s*'([^']+)'/) || [])[1] || '',
		icon: (src.match(/ICON\s*=\s*'([^']+)'/) || [])[1] || '',
		group: (src.match(/GROUP\s*=\s*'([^']+)'/) || [])[1] || '',
		referenceType: (src.match(/REFERENCE_TYPE\s*=\s*'([^']+)'/) || [])[1] || '',
		renderMode: /RENDER_MODE_MOUNT/.test(src) ? 'mount' : '',
		surfaces: ((src.match(/const SURFACES\s*=\s*\[([\s\S]*?)\];/) || [])[1] || '')
			.match(/'([^']+)'/g)?.map((s) => s.replace(/'/g, '')) || [],
	})
}

/** Leaves declared by the JS half, keyed by id. */
const js = new Map()
for (const f of walk(path.join(ROOT, 'src'), /\.(js|ts)$/)) {
	const src = fs.readFileSync(f, 'utf8')
	if (!/integrations\.register\(|registerIntegration\(/.test(src)) {
		continue
	}
	const id = (src.match(/_INTEGRATION_ID\s*=\s*'([^']+)'/) || src.match(/\bid:\s*'([^']+)'/) || [])[1]
	if (!id) {
		failures.push(`${path.relative(ROOT, f)}: registers an integration but no id could be read, `
			+ 'so it cannot be correlated with the PHP half.')
		continue
	}
	js.set(id, {
		file: path.relative(ROOT, f),
		label: (src.match(/label:\s*t\('[^']+',\s*'([^']+)'\)/) || [])[1] || '',
		icon: (src.match(/\n\ticon:\s*'([^']+)'/) || [])[1] || '',
		group: (src.match(/\n\tgroup:\s*'([^']+)'/) || [])[1] || '',
		referenceType: (src.match(/\n\treferenceType:\s*'([^']+)'/) || [])[1] || '',
		renderMode: (src.match(/renderMode:\s*'([^']+)'/) || [])[1] || '',
		surfaces: ((src.match(/const SURFACES\s*=\s*\[([^\]]*)\]/) || [])[1] || '')
			.match(/'([^']+)'/g)?.map((s) => s.replace(/'/g, '')) || [],
	})
}

// Pass 1 — id sets.
for (const [id, e] of php) {
	if (!js.has(id)) {
		failures.push(`'${id}': declared by ${e.file} but by no JS half. The descriptor reaches `
			+ 'server-side consumers and renders nothing — an orphan registration (ADR-066 decision 4).')
	}
}
for (const [id, e] of js) {
	if (!php.has(id)) {
		failures.push(`'${id}': registered by ${e.file} but by no PHP half. The surface renders but is `
			+ 'invisible to every consumer that does not load this app\'s bundle (gate-24 R2).')
	}
}

// Pass 2 — fields, for leaves both halves declare.
const FIELDS = ['label', 'icon', 'group', 'referenceType', 'renderMode']
for (const [id, p] of php) {
	const j = js.get(id)
	if (!j) {
		continue
	}
	for (const field of FIELDS) {
		if (p[field] === '' || j[field] === '') {
			failures.push(`'${id}'.${field}: unreadable on the ${p[field] === '' ? 'PHP' : 'JS'} half. `
				+ 'A value this checker cannot see is one it cannot compare, so this is a failure, not a skip.')
		} else if (p[field] !== j[field]) {
			failures.push(`'${id}'.${field}: JS says ${JSON.stringify(j[field])}, PHP says `
				+ `${JSON.stringify(p[field])} — the halves are bound by these values.`)
		}
	}
	if (p.surfaces.length === 0 || j.surfaces.length === 0) {
		failures.push(`'${id}'.surfaces: one half declares none. Both must write the list out — a set `
			+ 'declared by omission gives this checker nothing to compare.')
	} else if (JSON.stringify(p.surfaces) !== JSON.stringify(j.surfaces)) {
		failures.push(`'${id}'.surfaces: JS ${j.surfaces.join(',')} vs PHP ${p.surfaces.join(',')} — `
			+ 'order is part of what both halves promise.')
	}
}

if (failures.length > 0) {
	console.error(`✗ integration parity: ${failures.length} finding(s)`)
	for (const f of failures) {
		console.error(`  - ${f}`)
	}
	process.exit(1)
}

if (php.size === 0 && js.size === 0) {
	console.log('✓ integration parity: this repo registers no OpenRegister leaves — nothing to correlate.')
	process.exit(0)
}

console.log(`✓ integration parity: ${php.size} leaf/leaves correlated on both halves `
	+ `(${[...php.keys()].join(', ')}) — id, label, icon, group, referenceType, renderMode and surfaces all agree.`)
