# Design — performance-reviews-mvp

## Context

hrmq has no performance surface: no cycle or review object anywhere in the register. Two spec-branch drafts exist — `origin/spec/performance-management-advanced` (OKR cascade, 9-box talent grid, kalibratie sessions, continuous feedback, RewardLink; six actor roles; its own REQ-001…REQ-015) and `origin/spec/comp-planning-cycle` (CompCycle, BudgetAllocatie, pay-equity audits, compensation letters, payroll mutation) — both written before the declarative platform landed and both far past an MVP. ADR-001 Rule 6 (`openspec/architecture/adr-001-information-architecture.md`) already froze the placement decision for exactly these two drafts: *"performance-management-advanced (OKR/9-box/kalibratie) and comp-planning-cycle (jaarlijkse comp-cyclus) live as DETAIL_TAB rows on Medewerkers › Functie & comp. There is no 10th top-level 'Performance' menu."* This change cuts the performance half down to the platform-native MVP and leaves the comp half untouched as its own future change.

Market grounding: Spectr canonicalFeatures `hrmq-canon-performance-reviews` (6/9 competitor coverage — review cycles are a core module of Personio/BambooHR-class SMB suites) and `hrmq-canon-goals-okr` (covered here in its lightest form: goals inside the review). Legal grounding: BW 7:669 lid 3 sub d (redelijke grond d — disfunctioneren, the WWZ ontslagrecht system) makes a documented beoordelingsdossier — vastgestelde beoordelingen with a rating and concrete afspraken — the precondition for any underperformance dismissal; `https://wetten.overheid.nl/BWBR0005290` (BW Boek 7).

Verified at HEAD before designing:
- Fragment format: `lib/Settings/register.d/*.json` with `x-hrmq-fragment` + `components.schemas`; lifecycle in each schema's `configuration` under `x-openregister-lifecycle` (`field`/`initial`/`terminal`/`transitions`, transitions may carry `requires` with a guard FQCN — see `hr-timesheet.json` `Timesheet.approve`).
- `lib/Lifecycle/NoSelfApprovalGuard.php`: implements `LifecycleGuardInterface::check(array $object, string $action, string $userId)`; denies when `$userId === (string) $object['employeeId']`, and **fails closed** when the acting user or `employeeId` is empty. Shared today by Timesheet and Expense `approve`/`reject`.
- `RuleCatalogue::VERSION` is `2026-07.5`; `lib/Standards/rules/labour.json` (`domain: labour`, 15 rules) is the labour-law corpus file; SCHEMA.md severity vocabulary is `mandatory`/`conditional`/`recommended` and `bw7-10` is an established framework slug; check providers in `lib/Standards/Checks/` are auto-discovered.
- Register `lib/Settings/hrmq_register.json` `info.version` is `0.5.0`.
- Manifest: `EmployeesGroup` ("Personeel", order 90) children are Employees/EmploymentContracts/OrgUnits/OrgAssignments/GeneratedDocuments; `MijnHrGroup` (order 20) children are the five `userId=@me` self-service indexes; `EmployeeDetail` widgets end with `emp-assignments` (full-width row, gridY 23) then `emp-files` (gridY 27) — the org-chart-basic addition pattern reused here.
- Round-2 self-service pattern (`hr-timesheet.json`/`hr-leave.json`/`hr-attendance.json`): `userId` is a **denormalized plain NC-user-id string**, a copy of the linked Employee's `nextcloudUserId`, never a `$ref`, carried because the manifest filter grammar cannot join across schemas; the Mijn page filters on it with `@me`. Seed `employee-jansen` has `nextcloudUserId: "admin"`.
- Icons `CalendarSyncOutline`, `ClipboardAccountOutline`, `StarCheckOutline` exist in `vue-material-design-icons` at HEAD.

## Goals / Non-Goals

**Goals:** ship the ADR-001 Rule 6 dossier surface — a `ReviewCycle` container and a `PerformanceReview` dossier object on declarative lifecycles, separation of duties on `vaststellen` via the existing guard, one machine-checkable dossiervorming rule, the EmployeeDetail Beoordelingen row + cycle pages + employee self-view, and seeds that exercise the intended violation.

**Non-Goals:** OKR cascade / 9-box / kalibratie / continuous feedback / RewardLink (the `performance-management-advanced` draft — future change), the comp-planning cycle (`comp-planning-cycle` draft — explicitly a separate future change; nothing here touches compensation), career/functie frameworks and competency matrices (separate draft territory), review templates/question forms, 360°/peer feedback or a self-assessment entity, notifications/reminders (ADR-031/gate-18 app-wide deferral), manager-scoped visibility enforcement (register-wide RBAC posture, not per-change).

## Decisions

### D1 — MVP re-scope: two schemas, not the drafts' entity zoo

The advanced draft models OKR/KeyResult/NineBoxAssessment/CalibrationSession/Feedback/RewardLink; the comp draft adds CompCycle/BudgetAllocatie/PayEquityCheck. The MVP ships exactly two schemas — `ReviewCycle` (the orchestration container) and `PerformanceReview` (the dossier document) — in a new fragment `hr-performance.json`, driven entirely through the manifest renderer and the objects API (ADR-022 — no app-owned CRUD, no bespoke tables/services/controllers). Every advanced entity is a named non-goal, not a half-built schema. `ReviewCycle` is deliberately generic (type enum covers jaargesprek/beoordeling/tussentijds) so the future comp change can reference cycles rather than reinvent them.

### D2 — Goals live INSIDE the review; no separate `Goal` entity

The `goals` array (`{titel, status: open/behaald/vervallen, toelichting}`) is a property of `PerformanceReview`, mirroring the recruiting MVP's PII-inside-Application decision:

- **One dossier document, one lifecycle, one retention context**: under BW 7:669 lid 3 sub d the goals/afspraken ARE the dossier — they must be frozen with the review they were agreed in, walk the same concept→vastgesteld lifecycle, live under the same audit trail, and (later) leave under the same personnel-dossier retention regime. A free-standing Goal entity would need its own lifecycle, its own retention reasoning, and cross-object consistency rules the single-object RuleEngine cannot audit.
- **ADR-001 Rule 6 anchoring**: Rule 6 exists because performance data anchors on the personnel dossier; a Goal entity is the first step toward the OKR module Rule 6 explicitly rejects as a top-level surface. When the OKR follow-up lands, *that* change introduces the Goal/OKR entity and migrates; the MVP array is its documented predecessor, not a competitor.
- **Fewer schemas**: no goal index page, no orphan-goal cleanup, no goal↔review linking UI.

Trade-off: goals cannot be tracked across cycles (a carried-over goal is re-entered in the next review) and cannot be individually referenced. Acceptable at MVP scope; the OKR draft is the declared successor for cross-cycle goal tracking.

### D3 — Both lifecycles are declarative; stamps ride the carrying write

`ReviewCycle`: `x-openregister-lifecycle` on `status`, initial `concept`, transitions `openen` (concept→open) and `sluiten` (open→gesloten), terminal `gesloten` — a closed cycle is history, not reopenable (the Vacancy no-resurrection precedent); a correction means a new cycle.

`PerformanceReview`: initial `concept`, transitions `indienen` (concept→ingediend — the reviewer submits the draft verslag), `bespreken` (ingediend→besproken — the gesprek has taken place; **`besprokenOp` is stamped on the carrying write**, the Timesheet `approvedAt` pattern), `vaststellen` (besproken→vastgesteld — **`requires: OCA\Hrmq\Lifecycle\NoSelfApprovalGuard`** per D4; **`vastgesteldDoor` (NC uid, the `approvedBy` convention) is stamped on the carrying write**), and `heropenen` (vastgesteld→besproken — the correction edge: a vastgesteld verslag that turns out wrong is reopened to the besproken state, corrected, and vastgesteld again; the audit trail preserves every version). Terminal: `[]` (the Timesheet precedent — vastgesteld is re-openable, so no state is declared terminal). No invented edges: no skip from concept to besproken, no un-indienen; the manifest must expose exactly these four actions (`PayrollRunDetail` `_note` precedent).

### D4 — `vaststellen` reuses `NoSelfApprovalGuard` unchanged; the semantics fit

The guard's contract is: deny the transition when the acting user (`$userId`) equals the object's `employeeId`; fail closed when either is unknown. That is *exactly* the review separation-of-duties rule — the employee a beoordeling is about must never be the one who vaststelt it. `PerformanceReview` deliberately names its subject field `employeeId` (the same name and semantics as Timesheet/Expense), so the guard applies **unchanged** via `x-openregister-lifecycle.transitions.vaststellen.requires`. Known shared caveat, documented rather than solved: the guard compares the acting NC uid against the stored `employeeId` value — the identical comparison Timesheet/Expense already ship, with the identical reach; tightening actor-identity resolution (uid ↔ Employee.nextcloudUserId) is guard-wide work owned by `hrmq-rule-compliance-enforcement`, not forked here. The guard's docblock ("shared by the Timesheet and Expense…") is updated to name the third consumer; its Dutch deny-message is generic enough ("U mag uw eigen … niet goedkeuren") to reuse without behavioural change. `heropenen`/`indienen`/`bespreken` carry no guard — the employee legitimately participates in those steps.

### D5 — One corpus rule in labour.json (ontslagrecht = labour law), severity `recommended`

Dossiervorming is labour law (BW Boek 7 titel 10, ontslagrecht), not privacy or payroll — so the rule goes in the existing `lib/Standards/rules/labour.json`, not a new file (SCHEMA.md: one file per sub-domain; the ATS change created privacy.json because AVG was a *new* sub-domain — this is not). Severity is `recommended`, not `mandatory`: no statute obliges an employer to record a rating — the norm is *conditional in effect* (without a documented dossier, a 7:669 lid 3 sub d dismissal fails at the kantonrechter), which SCHEMA.md's vocabulary expresses as a recommended-practice rule. `RuleCatalogue::VERSION` bumps `2026-07.5` → `2026-07.6` (opaque bump marker); the file-level `version` stays `2026-07` (accretion convention — the 15 existing labour rules accreted under it).

ADR-031 declarative/imperative split:

| behaviour | path | rationale |
|---|---|---|
| Cycle workflow (concept→open→gesloten) | **declarative** `x-openregister-lifecycle` | ADR-031 default |
| Review workflow (concept→ingediend→besproken→vastgesteld, heropenen) | **declarative** `x-openregister-lifecycle` | ADR-031 default; `besprokenOp`/`vastgesteldDoor` stamped on the carrying writes |
| Self-approval prohibition on `vaststellen` | **existing guard** `NoSelfApprovalGuard` via `requires` | the one cross-actor rule the state machine cannot express; ADR-031's LifecycleGuardInterface exception, reused not rewritten |
| Dossiervorming completeness (rating + afspraken on vastgesteld) | imperative **CheckProvider** predicate (`NlPerformanceChecks`) | domain-rule evaluation over the versioned corpus — the established ADR-031 exception; violations surface via `occ hrmq:rules:audit`, audit-only |
| Review-must-belong-to-open-cycle | **neither** — deliberately deferred | a cross-object rule (review ↔ cycle status) the single-object RuleEngine cannot evaluate; guard wiring is `hrmq-rule-compliance-enforcement` territory |
| Reminders/notifications (cycle opens, gesprek due) | **neither** — deliberately deferred | x-openregister-notifications adoption is app-wide (ADR-031/gate-18), same deferral as loonaangifte/leave/ATS |

### D6 — IA per ADR-001 Rule 6: dossier row + sub-pages on existing groups; NO new menu

- The **primary surface** is the `emp-reviews` object-list row on `EmployeeDetail` — the DETAIL_TAB placement Rule 6 prescribes, added exactly like org-chart-basic added `emp-assignments`: a full-width row before the personnel-file Files leaf, which shifts down.
- `ReviewCycles` (index) + `ReviewCycleDetail` are SUB_PAGEs under the **existing** `EmployeesGroup` ("Personeel") — cycle orchestration is HR-admin work and Rule 6 allows sub-pages; no new group, no 10th top-level menu, no ADR amendment needed.
- `PerformanceReviewDetail` is a routed detail page but **not** a menu child (the TimesheetDetail convention) — reached from the dossier row, the cycle's review list, and the deepLink.
- `MijnBeoordelingen` joins the existing `MijnHrGroup`, filter `userId: @me` — the employee's read view of their own beoordelingen.
- **Deliberate omission**: there is no org-wide "all reviews" index page — reviews are dossier-anchored (Rule 6 rationale); the browsable aggregates are per-employee (dossier row) and per-cycle (`ReviewCycleDetail`'s review list). The `emp-reviews` row therefore omits `viewAllRoute` (the app-manifest-v2 schema does not require it); implementation verifies the renderer accepts an object-list without it, and falls back to adding a status-filterable index later only if the renderer demands a target.

### D7 — `userId` on PerformanceReview: the round-2 denormalized self-service pattern, verbatim

`PerformanceReview` has no user identity of its own — `employeeId` is a `$ref` UUID and the manifest filter grammar cannot join through it to `Employee.nextcloudUserId`. So the schema carries an optional `userId` (plain NC-user-id string, nullable, **never** a `$ref`, a copy of the linked Employee's `nextcloudUserId`) exactly as Timesheet/Expense/LeaveRequest/AttendanceRecord do, with the description mirroring theirs verbatim. `MijnBeoordelingen` filters on it with `@me`. Reviews of employees without an NC account keep `userId` null and simply never appear on a Mijn page (the portal-side view is ADR-046 portaliq territory, out of scope).

### D8 — Rating is nullable by schema; completeness is an audit rule, not a hard gate

`rating` and `afspraken` stay nullable/optional in the schema: a concept/ingediend review legitimately has no rating yet, and hard-requiring them at `vaststellen` would need a new completeness guard. Consistent with the corpus' audit-only posture (ATS retention, WVP milestones), completeness-at-vastgesteld is enforced as the `nl-performance-dossiervorming` audit rule instead — a vastgesteld review missing rating or afspraken is a *visible recommended-severity violation*, not a blocked transition. If practice shows HR wants the hard gate, a completeness guard is a small follow-up under `hrmq-rule-compliance-enforcement`.

## Schema deltas

**`ReviewCycle` (new fragment `hr-performance.json`, version 0.1.0, icon `CalendarSyncOutline`, `x-schema-org: schema:Event`):** `name` (string, required — e.g. "Jaargesprekken 2026"), `year` (integer, required — the cycle's calendar year), `type` (enum `jaargesprek`/`beoordeling`/`tussentijds`, required — enum append-only), `status` (enum `concept`/`open`/`gesloten`, default `concept`, required — governed by the lifecycle), `startDate` (string, format date, nullable), `endDate` (string, format date, nullable). Required: `name`, `year`, `type`, `status`. Lifecycle per D3. Gate-28: title + description on every property.

**`PerformanceReview` (same fragment, version 0.1.0, icon `ClipboardAccountOutline`, `x-schema-org: schema:Review`):** `employeeId` (string, format uuid, `$ref` Employee, required — the employee under review; drives the self-approval guard), `cycleId` (string, format uuid, `$ref` ReviewCycle, required), `reviewerId` (string, format uuid, `$ref` Employee, nullable — the manager conducting the gesprek), `status` (enum `concept`/`ingediend`/`besproken`/`vastgesteld`, default `concept`, required), `rating` (enum `onvoldoende`/`matig`/`voldoende`/`goed`/`uitstekend`, nullable — D8; description cites the dossiervorming rule), `sterktes` (string, nullable), `ontwikkelpunten` (string, nullable), `afspraken` (string, nullable — the concrete afspraken/verbetertraject; description cites BW 7:669 lid 3 sub d), `goals` (array of objects `{titel: string required, status: enum open/behaald/vervallen default open, toelichting: string nullable}`, default `[]` — D2: goals INSIDE the review, description states the no-Goal-entity decision), `besprokenOp` (string, format date, nullable — stamped on the carrying write of `bespreken`), `vastgesteldDoor` (string, nullable — NC uid of the vaststeller, the `approvedBy` convention, stamped on the carrying write of `vaststellen`, never a `$ref`), `userId` (string, nullable — the D7 denormalized NC uid, description mirrors Timesheet's verbatim). Required: `employeeId`, `cycleId`, `status`. Lifecycle per D3/D4 with `vaststellen.requires: OCA\Hrmq\Lifecycle\NoSelfApprovalGuard`. Register `info.version` 0.5.0 → 0.6.0.

## New corpus rule (labour.json)

| id | framework | source | statement (short) | severity | machineCheckable |
|---|---|---|---|---|---|
| `nl-performance-dossiervorming` | `bw7-10` | BW art. 7:669 lid 3 sub d (redelijke grond disfunctioneren, ontslagrecht via Wet werk en zekerheid); vaste rechtspraak over dossieropbouw/verbetertraject | A `vastgesteld` PerformanceReview must carry a non-null `rating` and non-empty `afspraken` — without a documented beoordeling + concrete afspraken there is no ontslagdossier | recommended | true |

`domain: labour`, `jurisdiction: NL`, `effectiveDate: 2015-07-01` (WWZ ontslagrecht in force), `sourceUrl: https://wetten.overheid.nl/BWBR0005290`. Check: `NlPerformanceChecks` registers one `PerformanceReview` predicate, evaluated only on `status: vastgesteld` (all other statuses pass vacuously — an unfinished review legitimately lacks a rating); no parameters needed. `RuleCatalogue::VERSION` → `2026-07.6`.

## Manifest delta

- **deepLinks**: `ReviewCycle` → `/apps/hrmq/review-cycles/{uuid}`, `PerformanceReview` → `/apps/hrmq/reviews/{uuid}`.
- **`EmployeeDetail`**: new object-list widget `emp-reviews` ("Beoordelingen", `ClipboardAccountOutline`; schema `PerformanceReview`, filter `employeeId: @objectId`, sort `besprokenOp` desc, columns `cycleId`/`status`/`rating`/`besprokenOp`, `rowRoute: PerformanceReviewDetail`, no `viewAllRoute` per D6) as a full-width row inserted before `emp-files` (gridY 27; `emp-files` shifts to 31) — the exact `emp-assignments` insertion pattern. The page `_note` gains one sentence naming the ADR-001 Rule 6 anchoring.
- **Menu**: `EmployeesGroup` gains child `ReviewCycles` ("Beoordelingscycli", `CalendarSyncOutline`); `MijnHrGroup` gains child `MijnBeoordelingen` ("Mijn beoordelingen", `StarCheckOutline`). No group changes otherwise; no new group.
- **`ReviewCycles`** (index, `/review-cycles`): columns `name`, `year`, `type`, `status`, `startDate`, `endDate`; filters `status`, `type`; sort `year` desc.
- **`ReviewCycleDetail`** (detail, `/review-cycles/:id`): data widget "Cycle" (all fields), object-list "Beoordelingen in deze cyclus" (schema `PerformanceReview`, filter `cycleId: @objectId`, columns `employeeId`/`status`/`rating`, rowRoute `PerformanceReviewDetail`), `lifecycleActions` exposing exactly `openen` ("Openen") and `sluiten` ("Sluiten"), audit-history sidebar tab.
- **`PerformanceReviewDetail`** (detail, `/reviews/:id`, not a menu child): data widget "Beoordeling" (rating/sterktes/ontwikkelpunten/afspraken/goals + besprokenOp/vastgesteldDoor; exclude `employeeId`/`cycleId`/`reviewerId` — Related resolves them — and `userId`), related widget, `lifecycleActions` exposing exactly `indienen`/`bespreken`/`vaststellen`/`heropenen` with Dutch labels, audit-history sidebar tab. A page `_note` documents D2 (goals inside the review) and D4 (guard on vaststellen).
- **`MijnBeoordelingen`** (index, `/mijn/beoordelingen`, under MijnHrGroup): schema `PerformanceReview`, filter `userId: @me`; columns `cycleId`, `status`, `rating`, `besprokenOp`; sort `besprokenOp` desc.
- **Icons**: `src/icons.js` registers `CalendarSyncOutline`, `ClipboardAccountOutline`, `StarCheckOutline` (verified present at HEAD; unregistered names silently fall back to help-circle).
- All pages validate against app-manifest-v2 (`npm run check:manifest`).

## Seed Data (ADR-001)

Extend `lib/Settings/register.d/hr-seed.json` (placeholder content only, obvious slugs):

**ReviewCycle (1):**
1. `review-cycle-2026` — name "Jaargesprekken 2026", year 2026, type `jaargesprek`, status `open`, startDate `2026-01-01`, endDate `2026-12-31`.

**PerformanceReview (2, both `cycleId: review-cycle-2026`, both `vastgesteld`):**
1. `review-jansen-2026` — employeeId `employee-jansen`, reviewerId `employee-visser`, status `vastgesteld`, rating `goed`, sterktes/ontwikkelpunten placeholder text, afspraken "Volgt in Q3 de cursus 'Advanced Vue' en neemt het onboarding-buddyschap op zich.", goals `[{titel: "Certificering Vue afronden", status: "behaald", toelichting: "Afgerond in mei."}, {titel: "Onboarding-buddy voor nieuwe collega's", status: "open", toelichting: null}]`, besprokenOp `2026-06-15`, vastgesteldDoor `manager-pietersen` (the seeds' established NC-uid placeholder, cf. Timesheet `approvedBy`), **userId `admin`** (copy of jansen's `nextcloudUserId` → appears on MijnBeoordelingen for the dev login) — the **complete** dossier: passes `nl-performance-dossiervorming`.
2. `review-visser-2026` — employeeId `employee-visser`, reviewerId null, status `vastgesteld`, **rating null**, sterktes placeholder, afspraken "Proeftijd-evaluatiedoelen vastleggen.", goals `[]`, besprokenOp `2026-06-20`, vastgesteldDoor `manager-pietersen`, userId null (visser has no NC account) — **exercises the intended `nl-performance-dossiervorming` violation** (vastgesteld without rating).

The seeded audit must show exactly one new violation: `nl-performance-dossiervorming` (recommended) on `review-visser-2026`; `review-jansen-2026` and all pre-existing seeds stay clean.

## Risks / Trade-offs

- **The incomplete seed IS a standing violation** — intentional (it proves the check); severity `recommended` keeps the demo audit from showing a false mandatory failure.
- **Guard identifier-domain caveat (D4)**: the self-approval check only bites when the acting NC uid equals the stored `employeeId` value — the identical, shipped caveat of Timesheet/Expense; fixing actor resolution is guard-wide `hrmq-rule-compliance-enforcement` work, deliberately not forked here.
- **`emp-reviews` without `viewAllRoute` (D6)**: every existing object-list row carries one; the v2 schema does not require it, but the renderer's behaviour must be verified during implementation — fallback documented in D6.
- **Goals array cannot track across cycles (D2)** — accepted; the OKR follow-up (`performance-management-advanced`) is the declared successor.
- **No open-cycle consistency check**: a review can reference a `gesloten` cycle without a violation (cross-object rule the single-object RuleEngine cannot evaluate — D5 table). Accepted; noted for the RuleEngine cross-object follow-up.
- **Fragment objects go LIVE on import** (portal-schemas precedent): seeds are obvious placeholders consistent with hr-seed.json's existing content; the review texts are deliberately bland.
- **`heropenen` reopens without a guard**: any user (including the employee) can reopen a vastgesteld review; the audit trail records it, and every path back to `vastgesteld` passes the guard again. Accepted for the MVP.

## Open Questions

- None blocking. OKR/9-box/kalibratie (`performance-management-advanced`), the comp cycle (`comp-planning-cycle`), career frameworks, review templates, 360° feedback, notifications, the completeness hard-gate and cross-object cycle-consistency checks are the declared follow-ups.
