---
capability: performance-reviews
status: done
built_by: openspec/changes/archive/2026-07-13-performance-reviews-mvp
---

# performance-reviews Specification

**Status**: done
**Scope**: hrmq
**OpenSpec changes**:
- [performance-reviews-mvp](../../changes/archive/2026-07-13-performance-reviews-mvp/) _(archived 2026-07-13)_ — `PerformanceReview` schema (goals inside the review, no Goal entity) with declarative concept→ingediend→besproken→vastgesteld/heropenen lifecycle, `NoSelfApprovalGuard` reuse on vaststellen, `nl-performance-dossiervorming` corpus rule + `NlPerformanceChecks` provider, EmployeeDetail Beoordelingen row + PerformanceReviewDetail + MijnBeoordelingen, seeded complete + intentionally incomplete reviews (kind: config)

## Purpose

The dossier half of the performance-review MVP: the `PerformanceReview`
object per employee per cycle, carrying rating, sterktes,
ontwikkelpunten, afspraken and a goals array **inside** the review (one
dossier document, one lifecycle, one retention context — no separate Goal
entity; the OKR follow-up owns cross-cycle goals), with separation of
duties on `vaststellen` via the existing `NoSelfApprovalGuard` and the
machine-checkable `nl-performance-dossiervorming` rule (BW 7:669 lid 3
sub d — a vastgestelde beoordeling without rating + afspraken is no
ontslagdossier; severity recommended, audit-only). Surfaces per ADR-001
Rule 6: the Beoordelingen row on the personnel dossier (`EmployeeDetail`),
`PerformanceReviewDetail`, and the `MijnBeoordelingen` `userId=@me`
self-service view (round-2 denormalized-uid pattern). Grounded in Spectr
canonicalFeatures `hrmq-canon-performance-reviews` (6/9 coverage) and
`hrmq-canon-goals-okr`. OKR/9-box/kalibratie, comp-cycles and career
frameworks are explicitly out of scope (separate drafts).

## Requirements

### Requirement: Reviews are dossier-anchored register objects with goals inside and separation of duties on vaststellen (REQ-PRV-000)

The review capability MUST consist solely of the `PerformanceReview` schema
in `lib/Settings/register.d/hr-performance.json` (goals array inside the
review — no `Goal` schema anywhere; declarative lifecycle with
`vaststellen.requires` the existing `OCA\Hrmq\Lifecycle\NoSelfApprovalGuard`,
reused unchanged), one `recommended`-severity corpus rule
(`nl-performance-dossiervorming`) with its check provider, manifest surfaces
anchored on the personnel dossier per ADR-001 Rule 6 (EmployeeDetail row,
`PerformanceReviewDetail`, `MijnBeoordelingen` via the denormalized
`userId`), and seed data. It MUST NOT introduce a new menu group, a new
guard class, or any OKR/9-box/kalibratie/comp-cycle object.

#### Scenario: Dossier-anchored surface, reused guard

- GIVEN the hrmq codebase with this capability applied
- WHEN `src/manifest.json`, `lib/Settings/register.d/` and `lib/Lifecycle/` are inspected
- THEN the review surface hangs off `EmployeeDetail`/`MijnHrGroup` with no new menu group, `hr-performance.json` declares no `Goal` schema, and `vaststellen` references the pre-existing `NoSelfApprovalGuard` FQCN with no new guard class added
- @e2e exclude structural manifest/register assertion with no user-observable flow of its own; covered by check:manifest and the delta spec's scenarios

### Requirement: A new `PerformanceReview` schema SHALL model the review dossier with goals inside the review (REQ-PRV-001)

The fragment `lib/Settings/register.d/hr-performance.json` SHALL declare `PerformanceReview` (version 0.1.0, icon `ClipboardAccountOutline`, `x-schema-org: schema:Review`): `employeeId` (string, format uuid, `$ref` Employee, required — the employee under review; description documents that it drives the self-approval guard), `cycleId` (string, format uuid, `$ref` ReviewCycle, required), `reviewerId` (string, format uuid, `$ref` Employee, nullable — the manager conducting the gesprek), `status` (enum `concept`/`ingediend`/`besproken`/`vastgesteld`, default `concept`, required), `rating` (enum `onvoldoende`/`matig`/`voldoende`/`goed`/`uitstekend`, nullable — nullable by design D8: completeness at vastgesteld is the audit rule, not a schema constraint), `sterktes` (string, nullable), `ontwikkelpunten` (string, nullable), `afspraken` (string, nullable — concrete afspraken/verbetertraject; description cites BW 7:669 lid 3 sub d), `goals` (array of objects `{titel: string required, status: enum open/behaald/vervallen default open, toelichting: string nullable}`, default `[]` — description states the D2 decision: goals live inside the review so they freeze with the dossier under one lifecycle, one audit trail and one retention context; no separate Goal entity anywhere), `besprokenOp` (string, format date, nullable — stamped on the carrying write of `bespreken`, the Timesheet `approvedAt` pattern), `vastgesteldDoor` (string, nullable — NC uid of the vaststeller, the `approvedBy` convention, never a `$ref`, stamped on the carrying write of `vaststellen`), `userId` (string, nullable — denormalized NC-user-id copy of the linked Employee's `nextcloudUserId`, the mijn-hr-self-service round-2 pattern, never a `$ref`; drives the MijnBeoordelingen `@me` page). Required: `employeeId`, `cycleId`, `status`. Every property carries title + description (gate-28).

#### Scenario: Goals are stored inside the review
- **GIVEN** a PerformanceReview written with `goals: [{"titel": "Certificering afronden", "status": "behaald", "toelichting": "Afgerond in mei."}]`
- **THEN** the goals array round-trips on the object; **AND** no `Goal` schema exists anywhere in the register configuration

#### Scenario: Incomplete review rejected
- **WHEN** a PerformanceReview is written without `employeeId` or `cycleId`
- **THEN** OpenRegister schema validation rejects it (required-property violation)

### Requirement: The review lifecycle SHALL be declarative and `vaststellen` SHALL require the existing `NoSelfApprovalGuard` (REQ-PRV-002)

`PerformanceReview.configuration` SHALL declare `x-openregister-lifecycle`: `field: status`, `initial: concept`, terminal `[]` (the Timesheet precedent — vastgesteld is re-openable), transitions `indienen` (concept→ingediend), `bespreken` (ingediend→besproken; description documents that `besprokenOp` is stamped on the carrying write), `vaststellen` (besproken→vastgesteld; **`requires: OCA\Hrmq\Lifecycle\NoSelfApprovalGuard`**; description documents that `vastgesteldDoor` is stamped on the carrying write) and `heropenen` (vastgesteld→besproken — the correction edge; every path back to vastgesteld passes the guard again). No other edges. The guard is reused **unchanged**: its contract — deny when the acting user equals the object's `employeeId`, fail closed on unknown actor or employee — is exactly the review separation-of-duties rule (the employee under review must not vaststellen their own beoordeling), and `PerformanceReview` deliberately names its subject field `employeeId` to match the guard's Timesheet/Expense contract. The guard's class docblock SHALL be updated to name the third consumer; no behavioural change to the guard. `indienen`/`bespreken`/`heropenen` carry no guard.

#### Scenario: Review walks the full lifecycle
- **GIVEN** a PerformanceReview in status `concept`
- **WHEN** `indienen`, then `bespreken` (carrying write sets `besprokenOp`), then `vaststellen` (executed by a user whose id differs from `employeeId`; carrying write sets `vastgesteldDoor`) are executed
- **THEN** the object ends in status `vastgesteld` with `besprokenOp` and `vastgesteldDoor` populated

#### Scenario: Self-vaststellen denied
- **GIVEN** a PerformanceReview in status `besproken` whose `employeeId` equals the acting user's id
- **WHEN** the `vaststellen` transition is attempted
- **THEN** `NoSelfApprovalGuard` denies the transition and the object stays `besproken`

#### Scenario: Heropenen allows correction
- **GIVEN** a PerformanceReview in status `vastgesteld`
- **WHEN** `heropenen` is executed
- **THEN** the status is `besproken` again and `vaststellen` (guard included) is the only path back to `vastgesteld`

### Requirement: A machine-checkable dossiervorming rule SHALL flag vastgesteld reviews without rating + afspraken (REQ-PRV-003)

`lib/Standards/rules/labour.json` SHALL gain rule `nl-performance-dossiervorming`: `domain: labour`, `jurisdiction: NL`, `framework: bw7-10`, source "BW art. 7:669 lid 3 sub d (redelijke grond disfunctioneren, ontslagrecht via Wet werk en zekerheid); vaste rechtspraak over dossieropbouw/verbetertraject", `sourceUrl: https://wetten.overheid.nl/BWBR0005290`, `severity: recommended` (no statute obliges recording a rating — but without the documented dossier a sub-d dismissal fails at the kantonrechter), `machineCheckable: true`, `effectiveDate: 2015-07-01`; statement: a `vastgesteld` PerformanceReview must carry a non-null `rating` and non-empty `afspraken`. A new check provider `lib/Standards/Checks/NlPerformanceChecks.php` (SPDX docblock, auto-discovered by `RuleEngine::providers()`) SHALL register the predicate, evaluated only on `status: vastgesteld` — all other statuses pass vacuously (an unfinished review legitimately lacks a rating). `RuleCatalogue::VERSION` bumps `2026-07.7` → `2026-07.8` (the HEAD value was re-verified at apply time — prior merges had already advanced it past the design's `2026-07.5`→`2026-07.6` baseline); violations surface via the existing `occ hrmq:rules:audit`.

#### Scenario: Complete vastgesteld review passes
- **GIVEN** a PerformanceReview with status `vastgesteld`, rating `goed` and non-empty `afspraken`
- **WHEN** the rule audit runs
- **THEN** `nl-performance-dossiervorming` reports no violation for the object

#### Scenario: Vastgesteld without rating violates
- **GIVEN** a PerformanceReview with status `vastgesteld` and `rating: null` (or empty `afspraken`)
- **WHEN** the rule audit runs
- **THEN** `nl-performance-dossiervorming` reports a `recommended`-severity violation naming the object

#### Scenario: Unfinished review passes vacuously
- **GIVEN** a PerformanceReview in status `concept`, `ingediend` or `besproken` without a rating
- **WHEN** the rule audit runs
- **THEN** no violation is reported (the rule only evaluates vastgesteld reviews)

### Requirement: The personnel dossier SHALL anchor the review surface per ADR-001 Rule 6 (REQ-PRV-004)

`src/manifest.json` SHALL add to `EmployeeDetail` an object-list widget `emp-reviews` ("Beoordelingen", icon `ClipboardAccountOutline`; schema `PerformanceReview`, filter `employeeId: @objectId`, sort `besprokenOp` desc, columns `cycleId`/`status`/`rating`/`besprokenOp`, `rowRoute: PerformanceReviewDetail`) as a full-width layout row inserted before the personnel-file Files leaf, which shifts down — the exact `emp-assignments` insertion pattern of the org-chart build. The row deliberately carries no `viewAllRoute`: there is no org-wide reviews index in the MVP (reviews are dossier-anchored per ADR-001 Rule 6; the per-cycle list lives on `ReviewCycleDetail`). The manifest SHALL further add `PerformanceReviewDetail` (detail over `PerformanceReview`, route `/reviews/:id`, **not** a menu child — the TimesheetDetail convention): a "Beoordeling" data widget (rating/sterktes/ontwikkelpunten/afspraken/goals/besprokenOp/vastgesteldDoor; exclude `employeeId`/`cycleId`/`reviewerId` — Related resolves them — and `userId`), a related widget, `lifecycleActions` exposing **exactly** `indienen`/`bespreken`/`vaststellen`/`heropenen` with Dutch labels, and an audit-history sidebar tab; a page `_note` documents the goals-inside-review decision and the guard on vaststellen. A deepLink `PerformanceReview` → `/apps/hrmq/reviews/{uuid}` is registered and `src/icons.js` registers `ClipboardAccountOutline`. No new menu group and no new top-level menu entry is created anywhere in this change. The manifest MUST validate (`npm run check:manifest`).

#### Scenario: Dossier shows the employee's reviews
@e2e exclude declarative widget wiring is covered by the shared CnPageRenderer library tests; app-level e2e suite does not exist yet (tracked by active change hrmq-test-coverage-baseline)
- **GIVEN** a seeded employee with one PerformanceReview
- **WHEN** `EmployeeDetail` renders for that employee
- **THEN** the "Beoordelingen" row lists the review and its row navigates to `PerformanceReviewDetail`

#### Scenario: No invented edges
- **WHEN** the manifest's `lifecycleActions` for `PerformanceReviewDetail` are compared to the `x-openregister-lifecycle` in `hr-performance.json`
- **THEN** they match action-for-action (`indienen`/`bespreken`/`vaststellen`/`heropenen`, same from/to) with no additional action

### Requirement: Employees SHALL see their own reviews via `MijnBeoordelingen` (REQ-PRV-005)

`src/manifest.json` SHALL add child `MijnBeoordelingen` ("Mijn beoordelingen", icon `StarCheckOutline`, registered in `src/icons.js`) to the **existing** `MijnHrGroup`, backed by page `MijnBeoordelingen` (index over `PerformanceReview`, route `/mijn/beoordelingen`, filter `userId: @me`, columns `cycleId`/`status`/`rating`/`besprokenOp`, sort `besprokenOp` desc) — the established mijn-hr-self-service pattern (MijnUren/MijnVerlof et al.). This works because `PerformanceReview.userId` carries the denormalized NC uid per REQ-PRV-001; reviews of employees without a Nextcloud account keep `userId` null and never appear on a Mijn page (their portal view is ADR-046 portaliq territory, out of scope).

#### Scenario: Employee sees own review only
@e2e exclude declarative index filtering is covered by the shared CnPageRenderer library tests; app-level e2e suite does not exist yet (tracked by active change hrmq-test-coverage-baseline)
- **GIVEN** the seeded review with `userId: admin` and the seeded review with `userId: null`
- **WHEN** the `admin` user opens `MijnBeoordelingen`
- **THEN** exactly the `userId: admin` review is listed

### Requirement: Seed data SHALL provide one complete and one intentionally incomplete vastgesteld review (REQ-PRV-006)

`lib/Settings/register.d/hr-seed.json` SHALL gain two PerformanceReviews, both `cycleId: review-cycle-2026` and both `vastgesteld` (placeholder content only): (1) `review-jansen-2026` — employeeId `employee-jansen`, reviewerId `employee-visser`, rating `goed`, placeholder sterktes/ontwikkelpunten, non-empty afspraken, a two-entry `goals` array (one `behaald`, one `open`), besprokenOp `2026-06-15`, vastgesteldDoor `manager-pietersen` (the seeds' established NC-uid placeholder), **userId `admin`** (copy of jansen's `nextcloudUserId` — feeds MijnBeoordelingen for the dev login) — the complete dossier; (2) `review-visser-2026` — employeeId `employee-visser`, reviewerId null, **rating null**, non-empty afspraken, `goals: []`, besprokenOp `2026-06-20`, vastgesteldDoor `manager-pietersen`, userId null — the intended violation. The seeded audit MUST show exactly one new violation: `nl-performance-dossiervorming` (recommended) on `review-visser-2026`; no pre-existing rule may regress. Seeds MUST import idempotently via the register Repair step.

#### Scenario: Seeded audit shows exactly the intended violation
- **WHEN** the rule audit runs against the seeded register
- **THEN** `review-visser-2026` carries exactly one `nl-performance-dossiervorming` violation, `review-jansen-2026` carries none, and no pre-existing payroll/labour/privacy rule reports a new violation

#### Scenario: Idempotent seed
- **WHEN** the register Repair import runs twice
- **THEN** each review exists exactly once
