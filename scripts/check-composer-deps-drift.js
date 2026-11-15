#!/usr/bin/env node
/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * check-composer-deps-drift.js — assert vendor/ matches composer.lock.
 *
 * WHY THIS EXISTS
 * ---------------
 * The Composer half of check-node-deps-drift.js (humaniq-boot-integrity task
 * 1.4). On 2026-08-19 `vendor/conduction/hydra-gates` was missing and
 * `vendor/` was root-owned, so `composer phpmd` and the hydra gates errored
 * out before checking anything, and `composer install` could not repair it.
 * Nothing said so until a developer was three gates deep.
 *
 * WHAT THIS CHECKS, AND WHAT IT DELIBERATELY DOES NOT
 * ---------------------------------------------------
 * For each watched package: the version composer.lock pins against the
 * version vendor/composer/installed.json records as installed, and that the
 * package directory is on disk. Separately, whether vendor/ is writable by
 * the account running this, because an unwritable vendor/ is what kept the
 * drift from being repaired. It reads only: no `composer install`, no repair.
 *
 * Exit 0 when every watched package agrees and vendor/ is writable, 1
 * otherwise, printing both values so a reader can act without re-deriving
 * them.
 */

const fs = require("fs");
const path = require("path");

const REPO_ROOT = path.resolve(__dirname, "..");
const VENDOR = path.join(REPO_ROOT, "vendor");

/**
 * Packages whose drift has actually bitten.
 *
 * A short, named list rather than the whole lockfile, for the same reason as
 * check-node-deps-drift.js: a whole-tree diff is noise, and noise stops being
 * read.
 */
const WATCHED = [
	// The phpmd unused-parameter ruleset and the hydra quality gates come from
	// here (composer.json `phpmd`); absent, those gates cannot run at all.
	"conduction/hydra-gates",
];

/**
 * The packages of a Composer file: composer.lock, or installed.json in its
 * Composer 2 (`{packages: [...]}`) or Composer 1 (`[...]`) shape.
 *
 * @param {object|Array} data The parsed file.
 * @param {boolean} withDev Whether to include packages-dev (composer.lock only).
 * @return {Array<object>} The package entries.
 */
function packagesOf(data, withDev) {
	if (Array.isArray(data)) {
		return data;
	}
	const packages = Array.isArray(data.packages) ? data.packages : [];
	const dev = withDev && Array.isArray(data["packages-dev"]) ? data["packages-dev"] : [];
	return packages.concat(dev);
}

/**
 * The version a package has in a list of entries.
 *
 * @param {Array<object>} entries The package entries.
 * @param {string} name The package name.
 * @return {string|null} The version, or null when absent.
 */
function versionIn(entries, name) {
	const entry = entries.find((candidate) => candidate && candidate.name === name);
	return (entry && entry.version) || null;
}

/**
 * Parse a JSON file, or null when it is absent or unreadable.
 *
 * @param {string} file The file.
 * @return {object|Array|null} The parsed content.
 */
function readJson(file) {
	if (!fs.existsSync(file)) {
		return null;
	}
	try {
		return JSON.parse(fs.readFileSync(file, "utf8"));
	} catch {
		return null;
	}
}

const lock = readJson(path.join(REPO_ROOT, "composer.lock"));
if (lock === null) {
	console.error(
		"[check-composer-deps-drift] FAIL — composer.lock not found or unreadable; nothing to check against.",
	);
	process.exit(1);
}

const installedFile = readJson(path.join(VENDOR, "composer", "installed.json"));
const installed = installedFile === null ? [] : packagesOf(installedFile, false);
const findings = [];

for (const name of WATCHED) {
	const locked = versionIn(packagesOf(lock, true), name);
	const present = fs.existsSync(path.join(VENDOR, ...name.split("/")));
	const version = versionIn(installed, name);

	if (locked === null) {
		findings.push(
			`${name}: not present in composer.lock — it is watched here but not a declared dependency.`,
		);
		continue;
	}
	if (!present || version === null) {
		findings.push(`${name}: locked ${locked}, NOT INSTALLED.`);
		continue;
	}
	if (version !== locked) {
		findings.push(`${name}: locked ${locked}, installed ${version}.`);
		continue;
	}

	console.log(
		`[check-composer-deps-drift] ${name}: ${version} (matches composer.lock)`,
	);
}

/**
 * Whether this account may write vendor/ (or, before any install, the repository root).
 *
 * @return {boolean} True when writable.
 */
function vendorWritable() {
	try {
		fs.accessSync(fs.existsSync(VENDOR) ? VENDOR : REPO_ROOT, fs.constants.W_OK);
		return true;
	} catch {
		return false;
	}
}

const writable = vendorWritable();
console.log(
	`[check-composer-deps-drift] vendor/ writable by this account: ${writable ? "yes" : "NO"}`,
);
if (!writable) {
	findings.push(
		"vendor/ is not writable by this account, so `composer install` cannot repair it (check its owner).",
	);
}

if (findings.length > 0) {
	console.error(
		"[check-composer-deps-drift] FAIL — vendor/ does not match composer.lock:",
	);
	for (const f of findings) {
		console.error(`  ${f}`);
	}
	console.error(
		"  Run `composer install` as the account that owns vendor/ (not `composer update` — only install reconciles to the lockfile).",
	);
	process.exit(1);
}

console.log(
	`[check-composer-deps-drift] PASS — ${WATCHED.length} watched package(s) match composer.lock.`,
);
