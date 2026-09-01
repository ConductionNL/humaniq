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
 * lockouts into an instance other people were using. humaniq had the same shape:
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
 *
 * ⚠️ `BASE_URL` is ALSO accepted, and that is not cosmetic. The shared
 * Conduction quality workflow exports the target instance as `BASE_URL` —
 * not `PLAYWRIGHT_BASE_URL`. openconnector adopted a `PLAYWRIGHT_BASE_URL`-only
 * resolver during its own Vue 3 migration and its E2E job has hard-failed on
 * every run since with `Error: PLAYWRIGHT_BASE_URL is not set.` humaniq ships no
 * `.github/workflows/` at all today, so nothing exercises this yet — which is
 * precisely why it has to be right before CI is added, rather than after the
 * first red run. Accepting the CI name costs nothing and keeps rule 1 intact:
 * all three names are read from the environment, none is defaulted.
 */

/** Nextcloud instances this suite must never touch. */
const FORBIDDEN_HOSTS = ["localhost:8080", "127.0.0.1:8080"];

/**
 * Is this process running inside a GitHub Actions runner?
 *
 * ⚠️ This exemption is load-bearing, and the comment above it was HALF right.
 * It correctly predicted that the shared Conduction quality workflow exports
 * the instance as `BASE_URL`, and accepted that name — but the value it
 * exports is literally `http://localhost:8080`, which `FORBIDDEN_HOSTS` then
 * rejects. So the guard written to stop this suite touching the shared dev
 * container would have rejected the ONE instance it is supposed to run
 * against, throwing from `resolveBaseURL()` at config-load time — before a
 * single test, with a message blaming a shared container that does not exist
 * on the runner.
 *
 * The distinction the guard actually wants is not "which port" but "is this
 * host disposable". On a GitHub-hosted runner :8080 is the run's own
 * `php -S` front controller, provisioned and destroyed with the job; there is
 * no shared instance reachable from it at all. `GITHUB_ACTIONS` is used rather
 * than the far broader `CI`, which a developer may well export in a shell that
 * CAN see the real :8080.
 *
 * @return True when running on a GitHub Actions runner.
 */
function isGitHubActionsRunner(): boolean {
	return process.env.GITHUB_ACTIONS === "true";
}

/**
 * Resolve the base URL for this run, or throw.
 *
 * @return The normalised base URL, without a trailing slash.
 */
export function resolveBaseURL(): string {
	const raw =
		process.env.PLAYWRIGHT_BASE_URL ||
		process.env.NEXTCLOUD_URL ||
		process.env.BASE_URL;

	if (!raw) {
		throw new Error(
			"PLAYWRIGHT_BASE_URL / NEXTCLOUD_URL / BASE_URL is not set. This suite deliberately has no default: " +
				"the old `|| http://localhost:8080` fallback pointed writes at the SHARED dev " +
				"instance. Provision an isolated instance and pass it explicitly, e.g. " +
				"PLAYWRIGHT_BASE_URL=http://localhost:8091 npm run test:e2e",
		);
	}

	const normalised = raw.replace(/\/+$/, "");

	if (
		!isGitHubActionsRunner() &&
		FORBIDDEN_HOSTS.some((host) => normalised.includes(host))
	) {
		throw new Error(
			`Refusing to run against ${normalised} — that is the SHARED dev container. ` +
				"These specs create and delete OpenRegister objects; run them against a " +
				"disposable instance instead (see spin-up-e2e-instance.sh).",
		);
	}

	return normalised;
}

/** Admin credentials for the instance under test. */
export const ADMIN_CREDENTIALS = {
	username: process.env.NC_ADMIN_USER || "admin",
	password: process.env.NC_ADMIN_PASS || "admin",
};
