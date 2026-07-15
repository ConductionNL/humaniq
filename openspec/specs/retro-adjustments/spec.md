---
capability: retro-adjustments
status: done
built_by: openspec/changes/archive/2026-07-14-retro-adjustments
---

# retro-adjustments Specification

**Status**: done
**Scope**: hrmq (consumes the merged `payroll-core-engine` and `payroll-core-schema`, no `depends_on`)
**OpenSpec changes**:
- [retro-adjustments](../../changes/archive/2026-07-14-retro-adjustments/) _(archived 2026-07-15)_ —
  terugwerkende kracht (TWK) corrections for a sealed prior-period payslip modeled as a DELTA
  (`PayrollAdjustment`), a `RetroAdjustmentService` that recomputes the original period against ITS
  OWN tax year and diffs a cents-exact delta idempotently by `correctionRef`, the surfacing of that
  delta as a current-run payslip component (`Payslip.retroAdjustment`, never a history mutation), the
  occ `hrmq:payroll:adjust` + `hrmq:payroll:year-transition` commands, the guarded
  `POST /api/payroll/adjust` endpoint + run/adjustment manifest pages, and the
  `nl-retro-adjustment-consistency` corpus self-check with its golden tests (kind: code+config)

## Purpose

The payroll engine (`payroll-core-engine`) seals a period's payslips into a run whose
`engineVersion`/`calculatedAt` are stamped and whose non-`draft` states refuse recalculation — but
real payroll inputs change *after* a period is sealed (a backdated raise, a late-corrected sick day,
a retroactive contract fix). Dutch payroll settles these with terugwerkende kracht herrekening (TWK):
the affected *past* payslip is recomputed against the tax year that governed it, and the **delta**
(not the recomputed slip) is paid or clawed back in the **current** open period — the sealed
historical payslip is never rewritten (it was filed in a loonaangifte and posted to the GL). This
change adds that capability: a `PayrollAdjustment` delta object, a `RetroAdjustmentService` that
recomputes the original period with corrected inputs against the ORIGINAL period's tax year and
diffs a cents-exact delta, and the surfacing of that delta as a component of the current run — plus a
documented, data-only year-transition procedure. The recompute is scoped to same-tax-year
corrections (only `nl-2026.json` ships at HEAD); multi-year historical tables are a named follow-up
(`retro-multi-year-tables`), and the recompute path is already year-generic so that follow-up is
data, not logic.

## Requirements

### Requirement: A PayrollAdjustment SHALL model a delta, never a mutation of the sealed payslip (REQ-RETRO-001)

`lib/Settings/register.d/hr-retro.json` SHALL define a `PayrollAdjustment` schema linking an original
sealed period + employee (`originalPeriod`, `originalPayrollRunId`/`originalPayslipId`/`employeeId`
`$ref`s), the corrected input (`correctionType`, `correctedGrossMonthlySalary`, `correctionRef`), the
computed **delta** in euro number fields (`deltaGross`, `deltaLoonheffing`, `deltaNet`,
`deltaWerknemersverzekeringen`, `deltaZvw`, `deltaVolksverzekeringen`, `deltaVakantiegeldReserved`),
the `engineVersion` used, the `settlementPeriod`/`settlementPayrollRunId`/`settlementLine`, and a
`status` enum (`draft`/`applied`, no `x-openregister-lifecycle`). `lib/Service/RetroAdjustmentService.php`
SHALL read the stored original Payslip only to diff **recomputed − stored** into cents-exact delta
fields, and SHALL never pass the original Payslip or PayrollRun to `saveObject`/`deleteObject`. A
`nl-retro-adjustment-consistency` corpus rule (PayrollAdjustment, ONE static `mandatory` severity)
SHALL, when `engineVersion` is present, assert the recorded delta equals recomputed − stored.

#### Scenario: The delta is stored on a new object; the sealed payslip is byte-untouched
- **GIVEN** an approved PayrollRun for 2026-02 with a sealed Payslip for the seeded employee
- **WHEN** `hrmq:payroll:adjust` computes a correction for that employee/period
- **THEN** a `PayrollAdjustment` is created carrying the cents-exact `delta*` fields, and the original
  Payslip and its PayrollRun are unchanged (no write occurred against them)

#### Scenario: A tampered delta fails the corpus self-check
- **GIVEN** a computed PayrollAdjustment whose `deltaNet` was hand-edited to disagree with the
  recorded corrected input
- **WHEN** `occ hrmq:rules:audit` runs
- **THEN** an `nl-retro-adjustment-consistency` violation is reported for that adjustment

### Requirement: The recompute SHALL use the ORIGINAL period's tax year (same-tax-year MVP) (REQ-RETRO-002)

`RetroAdjustmentService` SHALL derive the table id as `nl-{year of originalPeriod}` and recompute
with `PayrollCalculator` against `TaxTables::load()` of that year — never the current year's tables.
When the historical table file is absent it SHALL refuse the adjustment with a clear
`historical-tables-missing` message (naming the missing `{tableId}.json`) rather than recompute
against a wrong year. The MVP is therefore scoped to corrections whose original period falls in a tax
year for which a table exists (2026 at HEAD); multi-year historical corpora are a named follow-up
(`retro-multi-year-tables`), and the recompute code SHALL already be year-generic so that follow-up is
data-only. The adjustment's `engineVersion` SHALL be the loaded table id.

#### Scenario: A same-tax-year correction recomputes against that year's tables
- **GIVEN** an available `nl-2026.json` and a sealed 2026-02 payslip
- **WHEN** an adjustment is computed for original period 2026-02 with a corrected gross salary
- **THEN** the recompute uses `nl-2026`, the adjustment's `engineVersion` is `nl-2026`, and the delta
  reflects the 2026 schijven/kortingen

#### Scenario: A cross-year correction refuses when the historical table is missing
- **GIVEN** only `nl-2026.json` exists
- **WHEN** an adjustment is requested for original period 2025-11
- **THEN** the service refuses with a `historical-tables-missing` message naming `nl-2025.json`, and
  no PayrollAdjustment is written

### Requirement: Adjustments SHALL be idempotent by (originalPeriod, employeeId, correctionRef) (REQ-RETRO-003)

`RetroAdjustmentService.adjustFor()` SHALL probe for an existing PayrollAdjustment matching
`(originalPeriod, employeeId, correctionRef)` (probe-before-create) and update it in place when found,
create it when absent — so re-running the same correction produces exactly one adjustment and never
double-counts the delta.

#### Scenario: Re-running the same correction is an idempotent no-op
- **GIVEN** an adjustment already computed for (2026-02, seeded employee, correctionRef `t1`)
- **WHEN** `hrmq:payroll:adjust --original-period 2026-02 --employee EID --correction-ref t1 --gross 4000`
  runs again
- **THEN** no second PayrollAdjustment exists and the recorded delta is unchanged

### Requirement: The delta SHALL surface as a component of the CURRENT run, never in history (REQ-RETRO-004)

`Payslip` SHALL gain a nullable `retroAdjustment` component field. When `PayrollRunService.generate()`
builds the draft run for period P, it SHALL sum every `applied` PayrollAdjustment whose
`settlementPeriod == P` for that employee into the payslip's `retroAdjustment` (a nabetaling or
terugvordering line) and fold it into `nettoPay`. Only `applied` adjustments SHALL surface (a `draft`
adjustment is computed-but-unsettled and affects no run); applying an adjustment SHALL stamp its
`settlementPayrollRunId`. The sealed historical payslip SHALL NOT be modified to carry the delta.

#### Scenario: An applied adjustment lands in the current draft run's payslip
- **GIVEN** an `applied` PayrollAdjustment with `settlementPeriod` 2026-04 and a positive net delta
- **WHEN** the 2026-04 draft run is generated for that employee
- **THEN** the 2026-04 payslip's `retroAdjustment` carries the delta as a nabetaling and its
  `nettoPay` includes it, while the original sealed payslip is unchanged

#### Scenario: A draft (unsettled) adjustment does not affect any run
- **GIVEN** a `draft` PayrollAdjustment for settlement period 2026-04
- **WHEN** the 2026-04 draft run is generated
- **THEN** no `retroAdjustment` component is added to that employee's payslip

### Requirement: Sealed originals only; a draft original SHALL recompute directly, not via an adjustment (REQ-RETRO-005)

The service SHALL refuse to create an adjustment when the original PayrollRun's `status` is `draft`
(`refused-original-draft` — the run can be recomputed directly via `hrmq:payroll:run --recalculate`,
the existing engine path). Adjustments SHALL apply only to originals in `approved`/`posted`/`paid`,
whose payslips the engine itself refuses to recompute — so the sealed truth is corrected through a
separate delta object, never by reopening a booked run.

#### Scenario: A draft original run refuses adjustment
- **GIVEN** a PayrollRun for 2026-05 still in status `draft`
- **WHEN** `hrmq:payroll:adjust --original-period 2026-05 ...` runs (occ or endpoint)
- **THEN** the service refuses with `refused-original-draft` (endpoint: HTTP 400) and no
  PayrollAdjustment is written

### Requirement: Year-transition SHALL keep the taxYear period-derived and immutable once stamped (REQ-RETRO-006)

The engine SHALL keep deriving a run's tax-year table from its own period (no mutable "active tax
year" global), and a generated run's `engineVersion`/`calculatedAt` stamp together with the
non-`draft` recompute refusal SHALL make that stamp immutable. `occ hrmq:payroll:year-transition
--year YYYY` SHALL be the preflight for the annual roll: it SHALL assert `lib/Standards/tables/nl-YYYY.json`
exists (failing loudly otherwise), report that the roll is data-only (ship the table; runs pick it up
by period), and confirm the immutable-stamp guard — changing no engine state. Registered in
`appinfo/info.xml`.

#### Scenario: Year-transition preflight passes only when the new table exists
- **GIVEN** `nl-2027.json` has not been shipped
- **WHEN** `occ hrmq:payroll:year-transition --year 2027` runs
- **THEN** it fails loudly naming the missing `nl-2027.json`, and changes no run or stamp

#### Scenario: A stamped prior-year run keeps its engineVersion after the roll
- **GIVEN** an approved 2026-12 run stamped `engineVersion: nl-2026`
- **WHEN** the year is rolled to 2027 (the 2027 table is shipped)
- **THEN** the 2026-12 run's `engineVersion` and `calculatedAt` are unchanged and it still refuses
  recomputation

### Requirement: occ + a guarded endpoint + a PayrollRunDetail action SHALL drive adjustments (REQ-RETRO-007)

An occ command SHALL compute and settle adjustments: `hrmq:payroll:adjust --original-period YYYY-MM
--employee EID --correction-ref REF [--gross AMOUNT] [--settlement-period YYYY-MM] [--apply]`
computes (and with `--apply` settles) an adjustment and prints the delta + idempotency outcome. `appinfo/routes.php` SHALL add
`POST /api/payroll/adjust` → `PayrollController::adjust` (`#[NoAdminRequired]`) which resolves the
posted `adjustmentId` (and its `originalPayrollRunId`) through ObjectService under the caller's ambient
RBAC before any recompute (unknown/unauthorized → 404, the `calculate` no-admin-idor precedent) and
refuses recompute of an `applied` adjustment (400). `src/manifest.json`: `PayrollRunDetail` SHALL gain
an `open-form` action "Correctie boeken (TWK)" that creates a draft PayrollAdjustment prefilled with
`originalPayrollRunId: "@objectId"`, and a `PayrollAdjustmentDetail` page SHALL carry the `api-call`
"Herrekenen" action (`params: {adjustmentId: "@objectId"}`, confirm) — no `lifecycleActions`. `npm run
check:manifest` MUST pass.

#### Scenario: An unauthorized adjustment id never reaches the recompute
- **GIVEN** a caller whose RBAC cannot see adjustment X (or X does not exist)
- **WHEN** they POST `/api/payroll/adjust` with `adjustmentId: X`
- **THEN** the response is 404 and no recompute or write occurs

#### Scenario: The PayrollRunDetail action books a correction against the sealed run
@e2e exclude declarative action wiring is covered by the shared CnPageRenderer library tests; the app-level e2e suite does not exist yet (tracked by active change hrmq-test-coverage-baseline)
- **GIVEN** an approved run open on PayrollRunDetail
- **WHEN** the user executes "Correctie boeken (TWK)" and submits the corrected input
- **THEN** a draft PayrollAdjustment is created with `originalPayrollRunId` set to the run and the user
  lands on PayrollAdjustmentDetail
