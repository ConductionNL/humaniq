# Design — sick-pay-calc

## Context

**Verified against HEAD 2026-07-14.** Consumes two merged changes (no `depends_on` — both are
archived at HEAD):

- **`payroll-core-engine`**: `lib/Payroll/PayrollCalculator.php` —
  `calculate(CalculationInput, TaxTables): CalculationResult`, pure, zero Nextcloud deps, integer
  cents throughout; `lib/Payroll/TaxTables.php` — loads `lib/Standards/tables/nl-2026.json` (which
  carries `parameters.wml.referentiemaandloon` = 2294.40 and `hourly21Plus` = 14.71, both
  `verified: true`); `lib/Service/PayrollRunService.php` — idempotent draft run per
  (period, administrationId), one Payslip per active NL employee, currently feeding the employee's
  full `grossMonthlySalary` into the calculator (`generate()` at HEAD, line ~284/297).
- **`leave-verzuim-mvp`**: `SickLeaveCase` (`lib/Settings/register.d/hr-verzuim.json`) —
  `firstSickDay`, `status` (gemeld↔hersteld), `wachtdag` (bool), `loondoorbetalingPercentage`
  (default 70); the corpus rule `nl-loondoorbetaling-minimum` (BW 7:629 lid 1, mandatory) and its
  `NlVerzuimChecks::loondoorbetalingSatisfied()` predicate, which asserts only that an open case
  *carries* `loondoorbetalingPercentage ≥ 70` — no euro is computed.

Patterns this change mirrors (all read at HEAD):

- **Pure calculator shape**: `PayrollCalculator` + `CalculationInput`/`CalculationResult` — a
  `final` class in `lib/Payroll/` with no container, clock or IO; every intermediate integer cents;
  rounding stated per step. `SickPayCalculator` is the same shape.
- **Service pre-processing**: `PayrollRunService::generate()` already resolves per-employee data
  (contract, tax colour, salary) before building `CalculationInput`; the open-case lookup slots in
  at exactly that point and only substitutes the gross figure.
- **Check provider**: `NlVerzuimChecks` — single-object, side-effect-free predicates keyed by rule
  id under an object type (`SickLeaveCase`); the new `nl-loondoorbetaling-floor` predicate is added
  under `Payslip` in the same file (the sick-pay rules already live there).
- **Corpus rule shape**: `lib/Standards/rules/labour.json` rules carry
  `id/domain/jurisdiction/framework/source/statement/severity/machineCheckable/effectiveDate/sourceUrl`
  and optional `parameters` (the `nl-wvp-milestone-derivation` `milestoneWeeks` precedent).

## Goals / Non-Goals

**Goals:** a deterministic, integer-cents, table/parameter-driven loondoorbetaling computation
(70% + year-1 WML floor + wachtdag + CAO uplift + samengesteld/aangepast loon) that pre-processes
the gross fed to the verified `PayrollCalculator`, so a sick employee's payslip reflects doorbetaald
loon; the below-floor invariant made a machine-checkable mandatory corpus rule; one hand-computed
golden fixture + idempotency.

**Non-Goals (from the proposal, binding):** day/hour timesheet proration beyond the composite
`aangepastLoon`; second-year percentage tapering, 104-week hand-off, vangnet/ZW/no-risk/ziekengeld;
bijzonder tarief on doorbetaald loon; CAO staffel tables; write-time Payslip guards
(`hrmq-rule-compliance-enforcement`'s scope).

## Decisions

### D1 — SickPayCalculator is a pure function of (input, tables); all money is integer cents

`SickPayCalculator::compute(SickPayInput $in, TaxTables $t): SickPayResult` has zero Nextcloud
dependencies. Input carries the reference wage in cents, `aangepastLoonCents`,
`loondoorbetalingPercentage` (from the case), `yearOne` (bool), `wachtdag` (bool),
`firstSickDayInPeriod` (bool), and the part-time inputs (`contractHoursPerWeek`,
`fulltimeHoursPerWeek`). The WML monthly floor is derived from
`t.wml().referentiemaandloon` (the verified full-time figure) × the part-time factor, never a
hard-coded number. Every intermediate is integer cents; the two rounding points (the percentage
multiply and the wachtdag divide) round half-up to whole cents. This is what makes the golden
fixture byte-stable.

### D2 — The exact loondoorbetaling chain (all cents)

Given reference wage `W`, adjusted/worked wage `A` (`aangepastLoon`, `0 ≤ A ≤ W`, `0` when fully
sick), percentage `p` (`loondoorbetalingPercentage`, `≥ 70` by `nl-loondoorbetaling-minimum`),
`yearOne` (period within the first 52 weeks of `firstSickDay`), WML monthly floor `M`, wachtdag
flag and `firstSickDayInPeriod`, `workingDaysPerMonth D` (rule parameter, `21.75`):

1. **Non-worked base**: `B = W − A`.
2. **Continuation on the non-worked part**: `C = round(B × p / 100)`.
3. **Doorbetaald (pre-floor/pre-wachtdag)**: `L0 = A + C` (worked wage at 100% + continuation on
   the rest — the samengesteld/aangepast loon composition).
4. **Statutory floor**: `floor = max( round(W × 70 / 100), (yearOne ? min(W, M) : 0) )` — always
   at least 70% of the wage; in year 1 additionally at least the WML (capped at `W`, since
   doorbetaling never exceeds the wage).
5. **Floored doorbetaald**: `L = max(L0, floor)`; `floorApplied = (L > L0)`.
6. **Wachtdag**: `wd = (wachtdag AND firstSickDayInPeriod) ? round(L / D) : 0` — one waiting day,
   valued at the doorbetaald daily rate, charged once per case at its start.
7. **Payable gross**: `payableGross = L − wd`.

`payableGross` is fed to the verified `PayrollCalculator` as `grossMonthlySalaryCents` (replacing
the full salary), so loonheffing and net are computed on the doorbetaald loon. The result also
carries `M` (`minimumWageFloorCents`), `p` (`appliedPercentage`), `yearOne`, `W`
(`referenceWageCents`) and `L` (`doorbetaaldLoonCents`) for stamping onto the Payslip and for the
independent floor recomputation in the check (D5).

**Worked example (the anchor fixture — every figure hand-computed):**
Fully-sick employee, `W` = €3.800,00 = `380000` cents, `A` = `0`, `p` = 70, year 1, full-time
(36/36 → `M` = referentiemaandloon = €2.294,40 = `229440`), no wachtdag:

- `B = 380000 − 0 = 380000`;
- `C = round(380000 × 70 / 100) = round(266000) = 266000`;
- `L0 = 0 + 266000 = 266000`;
- `floor = max(round(380000 × 70/100)=266000, min(380000, 229440)=229440) = 266000`;
- `L = max(266000, 266000) = 266000`, `floorApplied = false`;
- `wd = 0` (no wachtdag) → **`payableGross = 266000` = €2.660,00**.

So the sick employee's payslip `grossPay` is **€2.660,00**, not €3.800,00, and the existing engine
computes loonheffing/net on that €2.660,00 (the gross-to-net chain itself is `payroll-core-engine`'s
already-verified concern — this design stops at the doorbetaald gross).

**Cross-checks (three more hand computations, the sibling fixtures):**

| Case | `W` | `A` | `p` | year | wachtdag | `C` | `L0` | `floor` | `L` | `wd` | `payableGross` |
|---|---|---|---|---|---|---|---|---|---|---|---|
| Floor binding | 300000 | 0 | 70 | 1 | no | 210000 | 210000 | max(210000, min(300000,229440)=229440)=229440 | **229440** (`floorApplied`) | 0 | **229440** (€2.294,40) |
| Wachtdag | 380000 | 0 | 70 | 1 | yes | 266000 | 266000 | 266000 | 266000 | round(266000/21.75)=round(12229.885)=**12230** | **253770** (€2.537,70) |
| CAO 100% | 380000 | 0 | 100 | 1 | no | 380000 | 380000 | 266000 | 380000 | 0 | **380000** (full wage) |
| Aangepast loon | 380000 | 100000 | 70 | 1 | no | round(280000×0.70)=196000 | 296000 | 266000 | 296000 | 0 | **296000** (€2.960,00) |
| Year 2 (no WML floor) | 300000 | 0 | 70 | 2 | no | 210000 | 210000 | max(210000, 0)=210000 | 210000 | 0 | **210000** (€2.100,00) |

The year-2 row shows the floor switch: the same €3.000,00 wage that floors to €2.294,40 in year 1
pays the bare 70% (€2.100,00) once past 52 weeks.

### D3 — yearOne and the WML floor are period/tables-driven, not hard-coded

`yearOne` = the run period falls within the first 52 weeks of `firstSickDay`: computed from
`firstSickDay` vs the period (weeks elapsed `< maxWeeks/2` where the rule carries `maxWeeks: 104`;
52-week boundary). `M` (WML monthly floor) = `round(referentiemaandloonCents × contractHoursPerWeek
/ fulltimeHoursPerWeek)`, using the verified `parameters.wml.referentiemaandloon` from `nl-2026.json`
so the floor tracks the tables, not a literal; full-time (36/36) → `229440`. The hourly×hours path
(`hourly21Plus` × actual hours) is a documented refinement — the referentiemaandloon route keeps the
floor aligned with the tables' single verified monthly figure.

### D4 — PayrollRunService substitutes only the gross; the full-salary path is untouched

In `generate()`, after resolving the covering contract, tax colour and `grossMonthlySalary`, look up
an **open (gemeld)** `SickLeaveCase` for the employee whose `firstSickDay ≤ period end` and
(recovered or open) covering the period. When present: build `SickPayInput` (reference =
`grossMonthlySalary` cents, `aangepastLoon` from the case, `p` = `loondoorbetalingPercentage`,
`yearOne`/`M`/`wachtdag`/`firstSickDayInPeriod` per D2/D3), `compute()`, and use
`result.payableGrossCents` as the `CalculationInput.grossMonthlySalaryCents`. When absent: the gross
is the full salary exactly as today. Either way `PayrollCalculator` is called unchanged. The Payslip
payload additionally stamps `sickLeaveCaseId`, `doorbetaaldLoon`, `wachtdagDeduction`,
`sickPayReferenceWage`, `sickPayPercentage`, `sickPayMinimumWageFloor`, `sickPayYearOne` (all null /
absent on a non-sick slip). Idempotency, orphan cleanup, roll-up and the draft-only guard are
inherited unchanged from `PayrollRunService`.

### D5 — The below-floor rule: one mandatory corpus rule + one recomputing Payslip predicate

New rule `nl-loondoorbetaling-floor` (labour.json, framework `bw7-10`, `severity: mandatory`,
`machineCheckable: true`, `parameters: {statutoryPercentage: 70, year1MinimumWageFloor: true,
workingDaysPerMonth: 21.75, maxWeeks: 104}`). Its predicate is added under `Payslip` in
`NlVerzuimChecks`:

- **Vacuous** when `sickLeaveCaseId` is null (a normal payslip is out of scope).
- Else **independently recompute** the floor from the payslip's own recorded fields and the corpus
  parameter: `floor = max( round(sickPayReferenceWage × statutoryPercentage/100),
  (sickPayYearOne ? sickPayMinimumWageFloor : 0) )` and assert cents-exact `doorbetaaldLoon ≥ floor`.
  This is an independent recomputation (not a stored answer echoed back), so it catches both an
  engine regression and a hand-edit that drops the paid amount below 70%/WML.

**ONE-static-severity-per-rule engine constraint (design.md, and the standing `NlVerzuimChecks`
note):** `RuleEngine::violationFor()` always takes a Violation's severity from the catalogue rule,
never from the predicate call site, and `RuleCatalogue`'s severity enum is
`mandatory | conditional | recommended` (no `advisory`). A single rule id therefore emits exactly
one severity for every object it matches. `nl-loondoorbetaling-floor` is consequently a single
**mandatory** boolean — "doorbetaald loon below the 70%/WML floor" — not a mandatory/advisory split;
any sub-floor payslip is a mandatory violation, mirroring `nl-loondoorbetaling-minimum`.

### D6 — Golden fixture is hand-computed; idempotency is asserted at the service

`tests/fixtures/sick-pay-2026/anchor.json` carries the D2 anchor (`{input: {referenceWage,
aangepastLoon, percentage, yearOne, wmlFloor, wachtdag, firstSickDayInPeriod, workingDaysPerMonth},
expected: {doorbetaaldLoon, wachtdagDeduction, payableGross, floorApplied, minimumWageFloor}}`),
byte-matching the hand computation (`payableGross` = `266000`). `PayrollSickPayCalculatorTest` runs
the anchor plus the four D2 cross-check rows (floor-binding, wachtdag, CAO-100%, aangepast-loon) and
the year-2 switch. `PayrollRunServiceSickPayTest` (mocked ObjectService, the
`payroll-core-engine` test idiom) asserts: an open-case employee's generated payslip carries
`grossPay` = the doorbetaald loon (not the full salary) and the stamped sick-pay fields; and a second
`runFor()` for the same (period, administrationId) without `--recalculate` produces no second run and
no changed payslip (idempotent — the existing probe-before-create path, re-exercised through the
sick branch).

### Declarative vs imperative (ADR-031)

| Behaviour | Path | Rationale |
|---|---|---|
| WML / referentiemaandloon / hourly figures | `payroll-core-engine` static tables (`nl-2026.json`) | tax-year data corpus; consumed, not duplicated |
| Loondoorbetaling parameters (70%, year-1 floor, wachtdag divisor, 104 weeks) | corpus rule `nl-loondoorbetaling-floor` `parameters` | facts about the law → versioned static data (the `nl-wvp-milestone-derivation` precedent) |
| Sick-pay computation | **imperative pure PHP** (`lib/Payroll/SickPayCalculator.php`) | a multi-step statutory formula with per-step rounding and a floor/composition — exactly what schema-declarative calculation cannot express; the ADR-031 exception, same class as `PayrollCalculator` and the CheckProviders |
| Gross substitution + payslip stamping | imperative service (`PayrollRunService::generate()`) | cross-object read (open case) feeding an existing multi-write with idempotency |
| Below-floor enforcement | corpus rule + `NlVerzuimChecks` predicate | the app's established rules-as-data + check-provider exception |
| SickLeaveCase / Payslip fields | declarative register schema (`register.d/*.json`) | ADR-031 default |

## Seed Data (ADR-001)

**No new seed objects.** The existing `lib/Settings/register.d/hr-seed.json` already seeds four
`SickLeaveCase` objects that exercise every branch of this feature against the seeded employees:
`employee-devries` (open since 2026-05-25 — recent, year 1), `employee-bakker` (open since
2025-09-29 — year 1 through ~Sept 2026), `employee-degroot` (open since 2025-06-02 — crosses into
year 2 around 2026-06), and `employee-jansen` (hersteld, `wachtdag: true` — out of scope once
recovered). The new Payslip sick-pay fields are null on the pre-existing hand-entered seed payslip
(so `nl-loondoorbetaling-floor` is vacuous for it — no false violation). The golden fixture IS this
change's canonical data. The dev-container gate exercises the real path: `occ hrmq:payroll:run
--period 2026-06` computes doorbetaald-loon payslips for the open-case employees, then `occ
hrmq:payroll:verify --period 2026-06` (and `occ hrmq:rules:audit`) must report zero
`nl-loondoorbetaling-floor` violations for a freshly generated run.

## Risks / Trade-offs

- **Self-consistent goldens can share a bug with the implementation.** Mitigated: the D2 anchor and
  the four cross-check rows are hand-computed in this design (not by the future code), and the
  below-floor check recomputes the floor independently from the payslip's own recorded figures.
- **`workingDaysPerMonth = 21.75` is a CBS/CAO average, not a per-calendar-month count.** A wachtdag
  is therefore valued at an average daily rate, not the exact working days of that month — documented
  as a parameter; exact-calendar valuation is a fast-follow. It never touches the non-wachtdag path.
- **referentiemaandloon vs hourly×hours.** The floor uses the tables' single verified monthly figure
  scaled by the part-time factor; for an employee whose contract hours diverge from the 36h
  full-time basis the hourly×hours route would differ by cents. Named in D3; the referentiemaandloon
  route keeps the floor tied to a `verified: true` table value.
- **Composite `aangepastLoon` vs true day-level proration.** The MVP composes worked + sick wage via
  one case field rather than per-day timesheet proration (the named fast-follow); for a clean
  full-period sickness (`A = 0`) the two coincide exactly.

## Open Questions

- None blocking. Day-level proration, hourly WML floor, second-year tapering and vangnet/ZW handling
  are named fast-follows.
