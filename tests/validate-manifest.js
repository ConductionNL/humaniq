#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// validate-manifest.js — validates src/manifest.json against the Conduction
// app-manifest contract. Prefers @conduction/nextcloud-vue's validateManifest
// (ADR-024/ADR-036 preferred path) when it is installed; otherwise falls back to
// a self-contained structural guard so `npm run check:manifest` never errors on a
// missing dependency.
//
// Usage:   node tests/validate-manifest.js
// Exit 0 — manifest passes; Exit 1 — manifest fails (or cannot be read).
'use strict'

const fs = require('fs')
const path = require('path')

const REPO_ROOT = path.resolve(__dirname, '..')
const MANIFEST_PATH = path.join(REPO_ROOT, 'src', 'manifest.json')

function fail(messages) {
	console.error('[validate-manifest] FAIL')
	for (const m of messages) console.error(`  - ${m}`)
	process.exit(1)
}

if (!fs.existsSync(MANIFEST_PATH)) {
	fail([`manifest not found: ${MANIFEST_PATH}`])
}

let manifest
try {
	manifest = JSON.parse(fs.readFileSync(MANIFEST_PATH, 'utf8'))
} catch (e) {
	fail([`manifest is not valid JSON: ${e.message}`])
}

// The base manifest is intentionally minimal (ADR-037): pages + menu live in
// src/manifest.d/*.json fragments and are merged at build time by
// buildManifest(). Merge them here so structural validation sees the effective
// manifest, not just the base shell.
const FRAGMENT_DIR = path.join(REPO_ROOT, 'src', 'manifest.d')
if (fs.existsSync(FRAGMENT_DIR)) {
	const pagesById = new Map((manifest.pages || []).map((p) => [p && p.id, p]))
	const menuById = new Map((manifest.menu || []).map((m) => [m && m.id, m]))
	for (const file of fs.readdirSync(FRAGMENT_DIR).filter((f) => f.endsWith('.json')).sort()) {
		let frag
		try {
			frag = JSON.parse(fs.readFileSync(path.join(FRAGMENT_DIR, file), 'utf8'))
		} catch (e) {
			fail([`fragment ${file} is not valid JSON: ${e.message}`])
		}
		for (const p of (frag.pages || [])) pagesById.set(p && p.id, p)
		for (const m of (frag.menu || [])) menuById.set(m && m.id, m)
	}
	manifest = { ...manifest, pages: [...pagesById.values()], menu: [...menuById.values()] }
}

// --- 1. Preferred path: library validateManifest (ADR-024/036) ----------
try {
	// eslint-disable-next-line node/no-missing-require
	const lib = require('@conduction/nextcloud-vue')
	if (typeof lib.validateManifest === 'function') {
		const result = lib.validateManifest(manifest)
		if (!result.valid) {
			fail(['(lib)', ...(result.errors || [])])
		}
		console.log(`[validate-manifest] PASS (lib): v${manifest.version} | ${(manifest.pages || []).length} pages`)
		process.exit(0)
	}
} catch (_) {
	// Library not installed (e.g. bare CI checkout) — use the structural guard.
}

// --- 2. Structural guard (self-contained fallback) ----------------------
const errors = []
if (!manifest.version || typeof manifest.version !== 'string') {
	errors.push('top-level: "version" (string) is required')
}
if (!Array.isArray(manifest.dependencies) || !manifest.dependencies.includes('openregister')) {
	errors.push('top-level: "dependencies" must include "openregister"')
}
if (!Array.isArray(manifest.menu)) errors.push('top-level: "menu" (array) is required')
if (!Array.isArray(manifest.pages) || manifest.pages.length === 0) {
	errors.push('top-level: "pages" (non-empty array) is required')
}

const allowedTypes = new Set(['index', 'detail', 'dashboard', 'logs', 'settings', 'chat', 'files', 'custom'])
const seenIds = new Set()
for (let i = 0; i < (manifest.pages || []).length; i++) {
	const page = manifest.pages[i]
	if (!page || typeof page !== 'object') {
		errors.push(`pages[${i}]: must be an object`)
		continue
	}
	for (const required of ['id', 'route', 'type', 'title']) {
		if (!page[required] || typeof page[required] !== 'string') {
			errors.push(`pages[${i}]: missing required string field "${required}"`)
		}
	}
	if (page.type && !allowedTypes.has(page.type)) {
		errors.push(`pages[${i}].type: "${page.type}" is not a known page type`)
	}
	if (page.id) {
		if (seenIds.has(page.id)) errors.push(`pages[${i}].id: duplicate "${page.id}"`)
		seenIds.add(page.id)
	}
	if (page.type === 'custom' && !page.component) {
		errors.push(`pages[${i}]: type=custom requires a "component" field`)
	}
}

if (errors.length > 0) {
	fail(errors)
}

console.log(
	`[validate-manifest] PASS (structural): v${manifest.version} | ${(manifest.pages || []).length} pages | ${(manifest.menu || []).length} menu items | deps: ${(manifest.dependencies || []).join(', ')}`
)
process.exit(0)
