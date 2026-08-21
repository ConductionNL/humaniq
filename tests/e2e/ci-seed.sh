#!/usr/bin/env bash
#
# SPDX-FileCopyrightText: 2026 Conduction B.V.
# SPDX-License-Identifier: EUPL-1.2
#
# Provision hrmq's OpenRegister register + schemas on a freshly installed
# Nextcloud, for the shared `E2E Tests (Playwright)` CI job.
#
# Wired up as the workflow's `playwright-seed-command`. That step runs AFTER
# `php -S` is up and with cwd set to the Nextcloud server root, so this is
# invoked as:
#
#     playwright-seed-command: 'bash apps/hrmq/tests/e2e/ci-seed.sh'
#
# WHY THIS IS NEEDED
# ------------------
# hrmq is a THIN CLIENT: Employee, Timesheet, Expense, PayrollRun, Payslip and
# 49 other entities are OpenRegister objects, and 176 of src/manifest.json's
# page configs name `register: "hrmq"`. With that register absent, nothing
# errors — the SPA boots, every route resolves to nothing, and the router falls
# back to its default page. Measured on run 30904454063 (job 91976455533):
# 68 failed / 2 passed, where BOTH "passes" were the default route
# (`/timesheets`). The failure mode and the success signal were the same page.
#
# Two independent defects produced that, and BOTH are fixed in the app rather
# than papered over here:
#
#   1. `appinfo/info.xml` declared the register-import repair step under
#      `<post-migration>` ONLY. Nextcloud's Installer::installAppLastSteps()
#      guards both the pre- and post-migration blocks with
#      `if ($previousVersion !== '')`, so on a FRESH install neither runs — only
#      `repair-steps/install` is unconditional. `occ app:enable hrmq` printed
#      "hrmq 0.2.0 enabled" and not one line of the step's own output.
#      Fixed by adding an `<install>` block.
#   2. `lib/Settings/hrmq_register.json` carried no `components.registers`
#      section at all. OpenRegister's ImportHandler creates registers from that
#      key and nowhere else, so even a repair step that DID run would have
#      created 54 schemas and zero registers — and then skipped every seed
#      object, whose `@self.register` is resolved through the map that section
#      populates. Fixed by declaring the register (pinned by
#      tests/Unit/Settings/RegisterDeclarationTest.php).
#
# So why does this script still exist? Because a repair step is not a gate:
#
#   * `InitializeRegister::run()` catches \Throwable and downgrades every
#     failure to a warning, so `occ app:enable hrmq` exits 0 either way.
#   * It runs with NO user session. OpenRegister's `main` — which this
#     workflow pins via `additional-apps` — calls `importFromApp()` WITHOUT the
#     `SystemOperationContext::run()` wrapper that exists on newer branches, so
#     the import is evaluated under ambient RBAC as an anonymous actor.
#
# This script therefore does the import EXPLICITLY over the admin HTTP API
# (a real admin session, so RBAC passes), FORCED, and then VERIFIES that the
# register, every schema slug, and the object collection the specs actually
# read are really there. A failed provision becomes ONE loud step failure here
# instead of 68 misleading spec failures that accuse the selectors.
#
# It also reports whether the register already existed BEFORE it did anything —
# that line is the evidence for whether fix (1) + (2) work on their own, which
# a script that just makes the register appear would otherwise destroy.
#
# It is idempotent: the import is idempotent server-side and re-running only
# re-verifies.

set -euo pipefail

SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
# tests/e2e/ci-seed.sh -> up TWO levels to the app root.
APP_DIR="$(cd -- "${SCRIPT_DIR}/../.." && pwd)"
if [ ! -f "${APP_DIR}/appinfo/info.xml" ]; then
	echo "::error::Resolved app dir ${APP_DIR} does not look like the hrmq app root (no appinfo/info.xml)." >&2
	exit 1
fi

# Run curl, write the body to $1, and echo ONLY the three-digit status.
#
# `curl -w '%{http_code}'` already emits `000` when the connection never
# completed, so the usual `|| echo 000` idiom appends a SECOND code and yields
# `000000` — a string that matches no comparison and reads like a parse bug
# rather than an unreachable host. Swallow curl's exit status instead and keep
# the last three characters, so an unreachable instance is a clean `000`.
http_get_code() {
	local out="$1"
	shift
	local code
	# Create the body file up front. curl does not create `-o` when the
	# connection never completes, and a downstream reader that then dies on
	# ENOENT reports a missing FILE where the real fact is an unreachable
	# instance — the traceback names the wrong thing entirely.
	: > "$out"
	code="$(curl -sS -o "$out" -w '%{http_code}' "$@" 2>/dev/null || true)"
	code="${code: -3}"
	printf '%s' "${code:-000}"
}

# ── Target resolution ────────────────────────────────────────────────────────
# The shared workflow's "Seed test data" step runs with BASE_URL / ADMIN_USER /
# ADMIN_PASSWORD available. Accept the same alias set tests/e2e/base-url.ts
# accepts so the seed and the specs cannot disagree about which instance they
# are talking to.
#
# On a developer box `localhost:8080` is the SHARED dev container, and this
# script performs ADMIN WRITES — it must never silently import a register into
# somebody else's environment. Off CI, an unset target is a hard error.
BASE="${PLAYWRIGHT_BASE_URL:-${BASE_URL:-${NEXTCLOUD_URL:-${NC_BASE_URL:-}}}}"
if [ -z "$BASE" ]; then
	if [ "${GITHUB_ACTIONS:-}" = "true" ]; then
		BASE="http://localhost:8080"
	else
		echo "ERROR: no base URL set. Export PLAYWRIGHT_BASE_URL or BASE_URL." >&2
		echo "       Refusing to default to http://localhost:8080 outside GitHub Actions —" >&2
		echo "       that is the SHARED dev container and this script writes to it." >&2
		exit 1
	fi
fi
BASE="${BASE%/}"

USER_NAME="${ADMIN_USER:-${NC_ADMIN_USER:-admin}}"
USER_PASS="${ADMIN_PASSWORD:-${NC_ADMIN_PASS:-admin}}"

echo "[ci-seed] target:  ${BASE}"
echo "[ci-seed] app dir: ${APP_DIR}"

WORK="$(mktemp -d)"
trap 'rm -rf "$WORK"' EXIT

# ── 0. Did the app's own install path already provision the register? ────────
# This decides whether the HTTP import below runs at all, and it is the single
# most informative line in the log.
#
# Measured on run 30919961510 (job 92028085860), the first run carrying the
# <install> block and the components.registers declaration: "ALREADY present".
# The app provisions its own register on a fresh install, as it should.
#
# So the import is a FALLBACK, not the normal path. Firing it unconditionally
# was worse than useless: it re-posted a configuration that was already imported
# and answered HTTP 400 on every healthy run, training the reader to ignore a
# 400 from the one step whose failure would matter if the app fix regressed.
PRE_BODY="${WORK}/pre-registers.json"
PRE_CODE="$(http_get_code "$PRE_BODY" -u "${USER_NAME}:${USER_PASS}" -H 'OCS-APIRequest: true' \
	"${BASE}/index.php/apps/openregister/api/registers?_limit=300")"
NEEDS_IMPORT=1
if [ "$PRE_CODE" = "200" ] && [ -f "$PRE_BODY" ] && grep -q '"slug":[[:space:]]*"hrmq"' "$PRE_BODY"; then
	NEEDS_IMPORT=0
	echo "[ci-seed] BEFORE import: the hrmq register is ALREADY present — 'occ app:enable hrmq' provisioned it."
	echo "[ci-seed] Skipping the HTTP import; the verification below still runs in full."
else
	echo "[ci-seed] BEFORE import: the hrmq register is NOT present (registers endpoint HTTP ${PRE_CODE})."
	echo "[ci-seed] The app's own install path did not provision it — see appinfo/info.xml"
	echo "[ci-seed] <repair-steps><install> and lib/Settings/hrmq_register.json components.registers."
	echo "[ci-seed] Falling back to an explicit admin HTTP import."
fi

# ── 1. Build the merged configuration ────────────────────────────────────────
# hrmq's base register file is deliberately almost empty: ADR-037 puts every
# schema in its own `lib/Settings/register.d/*.json` fragment so concurrent
# changes touch disjoint files. `SettingsService::loadRegisterConfigData()`
# deep-merges them at runtime; OpenRegister's generic importer cannot, so the
# merge is reproduced here with the SAME semantics as
# `SettingsService::deepMergeConfig()`:
#
#   * fragment files are globbed and SORTED (deterministic order);
#   * associative arrays merge by key union, recursing on shared keys;
#   * list arrays are CONCATENATED (this is what lets fragments contribute seed
#     objects and register schema entries additively);
#   * scalars in the fragment overwrite the base.
#
# A malformed fragment is a hard failure here rather than the runtime's
# skip-with-a-warning: in CI a silently dropped fragment is a missing schema,
# and a missing schema is a page that falls back to the default route.
MERGED="${WORK}/hrmq-merged.json"
python3 - "$APP_DIR" "$MERGED" <<'PY'
import glob
import json
import os
import sys

app_dir, out_path = sys.argv[1], sys.argv[2]
base_path = os.path.join(app_dir, 'lib', 'Settings', 'hrmq_register.json')

if not os.path.isfile(base_path):
    print(f'::error::hrmq_register.json not found at {base_path}.')
    sys.exit(1)

with open(base_path, encoding='utf-8') as fh:
    config = json.load(fh)


def deep_merge(base, overlay):
    """Mirror SettingsService::deepMergeConfig()."""
    for key, value in overlay.items():
        current = base.get(key)
        if isinstance(value, dict) and isinstance(current, dict):
            base[key] = deep_merge(current, value)
        elif isinstance(value, list) and isinstance(current, list):
            base[key] = current + value
        else:
            base[key] = value
    return base


fragments = sorted(glob.glob(os.path.join(app_dir, 'lib', 'Settings', 'register.d', '*.json')))
if not fragments:
    print('::error::No register.d fragments found — every hrmq schema lives in one.')
    sys.exit(1)

for fragment in fragments:
    with open(fragment, encoding='utf-8') as fh:
        try:
            config = deep_merge(config, json.load(fh))
        except json.JSONDecodeError as exc:
            print(f'::error::Malformed register fragment {os.path.basename(fragment)}: {exc}')
            sys.exit(1)

components = config.get('components') or {}
registers = components.get('registers') or {}
schemas = components.get('schemas') or {}
objects = components.get('objects') or []

# The register section is the whole reason the previous CI run failed. Refuse to
# post a configuration that cannot create it rather than letting the import
# report success over a register that will not exist.
if 'hrmq' not in registers:
    print('::error::The merged configuration declares no `hrmq` register '
          '(components.registers.hrmq). OpenRegister creates registers from that key and '
          'nowhere else, so importing this would create schemas and no register.')
    sys.exit(1)

declared = registers['hrmq'].get('schemas') or []
missing = sorted(set(schemas) - set(declared))
if missing:
    print(f'::error::The hrmq register does not list these defined schemas: {missing}')
    sys.exit(1)

with open(out_path, 'w', encoding='utf-8') as fh:
    json.dump(config, fh)

print(f'[ci-seed] merged {len(fragments)} fragments -> '
      f'{len(registers)} register(s), {len(schemas)} schema(s), {len(objects)} seed object(s)')
print(f'[ci-seed] configuration version: {config.get("info", {}).get("version")}')
PY

CONFIG_VERSION="$(python3 -c 'import json,sys; print(json.load(open(sys.argv[1]))["info"]["version"])' "$MERGED")"

# ── 2. Fallback only: import it as admin, forced ─────────────────────────────
# Runs ONLY when step 0 found no register — see the note there for why an
# unconditional import was actively harmful.
#
# OpenRegister's generic importer. Admin-only, so HTTP Basic as admin gives it
# the real session the repair step does not have. It reads the upload under the
# literal form key `file`; a raw JSON request body is NOT one of its accepted
# shapes. `force` is compared `=== 'true' || === true` there, so the
# form-encoded string is fine.
#
# `OCS-APIRequest: true` is load-bearing, not decoration: Nextcloud's
# Request::passesCSRFCheck() short-circuits to true on that header, and the
# strict-cookie precondition holds because a Basic-auth request carries no
# session cookie at all.
if [ "$NEEDS_IMPORT" = "1" ]; then
	IMPORT_URL="${BASE}/index.php/apps/openregister/api/configurations/import"
	echo "[ci-seed] POST ${IMPORT_URL} (forced, appId=hrmq, version=${CONFIG_VERSION})"

	IMPORT_BODY="${WORK}/import.json"
	IMPORT_CODE="$(http_get_code "$IMPORT_BODY" \
		-u "${USER_NAME}:${USER_PASS}" \
		-X POST \
		-H 'OCS-APIRequest: true' \
		-F "file=@${MERGED};type=application/json" \
		-F 'force=true' \
		-F 'appId=hrmq' \
		-F "version=${CONFIG_VERSION}" \
		"$IMPORT_URL")"
	echo "[ci-seed] configurations/import -> HTTP ${IMPORT_CODE}"
	if [ -f "$IMPORT_BODY" ]; then
		head -c 2000 "$IMPORT_BODY"; echo
	fi

	# HTTP 200 is necessary but NOT sufficient — a login redirect is also a 200
	# with an HTML body. Nothing is decided here; the verification below is the
	# gate, and it will fail loudly if this fallback did not actually work.
	if [ "$IMPORT_CODE" != "200" ]; then
		echo "::warning::The fallback import did not return HTTP 200. The verification below decides the outcome."
	fi
else
	echo "[ci-seed] Fallback import not needed."
fi

# ── 3. Verify the register and every schema actually exist ───────────────────
# An import reporting success is not the same as the register existing.
#
# The HTTP status is captured and checked SEPARATELY from the payload on
# purpose: an endpoint that 404s or redirects to the login form yields an empty
# slug set, which is indistinguishable from "the import produced nothing" if you
# only look at the parsed list. A wrong lookup manufactures an absence for free,
# so the two are reported as different errors.
#
# The required slug list is READ OUT OF THE REPO's own register declaration, not
# retyped here — a hand-maintained copy is exactly how a check drifts into
# asserting less than it claims.
verify() {
	python3 - "$1" "$2" "$3" "$4" "$5" <<'PY'
import json
import sys

path, kind, code, app_dir, limit = sys.argv[1], sys.argv[2], sys.argv[3], sys.argv[4], int(sys.argv[5])

with open(f'{app_dir}/lib/Settings/hrmq_register.json', encoding='utf-8') as fh:
    declaration = json.load(fh)['components']['registers']['hrmq']

required = {
    'registers': [declaration['slug']],
    'schemas': list(declaration['schemas']),
}[kind]

with open(path, encoding='utf-8') as fh:
    raw = fh.read()

if code != '200':
    print(f'::error::OpenRegister {kind} endpoint returned HTTP {code}, so the slug list below '
          f'proves nothing about the import. First 500 bytes:')
    print(raw[:500])
    sys.exit(1)

try:
    body = json.loads(raw)
except json.JSONDecodeError:
    print(f'::error::The {kind} endpoint did not return JSON (HTTP 200). First 500 bytes:')
    print(raw[:500])
    sys.exit(1)

items = body if isinstance(body, list) else (body.get('results') or [])

# The listing endpoints return a bare `{"results": [...]}` with NO total, so a
# page that came back exactly `limit` long is indistinguishable from a complete
# one — and every slug past the cut then reads as "missing after import".
# Measured: the shared dev instance answers `?_limit=1000` with exactly 1000
# schemas. Refuse to draw a conclusion from a possibly-truncated page rather
# than reporting an absence the query could not have shown.
if len(items) >= limit:
    print(f'::error::The {kind} endpoint returned {len(items)} items for a limit of {limit} — the '
          f'page is truncated, so an absent slug below would prove nothing. Raise the limit.')
    sys.exit(1)

# OpenRegister resolves schema/register URL segments via LOWER(slug), so
# comparing case-sensitively here would manufacture a false failure.
slugs = {(item.get('slug') or '').lower() for item in items if isinstance(item, dict)}
missing = [slug for slug in required if slug.lower() not in slugs]

print(f'[ci-seed] {kind} present ({len(slugs)}): {sorted(s for s in slugs if s)}')
if missing:
    print(f'::error::hrmq {kind} missing after import: {missing}')
    print('::error::Without them every manifest page that names them resolves to nothing and the '
          'router falls back to its default route — which is what 68 failed / 2 passed looked like.')
    sys.exit(1)

print(f'[ci-seed] {kind} OK ({len(required)} required slug(s) present)')
PY
}

REG_LIMIT=2000
REG_BODY="${WORK}/registers.json"
REG_CODE="$(http_get_code "$REG_BODY" -u "${USER_NAME}:${USER_PASS}" -H 'OCS-APIRequest: true' \
	"${BASE}/index.php/apps/openregister/api/registers?_limit=${REG_LIMIT}")"
verify "$REG_BODY" registers "$REG_CODE" "$APP_DIR" "$REG_LIMIT"

SCH_LIMIT=5000
SCH_BODY="${WORK}/schemas.json"
SCH_CODE="$(http_get_code "$SCH_BODY" -u "${USER_NAME}:${USER_PASS}" -H 'OCS-APIRequest: true' \
	"${BASE}/index.php/apps/openregister/api/schemas?_limit=${SCH_LIMIT}")"
verify "$SCH_BODY" schemas "$SCH_CODE" "$APP_DIR" "$SCH_LIMIT"

# ── 4. Probe the exact collection shape the specs use ────────────────────────
# The register existing is still not the same as it being READ/WRITEABLE by the
# admin session the specs use. `tests/e2e/spec-coverage/core-journeys.spec.ts`
# builds its URLs as /apps/openregister/api/objects/hrmq/<schema> — and its
# `resolveEmployeeSchema()` throws the "Is the hrmq register installed in
# OpenRegister?" error that started this whole investigation when none of them
# answers 200. Probe that shape here and give the failure a name.
for schema in Employee Timesheet Expense; do
	code="$(http_get_code /dev/null \
		-u "${USER_NAME}:${USER_PASS}" -H 'OCS-APIRequest: true' \
		"${BASE}/index.php/apps/openregister/api/objects/hrmq/${schema}?_limit=1")"
	echo "[ci-seed] objects/hrmq/${schema} probe -> ${code}"
	if [ "$code" != "200" ]; then
		echo "::error::The hrmq ${schema} collection is not readable (HTTP ${code})."
		echo "::error::Every spec touching it would fail with a message accusing the selectors."
		exit 1
	fi
done

# ── 5. Warm the SPA, and gate on the bundle actually being JavaScript ────────
# The shared workflow serves Nextcloud with `php -S`. The first hit pays a cold
# opcache and the first parse of a multi-megabyte webpack bundle, and that cost
# would otherwise land inside whichever spec happens to run first.
#
# Warm-up failures are ignored — but the bundle check at the end is a GATE, and
# it reads the SERVED response rather than the file on disk. Do NOT hardcode the
# bundle URL: Nextcloud serves an app's assets from whichever apps directory it
# was installed into (`/apps/hrmq/js/…` on the runner, `/custom_apps/hrmq/js/…`
# in the docker dev images), and asking for the wrong one does not 404 — it
# returns HTTP 200 with `text/html`, the NC error page served through index.php.
# A status-code check therefore reports success while fetching an HTML page
# instead of the bundle, so the warm-up silently warms nothing and the SPA never
# mounts.
for path in \
	"/index.php/apps/hrmq/" \
	"/index.php/apps/hrmq/api/manifest" \
	"/index.php/apps/openregister/api/registers?_limit=1"
do
	code="$(http_get_code /dev/null -u "${USER_NAME}:${USER_PASS}" \
		-H 'OCS-APIRequest: true' "${BASE}${path}")"
	echo "[ci-seed] warm ${path} -> ${code}"
done

# ---------------------------------------------------------------------------
# Administration + AdministrationAccess for the e2e caller.
#
# WHY THIS EXISTS
# ---------------
# The Dashboard's analytics widgets call `/apps/hrmq/api/analytics/*`, and that
# controller requires the caller to hold an `AdministrationAccess` row with role
# `hr` or `accountant` — the first surface in hrmq that actually enforces that
# field. Without one the endpoints correctly answer 403, four of the six
# dashboard widgets fail to load, and `manifest-pages.spec.ts` fails the
# Dashboard on "emitted console errors".
#
# That failure was RIGHT: the guard did its job and the seed was incomplete. An
# e2e run with no access row cannot exercise any tenant-scoped surface at all,
# so seeding one is provisioning the fixture, not weakening the check.
#
# Both writes are idempotent-by-tolerance: a duplicate simply fails and is
# ignored, because this script may run against an instance seeded by a previous
# job.
# ---------------------------------------------------------------------------
ADM_ID="E2E-ADM-001"
# hrmq-hours-process-redesign: the hours-process e2e journeys
# (spec-coverage/hours-process.spec.ts) book time entries as the admin user.
# The stamping listener resolves admin's own Employee (the register-seeded
# employee-jansen, nextcloudUserId "admin") and stamps its administrationId —
# ADM-001, the register-seeded administration — onto every booking, and every
# hours page filters on `administrationId: @workspace.activeAdministrationId?`.
# With the active pointer at E2E-ADM-001 those pages would filter out both the
# register-seeded rows AND everything admin books, so the ACTIVE administration
# below is set to ADM-001. An `hr`-role access row for ADM-001 is seeded here
# too (hr-seed.json already carries an accountant row; this one keeps the
# analytics guard independent of that seed's shape).
ACTIVE_ADM_ID="ADM-001"
for payload in \
	"{\"administrationId\":\"${ADM_ID}\",\"name\":\"E2E Administration\",\"active\":true,\"mode\":\"standard\"}|Administration" \
	"{\"userId\":\"${USER_NAME}\",\"administrationId\":\"${ADM_ID}\",\"role\":\"hr\"}|AdministrationAccess" \
	"{\"userId\":\"${USER_NAME}\",\"administrationId\":\"${ACTIVE_ADM_ID}\",\"role\":\"hr\"}|AdministrationAccess"
do
	body="${payload%|*}"
	schema="${payload##*|}"
	code="$(curl -sS -o /dev/null -w '%{http_code}' -X POST \
		-u "${USER_NAME}:${USER_PASS}" -H 'OCS-APIRequest: true' \
		-H 'Content-Type: application/json' -d "${body}" \
		"${BASE}/index.php/apps/openregister/api/objects/hrmq/${schema}" || true)"
	echo "[ci-seed] seed ${schema} -> ${code}"
done

# The access row alone is not enough: `AnalyticsController::authorizeCaller()`
# reads the caller's ACTIVE administration, which `AdministrationService` stores
# as a per-USER config value — not derived from the access rows. Without this
# pointer `getActiveAdministrationId()` returns null and every analytics
# endpoint answers 403, which is what the first cut of this seed missed: both
# objects were created (201/201) and the Dashboard still failed on eight 403s.
# Two URL forms, because they do not behave identically across environments:
# `/index.php/apps/...` answers 200 on the docker dev instance and 404 on the
# CI runner's `php -S`. Rather than guess which one CI has, try both and report
# BOTH codes — a seed step that silently fails is how the first two cuts of this
# fix looked like they had worked (Administration 201, AdministrationAccess 201,
# and the Dashboard still 403).
# `occ` FIRST, and VERIFY BY READING THE VALUE BACK.
#
# The pointer is nothing but a per-user config value —
# `AdministrationService::getActiveAdministrationId()` reads
# `getUserValue($userId, 'hrmq', 'active_administration_id')`. Setting it over
# HTTP made the seed depend on route-prefix form, session auth and CSRF, none of
# which this fixture needs: `/index.php/apps/hrmq/api/administration/active`
# answers 200 on the docker dev instance and 404 on the CI runner. `occ` writes
# the same value directly, with no routing involved.
#
# The read-back is the point. A write that reports success and a write that
# happened are different facts, and the previous two cuts of this seed differed
# exactly there: both objects created (201/201), the pointer never set, and the
# Dashboard failing on eight 403s four minutes later. This asserts the value the
# guard will actually read, from the same place it reads it.
ACT_KEY='active_administration_id'
ACT_OK=0
OCC=''
for cand in "${NEXTCLOUD_ROOT:-}/occ" "${PWD}/occ" "${APP_DIR}/../../occ" "${APP_DIR}/../../../occ"; do
	if [ -n "$cand" ] && [ -f "$cand" ]; then OCC="$cand"; break; fi
done

if [ -n "$OCC" ]; then
	echo "[ci-seed] occ: ${OCC}"
	# `user:setting <uid> <app> <key> <value>` — NOT `config:user:set`, which
	# does not exist ("There are no commands defined in the \"config:user\"
	# namespace"), and NOT `--value=`, which is parsed as the positional value
	# argument only by accident on some versions. Round-tripped against a live
	# instance: writes, reads back byte-identical, and reports
	# 'The setting does not exist for user "..."' once deleted — so the
	# comparison below can genuinely fail.
	php "$OCC" user:setting "${USER_NAME}" hrmq "${ACT_KEY}" "${ACTIVE_ADM_ID}" >/dev/null 2>&1 || true
	READBACK="$(php "$OCC" user:setting "${USER_NAME}" hrmq "${ACT_KEY}" 2>/dev/null | tr -d '\r\n' || true)"
	echo "[ci-seed] active administration read back as: '${READBACK}' (want '${ACTIVE_ADM_ID}')"
	if [ "$READBACK" = "$ACTIVE_ADM_ID" ]; then ACT_OK=1; fi
else
	echo "[ci-seed] occ not found (looked in NEXTCLOUD_ROOT, cwd, and two levels above the app dir)."
fi

# HTTP fallback, only if occ could not do it. Both prefix forms, both codes
# reported — never one silent attempt.
if [ "$ACT_OK" != "1" ]; then
	for form in "/index.php/apps/hrmq/api/administration/active" "/apps/hrmq/api/administration/active"; do
		code="$(curl -sS -o /dev/null -w '%{http_code}' -X POST \
			-u "${USER_NAME}:${USER_PASS}" -H 'OCS-APIRequest: true' \
			-H 'Content-Type: application/json' -d "{\"administrationId\":\"${ACTIVE_ADM_ID}\"}" \
			"${BASE}${form}" || true)"
		echo "[ci-seed] set active administration ${ACTIVE_ADM_ID} via ${form} -> ${code}"
		case "$code" in 2*) ACT_OK=1; break ;; esac
	done
fi

if [ "$ACT_OK" != "1" ]; then
	# Not fatal: only the analytics endpoints need it, and the rest of the suite
	# is still worth running. But say so loudly rather than leaving a reader to
	# infer it from a Dashboard failure 4 minutes later.
	echo "[ci-seed] WARNING: no active administration set — AnalyticsController will answer 403 and the Dashboard spec will fail on console errors."
fi

# ---------------------------------------------------------------------------
# Prove the guard the Dashboard depends on actually opens.
#
# Seeding the two objects and the pointer is necessary but demonstrably not
# sufficient: run 32304177761 seeded Administration 201, AdministrationAccess
# 201, AND read the pointer back as the exact value it wrote — and the
# Dashboard still failed on six 403s four minutes later. Every input the seed
# controls looked right while the thing that matters, whether
# AnalyticsController::authorizeCaller() opens for this caller, was never
# asked.
#
# So ask it here, against the same endpoint the widgets call. This turns an
# unexplained spec failure into a one-line seed diagnosis naming which half of
# the guard refused:
#
#   * `authorizeCaller()` needs BOTH the per-user active-administration
#     pointer AND an AdministrationAccess row for THIS user whose role is
#     `hr`/`accountant` (AdministrationService::accessRowsForUser() matches on
#     `userId` exactly).
#   * a 403 here with the pointer read back OK above therefore isolates the
#     failure to the access row / role half.
#
# Non-fatal, like the pointer step: the rest of the suite is still worth
# running. But it will no longer be silent.
# ---------------------------------------------------------------------------
GUARD_BODY="${WORK}/guard.json"
GUARD_CODE="$(http_get_code "$GUARD_BODY" -u "${USER_NAME}:${USER_PASS}" -H 'OCS-APIRequest: true' \
	"${BASE}/index.php/apps/hrmq/api/analytics/trends?metric=absence-rate")"
echo "[ci-seed] guard probe: GET /api/analytics/trends -> ${GUARD_CODE}"
if [ "$GUARD_CODE" != "200" ]; then
	echo "[ci-seed] WARNING: the analytics guard did NOT open for '${USER_NAME}' (HTTP ${GUARD_CODE})."
	echo "[ci-seed]   The Dashboard spec will fail on console errors. Diagnosis:"
	echo "[ci-seed]   - active-administration pointer: see the read-back line above."
	echo "[ci-seed]   - AdministrationAccess rows visible to this caller:"
	ACC_BODY="${WORK}/access.json"
	ACC_CODE="$(http_get_code "$ACC_BODY" -u "${USER_NAME}:${USER_PASS}" -H 'OCS-APIRequest: true' \
		"${BASE}/index.php/apps/openregister/api/objects/hrmq/AdministrationAccess?_limit=50")"
	echo "[ci-seed]     listing HTTP ${ACC_CODE}"
	# The app log is the ONLY place the real reason can appear.
	# `AdministrationService::loadAll()` wraps its ObjectService call in
	# `catch (\Throwable) { logger->warning(...); return []; }` — so a failing
	# load is indistinguishable from "this user has no access rows", and the
	# difference is written to nextcloud.log and nowhere else. Without this,
	# a run where the rows exist AND the pointer is set AND the guard still
	# refuses (observed: run 32304177761) has no visible cause at all.
	if [ -n "$OCC" ]; then
		NC_LOG="$(dirname "$OCC")/data/nextcloud.log"
		if [ -f "$NC_LOG" ]; then
			echo "[ci-seed]   - last hrmq/AdministrationService lines in nextcloud.log:"
			grep -aiE "administrationservice|analyticsservice|hrmq" "$NC_LOG" 2>/dev/null \
				| tail -8 | cut -c1-400 | sed 's/^/[ci-seed]     /' || true
		else
			echo "[ci-seed]   - nextcloud.log not found at ${NC_LOG}"
		fi
	fi
	if [ -s "$ACC_BODY" ]; then
		python3 - "$ACC_BODY" "$USER_NAME" <<'PY' || true
import json, sys
try:
    doc = json.load(open(sys.argv[1], encoding='utf-8'))
except Exception as exc:
    print(f'[ci-seed]     could not parse the listing: {exc}')
    raise SystemExit(0)
rows = doc.get('results') or []
print(f'[ci-seed]     total={doc.get("total")} rows_returned={len(rows)}')
for row in rows:
    print(f'[ci-seed]       userId={row.get("userId")!r} '
          f'administrationId={row.get("administrationId")!r} role={row.get("role")!r}')
mine = [r for r in rows if r.get('userId') == sys.argv[2]]
print(f'[ci-seed]     rows matching userId={sys.argv[2]!r}: {len(mine)} '
      f'(authorizeCaller needs >=1 with role hr/accountant)')
PY
	fi
fi

APP_HTML="${WORK}/app.html"
curl -sS -u "${USER_NAME}:${USER_PASS}" -H 'OCS-APIRequest: true' \
	"${BASE}/index.php/apps/hrmq/" -o "$APP_HTML" || true

# `|| true` is load-bearing: grep exits 1 when it matches nothing, and under
# `set -euo pipefail` that would abort right here — so the case the gate below
# exists to explain (no bundle) would die with a bare non-zero exit and none of
# the diagnosis. Let it fall through to the gate instead.
BUNDLE_SRC="$(grep -oE 'src="[^"]*hrmq-main[^"]*"' "$APP_HTML" | head -1 | sed 's/^src="//; s/"$//' || true)"

if [ -n "$BUNDLE_SRC" ]; then
	BUNDLE_INFO="$(curl -sS -o /dev/null \
		-w '%{http_code} %{content_type} %{size_download}' \
		-u "${USER_NAME}:${USER_PASS}" "${BASE}${BUNDLE_SRC}" || echo '000 - 0')"
	echo "[ci-seed] warm bundle ${BUNDLE_SRC} -> ${BUNDLE_INFO}"
else
	echo "[ci-seed] could not locate the bundle src in the rendered app page."
	BUNDLE_INFO=""
fi

if [ "${GITHUB_ACTIONS:-}" = "true" ]; then
	case "$BUNDLE_INFO" in
		*javascript*)
			echo "[ci-seed] bundle verified as JavaScript."
			;;
		*)
			echo "::error::The hrmq frontend bundle did not serve as JavaScript (got: ${BUNDLE_INFO:-<not found>})."
			echo "::error::The SPA cannot mount, so every UI spec would fail on a selector timeout with a misleading cause."
			echo "::error::A missing bundle returns HTTP 200 text/html, not 404."
			exit 1
			;;
	esac
fi

echo "[ci-seed] done — hrmq register, schemas and object collections provisioned and verified."
