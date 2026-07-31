/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The ONE place this suite decides which Nextcloud it talks to.
 *
 * WHY THIS FILE EXISTS
 * --------------------
 * Two apps in this fleet were found running their e2e suites against the
 * SHARED dev container on :8080, by two different mechanisms — and one of
 * them was the login spec, so every run fired failed logins and brute-force
 * lockouts into an instance other people were using. hrmq had the same shape:
 * `playwright.config.ts` and `global-setup.ts` each computed
 * `process.env.NEXTCLOUD_URL || 'http://localhost:8080'`, and
 * `core-journeys.spec.ts` recomputed it a THIRD time for its OpenRegister
 * seed/teardown — a WRITE path. A missing env var silently pointed all three
 * at the shared instance.
 *
 * Two rules follow, and both are enforced here rather than by convention:
 *
 *   1. `PLAYWRIGHT_BASE_URL` is authoritative and there is NO
 *      `?? 'http://localhost:8080'` fallback. An unset base URL is a hard,
 *      loud failure — never a quiet redirect onto somebody else's instance.
 *   2. Every consumer imports THIS value, so absolute (API seeding) and
 *      relative (page.goto) navigation in one spec cannot disagree.
 *
 * `NEXTCLOUD_URL` stays supported as a legacy alias so an existing invocation
 * keeps working, but it is never defaulted.
 */

/** Nextcloud instances this suite must never touch. */
const FORBIDDEN_HOSTS = ['localhost:8080', '127.0.0.1:8080']

/**
 * Resolve the base URL for this run, or throw.
 *
 * @return The normalised base URL, without a trailing slash.
 */
export function resolveBaseURL(): string {
	const raw = process.env.PLAYWRIGHT_BASE_URL || process.env.NEXTCLOUD_URL

	if (!raw) {
		throw new Error(
			'PLAYWRIGHT_BASE_URL is not set. This suite deliberately has no default: '
			+ 'the old `|| http://localhost:8080` fallback pointed writes at the SHARED dev '
			+ 'instance. Provision an isolated instance and pass it explicitly, e.g. '
			+ 'PLAYWRIGHT_BASE_URL=http://localhost:8091 npm run test:e2e',
		)
	}

	const normalised = raw.replace(/\/+$/, '')

	if (FORBIDDEN_HOSTS.some((host) => normalised.includes(host))) {
		throw new Error(
			`Refusing to run against ${normalised} — that is the SHARED dev container. `
			+ 'These specs create and delete OpenRegister objects; run them against a '
			+ 'disposable instance instead (see spin-up-e2e-instance.sh).',
		)
	}

	return normalised
}

/** Admin credentials for the instance under test. */
export const ADMIN_CREDENTIALS = {
	username: process.env.NC_ADMIN_USER || 'admin',
	password: process.env.NC_ADMIN_PASS || 'admin',
}
