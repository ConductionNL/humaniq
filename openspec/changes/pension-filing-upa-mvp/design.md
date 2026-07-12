# Design — pension-filing-upa-mvp

## Context

hrmq has no pension-filing surface at all. What it does have is every ingredient the UPA workflow gates on: `PayrollRun` (in `lib/Settings/register.d/hr-objects.json`) already carries `period`, `administrationId` and a `status` enum `draft → approved → posted → paid` plus the GL-reconciliation fields; the Timesheet/LeaveRequest/Expense fragments established the declarative `x-openregister-lifecycle` + guard pattern (`NoSelfApprovalGuard`); the payroll corpus + `CheckProvider` machinery already enforces 89 rules; and `LoonaangifteFilings`/`LoonaangifteFilingDetail` pages exist under the Loonadministratie menu group.

Market reference (verified 2026-07-12, Spectr `hrmq-insight-upa-table-stakes`, `hrmq-src-loket-apg`):
- Loket.nl's APG pensioenaangifte covers the APG-administered funds (ABP/O&O, SPW, bpfBOUW, Schoonmaak, Architecten/PFAB, PWRI), **can only be created once the salary run is approved**, walks create → review → confirm → send, auto-dispatches confirmed filings (15-min scheduler), and shows APG response messages in-app.
- The UPA (Uniforme Pensioenaangifte) standard is owned by SIVI (https://www.sivi.org/standaarden/uniforme-pensioenaangifte/); APG-administered funds require a complete monthly delivery per administration.

## Goals / Non-Goals

**Goals:** a `PensionFiling` object per period×fund with a declarative guarded lifecycle; the payroll-run-approval gate as a real server-side guard; reference-integrity, monthly-completeness and deadline-alert rules as versioned machine-checkable corpus entries; index/detail pages that drive the lifecycle; response capture fields.

**Non-Goals:** UPA XML rendering (SIVI message schema), wire transport to APG, scheduled auto-dispatch (n8n/OpenConnector later), non-APG fund configuration UI, pension-premium calculation.

## Decisions

### D1 — Lifecycle states are Dutch domain terms, gated on payroll-run approval

`status`: `concept → gecontroleerd → bevestigd → verzonden`; response outcomes live in `responseStatus`, not extra states. Transitions:

| action | from | to | notes |
|---|---|---|---|
| `controleren` | concept | gecontroleerd | review passed; `requires: OCA\Hrmq\Lifecycle\PayrollRunApprovedGuard` — denied unless the referenced PayrollRun is `approved`/`posted`/`paid` |
| `bevestigen` | gecontroleerd | bevestigd | confirm for dispatch |
| `verzenden` | bevestigd | verzonden | stamps `submittedDate` and `verzondenDoor` on the carrying write (Timesheet `approvedAt`/`approvedBy` pattern) |
| `heropenen` | gecontroleerd, bevestigd | concept | corrections before send |
| `corrigeren` | verzonden | concept | a sent delivery is superseded by a correction; the description documents that a corrected UPA delivery for the same period is the usual route |

Rationale: mirrors the loonaangifte filing flow (create→review→confirm→send, verified at Loket.nl) and reuses the exact lifecycle renderer + `lifecycleActions` widget the Timesheet/Expense/LeaveRequest pages already use. The gate sits on `controleren` (not on create) so an HR admin can prepare a concept filing while the run is still in review, but nothing can progress toward `verzonden` until the run is approved.

### D2 — `PayrollRunApprovedGuard` is the ADR-031 lifecycle-guard exception, fail-closed

New `lib/Lifecycle/PayrollRunApprovedGuard.php` implementing the same `OCA\OpenRegister\Lifecycle\LifecycleGuardInterface` as `NoSelfApprovalGuard` (`check(array $object, string $action, string $userId): GuardResult`). It reads `$object['payrollRunId']`, lazily resolves OpenRegister's ObjectService via the DI container (the `RuleTestDataSeeder` pattern — guards are constructed by the container, so constructor injection is available, unlike the stateless `NoSelfApprovalGuard`), loads the run, and:

- **denies** when `payrollRunId` is empty, the run cannot be loaded, or its `status` ∉ {`approved`, `posted`, `paid`} — fail closed, with a Dutch denial message naming the run status;
- **allows** otherwise. Guards are read-only per OpenRegister's contract: no stamping here.

Kind stays `config`: the guard is the one behaviour the declarative state machine cannot express (a cross-object precondition), exactly the exception `NoSelfApprovalGuard` established — repo precedent `hrmq-expenses` was `config` with guard involvement.

### D3 — Cross-object rule evaluation rides the existing `$context` parameter

The RuleEngine predicate contract is already `fn(array $object, array $context): bool`; only `jurisdiction` is populated today. `RuleAuditService::audit()` gains a small pre-pass that loads a lightweight cross-type index into the context before the per-type loop:

- `context['related']['PayrollRun']`: per run `{id, period, status}` keyed by id, plus the set of periods having an approved-or-later run;
- `context['related']['PensionFiling']`: the set of `period` values that have at least one filing.

The two cross-object predicates read that index; the deadline predicate stays purely per-object. No RuleEngine change; unit tests pass the context explicitly. Fail-closed: a `PensionFiling` whose `payrollRunId` does not resolve in the index violates `nl-upa-payrollrun-approved`.

### D4 — `fund` is an APG-first extensible enum; the schema is `schema:Action`

`fund` enum: `abp`, `spw`, `bpf-bouw`, `schoonmaak`, `pfab`, `pwri` — the APG-administered funds the verified Loket.nl reference supports, first; other administrators (TKP, PGGM, …) extend the enum in data later (no config UI in this MVP, see Non-Goals). Schema.org annotation is **`schema:Action`**, not `schema:Invoice`: a UPA delivery is a workflow act (agent delivers a report to a fund, with a lifecycle), matching Timesheet/LeaveRequest which carry lifecycles; `schema:Invoice` is reserved for money statements (Payslip). `deadline` is authoritative input per the fund's aanleverschema/uitvoeringsreglement — unlike the loonaangifte there is no single statutory derivation formula across funds, so no derivation rule is included.

### Declarative-vs-imperative decision (ADR-031)

| behaviour | path | rationale |
|---|---|---|
| Filing state machine | **declarative** `x-openregister-lifecycle` on the schema | ADR-031 default; renderer ships `lifecycleActions` widget |
| Payroll-run-approval gate on `controleren` | imperative **lifecycle guard** (`PayrollRunApprovedGuard`) | cross-object precondition the state machine cannot express — the established ADR-031 guard exception (`NoSelfApprovalGuard` precedent) |
| `submittedDate`/`verzondenDoor` stamping on `verzenden` | declarative (carrying write, as Timesheet stamps `approvedAt`/`approvedBy`) | existing pattern; guards are read-only |
| Run-approved reference integrity, monthly completeness, deadline alert | imperative **CheckProvider** methods (`NlPensionFilingChecks`) | domain-rule evaluation over the versioned corpus — the established ADR-031 exception this app already uses for all 89 rules |
| Cross-type sibling index for the checks | imperative pre-pass in `RuleAuditService` populating `$context` | the predicate contract already carries `$context`; no engine change |
| Status/deadline KPI on pages | declarative stats-block widgets | manifest supports stat widgets with filters |

## Schema (new fragment `lib/Settings/register.d/hr-pension.json`)

OpenAPI 3.0.0 `components.schemas` fragment shape (like `hr-leave.json`), `x-hrmq-fragment: hr-pension`, one schema **`PensionFiling`** (slug `PensionFiling`, icon `PiggyBankOutline`, version `0.1.0`, `x-schema-org: schema:Action`):

- `payrollRunId` — string, format uuid, `$ref: PayrollRun`, **required**. The approved run this delivery reports on; drives the guard and the reference-integrity rule.
- `period` — string, YYYY-MM, **required**.
- `fund` — string enum `abp|spw|bpf-bouw|schoonmaak|pfab|pwri`, **required** (D4).
- `aanleverkenmerk` — string, nullable. The UPA delivery reference returned/agreed for the delivery.
- `deadline` — string, format date, **required**. Fund aanleverschema deadline (authoritative input, not derived — D4).
- `status` — enum `concept|gecontroleerd|bevestigd|verzonden`, default `concept`, **required**, governed by the lifecycle.
- `responseStatus` — enum `geen|ontvangen-ok|afgekeurd`, default `geen`. Fund/APG response outcome (response surfacing is table stakes per the Loket.nl reference).
- `responseMessage` — string, nullable. Free-text response message from the fund.
- `submittedDate` — string, format date, nullable — stamped on the `verzenden` carrying write.
- `verzondenDoor` — string, nullable — display name of the sender, stamped on the `verzenden` carrying write.

`required: [payrollRunId, period, fund, deadline, status]`. Lifecycle in `configuration.x-openregister-lifecycle` (`field: status`, `initial: concept`, `terminal: []`) with the D1 transitions; `controleren` carries `requires: OCA\\Hrmq\\Lifecycle\\PayrollRunApprovedGuard`.

## New corpus rules (payroll.json)

| id | source | statement (short) | severity | machineCheckable |
|---|---|---|---|---|
| `nl-upa-payrollrun-approved` | UPA-specificatie (SIVI) / fund delivery terms | A pension delivery (PensionFiling) must reference a PayrollRun in `approved`/`posted`/`paid` status — the delivery reports approved salary data | mandatory | true |
| `nl-upa-monthly-completeness` | APG aanleververplichting (UPA, monthly delivery) | APG-administered funds require a complete monthly delivery: every period with an approved PayrollRun must have a PensionFiling for each configured fund (MVP check: no approved-run period lacks any PensionFiling) | mandatory | true |
| `nl-upa-deadline-alert` | Fund aanleverschema / uitvoeringsreglement | An unsent (status ≠ verzonden) PensionFiling whose deadline is past or within 14 days is a violation (mandatory when overdue, advisory when approaching) | mandatory | true |

All three: `domain: reporting`, `jurisdiction: NL`, `framework: nl-pensioenaangifte` (**new** framework slug — frameworks are opaque strings to `RuleCatalogue`, enumerated only as examples in `lib/Standards/rules/SCHEMA.md`, which gains the new slug), `sourceUrl: https://www.sivi.org/standaarden/uniforme-pensioenaangifte/` (SIVI owns the UPA standard). `RuleCatalogue::VERSION` bumps (SCHEMA.md: "bump on any change to the rule files"). Corpus severity vocabulary has no `advisory`; like the sibling `nl-loonaangifte-deadline-alert`, the rule carries one severity (`mandatory`) and the approaching-window branch is the same rule's early-warning — a per-branch severity split would need two corpus entries and is deliberately not modeled (see Risks).

Checks live in the **new** auto-discovered provider `lib/Standards/Checks/NlPensionFilingChecks.php` (implements `CheckProvider`): `PensionFiling` predicates for `nl-upa-payrollrun-approved` (via `context['related']['PayrollRun']`, fail-closed) and `nl-upa-deadline-alert` (per-object, audit-run date); a `PayrollRun` predicate for `nl-upa-monthly-completeness` (an NL run in approved-or-later status whose period is absent from `context['related']['PensionFiling']` violates). Providers merge additively per object type, so keying `PayrollRun` next to `NlPayrollChecks` is safe.

## Manifest delta

- `PensionFilings` (new index page, route `/pension-filings`): register `hrmq`, schema `PensionFiling`, columns `period`, `fund`, `status`, `deadline`, `responseStatus`; filters `fund`, `status`; default sort `deadline` asc.
- `PensionFilingDetail` (new detail page, route `/pension-filings/:id`): stats-block with Status and Deadline entries; a data widget; a related widget (the `$ref` PayrollRun resolves there); `lifecycleActions` bound to the lifecycle (labels Controleren / Bevestigen / Verzenden / Heropenen / Corrigeren), structured identically to `TimesheetDetail`'s lifecycleActions; audit-history sidebar tab.
- Menu: `PayrollGroup` (Loonadministratie) gains a `PensionFilings` child ("Pensioenaangiftes", icon `PiggyBankOutline`) next to `LoonaangifteFilings`. ADR-001's frozen IA homes pensioen under "Aangiftes & compliance"; the current manifest predates that IA and the move is owned by the active `hrmq-ia-navigation-alignment` change — this change follows the current placement.
- `npm run check:manifest` must stay green.

## Seed Data (ADR-001)

`hr-seed.json` today carries no PayrollRun (runs are only provider-seeded by `NlPayrollChecks::seedObjects()` when the type is empty — one `posted` 2026-01 sample, no slug). Because the PensionFiling seeds must *reference* runs, both sides go into `lib/Settings/register.d/hr-seed.json` with slugs (the mechanism Timesheet seeds use for `employeeId`):

- **2 PayrollRun seeds** — `payrollrun-2026-05` and `payrollrun-2026-06`, `jurisdiction: NL`, `status: approved`, `administrationId: ADM-001`, with internally-consistent GL fields so the existing `xc-payroll-gl-reconciliation` / clearing checks stay satisfied. (Provider seeding then finds PayrollRun non-empty and skips — acceptable; the provider sample remains the empty-register fallback.)
- **3 PensionFiling seeds**:
  1. `pensionfiling-2026-05-abp` — period `2026-05`, fund `abp`, `payrollRunId: payrollrun-2026-05`, deadline `2026-06-30`, status `verzonden`, `submittedDate: 2026-06-20`, `verzondenDoor: manager-pietersen`, `aanleverkenmerk: UPA-000000000000`, responseStatus `ontvangen-ok` (happy path, response captured).
  2. `pensionfiling-2026-06-abp` — period `2026-06`, fund `abp`, `payrollRunId: payrollrun-2026-06`, deadline `2026-07-31`, status `concept`, responseStatus `geen` (deadline approaching → exercises the alert early-warning branch).
  3. `pensionfiling-2026-05-spw` — period `2026-05`, fund `spw`, `payrollRunId: payrollrun-2026-05`, deadline `2026-06-30`, status `bevestigd`, responseStatus `geen` (overdue and unsent → mandatory `nl-upa-deadline-alert` violation; second fund on the same run).

Both seeded periods have filings, so `nl-upa-monthly-completeness` stays green on seed data. All identifiers are obvious placeholders (`ADM-001`, `UPA-000000000000`, nil UUID `00000000-0000-0000-0000-000000000000` where a literal uuid is unavoidable, loonheffingennummer-style `000000000L01` if referenced). `NlPensionFilingChecks` does **not** implement `SeedsObjects`: a self-contained provider sample cannot carry a resolvable cross-reference, and a dangling `payrollRunId` would immediately violate the fail-closed reference rule.

## Risks / Trade-offs

- **Gate placement**: the guard sits on `controleren`, so a concept filing referencing a `draft` run can exist (and is simultaneously flagged by `nl-upa-payrollrun-approved` at audit time). This is deliberate: prepare-early, progress-late — and the audit rule keeps the gap visible.
- **Severity granularity**: one severity per corpus rule means the ≤14-days early warning reports under the same `mandatory` rule as overdue; splitting would double the corpus entry for a presentation nuance. Mirrors the sibling loonaangifte alert rule.
- **MVP completeness check is fund-blind**: "each configured fund" needs fund configuration that doesn't exist yet (Non-Goals); the MVP check only demands *some* filing per approved-run period. The rule statement records the full obligation so tightening the predicate later is a check-only change.
- **Guard needs the register at transition time**: if OpenRegister cannot load the run (transient failure), the transition is denied, not allowed — fail-closed can surface as a spurious denial; the denial message says why.
- **Enum extensibility**: adding a fund is a schema-fragment data change (enum append, non-breaking); removal would be breaking and is not planned.

## Open Questions

- None blocking. UPA XML generation, APG transport, and auto-dispatch are follow-up specs (Non-Goals); fund-configuration UI is deferred with them.
