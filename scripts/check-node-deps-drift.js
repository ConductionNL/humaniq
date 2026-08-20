#!/usr/bin/env node
/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * check-node-deps-drift.js — assert node_modules matches package-lock.json.
 *
 * WHY THIS EXISTS
 * ---------------
 * On 2026-08-19 this checkout had `@conduction/nextcloud-vue 1.0.0-beta.215`
 * physically installed while `package-lock.json` pinned `2.2.0-vue3.2`. Nothing
 * complained. The app built, the unit suite passed, the manifest validated —
 * and the shipped bundle was compiled against a library two major lines behind
 * the one the repo declares.
 *
 * That drift is invisible by construction: npm only reconciles the tree when
 * you ask it to, and every downstream tool reads whatever happens to be on
 * disk. The failure mode is not a crash; it is a green build of the wrong code.
 *
 * WHAT THIS CHECKS, AND WHAT IT DELIBERATELY DOES NOT
 * ---------------------------------------------------
 * It compares the RESOLVED version in the lockfile against the `version` field
 * in the installed package's own `package.json` — the two things that must
 * agree for a build to mean anything. It does NOT run an install, and it does
 * NOT try to repair the tree: a check that silently fixes what it measures can
 * never report a problem, and a build step that mutates node_modules is how the
 * drift got normalised in the first place.
 *
 * Exit 0 when every watched package agrees, 1 otherwise, printing both values
 * so a reader can act without re-deriving them.
 */

const fs = require('fs')
const path = require('path')

const REPO_ROOT = path.resolve(__dirname, '..')

/**
 * Packages whose drift has actually bitten, or would bite silently.
 *
 * Deliberately a short, named list rather than "every dependency": a
 * whole-tree diff produces noise on every transitive bump, and noise is what
 * makes a check stop being read. Add a package here when its drift has cost
 * something, and say so in the comment.
 */
const WATCHED = [
	// Compiled into the shipped bundle. hrmq is manifest-driven, so nearly all
	// of its rendering behaviour comes from this package — a wrong version
	// here is a wrong application, not a wrong dependency.
	'@conduction/nextcloud-vue',
]

/**
 * Read the version a package resolves to in package-lock.json.
 *
 * @param {object} lock The parsed lockfile.
 * @param {string} name The package name.
 * @return {string|null} The locked version, or null when absent.
 */
function lockedVersion(lock, name) {
	const entry = (lock.packages || {})[`node_modules/${name}`]
	return (entry && entry.version) || null
}

/**
 * Read the version physically present under node_modules.
 *
 * @param {string} name The package name.
 * @return {string|null} The installed version, or null when not installed.
 */
function installedVersion(name) {
	const pkgPath = path.join(REPO_ROOT, 'node_modules', name, 'package.json')
	if (!fs.existsSync(pkgPath)) {
		return null
	}
	try {
		return JSON.parse(fs.readFileSync(pkgPath, 'utf8')).version || null
	} catch {
		return null
	}
}

const lockPath = path.join(REPO_ROOT, 'package-lock.json')
if (!fs.existsSync(lockPath)) {
	console.error('[check-node-deps-drift] FAIL — package-lock.json not found; nothing to check against.')
	process.exit(1)
}

const lock = JSON.parse(fs.readFileSync(lockPath, 'utf8'))
const findings = []

for (const name of WATCHED) {
	const locked = lockedVersion(lock, name)
	const installed = installedVersion(name)

	if (locked === null) {
		findings.push(`${name}: not present in package-lock.json — it is watched here but not a declared dependency.`)
		continue
	}
	if (installed === null) {
		findings.push(`${name}: locked ${locked}, NOT INSTALLED.`)
		continue
	}
	if (installed !== locked) {
		findings.push(`${name}: locked ${locked}, installed ${installed}.`)
		continue
	}

	console.log(`[check-node-deps-drift] ${name}: ${installed} (matches lockfile)`)
}

if (findings.length > 0) {
	console.error('[check-node-deps-drift] FAIL — node_modules does not match package-lock.json:')
	for (const f of findings) {
		console.error(`  ${f}`)
	}
	console.error('  Run `npm ci` (not `npm install` — only ci reconciles the tree to the lockfile).')
	console.error('  Anything built before you do is compiled against the version above, not the declared one.')
	process.exit(1)
}

console.log(`[check-node-deps-drift] PASS — ${WATCHED.length} watched package(s) match the lockfile.`)
