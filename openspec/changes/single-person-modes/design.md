# Design — single-person-modes

## Context

**Verified against HEAD 2026-07-17.** Read read-only, directly grounding this design:

- `openspec/architecture/adr-001-information-architecture.md` Rule 4 — "ZZP/DGA en eenmanszaak zijn MODES, geen
  aparte app," naming `zzp-dga-single-person-mode`/`zzp-eenmanszaak-no-payroll-mode` (the two May-2026 drafts this
  change modernises) and requiring: "spec proposals targeting single-person scenarios must place their behaviour
  as `SETTING` mode-flags and document which existing menus are hidden/altered."
- `openspec/specs/dga-payroll-mode/spec.md` — DONE. `Employee.isDga` → `CalculationInput.verzekeringsplichtig:
  false`, the €58.000/jaar 2026 gebruikelijkloon norm (`lib/Standards/tables/nl-2026.json`
  `parameters.gebruikelijkloon.jaarnorm`, `TaxTables::gebruikelijkloon(): {jaarnormCents}`), and `NlDgaChecks`
  (`lib/Standards/Checks/NlDgaChecks.php`) — an auto-discovered `CheckProvider` contributing the
  `nl-gebruikelijkloon-norm` conditional-severity rule: vacuous unless `isDga: true`; vacuous when
  `gebruikelijkloonJustification` is non-empty; else satisfied only when `grossMonthlySalary × 12 >=
  jaarnormCents`. This design calls this exact predicate; it does not reimplement it.
- `openspec/specs/proforma-payslip/spec.md` — DONE. Not consumed directly by this change, but it is why "let a
  DGA test a hypothetical salary" is already solved and out of this change's scope.
- `openspec/specs/multi-administratie/spec.md` — DONE, with one **named, unclosed follow-up**: REQ-MULTI-006:
  "the Dashboard-widget `runtime.user` visibleIf wiring remains a separate, small, named follow-up." This design
  closes it. `Administration` (`lib/Settings/register.d/hr-administratie.json`) carries
  `administrationId`/`name`/`kvkNumber`/`loonheffingennummer`/`active` — no mode field.
  `AdministrationController::context()` (`lib/Controller/AdministrationController.php:118`) returns
  `AdministrationService::context($userId): {activeAdministrationId, administrations}`. `PageController::index()`
  (`lib/Controller/PageController.php`) already stamps `activeAdministrationId` via
  `IInitialState::provideInitialState()` before the template renders, precisely the mechanism `cnWorkspaceContext`
  seeds from on first paint (REQ-MULTI-004) — the reused precedent for a second key (D2).
- `lib/Settings/register.d/hr-objects.json` — `Employee.nextcloudUserId` ("the durable Employee<->account link";
  `mijn-hr-self-service`) and `Payslip.userId` (denormalized copy, filtered with the `@me` token on
  `MijnLoonstroken`) — the resolution precedent D5 reuses. `Employee.administrationId` (multi-administratie
  REQ-MULTI-001) is the field the new headcount check (D4) groups by.
- `lib/Standards/Checks/NlAdministratieChecks.php` — the `nl-administratie-scope-consistency` posture: a
  recommended-severity, auto-discovered, vacuous-by-default data-quality lamp over the `administrationId`
  denormalization. D4 applies the identical posture to headcount instead of scope drift.
- `lib/Service/RuleAuditService.php` / `lib/Command/RulesAuditCommand.php` — `occ humaniq:rules:audit` is the
  **only** existing surface for any corpus-rule verdict, including `nl-gebruikelijkloon-norm`. `grep -rn
  RuleAuditService lib/Controller/` returns nothing — no HTTP endpoint exists. This is the gap D5 closes, narrowly
  (one rule, one employee, self-service), not by exposing the whole audit report over HTTP.
- `/home/rubenlinde/humaniq-goal/nextcloud-vue/src/schemas/app-manifest-v2.schema.json` — `$defs/visibleIfCondition`
  (menu items, both parent `menuItem` and nested `menuItemLeaf`) evaluates against `manifest.runtime.user.*`
  dot-paths with `eq`/`in`/`notIn`/`gt`/`gte`/`lt`/`lte`/`truthy` predicates (`visibleIfContext.js`). The `runtime`
  top-level manifest key is documented as "injected into the manifest by the backend... at serve time," canonical
  sub-object `user`, `additionalProperties: true` (not closed to `user` alone). `grep -c visibleIf src/
  manifest.json` in this repo returns **0** — humaniq has never used this primitive; `PageController::manifest()`
  serves the static `src/manifest.json` file unchanged, with no per-caller `runtime` merge today. Wiring it is new
  work, not a rewire of existing behaviour (D2).
- `/home/rubenlinde/humaniq-goal/nextcloud-vue/src/schemas/app-manifest-v2.schema.json` `banner` widget
  (`CnBannerWidget`): `props {variant, text, visibleWhen?, route?}`, `visibleWhen: {endpoint | source, field?, op?,
  value?}`, "fail-safe: hidden on fetch failure." `source.filter` documented as supporting "the shared @-token
  grammar"; `endpoint` is documented only as "a same-origin JSON URL" with no stated per-object token
  interpolation — D5 designs around this uncertainty rather than assuming it away.
- `lib/Settings/register.d/hr-expense.json` — `Expense` already carries `"Type reis"`/`"Afstand (km)"` fields —
  the exact shape the `eenmanszaak` draft's proposed `KilometerLog` entity would have duplicated. Cited in the
  proposal's Non-Goals as evidence, not re-specified here.

## Goals / Non-Goals

**Goals:** give `Administration` a real mode flag (Rule 4); make that flag visible to the manifest renderer at
all (closing REQ-MULTI-006); hide the menu surfaces that only make sense with more than one person; flag
(never block) headcount drift on a `dga_single_person` administratie; put the existing gebruikelijkloon
compliance verdict somewhere a self-running DGA can actually see it.

**Non-Goals (binding, from the proposal):** FOR-saldo, lijfrente-jaarruimte, box-2 aanmerkelijk-belang, IB-pakket
export, `accountant_of_record` delegation, a KilometerLog entity, TaxContext/urencriterium, IB-jaaroverzicht
export. None of these compute a payroll figure or extend an existing humaniq engine; all are IB-aangifte tooling, a
different compliance domain, and out of humaniq's stated scope (`openspec/config.yaml`).

## Decisions

### D1 — `Administration.mode`: a three-value enum, not two booleans

`standard` (default) / `dga_single_person` / `eenmanszaak_no_payroll`. A two-boolean shape
(`singlePersonMode`+`payrollActive`) was considered and rejected: it admits an invalid combination
(`singlePersonMode: true, payrollActive: true` with zero DGA employees, or `payrollActive: false` on a
multi-employee administratie) that a three-value enum makes structurally unrepresentable. The two values map
1:1 onto the two modernised draft slugs, keeping the change traceable to its source.

| Value | Meaning | Salarissen visible? | Expects headcount |
|---|---|---|---|
| `standard` (default) | every existing multi-employee administratie | yes | unconstrained |
| `dga_single_person` | a single-person BV; the DGA still draws `loon`, still runs `dga-payroll-mode`'s calculator monthly | yes | exactly 1 active `Employee` with `isDga: true` (D4, soft) |
| `eenmanszaak_no_payroll` | a sole proprietorship taking `winstuitkering`, never `loon`; no payroll data exists to process | no | unconstrained — the mode itself carries no employee-count expectation (Why: a true eenmanszaak may model zero Employee records at all) |

### D2 — `runtime.user.administrationMode`: the exact `activeAdministrationId` mechanism, applied to a second key

`PageController::index()` already resolves the caller's active administratie and calls
`IInitialState::provideInitialState('activeAdministrationId', $administrationId)` before the template renders
(REQ-MULTI-004). This change adds a second call, `provideInitialState('activeAdministrationMode', $mode)`,
resolved via `AdministrationService` (extended to look up the active `Administration.mode`, defaulting `standard`
when unresolved — the identical no-regression default REQ-MULTI-004 established for `activeAdministrationId`).
`AdministrationController::context()`'s response additionally carries `mode` per administratie entry (for the
`Administraties` switcher UI to display, and so a client-side switch — like `activeAdministrationId` already does
via `cnWorkspaceContext` — can update the mode without a reload).

The FE half — exactly how humaniq's `App.vue`/`CnAppRoot` boot sequence merges an `IInitialState`-seeded value into
`effectiveManifest.runtime.user` for `visibleIf` to read — is **not** fully traced here; `App.vue` already
performs the analogous `loadState('humaniq', 'activeAdministrationId', '')` → `cnWorkspaceContext` seed for
REQ-MULTI-004, so the wiring point exists and this change extends it with a second key. Verify against HEAD at
implementation time exactly which prop `CnAppRoot` expects `runtime` on (`customRuntime`, a boot option, or a
`loadState`-sourced merge) — this is the first real consumer of `visibleIf` in humaniq, so no second example exists
in this codebase to copy verbatim.

### D3 — Which menu entries hide, and why each one specifically

Only entries whose *purpose* requires more than one person hide. Two people can still coexist administratively
in a `dga_single_person` administratie in a transitional edge case (D4 flags it, does not forbid it), so hiding
is scoped to genuinely multi-person UX, never to "this schema happens to have an `employeeId` field":

| Menu id(s) | Why it hides under `dga_single_person`/`eenmanszaak_no_payroll` |
|---|---|
| `OrgUnits`, `OrgAssignments` | an org-chart has no meaning with one person |
| `TimesheetApproval`, `TeamUrengoedkeuring` | approving whose hours, if there is no one else? |
| `LeaveApproval`, `TeamVerlofgoedkeuring` | same — team leave approval |
| `ExpenseApproval`, `TeamDeclaratiegoedkeuring` | same — team expense approval |
| `PlanningGroup` (`Rosters`, `RosterAssignments`, `Shifts`) | roster/shift planning schedules multiple people across time slots |

Additionally, ONLY under `eenmanszaak_no_payroll` (not `dga_single_person`, which still runs payroll for the
DGA's own `loon`):

| Menu id(s) | Why it additionally hides under `eenmanszaak_no_payroll` only |
|---|---|
| `PayrollGroup` (all children) | no `loon` is ever paid; every payroll-run/payslip/filing surface is structurally empty |
| `ProformaPayslipMenu` | simulating a payslip for a salary that will never be paid has no purpose here |

`Medewerkers` (`Employees`, `EmploymentContracts`), `Salarissen`, and `MijnHrGroup` items are **not** hidden under
`dga_single_person` — the DGA is one `Employee` record who still has a contract and still runs one payroll a
month. `CompReviewCycles`/`ReviewCycles`/`SalaryBands` are deliberately left visible too: a single-person
administratie MAY still want to record its own annual review/comp decision; nothing about the schema requires a
second person, and hiding them would remove a feature some solo DGAs use for their own record-keeping. Named here
so a future change does not assume this list was exhaustive by accident.

### D4 — Headcount drift is a recommended-severity lamp, never a write-time block

The May-2026 draft proposed a hard, synchronous validation ("exactly one active employee with `is_dga = true`
exists before mode switch") gating the mode toggle itself. This codebase has no precedent for a cross-object
*count* validation enforced at write time on a `SETTING`-style record — `nl-administratie-scope-consistency`
(the closest analogue) is a read-only, auto-discovered, recommended-severity corpus check, never a create/update
guard. Blocking the mode switch or a second-employee create would also require a new synchronous write-time hook
this codebase's `ObjectService`-mediated save path (ADR-022 — humaniq owns no CRUD) does not currently expose for
`Administration` or `Employee`.

`NlSinglePersonChecks::checks()['Administration']['nl-single-person-mode-employee-count']` (recommended
severity, auto-discovered `CheckProvider`, the `NlAdministratieChecks` shape applied to a new predicate) is
**vacuous** unless `mode: dga_single_person`; else it counts active `Employee` rows (soft-deleted/offboarded
excluded, the existing `Employee` active-status convention) whose `administrationId` equals this
`Administration.administrationId`, and is satisfied only when that count is exactly 1 AND the one matching
Employee has `isDga: true`. A drifted administratie (0, 2+, or 1-but-not-DGA) surfaces on the next `occ
humaniq:rules:audit` run — visible, traceable, never silently wrong — but never blocks anything. Reversing the mode
(back to `standard`) is always just editing the `Administration.mode` field; no data migration, no lock, matching
the drafts' own "reversible" requirement without inventing enforcement machinery this codebase does not have
elsewhere.

### D5 — Self-service gebruikelijkloon status: a scoped read, not a general audit endpoint

`RuleAuditService` is register-wide (loads every object of every engine-supported type) and admin/CLI-scoped by
convention — exposing it directly over HTTP would leak the whole compliance posture to a self-service caller, far
beyond "is my own salary compliant." Instead, `PayrollController::dgaStatus()` (`#[NoAdminRequired]`, the
`PayrollController::proforma()`/`authorizeRun` resolve-first-then-404 shape):

1. Resolves the caller's own `Employee` via `nextcloudUserId === $this->userSession->getUser()->getUID()`
   (the `mijn-hr-self-service` link `Employee.nextcloudUserId` already documents). No `Employee` found, or the
   found `Employee` has `isDga: false`/absent → **404** (unavailable and "not a DGA" collapse to the same status,
   the `proforma`-endpoint posture: never leak which case it was).
2. Calls `NlDgaChecks`'s exact predicate (extracted as a small static/pure call, not copy-pasted — `grossMonthlySalary
   × 12 >= TaxTables::load(current-year)->gebruikelijkloon()['jaarnormCents']`, vacuous-when-justified handling
   identical to the shipped check) against that one `Employee` record, read fresh on every call — no caching, no
   persistence, the `proforma-payslip` "stateless, deterministic" posture.
3. Returns `{isDga: true, grossAnnualSalaryCents, jaarnormCents, met: bool, justification: string|null}` — no
   register write, ever.

**Why a new endpoint rather than a scoped `RuleAuditService` call:** `RuleAuditService::audit()`'s public contract
takes no per-employee scope parameter and returns a register-wide report shape; narrowing its *output* after the
fact would still mean it walked every object first (wasteful for a single self-service check) and its 403/404
posture is admin-shaped, not self-service-shaped. Reusing `NlDgaChecks`'s predicate directly — the actual rule
logic — while writing a purpose-built self-service resolve-and-respond wrapper is the smaller, more honest reuse:
the *rule* is never reimplemented, only its invocation surface is new.

**The manifest surface.** A `banner` widget (`variant: warning`, `visibleWhen: {endpoint: '/apps/humaniq/api/payroll/
dga-status', field: 'met', op: 'eq', value: false}`) on a new self-service `MijnGebruikelijkLoon` page under
`MijnHrGroup`, `visibleIf: {"user.administrationMode": {eq: "dga_single_person"}}` gating the whole menu entry
(D2/D3). Because the endpoint takes no per-object parameter (it always means "my own record," D5.1), the
`endpoint`-token-interpolation uncertainty the Context section flags for `visibleWhen.endpoint` does not apply
here — there is no object id to interpolate. If, at implementation time, `visibleWhen.endpoint` turns out not to
support even a fixed (non-templated) same-origin path the way this design assumes, the fallback is a small
custom-component widget (`widgetKey` resolved against `customComponents`, the pattern `AdministrationSwitcher`/
`ProformaPayslip` already establish for `type: custom` pages) calling the same endpoint directly — a documented
fallback, not a silent one.

### Declarative vs imperative (ADR-031)

| Behaviour | Path | Rationale |
|---|---|---|
| `Administration.mode` field | declarative schema | ordinary enum property, no computation |
| `runtime.user.administrationMode` wiring | imperative (`PageController`/`AdministrationController`) | session/request-scoped resolution, inherently imperative in Nextcloud's controller pipeline (the `activeAdministrationId` precedent) |
| Menu visibility | declarative `visibleIf` (manifest) | exactly the primitive nc-vue ships for this; no new FE logic |
| Headcount drift (D4) | imperative pure predicate (`NlSinglePersonChecks`), auto-discovered `CheckProvider` | reads existing `administrationId`/`isDga` fields; the `nl-administratie-scope-consistency` posture, not a new corpus-rule shape |
| Gebruikelijkloon status (D5) | guarded controller endpoint (imperative) + declarative `banner` widget | the compute is a scoped reuse of an existing imperative check; the display is the one declarative primitive built for exactly this (`visibleWhen`) |

## Seed Data (ADR-001)

Two administraties already exist in `hr-seed.json` (`ADM-001` — the multi-employee anchor carrying
`employee-jansen`/EMP-0001 and others; `ADM-002` — the isolated multi-administratie fixture). This change adds:

1. **`ADM-001.mode` stays `standard`** (no change) — proves the default is a no-op for every existing seeded
   multi-employee fixture; `npm run check:manifest` and the existing `occ humaniq:rules:audit` output are unaffected.
2. **A NEW `ADM-003` Administration**, `mode: dga_single_person`, with exactly one seeded `Employee`
   (`employee-devries-dga`, `isDga: true`, `grossMonthlySalary: 3500.00` — deliberately BELOW the €4.833,33/maand
   implied by the €58.000 annual norm, with no `gebruikelijkloonJustification`) — the dev-container verification
   gate: `GET /api/payroll/dga-status` as that seeded user returns `met: false`; the "Mijn gebruikelijk loon"
   self-service banner renders the warning variant; `occ humaniq:rules:audit` still reports the same
   `nl-gebruikelijkloon-norm` violation it already reports today (this change adds a second, self-service surface
   for the same fact — it does not change the audit's own output). `Salarissen`/`Medewerkers` menu items remain
   visible for a user whose active administratie is `ADM-003`; `OrgUnits`/`PlanningGroup`/team-approval-queue
   items do not appear.
3. **A NEW `ADM-004` Administration**, `mode: eenmanszaak_no_payroll`, zero seeded `Employee` rows — the
   verification gate: a user whose active administratie is `ADM-004` sees no `PayrollGroup`/`ProformaPayslipMenu`
   entries; `Medewerkers`/`MijnHrGroup` remain (an eenmanszaak owner may still track their own declaraties/assets
   through the existing, unrelated Expense/Asset schemas — untouched by this change).

## Risks / Trade-offs

- **`visibleIf` is genuinely new infrastructure in humaniq** (Context: 0 existing usages) — D2's FE wiring carries
  more implementation-time uncertainty than a change that extends an established pattern would. Named explicitly,
  not glossed over: verify `CnAppRoot`'s runtime-merge contract against HEAD before assuming D2's mechanism is a
  drop-in.
- **The headcount check (D4) is soft by design** — a `dga_single_person` administratie that grows to two
  employees is flagged, not blocked, on the next audit run; between drift and the next `occ humaniq:rules:audit`,
  the UI still hides multi-employee surfaces the second employee may need. Named fast-follow: a
  `runtime.user`-driven in-app nudge (not a hard block) when the audit next runs, deferred because it needs no
  new mechanism beyond what D2 already ships once wired.
- **The `visibleWhen.endpoint` non-templated assumption (D5)** carries the same category of uncertainty as D2 —
  documented with an explicit, buildable fallback (custom widget) rather than assumed away.

## Open Questions

- None blocking. The `CnAppRoot` runtime-merge mechanism (D2) and `visibleWhen.endpoint` behaviour (D5) are
  implementation-time verifications with documented fallbacks, not open design decisions.
