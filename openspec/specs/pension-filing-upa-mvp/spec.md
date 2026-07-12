---
capability: pension-filing-upa-mvp
status: done
built_by: openspec/changes/archive/2026-07-12-pension-filing-upa-mvp
---

# pension-filing-upa-mvp Specification

**Status**: done
**Scope**: hrmq
**OpenSpec changes**:
- [pension-filing-upa-mvp](../../changes/archive/2026-07-12-pension-filing-upa-mvp/) _(archived 2026-07-12)_ — new `PensionFiling` schema in a new `hr-pension` fragment with a declarative concept→gecontroleerd→bevestigd→verzonden lifecycle gated by `PayrollRunApprovedGuard`, 3 new machine-checkable NL UPA rules (framework `nl-pensioenaangifte`), and pension-filing index/detail pages (kind: config)

## Purpose

Give hrmq its first sector-pension filing surface (UPA — Uniforme
Pensioenaangifte): a `PensionFiling` per period×fund for the APG-administered
funds (ABP, SPW, bpfBOUW, Schoonmaak, PFAB, PWRI), with a declarative
create→review→confirm→send lifecycle whose review step is server-side gated on
the referenced PayrollRun being approved/posted/paid — the verified Loket.nl
gating rule — plus reference-integrity, monthly-completeness and deadline-alert
rules in the versioned corpus, and filing pages that drive the lifecycle.
#3-ranked missing feature from the 2026-07-12 market deep-research (Spectr
insight `hrmq-insight-upa-table-stakes`, source `hrmq-src-loket-apg`).
UPA XML generation, APG wire delivery and scheduled auto-dispatch are
explicitly out of scope.

## Requirements

### REQ-PFU-001: A new `hr-pension` fragment SHALL define the `PensionFiling` schema

`lib/Settings/register.d/hr-pension.json` (new file, `x-hrmq-fragment: hr-pension`, OpenAPI 3.0.0 `components.schemas` shape like `hr-leave.json`) declares `PensionFiling` (slug `PensionFiling`, icon `PiggyBankOutline`, version `0.1.0`, `x-schema-org: schema:Action`) with properties: `payrollRunId` (string, format uuid, `$ref: PayrollRun`), `period` (string, YYYY-MM), `fund` (enum `abp|spw|bpf-bouw|schoonmaak|pfab|pwri`), `aanleverkenmerk` (string, nullable), `deadline` (string, format date), `status` (enum `concept|gecontroleerd|bevestigd|verzonden`, default `concept`), `responseStatus` (enum `geen|ontvangen-ok|afgekeurd`, default `geen`), `responseMessage` (string, nullable), `submittedDate` (date, nullable), `verzondenDoor` (string, nullable). `required: [payrollRunId, period, fund, deadline, status]`. The existing register Repair import picks the fragment up without code changes.

#### Scenario: Schema validates a complete filing
- **GIVEN** the imported hrmq register
- **WHEN** an object `{payrollRunId: "00000000-0000-0000-0000-000000000000", period: "2026-06", fund: "abp", deadline: "2026-07-31"}` is created
- **THEN** creation succeeds with `status` defaulted to `concept` and `responseStatus` to `geen`

#### Scenario: Unknown fund rejected
- **WHEN** an object is written with `fund: "not-a-fund"`
- **THEN** OpenRegister schema validation rejects it (enum mismatch)

### REQ-PFU-002: `PensionFiling` SHALL carry a declarative lifecycle `concept → gecontroleerd → bevestigd → verzonden`

`configuration.x-openregister-lifecycle` on the schema declares `field: status`, `initial: concept`, and transitions `controleren` (concept→gecontroleerd, `requires: OCA\Hrmq\Lifecycle\PayrollRunApprovedGuard`), `bevestigen` (gecontroleerd→bevestigd), `verzenden` (bevestigd→verzonden), `heropenen` (gecontroleerd|bevestigd→concept), `corrigeren` (verzonden→concept). The `verzenden` description documents that `submittedDate` and `verzondenDoor` are stamped on the carrying write (Timesheet `approvedAt`/`approvedBy` pattern; guards are read-only).

#### Scenario: Filing walks the happy path
- **GIVEN** a `PensionFiling` referencing a PayrollRun with `status: approved`
- **WHEN** the actions `controleren`, `bevestigen`, `verzenden` are executed in order via the OpenRegister lifecycle endpoint
- **THEN** the object's `status` ends as `verzonden` and the write carrying `verzenden` records `submittedDate` and `verzondenDoor`

#### Scenario: Illegal jump is rejected
- **GIVEN** a filing in status `concept`
- **WHEN** the `verzenden` action is attempted
- **THEN** OpenRegister rejects the transition (no `concept→verzonden` edge)

#### Scenario: Sent filing reopened only via corrigeren
- **GIVEN** a filing in status `verzonden`
- **WHEN** `corrigeren` is executed
- **THEN** status returns to `concept`; **AND** `heropenen` from `verzonden` is not a declared edge

### REQ-PFU-003: `PayrollRunApprovedGuard` SHALL gate `controleren` on payroll-run approval, fail-closed

`lib/Lifecycle/PayrollRunApprovedGuard.php` implements `OCA\OpenRegister\Lifecycle\LifecycleGuardInterface` (`check(array $object, string $action, string $userId): GuardResult`, the `NoSelfApprovalGuard` shape). It loads the PayrollRun referenced by `$object['payrollRunId']` from the hrmq register (ObjectService resolved lazily via the DI container: `find(id:, register:, schema: 'PayrollRun')`) and returns `GuardResult::allow()` only when the run's `status` is `approved`, `posted` or `paid`. It returns `GuardResult::deny(...)` with a Dutch reason when `payrollRunId` is empty, the run cannot be loaded, or the status is anything else — never allow-on-error. Registered in `Application::register()` (constructed with `ContainerInterface` + `IAppConfig`, autowired) so OpenRegister's `LifecycleGuardRegistry` can resolve the `requires` FQCN.

#### Scenario: Draft run blocks review
- **GIVEN** a `PensionFiling` in `concept` whose referenced PayrollRun has `status: draft`
- **WHEN** `controleren` is attempted
- **THEN** the guard denies the transition and the filing stays `concept`

#### Scenario: Approved run unblocks review
- **GIVEN** the same filing after the run reaches `status: approved`
- **WHEN** `controleren` is attempted
- **THEN** the guard allows and the filing becomes `gecontroleerd`

#### Scenario: Dangling reference fails closed
- **GIVEN** a filing whose `payrollRunId` matches no PayrollRun in the register
- **WHEN** `controleren` is attempted
- **THEN** the guard denies the transition

### REQ-PFU-004: The rule corpus SHALL gain three machine-checkable NL UPA rules under a new `nl-pensioenaangifte` framework

`lib/Standards/rules/payroll.json` gains `nl-upa-payrollrun-approved`, `nl-upa-monthly-completeness`, `nl-upa-deadline-alert` (all `domain: reporting`, `jurisdiction: NL`, `framework: nl-pensioenaangifte`, `severity: mandatory`, `machineCheckable: true`, `sourceUrl: https://www.sivi.org/standaarden/uniforme-pensioenaangifte/`, sources per the design.md table). `nl-pensioenaangifte` is a new framework slug, added to the examples list in `lib/Standards/rules/SCHEMA.md`. `RuleCatalogue::VERSION` stayed at `2026-07` — the loonaangifte-filing-lifecycle change already bumped it this month, and SCHEMA.md's version string carries no finer granularity than year-month, so no further bump was needed.

#### Scenario: Corpus stays loadable and versioned
- **WHEN** `occ hrmq:rules:audit` runs after the corpus edit
- **THEN** the RuleCatalogue loads without error and reports the three new rules as enforced (each has a CheckProvider predicate)

### REQ-PFU-005: `NlPensionFilingChecks` SHALL enforce reference integrity, monthly completeness, and deadline alerting

New auto-discovered provider `lib/Standards/Checks/NlPensionFilingChecks.php` (implements `CheckProvider`; does NOT implement `SeedsObjects`). `RuleAuditService::audit()` gained a pre-pass (`buildRelatedContext()`) that populates `$context['related']` with a lightweight cross-type index (PayrollRun `{id, period, status}` by id + approved-run period set; PensionFiling period set) before evaluation — the predicate contract `fn(array $object, array $context): bool` already carries the context. Predicates:

1. **`nl-upa-payrollrun-approved`** (on `PensionFiling`) — violates when `payrollRunId` does not resolve in the index or resolves to a run whose `status` ∉ {`approved`, `posted`, `paid`} (fail-closed).
2. **`nl-upa-monthly-completeness`** (on `PayrollRun`) — an NL run in approved-or-later status violates when its `period` has no PensionFiling at all (MVP fund-blind check; full per-configured-fund obligation recorded in the rule statement).
3. **`nl-upa-deadline-alert`** (on `PensionFiling`) — a filing whose `status` is not `verzonden` violates when `deadline` is in the past (overdue) or within 14 days of the audit run date (approaching).

#### Scenario: Filing on a draft run flagged
- **GIVEN** a PensionFiling whose referenced PayrollRun has `status: draft`
- **WHEN** `occ hrmq:rules:audit` runs
- **THEN** a `nl-upa-payrollrun-approved` violation is reported for that filing

#### Scenario: Approved run without any filing flagged
- **GIVEN** an NL PayrollRun with `status: approved` for period `2026-04` and no PensionFiling for `2026-04`
- **WHEN** the audit runs
- **THEN** a `nl-upa-monthly-completeness` violation is reported for that run

#### Scenario: Overdue unsent filing raises a violation
- **GIVEN** the seed filing `pensionfiling-2026-05-spw` in status `bevestigd` with `deadline: 2026-06-30`
- **WHEN** the audit runs on any date after 2026-06-30
- **THEN** a `nl-upa-deadline-alert` violation is reported

#### Scenario: Sent filing never alerts
- **GIVEN** a filing in status `verzonden` with a past deadline
- **WHEN** the audit runs
- **THEN** no `nl-upa-deadline-alert` violation is reported for it

### REQ-PFU-006: New filing pages SHALL surface the lifecycle and deadline KPIs

`src/manifest.json` gains (a) a `PensionFilings` index page (route `/pension-filings`, register `hrmq`, schema `PensionFiling`) with columns `period`, `fund`, `status`, `deadline`, `responseStatus`, filters `fund`/`status`, default sort `deadline` ascending; (b) a `PensionFilingDetail` detail page (route `/pension-filings/:id`) with a compact Status/Deadline widget (implemented as a top-of-page `data` widget rather than a `stats-block`, since the vendored app-manifest-v2 schema's stat/stats-block `metric` only supports `count`/`sum` numeric aggregation over a collection and cannot echo a non-numeric enum/date field of the current object — the same constraint the sibling `LoonaangifteFilingDetail` page's `_note` documents), a data widget, a related widget (the `$ref` PayrollRun resolves there), an audit-history sidebar tab, and a `lifecycleActions` widget exposing Controleren / Bevestigen / Verzenden / Heropenen / Corrigeren, structured identically to `TimesheetDetail`'s lifecycleActions (real labels, no placeholder text); (c) a `PensionFilings` entry ("Pensioenaangiftes", icon `PiggyBankOutline`) in the `PayrollGroup` (Loonadministratie) menu next to `LoonaangifteFilings`. The manifest validates (`npm run check:manifest`).

#### Scenario: Manifest stays valid
- **WHEN** `npm run check:manifest` runs
- **THEN** it exits 0

#### Scenario: Detail page drives the lifecycle
@e2e exclude declarative widget wiring is covered by the shared CnPageRenderer library tests; app-level e2e suite does not exist yet (tracked by active change hrmq-test-coverage-baseline)
- **GIVEN** a filing in status `concept` (on an approved run) opened on `PensionFilingDetail`
- **WHEN** the user executes Controleren
- **THEN** the page reflects status `gecontroleerd` and offers Bevestigen

### REQ-PFU-007: Seed data SHALL provide approved runs and filings exercising the lifecycle and alert branches

`lib/Settings/register.d/hr-seed.json` gains two NL PayrollRun seeds in `approved` status (`payrollrun-2026-05`, `payrollrun-2026-06`, GL fields internally consistent so existing payroll checks stay green) and three PensionFiling seeds (verzonden+ontvangen-ok on 2026-05/abp; concept on 2026-06/abp; bevestigd overdue on 2026-05/spw), referencing the runs by slug exactly as Timesheet seeds reference employees. All identifiers are obvious placeholders (`ADM-001`, `UPA-000000000000`). `NlPensionFilingChecks` seeds no provider objects: a self-contained sample cannot carry a resolvable `payrollRunId` and would violate the fail-closed reference rule.

#### Scenario: Idempotent seed
- **WHEN** the register Repair import (and `occ hrmq:rules:seed-testdata`) runs twice
- **THEN** the two runs and three filings exist exactly once

#### Scenario: Seeded periods are complete
- **GIVEN** the seeded data
- **WHEN** the audit runs
- **THEN** no `nl-upa-monthly-completeness` violation is reported (both approved-run periods have filings)
