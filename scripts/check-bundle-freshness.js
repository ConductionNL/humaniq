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

const { execSync } = require('child_process')
const fs = require('fs')
const path = require('path')

const REPO_ROOT = path.resolve(__dirname, '..')
const BUNDLE = path.join(REPO_ROOT, 'js', 'humaniq-main.js')

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
const STAMP = path.join(REPO_ROOT, 'js', '.build-stamp')

/**
 * Last commit time touching a path, as a Date.
 *
 * @param {string} rel Repo-relative path.
 * @return {Date|null} The commit time, or null when git cannot answer.
 */
function lastCommitTime(rel) {
	try {
		const out = execSync(`git -C ${JSON.stringify(REPO_ROOT)} log -1 --format=%cI -- ${JSON.stringify(rel)}`, {
			encoding: 'utf8',
			stdio: ['ignore', 'pipe', 'ignore'],
		}).trim()
		return out ? new Date(out) : null
	} catch {
		return null
	}
}

if (!fs.existsSync(BUNDLE)) {
	console.error('[check-bundle-freshness] FAIL — js/humaniq-main.js does not exist. Run `npm run build`.')
	process.exit(1)
}

// Prefer the stamp; fall back to the bundle's mtime for a tree built before
// `postbuild` existed, and say which was used.
let builtAt
if (fs.existsSync(STAMP)) {
	const raw = fs.readFileSync(STAMP, 'utf8').trim()
	const parsed = new Date(raw)
	builtAt = isNaN(parsed.getTime()) ? fs.statSync(STAMP).mtime : parsed
} else {
	console.log('[check-bundle-freshness] no js/.build-stamp — falling back to the bundle mtime,')
	console.log('  which under webpack compareBeforeEmit can be older than the last build.')
	builtAt = fs.statSync(BUNDLE).mtime
}
const srcCommittedAt = lastCommitTime('src')

if (srcCommittedAt === null) {
	console.log('[check-bundle-freshness] UNKNOWN — no git history available (git-less deploy?).')
	console.log('  Cannot compare the bundle against its source. Reporting rather than guessing.')
	process.exit(0)
}

console.log(`[check-bundle-freshness] bundle built:      ${builtAt.toISOString()}`)
console.log(`[check-bundle-freshness] src last committed: ${srcCommittedAt.toISOString()}`)

// Uncommitted local edits to src/ are normal mid-work and are NOT a failure —
// the developer has not claimed to have built them yet. What this catches is a
// bundle older than source that is already committed, which is the state that
// gets pushed, deployed, and believed.
if (builtAt < srcCommittedAt) {
	const days = ((srcCommittedAt - builtAt) / 86400000).toFixed(1)
	console.error(`[check-bundle-freshness] FAIL — the bundle predates committed src/ by ${days} day(s).`)
	console.error('  What ships is not what the source says. Run `npm run build`.')
	console.error('  This is the exact shape of the 2026-08-19 incident: source correct, app broken.')
	process.exit(1)
}

console.log('[check-bundle-freshness] PASS — the bundle is at least as new as committed src/.')
