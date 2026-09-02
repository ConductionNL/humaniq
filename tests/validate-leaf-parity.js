#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// validate-leaf-parity.js — asserts humaniq's `humaniq-hours` leaf declares the
// SAME thing on both of its halves.
//
// An OpenRegister leaf is registered twice (ADR-066): the JS half
// (`src/integrations/registerHoursLeaf.js`) mounts the render surface, and the
// PHP half (`lib/Listener/RegisterHoursLeafListener.php`) makes the descriptor
// visible to server-side consumers that never load an app bundle. The two are
// bound by nothing but a shared `id` string.
//
// WHY THIS SCRIPT EXISTS
//
// Nothing at runtime compares them. A drifted field does not throw — it renders
// a leaf whose server descriptor says something else, and the direction it
// fails in is invisible:
//
//   - a changed `id` splits one leaf into two orphan registrations, neither of
//     which errors;
//   - a changed `renderMode` makes the host hand an SFC to a mount-mode leaf
//     (or the reverse) and the surface BLANKS with no console message;
//   - a changed `label` renders one name in the app and another in the admin
//     leaf catalogue, so the same leaf appears to be two things;
//   - a `surfaces` list that differs decides the leaf renders somewhere one
//     half never agreed to.
//
// hermiq's two halves drifted exactly this way and it went unnoticed, which is
// why both halves here write their `surfaces` out explicitly instead of
// relying on a default — a set declared by OMISSION gives this check nothing to
// compare.
//
// Run: npm run check:leaf-parity

const fs = require('fs')
const path = require('path')

const ROOT = path.resolve(__dirname, '..')
const JS = path.join(ROOT, 'src/integrations/registerHoursLeaf.js')
const PHP = path.join(ROOT, 'lib/Listener/RegisterHoursLeafListener.php')

const failures = []

/**
 * Record a mismatch between the two halves.
 *
 * @param {string} field The descriptor field that disagrees.
 * @param {string} js    What the JS half declares.
 * @param {string} php   What the PHP half declares.
 *
 * @return {void}
 */
function mismatch(field, js, php) {
	failures.push(
		`${field}: JS says ${JSON.stringify(js)}, PHP says ${JSON.stringify(php)} — `
		+ 'the halves are bound by these values; a difference is an orphan '
		+ 'registration on both sides rather than an error on either.',
	)
}

for (const f of [JS, PHP]) {
	if (!fs.existsSync(f)) {
		console.error(`[validate-leaf-parity] FAIL — missing half: ${path.relative(ROOT, f)}`)
		process.exit(1)
	}
}

const js = fs.readFileSync(JS, 'utf8')
const php = fs.readFileSync(PHP, 'utf8')

/**
 * First capture group of `re` in `text`, or '' when it does not match.
 *
 * Returning '' rather than throwing is deliberate: an unreadable half must
 * FAIL the comparison loudly below, not crash this script into an exit code a
 * caller might read as "no findings".
 *
 * @param {string} text  The file contents.
 * @param {RegExp} re    The pattern.
 * @return {string} The captured value, or ''.
 */
function grab(text, re) {
	const m = text.match(re)
	return m ? m[1] : ''
}

const pairs = [
	['id', grab(js, /HOURS_INTEGRATION_ID\s*=\s*'([^']+)'/), grab(php, /LEAF_ID\s*=\s*'([^']+)'/)],
	['label', grab(js, /label:\s*t\('humaniq',\s*'([^']+)'\)/), grab(php, /LABEL_SOURCE\s*=\s*'([^']+)'/)],
	['icon', grab(js, /\n\ticon:\s*'([^']+)'/), grab(php, /ICON\s*=\s*'([^']+)'/)],
	['group', grab(js, /\n\tgroup:\s*'([^']+)'/), grab(php, /GROUP\s*=\s*'([^']+)'/)],
	['referenceType', grab(js, /\n\treferenceType:\s*'([^']+)'/), grab(php, /REFERENCE_TYPE\s*=\s*'([^']+)'/)],
]

for (const [field, a, b] of pairs) {
	if (a === '' || b === '') {
		failures.push(`${field}: could not be read from ${a === '' ? 'the JS' : 'the PHP'} half — `
			+ 'a value this check cannot see is a value it cannot compare, so this is a failure, not a skip.')
		continue
	}
	if (a !== b) {
		mismatch(field, a, b)
	}
}

// `surfaces` is a list, and ORDER is part of what both halves promise.
const jsSurfaces = (grab(js, /const SURFACES = \[([^\]]+)\]/).match(/'([^']+)'/g) || [])
	.map((s) => s.replace(/'/g, ''))
const phpSurfaces = (grab(php, /const SURFACES = \[([\s\S]*?)\];/).match(/'([^']+)'/g) || [])
	.map((s) => s.replace(/'/g, ''))

if (jsSurfaces.length === 0 || phpSurfaces.length === 0) {
	failures.push('surfaces: one half declares none. Both halves must write the list out — '
		+ 'a set declared by omission gives this check nothing to compare, which is how '
		+ "hermiq's halves drifted apart unnoticed.")
} else if (JSON.stringify(jsSurfaces) !== JSON.stringify(phpSurfaces)) {
	mismatch('surfaces', jsSurfaces.join(','), phpSurfaces.join(','))
}

// renderMode: the JS half writes the literal, the PHP half the constant name.
const jsMode = grab(js, /renderMode:\s*'([^']+)'/)
const phpMode = /RENDER_MODE_MOUNT/.test(php) ? 'mount' : grab(php, /renderMode:\s*[^,\n]*?'([^']+)'/)
if (jsMode !== phpMode) {
	mismatch('renderMode', jsMode, phpMode)
}

if (failures.length > 0) {
	console.error(`[validate-leaf-parity] FAIL — ${failures.length} disagreement(s) between the two halves:`)
	for (const f of failures) {
		console.error(`  - ${f}`)
	}
	process.exit(1)
}

console.log(`[validate-leaf-parity] PASS — both halves of '${pairs[0][1]}' agree on `
	+ `id, label, icon, group, referenceType, renderMode and ${jsSurfaces.length} surfaces.`)
