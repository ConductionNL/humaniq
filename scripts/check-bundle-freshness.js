#!/usr/bin/env node
/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * check-bundle-freshness.js — assert js/ was built from the src/ on disk.
 *
 * WHY THIS EXISTS
 * ---------------
 * On 2026-08-19 the shipped bundle was 19 days older than the manifest it was
 * supposed to contain, and had been compiled against a dependency version the
 * lockfile no longer pinned. Every source-level check passed. The app was
 * broken in production for its primary navigation surface, and the source was
 * innocent — a class of defect no linter, unit test or schema validator can
 * see, because none of them read the artefact that actually ships.
 *
 * The one instrument that would have caught it is a comparison between what is
 * built and what it was built from.
 *
 * WHY MTIME, AND WHERE IT IS NOT TRUSTWORTHY
 * ------------------------------------------
 * `js/` is git-ignored, so its mtime is only ever moved by an actual build —
 * never by a checkout, rebase or pull. That makes it a real "when was this
 * built" signal LOCALLY.
 *
 * `src/`'s working-tree mtime is NOT trustworthy the same way: a fresh clone
 * stamps every file with the clone time, so on CI every source file looks
 * newer than any bundle and this check would fail every run for no reason.
 * So the source side uses git's own record — the last COMMIT time touching
 * src/ — which survives a clone intact.
 *
 * Neither signal exists in a git-less deploy (an unpacked release tarball).
 * There the check reports UNKNOWN and exits 0 rather than inventing a verdict:
 * a check that cannot see its subject must say so, not guess.
 */

const { execFileSync } = require("child_process");
const fs = require("fs");
const path = require("path");
const { computeSourceHash } = require("./lib/source-hash.js");

const REPO_ROOT = path.resolve(__dirname, "..");
const BUNDLE = path.join(REPO_ROOT, "js", "humaniq-main.js");

/** The sidecar `postbuild` writes: a content hash of the src/ it was built from. */
const BUILD_INFO = path.join(REPO_ROOT, "js", "build-info.json");

/**
 * `--sidecar` mode: compare a freshly-computed source hash against the one the
 * build recorded.
 *
 * This is the mode that works where the default one cannot. The mtime check
 * reads git's last-commit time for src/, so an unpacked release tarball, which
 * has no git, gets UNKNOWN and exit 0 -- and an unpacked release tarball is
 * precisely what an operator runs. A content hash needs no git and no clock.
 *
 * A MISSING sidecar is a FAILURE here, not an UNKNOWN. The default mode is
 * allowed to shrug because it genuinely cannot see its subject; this mode is
 * asked for by name, and answering "cannot tell" to a direct question about a
 * shipped artefact is how a stale bundle gets waved through.
 *
 * @return {never}
 */
function checkSidecar() {
	const recorded = readBuildInfo();
	const current = computeSourceHash(REPO_ROOT);
	reportHashes(recorded, current);

	if (recorded.sourceHash === current.sourceHash) {
		reportLibrarySource(recorded);
		console.log("[check-bundle-freshness] PASS -- the bundle was built from this exact src/.");
		process.exit(0);
	}

	failOnOtherListing(recorded, current);

	console.error("[check-bundle-freshness] FAIL -- src/ has changed since this bundle was built.");
	console.error("  What ships is not what the source says. Run `npm run build`.");
	process.exit(1);
}

/**
 * Print the recorded and the current source hash.
 *
 * @param {object} recorded - the recorded build info
 * @param {{sourceHash: string, fileCount: number, listedBy: string}} current - the hash of src/ now
 */
function reportHashes(recorded, current) {
	console.log(`[check-bundle-freshness] recorded sourceHash: ${String(recorded.sourceHash).slice(0, 16)}…`);
	console.log(`[check-bundle-freshness] current  sourceHash: ${current.sourceHash.slice(0, 16)}… (${current.fileCount} file(s) via ${current.listedBy})`);
	console.log(`[check-bundle-freshness] built at ${recorded.builtAt} for appVersion ${recorded.appVersion}`);
}

/**
 * Exit 1 when the hashes were computed over file lists from different
 * sources.
 *
 * @param {object} recorded - the recorded build info
 * @param {{listedBy: string}} current - the hash of src/ now
 */
function failOnOtherListing(recorded, current) {
	// A file set listed two different ways is a different question from a file
	// set that changed, and saying so beats reporting a stale bundle that is
	// current.
	if (recorded.listedBy !== undefined && recorded.listedBy !== current.listedBy) {
		console.error(
			`[check-bundle-freshness] FAIL -- hashes differ AND the file list came from a different source (recorded ${recorded.listedBy}, now ${current.listedBy}).`,
		);
		console.error("  Re-run where the same listing is available before trusting this verdict.");
		process.exit(1);
	}
}

/**
 * The sidecar the build wrote; exits 1 when it is missing or does not parse.
 *
 * @return {object} the recorded build info
 */
function readBuildInfo() {
	if (fs.existsSync(BUILD_INFO) === false) {
		console.error("[check-bundle-freshness] FAIL -- js/build-info.json is missing.");
		console.error("  Nothing records what this bundle was built from, so nothing can");
		console.error("  confirm it matches. Build with `npm run build`, whose postbuild");
		console.error("  step writes it.");
		process.exit(1);
	}

	let recorded;
	try {
		recorded = JSON.parse(fs.readFileSync(BUILD_INFO, "utf8"));
	} catch (error) {
		console.error(`[check-bundle-freshness] FAIL -- js/build-info.json is unreadable: ${error.message}`);
		process.exit(1);
	}
	return recorded;
}

/**
 * Say which @conduction/nextcloud-vue the bundle was built against; exits 1
 * in CI when that was a sibling checkout.
 *
 * @param {object} recorded - the recorded build info
 */
function reportLibrarySource(recorded) {
	console.log(
		`[check-bundle-freshness] built against @conduction/nextcloud-vue ${recorded.libVersion ?? "unknown"} (${recorded.libSource ?? "unrecorded"})`,
	);

	// A bundle built from a sibling ../nextcloud-vue checkout must never
	// ship: on 2026-08-19 that alias was opt-OUT, so dev boxes and CI were
	// compiling different code by default and nothing said so. Locally the
	// flag is a deliberate choice and this is information; in CI it is a
	// defect, so only CI fails on it.
	if (recorded.libSource !== "local") {
		return;
	}
	if (process.env.CI) {
		console.error(
			"[check-bundle-freshness] FAIL -- this bundle was built from a sibling ../nextcloud-vue checkout, not the declared dependency.",
		);
		console.error("  That artefact is not shippable. Rebuild without USE_LOCAL_LIB=true.");
		process.exit(1);
	}

	console.warn(
		"[check-bundle-freshness] NOTE -- built from ../nextcloud-vue, not the declared dependency. Fine locally, never shippable.",
	);
}

if (process.argv.includes("--sidecar")) {
	checkSidecar();
}

/**
 * A stamp written by `postbuild` on EVERY successful build.
 *
 * The bundle's own mtime cannot serve as "when was this built": webpack 5's
 * `output.compareBeforeEmit` skips writing an asset whose bytes are unchanged,
 * so the mtime records when the OUTPUT last CHANGED, not when a build last
 * ran. Measured here — a `@spec` comment added to a .vue file is stripped in
 * production, the bundle came out byte-identical, webpack left the file alone,
 * and this check failed a bundle that was provably current.
 *
 * A check that cries wolf is a check people stop reading, so the signal is a
 * stamp the build writes unconditionally.
 */
const STAMP = path.join(REPO_ROOT, "js", ".build-stamp");

/**
 * Last commit time touching a path, as a Date.
 *
 * @param {string} rel Repo-relative path.
 * @return {Date|null} The commit time, or null when git cannot answer.
 */
function lastCommitTime(rel) {
	try {
		/* execFileSync with an argv array, not execSync with an interpolated
		   string: no shell is involved, so a path containing shell
		   metacharacters is passed through as a single argument instead of
		   being parsed. JSON.stringify quoting happened to hold here, but it
		   is quoting for JSON, not for a shell - the two only coincide by
		   luck. `--` still separates the pathspec from the options. */
		const out = execFileSync(
			"git",
			["-C", REPO_ROOT, "log", "-1", "--format=%cI", "--", rel],
			{
				encoding: "utf8",
				stdio: ["ignore", "pipe", "ignore"],
			},
		).trim();
		return out ? new Date(out) : null;
	} catch {
		return null;
	}
}

if (!fs.existsSync(BUNDLE)) {
	console.error(
		"[check-bundle-freshness] FAIL — js/humaniq-main.js does not exist. Run `npm run build`.",
	);
	process.exit(1);
}

// Prefer the stamp; fall back to the bundle's mtime for a tree built before
// `postbuild` existed, and say which was used.
let builtAt;
if (fs.existsSync(STAMP)) {
	const raw = fs.readFileSync(STAMP, "utf8").trim();
	const parsed = new Date(raw);
	builtAt = isNaN(parsed.getTime()) ? fs.statSync(STAMP).mtime : parsed;
} else {
	console.log(
		"[check-bundle-freshness] no js/.build-stamp — falling back to the bundle mtime,",
	);
	console.log(
		"  which under webpack compareBeforeEmit can be older than the last build.",
	);
	builtAt = fs.statSync(BUNDLE).mtime;
}
const srcCommittedAt = lastCommitTime("src");

if (srcCommittedAt === null) {
	console.log(
		"[check-bundle-freshness] UNKNOWN — no git history available (git-less deploy?).",
	);
	console.log(
		"  Cannot compare the bundle against its source. Reporting rather than guessing.",
	);
	process.exit(0);
}

console.log(
	`[check-bundle-freshness] bundle built:      ${builtAt.toISOString()}`,
);
console.log(
	`[check-bundle-freshness] src last committed: ${srcCommittedAt.toISOString()}`,
);

// Uncommitted local edits to src/ are normal mid-work and are NOT a failure —
// the developer has not claimed to have built them yet. What this catches is a
// bundle older than source that is already committed, which is the state that
// gets pushed, deployed, and believed.
if (builtAt < srcCommittedAt) {
	const days = ((srcCommittedAt - builtAt) / 86400000).toFixed(1);
	console.error(
		`[check-bundle-freshness] FAIL — the bundle predates committed src/ by ${days} day(s).`,
	);
	console.error(
		"  What ships is not what the source says. Run `npm run build`.",
	);
	console.error(
		"  This is the exact shape of the 2026-08-19 incident: source correct, app broken.",
	);
	process.exit(1);
}

console.log(
	"[check-bundle-freshness] PASS — the bundle is at least as new as committed src/.",
);
