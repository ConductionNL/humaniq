# Delta — payroll-mutation-reports

Depends on `payroll-core-engine` (computed PayrollRuns, Payslip component cents fields, the Payslip
`payrollRunId` `$ref`): a pure run-to-run payroll diff service (per-employee join/leave detection +
per-component cents deltas), run-level roll-ups, the `hrmq:payroll:mutations` occ command, an
idempotent persisted `PayrollMutationReport`, first-run handling, and admin-only report pages.

## ADDED Requirements

### Requirement: A pure service SHALL classify each employee as entered/left/changed/unchanged across two runs (REQ-MUT-001)

`lib/Service/PayrollMutationService.php` SHALL take two PayrollRun ids (`fromRunId`, `toRunId`),
resolve each run and its `payrollRunId`-scoped Payslips (the `RuleAuditService::auditPayrollRunScope`
idiom), key both payslip sets by `employeeId`, and classify each employee by set membership:
present only in `to` → **entered**, present only in `from` → **left**, present in both with any
headline component (`grossPay`/`nettoPay`/`loonheffing`/employer-cost) differing → **changed**,
present in both with all four equal → **unchanged**. The service SHALL have zero Nextcloud
dependency in the computation (ObjectService reads only, no clock, no HTTP) and SHALL never
construct `PayrollCalculator`, read the tax tables, or write any PayrollRun/Payslip — it reads
persisted payslips and subtracts, so it cannot drift from the engine.

#### Scenario: An employee present in both runs with a changed gross is classified changed
- **GIVEN** a `from` run with employee A at grossPay 380000 and a `to` run with A at grossPay 400000
- **WHEN** the diff runs
- **THEN** A is classified `changed`

#### Scenario: Employees appearing/disappearing are entered/left
- **GIVEN** a `from` run containing employee B (not in `to`) and a `to` run containing employee C
  (not in `from`)
- **WHEN** the diff runs
- **THEN** B is classified `left` and C is classified `entered`

### Requirement: The service SHALL compute per-component integer-cents deltas for shared employees (REQ-MUT-002)

For each employee present in both runs the service SHALL compute, in **integer cents**, the delta
`to.component − from.component` for `grossPay`, `nettoPay`, `loonheffing` and total employer cost
(`werknemersverzekeringen + zvw`), carrying `before`/`after`/`delta` per component on the employee's
mutation line. Subtraction of the already-cents-carrying fields SHALL be exact — no float
accumulation and no rounding.

#### Scenario: Gross and employer-cost deltas are exact cents
- **GIVEN** employee A `from` (grossPay 380000, werknemersverzekeringen 41914, zvw 23180) and `to`
  (grossPay 400000, werknemersverzekeringen 44000, zvw 24000)
- **WHEN** the diff runs
- **THEN** A's grossPay delta is +20000 and A's employer-cost delta is +2906 (68000 − 65094)

### Requirement: The service SHALL roll per-employee deltas into run-level totals (REQ-MUT-003)

The service SHALL compute, over the union of the two runs' payslips, `grossDelta`, `netDelta`,
`loonheffingDelta` and `employerCostDelta` as `Σ to − Σ from` (an entered employee's `from` side
counts as 0, a left employee's `to` side as 0), `totalWageCostDelta = grossDelta + employerCostDelta`
(the total wage-cost delta), and the counts `enteredCount`/`leftCount`/`changedCount`/
`unchangedCount` with `changedEmployeeCount = changedCount`.

#### Scenario: The worked example rolls up to the expected run-level deltas
- **GIVEN** `from` = {A gross 380000/net 308117/loonheffing 71883/employerCost 65094, B gross
  250000/net 210000/loonheffing 30000/employerCost 40000} and `to` = {A gross 400000/net
  322000/loonheffing 78000/employerCost 68000, C gross 300000/net 240000/loonheffing
  42000/employerCost 48000}
- **WHEN** the diff runs
- **THEN** grossDelta is +70000, netDelta is +43883, loonheffingDelta is +18117, employerCostDelta
  is +10906, totalWageCostDelta is +80906, and the counts are entered 1 / left 1 / changed 1 /
  unchanged 0 with changedEmployeeCount 1

### Requirement: An occ command SHALL print the mutation report (REQ-MUT-004)

`occ hrmq:payroll:mutations --from <runId> --to <runId> [--persist]` SHALL print the per-employee
mutation table (entered / left / changed rows with the per-component deltas) and the run-level
deltas + counts. `--to <runId>` alone (no `--from`) SHALL auto-resolve the prior run (REQ-MUT-006).
`--persist` SHALL upsert the `PayrollMutationReport` (REQ-MUT-005). The command SHALL be registered
in `appinfo/info.xml`.

#### Scenario: The command prints entered/left/changed rows and run-level totals
- **GIVEN** two engine-computed runs for administration ADM-001 (2026-02 and 2026-03)
- **WHEN** `occ hrmq:payroll:mutations --from <feb> --to <mar>` runs
- **THEN** it prints each employee's classification and headline deltas and the run-level
  `totalWageCostDelta` and `changedEmployeeCount`

### Requirement: The report SHALL be persistable as an idempotent PayrollMutationReport keyed (fromRunId, toRunId) (REQ-MUT-005)

`lib/Settings/register.d/hr-objects.json` SHALL define a `PayrollMutationReport` schema (slug
`PayrollMutationReport`) carrying the header (fromRunId, toRunId, fromPeriod, toPeriod,
administrationId, generatedAt), the run-level deltas (grossDelta, netDelta, loonheffingDelta,
employerCostDelta, totalWageCostDelta), the counts (enteredCount, leftCount, changedCount,
unchangedCount), and a `lines` array of per-employee mutations. Persisting SHALL probe for an
existing report with the same `(fromRunId, toRunId)` and **upsert in place**, never creating a
duplicate; `required` SHALL include toRunId, toPeriod, administrationId, generatedAt. The service
SHALL write via OpenRegister's ObjectService (register `hrmq`).

#### Scenario: Regenerating the same run pair updates one report
- **GIVEN** a persisted report for `(fromRunId=R1, toRunId=R2)`
- **WHEN** the report is generated again for the same pair with `--persist`
- **THEN** the existing report is updated in place and no second `PayrollMutationReport` exists for
  `(R1, R2)`

### Requirement: The service SHALL auto-resolve the prior run and refuse cross-administration diffs (REQ-MUT-006)

When only `toRunId` is given the service SHALL resolve `from` = the PayrollRun of the **same
administration** whose `period` is the closest one strictly before the `to` run's period; when none
exists it SHALL take the first-run path (REQ-MUT-007). When both runs are given the service SHALL
refuse the diff if their `administrationId` differ (a cross-administration comparison is meaningless
— occ: `failed` outcome with a clear message; endpoint: HTTP 400).

#### Scenario: Prior period of the same administration is auto-resolved
- **GIVEN** administration ADM-001 has runs for 2026-01, 2026-02 and 2026-03
- **WHEN** `hrmq:payroll:mutations --to <2026-03 run>` runs
- **THEN** the report compares the 2026-02 run against the 2026-03 run

#### Scenario: A cross-administration pair is refused
- **GIVEN** run R1 of administration ADM-001 and run R2 of administration ADM-099
- **WHEN** a diff of `(R1, R2)` is requested
- **THEN** the service refuses (endpoint 400, occ non-zero) and produces no report

### Requirement: A first run with no prior SHALL yield every employee entered with zero-baseline deltas (REQ-MUT-007)

When no `from` run exists (auto-resolution finds none, or `fromRunId` is explicitly null) the service SHALL classify **every** `to`-run employee as `entered` with per-component `before = 0`,
`after = component`, `delta = component`; `leftCount`, `changedCount` and `unchangedCount` SHALL be
0; the run-level deltas SHALL equal the `to` run's own totals; and `fromRunId`/`fromPeriod` on the
persisted report SHALL be null/empty. A first run SHALL be produced and persistable — it is a valid
input, not an error.

#### Scenario: The first run of an administration diffs against nothing
- **GIVEN** administration ADM-050 whose only run is 2026-01 (no earlier period)
- **WHEN** `hrmq:payroll:mutations --to <2026-01 run> --persist` runs
- **THEN** every employee is classified `entered` with `before = 0`, `totalWageCostDelta` equals the
  run's gross + employer charges, and the persisted report's `fromRunId` is null

### Requirement: Report generation and surfaces SHALL be admin/HR only through ONE guarded endpoint (REQ-MUT-008)

`appinfo/routes.php` SHALL add `POST /api/payroll/mutations` → `PayrollController::mutations`
(`#[NoAdminRequired]`) which SHALL enforce an explicit **admin/HR authorization check** (non-admin
callers get HTTP 403 — payroll figures are sensitive), resolve the run(s) through ObjectService
under the caller's ambient RBAC first (unknown/unauthorized → 404, the `PayrollController::calculate`
no-admin-idor pattern), refuse cross-administration pairs (400, REQ-MUT-006), then generate + persist
and return the report id. `src/manifest.json` SHALL add a `PayrollMutations` report page and a
`PayrollMutationReportDetail` detail page (run-level deltas as stat KPIs + the per-employee mutation
table), both admin-scoped under the Payroll nav group, and a `PayrollRunDetail` `api-call` action
"Mutatieoverzicht" (`params: {toRunId: "@objectId"}`, onSuccessRoute `PayrollMutationReportDetail`).
`npm run check:manifest` MUST pass.

#### Scenario: A non-admin caller is refused
- **GIVEN** an authenticated non-admin/non-HR user
- **WHEN** they POST `/api/payroll/mutations` with a valid `toRunId`
- **THEN** the response is 403 and no report is generated or persisted

#### Scenario: The run page generates a mutation report
@e2e exclude declarative action wiring is covered by the shared CnPageRenderer library tests; the app-level e2e suite does not exist yet (tracked by active change hrmq-test-coverage-baseline)
- **GIVEN** an admin on `PayrollRunDetail` for a run with a prior period
- **WHEN** they execute "Mutatieoverzicht"
- **THEN** the endpoint generates + persists the report and routes to `PayrollMutationReportDetail`
  showing the run-level deltas and per-employee mutations

### Requirement: Unit tests SHALL pin the diff, roll-up, first-run and idempotency behaviour (REQ-MUT-009)

`tests/Unit/Service/PayrollMutationServiceTest.php` (mocked ObjectService) SHALL cover: the
design.md worked example (A changed / B left / C entered → grossDelta +70000, netDelta +43883,
loonheffingDelta +18117, employerCostDelta +10906, totalWageCostDelta +80906), per-component
cents deltas, the first-run all-entered path, the idempotent `(fromRunId, toRunId)` upsert, and the
same-administration guard.

#### Scenario: The worked-example fixture reproduces the run-level deltas
- **WHEN** `composer test` (or `vendor/bin/phpunit`) runs
- **THEN** the service test asserts the worked example's exact run-level deltas and per-employee
  classifications, the first-run path, the idempotent upsert and the cross-administration refusal
