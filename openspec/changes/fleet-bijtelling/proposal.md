---
kind: code
---

# Fleet Bijtelling — fiscal addition for private use of a company car

## Why

**Verified against HEAD 2026-07-15.** `lib/Settings/register.d/hr-assets.json`'s `Asset` schema
already carries a `kenteken` field for category `voertuig` and its own docstring names the exact
gap this change closes: *"A voertuig Asset carries its kenteken and nothing fiscal -- bijtelling
automation is explicitly out of scope (engine-blocked per Spectr hrmq-canon-fleet-bijtelling;
follow-up spec fleet-bijtelling)"*. The `asset-management-mvp` design.md recorded the reason it was
blocked: bijtelling needs a maintained Belastingdienst staffel/cap and a **payroll-engine coupling**
that did not exist yet. That coupling now exists — `payroll-core-engine` (merged 2026-07-14) ships
`PayrollCalculator` (pure, table-driven NL gross-to-net) and `PayrollRunService` (assembles the
`CalculationInput` and stamps the `Payslip`) — so this follow-up can finally land.

Bijtelling privégebruik auto (Wet LB 1964 art. 13bis) is not a post-tax adjustment. Unlike
`retro-adjustments`/`leave-buy-sell`/the in-flight `loonbeslag` — which fold an already-decided
external fact onto `Payslip.nettoPay` **without ever re-invoking the calculator** — bijtelling is a
percentage of the car's cataloguswaarde that the employer must **add to the taxable wage** before
loonheffing/premies are computed: it raises `PayrollCalculator`'s taxable gross itself, not just
the net outcome. That makes this a genuine **engine-input** change, and a correctness-critical tax
one: a wrong bijtelling silently mis-states loonheffing, premie volksverzekeringen, Zvw and every
werknemersverzekering the calculator derives from the same `tvl` (design.md D2 of
`payroll-core-engine`) — for every payslip of every employee with a company car, every period. The
bar this change must clear is the one `payroll-core-engine` itself set: a hand-computed golden
anchor, verified digit-for-digit, not merely "the code runs".

## What Changes

- **NEW schemas `Vehicle` + `CarAssignment`** (`lib/Settings/register.d/hr-fleet.json`, new
  fragment) — deliberately **decoupled** from the custody-tracking `Asset`/`AssetAssignment`
  (`hr-assets.json`): `Vehicle` carries the car's fiscal facts (`cataloguswaarde`, `fuelType`,
  `bijtellingCategorie`), `CarAssignment` carries the effective-dated employee link
  (`vehicleId`, `employeeId`, `effectiveFrom`/`effectiveTo`, `eigenBijdrage`) — the
  `AssetAssignment`/`OrgAssignment` shape reused, not extended (design.md D1: an employee's company
  car may or may not also be custody-tracked as an `Asset`; the two records are never linked, and
  neither schema references the other) (REQ-FLEET-001).
- **NEW `bijtellingPrivegebruikAuto` parameter group in `lib/Standards/tables/nl-2026.json`** — the
  2026 rates, sourced and verified against the Belastingdienst page directly (primary source, not a
  secondary summary): standard rate **22%** of cataloguswaarde; reduced rate **18%** for
  zero-emission cars up to and including a cataloguswaarde of **€30.000**, with **22%** applying to
  the excess above that cap (REQ-FLEET-002). Versioned, data-only, per the tables `SCHEMA.md`
  annual-re-issue discipline — no calculator code changes for a rate change in a future tax year.
- **`PayrollRunService::generate()` gains the bijtelling fold — BEFORE the calculator runs.** The
  employee's open `CarAssignment` covering the period (if any) contributes
  `monthlyBijtelling = round(cataloguswarde-base × pct / 12) − eigenBijdrage` (floored at 0),
  **added to `grossMonthlySalaryCents`** immediately after the sick-pay substitution and immediately
  before `CalculationInput` is constructed — the exact point the service already assembles the
  input (`payroll-core-engine` design.md D2 step 1). `PayrollCalculator` itself is **never touched**
  — it only ever sees a `tvl` that already includes bijtelling, so every downstream step (tabelloon,
  schijventarief, heffingskortingen, vakantiegeldreservering, Zvw, Awf/Aof/Wko/Whk) runs unmodified
  over the larger gross. `Payslip` gains `bijtelling` (nullable number) and `carAssignmentId`
  (nullable `$ref` CarAssignment) — null on a payslip with no covering assignment, byte-identical to
  today (REQ-FLEET-003).
- **NEW `lib/Standards/Checks/NlFleetChecks.php`** (auto-discovered `CheckProvider` — `RuleEngine`
  globs `Checks/*.php`, no registration wiring) — the `nl-bijtelling-auto-privegebruik` predicate:
  vacuous when `Payslip.carAssignmentId` is null; else re-derives the expected bijtelling from the
  referenced `CarAssignment` + `Vehicle` (context enrichment `fleet.carAssignmentsById` /
  `fleet.vehiclesById` in `RuleAuditService::audit()`, the `payroll.runsById` precedent) and flags
  any cents-mismatch against the recorded `Payslip.bijtelling` (REQ-FLEET-004).
- **NEW rule** `nl-bijtelling-auto-privegebruik` in `lib/Standards/rules/payroll.json`
  (domain `tax`, framework `nl-bijtelling-auto`, source Wet LB 1964 art. 13bis,
  `machineCheckable: true`) — the corpus statement the new check enforces.

### Non-goals (named fast-follows and exclusions)

- **Grijs kenteken / bestelauto doorlopend-afwisselend-gebruik regimes** — the bestelauto-specific
  bijtelling alternatives (fixed low rate, per-dag regeling) are not modelled; `bijtellingCategorie`
  covers passenger-car `standaard`/`elektrischGeplafonneerd` only.
- **≤500 km privé exemption** (`verklaring geen privégebruik auto` / sluitende rittenregistratie) —
  no workflow to suppress bijtelling on a declared-no-private-use car; every `CarAssignment` covering
  the period is assumed to carry bijtelling.
- **Hydrogen / fully solar-powered uncapped 18%** — the Belastingdienst's uncapped reduced rate for
  hydrogen and fully solar-cell-powered cars is not modelled; `bijtellingCategorie` has no
  `elektrischOnbeperkt` value in the MVP, so those vehicles must be entered as `standaard` (an
  overstatement, never an understatement — the honest-failure direction) until this is added.
- **60-month DET (datum eerste toelating) re-rating** — `bijtellingCategorie` and the applicable
  percentage are fixed inputs on the `Vehicle`/`CarAssignment` for the MVP; the statutory 60-month
  fixed-term boundary (after which the percentage may need to be re-assessed) is not tracked or
  enforced.
- **Overlap/single-active-assignment guard** — unlike `loonbeslag`'s
  `nl-loonbeslag-single-active`, this MVP does not add a machine check for overlapping
  `CarAssignment`s per employee; `PayrollRunService` resolves the first covering assignment (the
  `coveringContract()` precedent). A named follow-up if fleet data proves this happens in practice.

## Capabilities

### New Capabilities

- `fleet-bijtelling`: the `Vehicle`/`CarAssignment` schema pair, the versioned 2026 bijtelling
  table data, the `PayrollRunService` gross-fold (a genuine engine-input change, unlike the
  post-tax folds of `retro-adjustments`/`leave-buy-sell`/`loonbeslag`), the `Payslip.bijtelling` /
  `Payslip.carAssignmentId` fields, and the `NlFleetChecks` corpus enforcement.

### Modified Capabilities

<!-- none — PayrollCalculator's equation chain (payroll-core-engine REQ-PCE-001/-002) is consumed,
     never modified: it still only ever sees one grossMonthlySalaryCents input. PayrollRunService
     gains a fold analogous to the sick-pay-calc substitution (design.md D4 of payroll-core-engine
     already documents "before building the CalculationInput" as the extension point); Payslip
     gains two new nullable fields, the existing shape is otherwise untouched. -->

## Impact

- `lib/Settings/register.d/hr-fleet.json` — NEW (`Vehicle` + `CarAssignment` schemas,
  `x-hrmq-fragment: hr-fleet`).
- `lib/Standards/tables/nl-2026.json` — NEW `parameters.bijtellingPrivegebruikAuto` group +
  `basedOn` citation; `RuleCatalogue::VERSION` bump (tables `SCHEMA.md` discipline).
- `lib/Settings/register.d/hr-objects.json` — `Payslip.bijtelling` + `Payslip.carAssignmentId` NEW
  nullable fields; `Payslip.version` bump.
- `lib/Service/PayrollRunService.php` — bijtelling fold (open-CarAssignment index, formula, gross
  addition BEFORE `CalculationInput`, payload merge), inserted after the sick-pay substitution and
  before the retro-adjustment/leave-buy-sell post-tax folds.
- `lib/Standards/Checks/NlFleetChecks.php` — NEW; `lib/Service/RuleAuditService.php` — context
  enrichment (`fleet.vehiclesById`, `fleet.carAssignmentsById`); `lib/Standards/rules/payroll.json`
  — 1 new rule id (`nl-bijtelling-auto-privegebruik`).
- `tests/fixtures/payroll-2026/*.json` — a new bijtelling-anchor fixture (€3.800 salary +
  €500 bijtelling → €4.300 taxable gross, this change's hand-computed golden case);
  `tests/Unit/Service/PayrollRunServiceTest.php` — bijtelling fold cases (present, absent, floored
  at zero); `tests/Unit/Standards/NlFleetChecksTest.php` — NEW.
- `src/manifest.json` — `Vehicles`/`CarAssignments` index + detail pages (fleet admin surface);
  `npm run check:manifest` passes.
- No change to `lib/Payroll/PayrollCalculator.php`, `lib/Payroll/CalculationInput.php`, or
  `lib/Payroll/CalculationResult.php` — the entire integration is upstream of the calculator call.
