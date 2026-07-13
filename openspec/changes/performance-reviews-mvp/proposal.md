---
kind: config
---

# Performance Reviews MVP (beoordelingscycli + gespreksverslagen op het personeelsdossier)

## Why

Spectr's canonical-feature coverage scan puts performance reviews among hrmq's largest remaining gaps: `hrmq-canon-performance-reviews` sits at 6/9 competitor coverage (Personio, BambooHR and every serious SMB HR suite ship review cycles as a core module) while hrmq has nothing — no cycle object, no review object, no surface; `hrmq-canon-goals-okr` is the adjacent canonical feature this MVP covers in its lightest form (goals recorded inside the review). Two rich drafts exist on spec branches — `performance-management-advanced` (OKR cascade / 9-box / kalibratie) and `comp-planning-cycle` (jaarlijkse comp-cyclus) — but both are far past an MVP and both were written before the declarative platform landed. Legally the MVP matters on its own: under Dutch ontslagrecht (BW 7:669 lid 3 sub d, disfunctioneren — the WWZ-era redelijke-grond system) an employer can only dismiss for underperformance with a documented dossier — vastgestelde beoordelingen with a rating and concrete afspraken/verbetertraject. Today that dossier lives in Word files; hrmq has the register, the lifecycle machinery, the `NoSelfApprovalGuard`, and the versioned rule corpus to make dossiervorming structured and machine-auditable. ADR-001 Rule 6 fixes the placement: performance & comp are **detail-tabs on the personnel dossier**, not a module — which is exactly the shape of this MVP.

## What Changes

- **New fragment `lib/Settings/register.d/hr-performance.json`** with two schemas:
  - **`ReviewCycle`** — name, year, type (enum `jaargesprek`/`beoordeling`/`tussentijds`), startDate, endDate, and a declarative `x-openregister-lifecycle` `concept → open → gesloten` (`openen` opens the cycle for reviews; `sluiten` closes it; terminal `gesloten`).
  - **`PerformanceReview`** — `employeeId` (`$ref` Employee, required), `cycleId` (`$ref` ReviewCycle, required), `reviewerId` (`$ref` Employee, nullable), rating (enum `onvoldoende`/`matig`/`voldoende`/`goed`/`uitstekend`, nullable), sterktes/ontwikkelpunten/afspraken text fields, a `goals` array of `{titel, status open/behaald/vervallen, toelichting}` objects **inside** the review (deliberately no separate Goal entity — design D2), optional `userId` (denormalized NC uid, the mijn-hr-self-service round-2 pattern), and a declarative lifecycle `concept → ingediend → besproken → vastgesteld` with a `heropenen` correction edge; `besprokenOp` and `vastgesteldDoor` are stamped on the carrying writes of `bespreken`/`vaststellen`.
- **Separation of duties on `vaststellen`** — the transition `requires` the **existing** `OCA\Hrmq\Lifecycle\NoSelfApprovalGuard`: its contract (deny when the acting user equals the object's `employeeId`, fail closed on unknown actor/employee) fits the review case exactly — the employee under review must not vaststellen their own beoordeling (design D4). No new guard code; the guard's docblock gains the third consumer.
- **One new machine-checkable corpus rule** `nl-performance-dossiervorming` in `lib/Standards/rules/labour.json` (a `vastgesteld` review must carry a non-null `rating` and non-empty `afspraken` — dossieropbouw for BW 7:669 lid 3 sub d ontslagdossiers; severity `recommended`) + new check provider `lib/Standards/Checks/NlPerformanceChecks.php`; `RuleCatalogue::VERSION` bumps `2026-07.5` → `2026-07.6`. Violations surface via the existing `occ hrmq:rules:audit`.
- **Manifest (ADR-001 Rule 6 — no new menu, no module):**
  - `EmployeeDetail` gains a **Beoordelingen object-list row** (PerformanceReviews for this employee — the DETAIL_TAB anchoring Rule 6 prescribes; the exact `emp-assignments` addition pattern of the org-chart build).
  - `ReviewCycles` (index) + `ReviewCycleDetail` as SUB_PAGEs under the **existing** `EmployeesGroup` (Personeel) — cycle orchestration is HR-admin work; sub-pages are allowed, no new group.
  - `PerformanceReviewDetail` (detail, not a menu entry — reached from the dossier row, the cycle's review list, and the deepLink).
  - `MijnBeoordelingen` under the existing `MijnHrGroup`, filtered `userId=@me` — employees read their own vastgestelde beoordelingen; this is why `PerformanceReview` carries the optional denormalized `userId`.
  - deepLinks for `ReviewCycle` and `PerformanceReview`.
- **Seed data** — 1 open `ReviewCycle` 2026 + 2 reviews: one `vastgesteld` and complete for `employee-jansen` (`userId: admin` → visible on MijnBeoordelingen) and one `vastgesteld` with a **missing rating** → exercises the intended `nl-performance-dossiervorming` violation in the seeded audit.

### Non-goals

- **No OKR cascade, no 9-box, no kalibratie, no continuous feedback, no RewardLink** — the whole `performance-management-advanced` draft ambition stays a separate future change; the MVP's `goals` array inside the review is the only goal surface.
- **No comp-planning cycle** — salary/bonus/promotion rounds, budget allocation, pay-equity audits and compensation letters are the `comp-planning-cycle` draft, explicitly a separate future change; nothing here writes compensation data.
- **No career/functie frameworks** (career paths, competency matrices) — separate draft territory.
- **No review templates or configurable question forms** — the MVP's sterktes/ontwikkelpunten/afspraken fields are the fixed form; a template entity is a follow-up.
- **No 360°/peer feedback, no self-assessment entity** — a single review object per employee per cycle.
- **No automatic reminders/notifications** — x-openregister-notifications adoption is app-wide work (ADR-031/gate-18), the same deferral as loonaangifte, leave-verzuim and ATS.
- **No manager-scoped visibility enforcement** — RBAC/publish posture is register-wide work; the MVP relies on the app-level access model like every other hrmq schema.

## Capabilities

### New Capabilities

- `performance-review-cycles`: the `ReviewCycle` schema with its declarative concept→open→gesloten lifecycle, the `ReviewCycles`/`ReviewCycleDetail` pages under the existing Personeel group, and the seeded open 2026 cycle.
- `performance-reviews`: the `PerformanceReview` schema (goals inside the review, no Goal entity) with its declarative concept→ingediend→besproken→vastgesteld/heropenen lifecycle and the `NoSelfApprovalGuard` on `vaststellen`, the `nl-performance-dossiervorming` corpus rule with the `NlPerformanceChecks` provider, the `EmployeeDetail` Beoordelingen row + `PerformanceReviewDetail` + `MijnBeoordelingen` pages, and the two seeded reviews.

### Modified Capabilities

<!-- none — existing specs are untouched; this change ADDS a fragment, one corpus rule + provider, pages/rows on existing groups, and seeds. EmployeeDetail's widget addition follows the same additive pattern org-chart-basic used for emp-assignments. -->

## Impact

- `lib/Settings/register.d/hr-performance.json` — **new fragment**, `ReviewCycle` + `PerformanceReview` (both 0.1.0) with `x-openregister-lifecycle`; `vaststellen` carries `requires: OCA\Hrmq\Lifecycle\NoSelfApprovalGuard`.
- `lib/Settings/hrmq_register.json` — `info.version` 0.5.0 → 0.6.0 (version-gated re-import).
- `lib/Standards/rules/labour.json` — +1 rule `nl-performance-dossiervorming` (labour law — ontslagrecht, not privacy/payroll); `RuleCatalogue::VERSION` 2026-07.5 → 2026-07.6.
- `lib/Standards/Checks/NlPerformanceChecks.php` — new check provider (auto-discovered by `RuleEngine::providers()`).
- `lib/Lifecycle/NoSelfApprovalGuard.php` — docblock only (documents the third consumer); no behavioural change.
- `src/manifest.json` — `emp-reviews` object-list on `EmployeeDetail`; pages `ReviewCycles`, `ReviewCycleDetail`, `PerformanceReviewDetail`, `MijnBeoordelingen`; menu children `ReviewCycles` (EmployeesGroup) + `MijnBeoordelingen` (MijnHrGroup); deepLinks `ReviewCycle`/`PerformanceReview`.
- `src/icons.js` — register `CalendarSyncOutline`, `ClipboardAccountOutline`, `StarCheckOutline` (all verified present in `vue-material-design-icons` at HEAD).
- `lib/Settings/register.d/hr-seed.json` — seed cycle + 2 reviews.
- `lib/Repair/InitializeRegister.php` — no change (fragment import picks up the new fragment + version bumps).
- Related: `performance-management-advanced` and `comp-planning-cycle` remain separate future drafts (non-goals above); `hrmq-rule-compliance-enforcement` owns guard/audit enforcement wiring beyond the audit-only posture; `hrmq-ia-navigation-alignment` owns final IA ordering.
