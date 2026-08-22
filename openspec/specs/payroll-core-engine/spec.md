---
capability: payroll-core-engine
status: done
built_by: openspec/changes/archive/2026-07-14-payroll-core-engine
---

# payroll-core-engine Specification

**Status**: done
**Scope**: humaniq (chain spec 2 of 2 — ADR-032; `depends_on: [payroll-core-schema]`)
**OpenSpec changes**:
- [payroll-core-engine](../../changes/archive/2026-07-14-payroll-core-engine/) _(archived
  2026-07-14)_ — pure table-driven `PayrollCalculator` (Rekenvoorschriften 2026 chain, integer
  cents, AOW-age + groen variants) + `PayrollRunService` (idempotent draft runs per
  period/administration, draft-only recalculation, ObjectService payslip generation), occ
  `humaniq:payroll:run`/`humaniq:payroll:verify`, one guarded calculate endpoint + run-pages
  actions/child list, `NlEngineChecks` enforcement of the spec-1 contract, golden fixtures +
  balancing invariant, non-certification README disclaimer (kind: code)

## Purpose

The strategic centerpiece: the only open-source Dutch payroll calculation engine (verified
2026-07-12/13 — Spectr `hrmq-canon-payroll-engine` 7/9 coverage,
`hrmq-insight-odoo-nl-enterprise-only`). Produces the approved-run inputs the already-shipped
glpost/netpay/filing pipeline consumes, audited by the same rule corpus that audits hand-entered
data (`humaniq:payroll:verify` = the corpus as the engine's self-check). Rekenvoorschriften-based and
explicitly NOT certified — outputs carry `engineVersion`, and the disclaimer is a requirement.

## Requirements

### Requirement: A pure, stateless, table-driven calculator SHALL compute the NL gross-to-net chain in integer cents (REQ-PCE-001)

`lib/Payroll/PayrollCalculator.php` SHALL compute, for a monthly wage input, exactly the design.md
D2 equation chain from the Rekenvoorschriften 2026 — with `lib/Payroll/TaxTables.php` loading
`lib/Standards/tables/{id}.json` and converting euro amounts to integer cents:
tabelloon `L = floor((tvl×F)/Lv)×Lv`, schijventarief `X1`, heffingskortingen AHK/ARK (ARK with the
5-decimal term rounding and cumulative `arkm1..3` caps, both kortingen rounded up to whole euros,
applied only when `loonheffingskortingToegepast`), `X = max(0, X1 − kortingen)` floored,
tijdvakbedragen `x = X/F` and `ark/F` rounded to 2 decimals, vakantiegeldreservering 8%, informative
volksverzekeringen split, Zvw werkgeversheffing 6,10% over the capped bijdrageloon, and
Awf/Aof/Wko/Whk employer charges over the capped premieloon with `awfTariff` selecting laag/hoog.
The class SHALL have zero Nextcloud dependencies (no container, no clock, no IO beyond TaxTables)
and all monetary arithmetic SHALL be integer cents with the per-step rounding rules — never
accumulated floats.

#### Scenario: The anchor case reproduces the hand-computed Rekenvoorschriften figures
- **GIVEN** input €3.800,00 monthly, wit, korting applied, below AOW, Awf low, Aof laag, Whk 1,52
  and the `nl-2026` tables
- **WHEN** `calculate()` runs
- **THEN** loonheffing is €718,83, arbeidskorting €473,75, zvw €231,80, werknemersverzekeringen
  €419,14, vakantiegeldReserved €304,00 and nettoPay €3.081,17 (design.md D2 worked example)

#### Scenario: Without the loonheffingskorting election no kortingen apply
- **GIVEN** the anchor input with `loonheffingskortingToegepast: false`
- **WHEN** `calculate()` runs
- **THEN** AHK and ARK are €0, `arbeidskorting` is €0,00 and loonheffing equals
  `round2(floorEuro(X1)/12)` for the same tabelloon

### Requirement: AOW-age and groene-tabel variants SHALL be table-set switches (REQ-PCE-002)

The calculator SHALL switch to the AOW-age bracket set (17,85% first bracket, birth-year selecting
the 1945-or-earlier vs 1946-or-later row) and AOW korting columns (AHK 1.556/0,03195, halved ARK
factors, ouderenkorting) from the first day of the calendar month in which the employee reaches the
tables' AOW-leeftijd (67), derived from `dateOfBirth` against the run period. For
`taxTableColor: groen` the identical chain SHALL run with arbeidskorting skipped
(Rekenvoorschriften §2.2.3.4) — no further groene claims.

#### Scenario: AOW-age employee gets the reduced volksverzekeringen path
- **GIVEN** an employee born 1959-03-15 (AOW-leeftijd reached) with €3.800,00 monthly, wit,
  korting applied, period 2026-06
- **WHEN** `calculate()` runs
- **THEN** the first-bracket rate applied is 17,85%, the AHK maximum used is €1.556, the ARK
  factors are the AOW column, and ouderenkorting is applied per the tables

#### Scenario: Groene tabel applies no arbeidskorting
- **GIVEN** the anchor input with `taxTableColor: groen`
- **WHEN** `calculate()` runs
- **THEN** ARK is €0 while AHK still applies, and `Payslip.arbeidskorting` would be €0,00

### Requirement: PayrollRunService SHALL generate draft runs idempotently per (period, administrationId) (REQ-PCE-003)

`lib/Service/PayrollRunService.php` SHALL create at most one `PayrollRun` per
(period, administrationId) (probe-before-create, the netpay/glpost pattern), generate one Payslip
per active NL employee whose contract covers the period (upsert keyed on
`(payrollRunId, employeeId)`, orphaned engine payslips of that run deleted, payslips with a
different or null `payrollRunId` never touched), roll up `totalGross`/`totalLoonheffing`/
`totalEmployerCharges`/`totalWithholdings`/`totalNet` as cents-exact sums, and stamp
`engineVersion` (the tables id) + `calculatedAt`. Employees it cannot compute (no contract, no
`grossMonthlySalary` — hourly path is a named fast-follow, missing BSN/ID-verification —
anoniementarief fast-follow, non-NL jurisdiction) SHALL be skipped with a per-employee reason in
the outcome, never computed wrong and never silently dropped.

#### Scenario: Second run for the same period is an idempotent no-op
- **GIVEN** a draft run already generated for (2026-02, ADM-001)
- **WHEN** `humaniq:payroll:run --period 2026-02` runs again without `--recalculate`
- **THEN** no second PayrollRun and no duplicate Payslips exist, and the outcome reports the
  existing run

#### Scenario: Salary-less employee is skipped with a reason
- **GIVEN** an active NL employee with a contract but no `grossMonthlySalary`
- **WHEN** the run generates
- **THEN** no Payslip is created for them and the outcome names the employee with reason
  `no-monthly-salary (hourly path: fast-follow)`

### Requirement: Recalculation SHALL be allowed only in draft; approval stays a human act (REQ-PCE-004)

The service SHALL refuse to (re)calculate a run whose `status` is not `draft` (approved runs are
consumed by glpost/netpay — recomputing them would rewrite booked truth). `--recalculate` (and the
endpoint) re-runs generation for a draft run in place. The service SHALL never write any `status`
value other than creating `draft`; the `draft → approved` edit remains a human action on the
existing enum, and write-time guard wiring remains owned by the active
`humaniq-rule-compliance-enforcement` change.

#### Scenario: An approved run refuses recalculation
- **GIVEN** a run in status `approved`
- **WHEN** recalculation is requested via occ or the endpoint
- **THEN** the service refuses (endpoint: HTTP 400 with a clear message; occ: non-zero outcome for
  that run) and no Payslip or total changes

### Requirement: Payslips SHALL be engine-written via ObjectService; the UI stays create-less (REQ-PCE-005)

The service SHALL write PayrollRun/Payslip objects through OpenRegister's ObjectService (container
resolve, register `hrmq`) — verified legitimate because `allowCreate: false` is a UI-only
object-list affordance with zero server-side enforcement in the openregister checkout (design.md
Context). Every Payslip surface in the manifest SHALL keep `allowCreate: false`, so generation
remains the only create path. Generated payslips carry `payrollRunId`, the employee's
`nextcloudUserId` as `userId`, and the D2 component fields including `arbeidskorting`.

#### Scenario: Generated payslip is fully attributed
- **GIVEN** a generated run for 2026-02
- **WHEN** its payslip for the seeded employee is read
- **THEN** `payrollRunId` references the run, `userId` mirrors the employee's `nextcloudUserId`,
  and `arbeidskorting` carries the applied tijdvakbedrag

### Requirement: occ commands SHALL run and verify payroll; the corpus is the engine's self-check (REQ-PCE-006)

`occ humaniq:payroll:run --period YYYY-MM [--administration ADM] [--recalculate]` SHALL
create/recalculate draft runs and print the per-employee outcome (computed/skipped+reason). `occ
humaniq:payroll:verify --period YYYY-MM [--administration ADM]` SHALL run the RuleEngine over exactly
the run(s) + their payslips (the run-scoped corpus audit), print violations, and exit non-zero on
any mandatory violation, `0` otherwise (the `humaniq:rules:audit` exit-code convention). Both are
registered in `appinfo/info.xml`.

#### Scenario: A freshly generated run verifies clean
- **GIVEN** `occ humaniq:payroll:run --period 2026-02` generated a run from the seeded employee
- **WHEN** `occ humaniq:payroll:verify --period 2026-02` runs
- **THEN** it reports zero violations for the run and its payslips and exits `0`

#### Scenario: A tampered payslip fails the run-scoped verify
- **GIVEN** a generated payslip whose `nettoPay` was edited to break the consistency equation
- **WHEN** `occ humaniq:payroll:verify --period 2026-02` runs
- **THEN** an `nl-engine-output-consistency` violation is reported and the exit code is non-zero

### Requirement: NlEngineChecks SHALL enforce the spec-1 contract rules (REQ-PCE-007)

`lib/Standards/Checks/NlEngineChecks.php` SHALL register predicates for `nl-engine-table-version`
(PayrollRun: vacuous when `engineVersion` null; else `calculatedAt` present AND
`lib/Standards/tables/{engineVersion}.json` exists — tables dir globbed once, no per-object IO)
and `nl-engine-output-consistency` (Payslip: vacuous when `payrollRunId` null or its run — via the
`payroll.runsById` audit context enriched in `RuleAuditService::audit()`, the glpost precedent —
carries no `engineVersion`; else cents-exact
`nettoPay = grossPay − loonheffing − pensionContribution(null→0) − (zvwMode=inhouding ? zvw : 0)`).
After this change both rules count as enforced in the audit coverage.

#### Scenario: A run stamped with a non-existent table version violates
- **GIVEN** a PayrollRun with `engineVersion: "nl-2031"`
- **WHEN** `occ humaniq:rules:audit` runs
- **THEN** an `nl-engine-table-version` violation is reported for that run

#### Scenario: Hand-entered records stay out of scope
- **GIVEN** the pre-existing seeded PayrollRun and Payslip (null `engineVersion`/`payrollRunId`)
- **WHEN** the audit runs
- **THEN** neither new rule reports a violation for them

### Requirement: The run pages SHALL drive creation and (re)calculation through ONE guarded endpoint (REQ-PCE-008)

`appinfo/routes.php` SHALL add `POST /api/payroll/calculate` → `PayrollController::calculate`
(`#[NoAdminRequired]`), which resolves the posted `runId` through ObjectService under the caller's
ambient RBAC before any computation (unknown/unauthorized collapse to 404 — the
DocumentController no-admin-idor pattern) and refuses non-draft runs (400). `src/manifest.json`:
`PayrollRuns` (index) gains the `open-form` action "Loonrun aanmaken" (schema-driven draft-run
create, onSuccessRoute PayrollRunDetail); `PayrollRunDetail` gains the `api-call` action
"(Her)berekenen" (`params: {runId: "@objectId"}`, confirm, success/error messages) as a page
action — NOT a `lifecycleActions` widget, since PayrollRun carries no `x-openregister-lifecycle` —
plus an FK-scoped Payslips object-list (`filter: {payrollRunId: "@objectId"}`,
`allowCreate: false`), with the now-stale `_note` rationales rewritten. `npm run check:manifest`
MUST pass.

#### Scenario: Unauthorized run id never reaches the engine
- **GIVEN** a caller whose RBAC cannot see run X (or X does not exist)
- **WHEN** they POST `/api/payroll/calculate` with `runId: X`
- **THEN** the response is 404 and no calculation or write occurs

#### Scenario: Detail page recalculates a draft run
@e2e exclude declarative action wiring is covered by the shared CnPageRenderer library tests; the app-level e2e suite does not exist yet (tracked by active change humaniq-test-coverage-baseline)
- **GIVEN** a draft run opened on PayrollRunDetail
- **WHEN** the user executes "(Her)berekenen" and confirms
- **THEN** the endpoint recalculates the run and the refreshed page shows the updated totals and
  payslip list

### Requirement: Golden tests SHALL pin the engine, with slots for official Belastingdienst cases (REQ-PCE-009)

`tests/fixtures/payroll-2026/*.json` SHALL carry table-driven cases (bruto in → expected
components out) computed from the tables file itself: the D2 anchor (3.800), minimum-wage earner
(2.294,40), part-time, no-loonheffingskorting, AOW-age, bracket-3 high earner, and a groen
structure case — plus `tests/fixtures/payroll-2026/official/README.md` with clearly marked empty
slots for the official Belastingdienst test cases. `PayrollCalculatorTest` SHALL run every
fixture; `BalancingInvariantTest` SHALL assert, across ALL fixtures: the spec-1 net equation
cents-exact, `volksverzekeringen ≤ loonheffing`, `werknemersverzekeringen = awf+aof+wko+whk`, and
the tables-vs-corpus cross-check (Zvw 6,10/4,85 and WML 14,71 in `nl-2026.json` equal the values
the existing rule statements assert). `PayrollRunServiceTest` (mocked ObjectService) SHALL cover
idempotency, draft-only recalculation, orphan cleanup, skip reasons and total roll-up.

#### Scenario: Fixtures and invariants pass
- **WHEN** `composer test` (or `vendor/bin/phpunit`) runs
- **THEN** every golden fixture reproduces its expected components exactly and the balancing
  invariant holds for all fixtures

#### Scenario: Tables/corpus divergence fails the build
- **GIVEN** a hypothetical edit setting the tables' Zvw werkgeversheffing to 6,50 while the corpus
  rule still states 6,10
- **WHEN** the invariant test runs
- **THEN** it fails naming the diverging parameter

### Requirement: The engine SHALL ship a prominent non-certification disclaimer (REQ-PCE-010)

`README.md` SHALL gain a payroll-engine section stating: the engine implements the Belastingdienst
*Rekenvoorschriften voor de geautomatiseerde loonadministratie 2026* and is **NOT certified**;
every computed run carries `engineVersion` tracing it to the exact parameter file; the golden
tests are self-consistent with those tables and the official Belastingdienst test sets have not
yet been run against it (slot documented in the fixtures); known MVP limitations (fixed monthly
salary only, no VCR, no anoniementarief/CAO/bijzonder tarief/30%/pension computation) and that
production use requires verification against the official test sets by a qualified
loonadministrateur. Honesty is a feature: the disclaimer is a requirement, not a footnote.

#### Scenario: The disclaimer is present and complete
- **WHEN** `README.md` is read after this change
- **THEN** it contains the non-certification statement, the engineVersion traceability note, the
  official-test-set gap and the named MVP limitations
