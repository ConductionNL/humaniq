# Tasks — hrmq hours-process redesign

Ordering is load-bearing: V (verify) gates everything; D (dependencies) can run in parallel in
their own repos; sections 1–7 are hrmq work. Every task is small enough to verify on its own.

## V. Verify the design's two load-bearing assumptions (BLOCKING — before any implementation)

- [x] V1 PHPUnit proof (in a throwaway test against the local `openregister` checkout) that a
      listener on `ObjectCreatingEvent`/`ObjectUpdatingEvent` can mutate the payload and the
      mutation persists into the saved object. **Must include a must-fail control**: run once with
      the mutation (assert persisted) and once without (assert absent) — a check that cannot fail
      proves nothing. If mutation does NOT propagate, switch Decisions 4/5 to the documented
      fallback (`ObjectTransitionedEvent` stamping + emission move) BEFORE starting section 2, and
      update design.md.
- [ ] V2 Confirm in the running dev instance that `fieldsFromSchema()`-driven create dialogs on a
      `CnIndexPage` honour `includeFields` + `fieldOverrides` exactly as read from
      `node_modules/@conduction/nextcloud-vue/src/components/CnIndexPage/CnIndexPage.vue:288-305`
      — open any existing index page with a temporary `includeFields` config and assert the
      dialog renders only the allowlisted fields. (Probe through the SHIPPING render path, not a
      unit harness.)

## D. Dependency work items (separate repos — file as issues/PRs there, link back here)

- [ ] D1 **openregister**: `POST /api/objects/{id}/transition` accepts optional `data`;
      `TransitionEngine` merges only keys allowlisted by
      `x-openregister-lifecycle.transitions.<action>.inputs` into the carrying write; unknown key
      → 400; missing `required` input → 422. Unit tests both ways.
- [ ] D2 **nextcloud-vue**: `CnLifecycleActions` transition-input dialog (own component under
      `src/dialogs/`, modal-isolation rule) for transitions declaring `inputs`; POSTs
      `{action, data}`; plain-button behaviour unchanged when no `inputs`. Vitest cover.
- [ ] D3 **nextcloud-vue** (optional, non-blocking): `type: "transition"` row action in
      `CnIndexPage/manifestActionDispatch.js` for queue-row approve/reject. Do not gate anything
      in this change on D3.

## 1. Register fragments (schema work — no behaviour yet)

- [x] 1.1 `lib/Settings/register.d/hr-timesheet.json`: add the `TimeEntry` schema exactly per
      design.md Decision 1 (properties, required set, `origin` as the only `readOnly: true`,
      user-oriented titles/descriptions, ADR rationale in `x-notes`). Schema version starts
      0.1.0.
- [x] 1.2 Same file: rework `Timesheet` per Decision 2 — add `entryCount`; rewrite every
      `description` user-oriented with rationale moved to `x-notes`; add
      `transitions.submit.requires: OCA\\Hrmq\\Lifecycle\\TimesheetNotEmptyGuard`; keep the
      lifecycle block and `NoSelfApprovalGuard` wiring otherwise byte-identical. Bump Timesheet
      0.5.0 → 0.6.0 and the register `info.version` in `lib/Settings/hrmq_register.json`.
- [x] 1.3 `lib/Settings/register.d/hr-cost-rate.json`: move the `domainObjectRef` /
      `domainObjectType` / `allocationKey` extension from `Timesheet` to `TimeEntry`; keep
      denormalized aggregate copies on `Timesheet` only if the shillinq consumer contract needs
      them (check `PayrollGLPostService` usages first; if unused, drop with a note).
- [x] 1.4 Acceptance check for Decision 10: `description` values in both fragments contain no
      "ADR-" / spec-citation strings (grep), and `python3 -m json.tool` passes on both.
- [ ] 1.5 `occ` re-import on the dev instance; confirm both schemas land and existing seed
      objects still validate (additive + role changes only — nothing renamed).

## 2. Guards, listeners, services (+ unit tests, one test class per production class)

- [x] 2.1 `lib/Service/OrgResolutionService.php`: extract the employee→active-assignment→unit
      chain (manager `nextcloudUserId`, unit `costCenter`) from the logic
      `lib/Standards/Checks/NlOrgChecks.php` audits, and refactor `NlOrgChecks` to consume it so
      stamp and audit share one code path. Unit tests: resolving, vacuous (missing hop), expired
      assignment.
- [x] 2.2 `lib/Lifecycle/TimesheetNotEmptyGuard.php` (`LifecycleGuardInterface`): deny `submit`
      when `entryCount` < 1 or `hours` <= 0; fail-closed on missing aggregates; Dutch
      user-facing message. Unit tests: allow, deny-empty, deny-zero-hours, deny-missing.
- [x] 2.3 `lib/Listener/TimeEntryStampListener.php` (pre-save, schema `timeentry`): Decision 5 —
      employeeId resolution, userId/administrationId/costCenter stamps, hours derivation with
      422 on invalid span, timesheet find-or-create (month grain), parent-mutability refusal
      (Decision 3). Unit tests per behaviour incl. the refusal path and the
      submitted-parent-conflict message.
- [x] 2.4 `lib/Listener/TimesheetProcessStampListener.php` (pre-save, schema `timesheet`):
      Decision 4 — create-sanitise, update-inertness (client process-field input restored from
      stored), edge stamping (submit/approve/reject/reopen), reject-edge `rejectionReason`
      allowlist, internal-writer marker. Unit tests: every edge, the inertness case (client
      writes `approvedBy` with no edge → stored value survives), and the marker exemption.
- [x] 2.5 `lib/Listener/TimesheetAggregateListener.php` (post-save + delete, schema `timeentry`):
      Decision 3 recompute of `hours`/`entryCount`/`projectId`/`costCenter`/`billable`, old+new
      parent on reparent. Unit tests assert recomputed VALUES (sum, homogeneous-vs-null,
      all-billable) — not merely that a write occurred — plus the no-loop property (a Timesheet
      write triggers no TimeEntry recompute).
- [x] 2.6 Register all listeners in `lib/AppInfo/Application.php` next to the existing
      `TimesheetApprovalListener` registration (line ~286). Confirm `composer check:strict`
      passes; fix any pre-existing findings encountered in touched files.
- [x] 2.7 Regression tests for the event contract: extend
      `TimeEntryEventService` tests to assert (a) an aggregation write (approved → approved, new
      hours) emits nothing, and (b) a stamped approval write emits `approvedBy`/`approvedAt`
      non-empty. No production change to `TimeEntryEventService` expected.

## 3. Migration repair step

- [x] 3.1 `lib/Repair/MigrateHoursProcess.php` per Decision 9 (backfill caches, synthesize
      `origin: "migration"` entries, recompute via the shared aggregate service, single summary
      log line). Register under `<post-migration>` AND `<install>` in `appinfo/info.xml`.
- [x] 3.2 Unit tests: (a) the three seed-shaped rows — jansen gains nothing (userId already
      `admin`), devries/bakker keep `userId: null` and are counted `K=2` in ONE summary line;
      (b) entry synthesis for `hours > 0` + zero entries, none for a timesheet that has entries;
      (c) **run twice** — second run reports `M=0`, zero new writes (idempotency is asserted, not
      assumed); (d) approved-row entries are created despite the mutability guard (marker path).
- [ ] 3.3 Run on the dev instance; verify the 3 existing rows: aggregates recomputed, one
      migration entry each, summary line correct.

## 4. Manifest fragments (pages + forms — builds on hrmq-manifest-fragment-pipeline)

- [ ] 4.1 Edit `src/manifest.d/hr-timesheet.json`: apply the Decision 8 modifications to
      `MijnUren`, `Timesheets`, `TimesheetApproval`, `TeamUrengoedkeuring`, `TimesheetDetail`
      (exact configs in the design table — includeFields allowlists, actionToggles, stat-widget
      re-point, "Urenboekingen" object-list widget, `includeFields: ["description"]` edit form).
- [ ] 4.2 New `src/manifest.d/hours-process-redesign.json` (per-change fragment, ADR-037):
      `MijnUrenstaten`, `TimeEntries`, `TimeEntryDetail` pages + menu leaf additions
      (`MijnHrGroup` after `MijnUren`; `TimesheetsGroup` before `Timesheets`) via the
      re-declare-group-by-id mechanism. No `deepLinks`/`runtime`/`dependencies` keys in the
      fragment (silently dropped — grep-check).
- [ ] 4.3 `node tests/validate-manifest.js` passes with the new page count printed; every new
      route resolves in the running app (routable AND reachable: click each new menu entry —
      never assert from the manifest alone).
- [ ] 4.4 Form allowlist verification in the browser: `MijnUren` create dialog shows EXACTLY
      `startedAt, endedAt, breakMinutes, description, projectId, billable` — assert the expected
      field list, and separately assert `status`/`approvedBy`/`employeeId` are absent (check for
      the expected value AND the forbidden ones). Repeat for `TimeEntries` (adds `employeeId`)
      and `TimesheetDetail` edit (`description` only).
- [ ] 4.5 After D1+D2 land: declare `inputs` on the `reject` transition in the schema fragment,
      add the `transitions` config to `TimesheetDetail.lifecycleActions`, and verify the reject
      dialog captures a reason that lands in `rejectionReason` (blocked-on-D1/D2 — keep as an
      unchecked tail task if the dependencies slip; nothing else depends on it).

## 5. Seed data

- [ ] 5.1 `lib/Settings/register.d/hr-seed.json`: add 2–3 `TimeEntry` seed objects per seeded
      Timesheet (summing to the timesheet's `hours`, realistic start/stop values,
      `origin: "manual"`), so a fresh dev login shows a populated booking list and the aggregate
      pipeline is demonstrated. Keep every identifier an obvious placeholder. Do NOT stamp
      process fields the listeners now own — let the import path exercise them where possible;
      where the seed importer bypasses listeners, keep the seeds' existing literal values and
      note it.
- [ ] 5.2 Verify seeded state on a clean env (`/clean-env`): `MijnUren` (admin) lists jansen's
      entries; `MijnUrenstaten` lists the jansen timesheet; devries/bakker rows absent from both
      (fail-closed, userId null).

## 6. e2e (Playwright — `tests/e2e/spec-coverage/`, CI seeds via `tests/e2e/ci-seed.sh`, which
      refuses :8080 by design; use the resolved router base per `core-journeys.spec.ts`)

- [ ] 6.1 New `tests/e2e/spec-coverage/hours-process.spec.ts` referencing, by verbatim scenario
      name in comments, every non-excluded scenario of this change's spec deltas (gate-19
      traceability). Journeys: (a) book hours via the `MijnUren` create dialog and assert the
      exact form field set (expected present AND process fields absent); (b) the parent
      timesheet's `hours`/`entryCount` on `MijnUrenstaten` reflect the booking; (c) submit from
      `TimesheetDetail`, assert `submittedAt` renders non-empty in the Goedkeuring panel;
      (d) approve the seeded OTHER-employee submitted timesheet from the approval queue →
      detail → Approve, assert status `approved` and `approvedBy` rendered (NoSelfApprovalGuard
      makes admin-approves-own impossible — use the seeded `managerUserId: "admin"` row);
      (e) an approved timesheet's entries show no edit affordance.
- [ ] 6.2 Update `core-journeys.spec.ts` test "Timesheets index renders add button and
      list-or-empty" — the Add button is now disabled/absent by design; re-point the assertion
      to the new expected state (and to `TimeEntries` for a positive add-button case).
- [ ] 6.3 Extend `tests/e2e/ci-seed.sh` only if the register import does not already carry the
      new seeds (it should — seeds live in the register fragments); verify by running the suite
      against a fresh CI-style instance, not by reading the script.

## 7. Close-out

- [ ] 7.1 `composer check:strict` + full PHPUnit + `npm run test` green; run the hydra gates
      wrapper (`scripts/run-hydra-gates.sh`) — gate-16 (`@spec` tags on every new/changed
      method, pointing at this change's spec deltas) and gate-19 (scenario traceability) clean.
- [ ] 7.2 Update `docs/` feature docs for the hours process (booking vs timesheet vs approval)
      if `docs/` covers timesheets today (check first — do not create docs that don't exist).
- [ ] 7.3 Migration re-run proof on the dev instance (run repair twice, diff object counts).
- [ ] 7.4 Hand the D1/D2/D3 issue links + open questions 1–3 (design.md) back to the
      orchestrator before archiving.
