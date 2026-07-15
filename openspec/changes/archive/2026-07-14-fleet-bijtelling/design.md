# Design — fleet-bijtelling

## Context

**Verified against HEAD 2026-07-15.** Reads (never modifies) `payroll-core-engine` (merged
2026-07-14): `lib/Payroll/PayrollCalculator.php` (pure, `calculate(CalculationInput, TaxTables):
CalculationResult`, zero NC deps), `lib/Payroll/CalculationInput.php` (the ONE
`grossMonthlySalaryCents` field the whole gross-to-net chain keys off — `tvl` in design.md D2 of
that change), and `lib/Service/PayrollRunService.php::generate()`, which already builds
`$grossMonthlySalaryCents`, optionally substitutes it with the sick-pay `payableGrossCents`
(sick-pay-calc), constructs `CalculationInput`, calls the calculator, then folds three **post-tax**
facts onto `nettoPay` (retro-adjustments, leave-buy-sell, and the in-flight `loonbeslag`) —
`PayrollCalculator` is never re-invoked for any of those three.

Also read: `lib/Settings/register.d/hr-assets.json`'s `Asset`/`AssetAssignment` (the
`asset-management-mvp` custody-tracking schema pair — `Asset.kenteken` for category `voertuig`
explicitly disclaims fiscal semantics and names this exact follow-up), `lib/Standards/
tables/nl-2026.json` + `SCHEMA.md` (versioned tax-year parameter leaves, `{value, source,
verified}`), `lib/Standards/rules/payroll.json` (domain `tax` — `nl-loonheffingen-inhouding`,
`nl-vakantiebijslag-8procent`, `nl-engine-*`), and `lib/Standards/Checks/NlAssetChecks.php` +
`NlEngineChecks.php` (the `CheckProvider` shape — `RuleEngine::providers()` globs
`Checks/*.php`, no manual registration; cross-object predicates read `RuleAuditService::audit()`'s
enriched context, the `payroll.runsById` precedent).

2026 bijtelling rates verified directly against the Belastingdienst primary source (not a
secondary summary): [Bijtelling privégebruik auto 2026 —
Belastingdienst](https://www.belastingdienst.nl/wps/wcm/connect/bldcontentnl/belastingdienst/zakelijk/winst/inkomstenbelasting/veranderingen-inkomstenbelasting-2026/bijtelling-privegebruik-auto-2026):
standard rate 22%; reduced rate 18% for zero-emission cars (except hydrogen/fully solar-powered,
which get 18% uncapped — out of MVP scope, see proposal Non-goals) up to and including a
cataloguswaarde of €30.000, 22% on the excess above that cap.

## Goals / Non-Goals

**Goals:** a dedicated `Vehicle`/`CarAssignment` schema pair carrying the fiscal facts bijtelling
needs; the 2026 rate/cap as versioned, sourced table data; the monthly bijtelling added to the
taxable gross at exactly the point `PayrollRunService` already assembles `CalculationInput`, with
zero changes to `PayrollCalculator`; a `Payslip.bijtelling` record of what was added, verified by a
machine-checkable, auto-discovered corpus rule; a digit-for-digit hand-computed golden anchor
proving the integration (this design's D4).

**Non-Goals (from the proposal, binding):** grijs-kenteken/bestelauto special regimes, the
≤500km-privé exemption workflow, the hydrogen/fully-solar uncapped rate, 60-month DET re-rating,
an overlap/single-active-assignment guard rule (all named follow-ups in the proposal).

## Decisions

### D1 — `Vehicle`/`CarAssignment` are a NEW schema pair, decoupled from `Asset`/`AssetAssignment`

`hr-assets.json`'s `Asset` (category `voertuig`) is custody-tracking only — its own docstring says
so and names this change as the follow-up. Rather than retrofitting fiscal fields onto `Asset`
(which would force every non-vehicle `Asset` category to carry meaningless `cataloguswaarde`/
`fuelType` properties, and conflate "is this item checked out" with "is this car's bijtelling
correct"), this change adds a separate pair in a new fragment `lib/Settings/register.d/
hr-fleet.json` (`x-hrmq-fragment: hr-fleet`), mirroring the `Asset`/`AssetAssignment` shape
(item schema + effective-dated assignment schema) but with zero cross-reference between the two
pairs: an employee's company car MAY also be tracked as an `Asset` for custody purposes, or may
not be — `fleet-bijtelling` does not require or assume either.

- **`Vehicle`** (icon `CarSide`, `x-schema-org: schema:Vehicle`): `name` (string, required — human
  label e.g. "Tesla Model Y"), `kenteken` (string, nullable), `cataloguswaarde` (number, required —
  the fiscal catalog value in EUR the bijtelling base), `fuelType` (enum `benzine|diesel|hybride|
  volledigElektrisch|waterstof|overig`, required — descriptive), `bijtellingCategorie` (enum
  `standaard|elektrischGeplafonneerd`, required — the fiscal classification selecting which
  `nl-{year}.json` rate/cap row applies; append-only enum), `active` (boolean, default `true`),
  `administrationId` (nullable plain string, ADR-062 rule 7 — NOT a `$ref`, the `Asset` precedent).
- **`CarAssignment`** (icon `CarKey`, `x-schema-org: schema:OwnershipInfo`, the `AssetAssignment`/
  `OrgAssignment` effective-dating pattern): `vehicleId` (uuid `$ref Vehicle`, required),
  `employeeId` (uuid `$ref Employee`, required), `effectiveFrom` (date, required),
  `effectiveTo` (date, nullable — null = ongoing), `eigenBijdrage` (number, default `0` — the
  employee's monthly contribution for private use, WetLB 1964 art. 13bis lid 5, reduces the
  bijtelling), `administrationId` (nullable plain string, mirroring the receiving employee's, the
  `AssetAssignment` precedent).

`PayrollRunService` resolves the employee's **open CarAssignment covering the period** the same
way it resolves the covering `EmploymentContract` (`coveringContract()`) and the open
`SickLeaveCase` (`openSickCaseFor()`) — id/slug/employeeNumber key resolution,
`coversPeriod(effectiveFrom, effectiveTo, period)` — first match wins; no overlap guard in the
MVP (proposal Non-goals).

### D2 — The 2026 bijtelling rate/cap is versioned table data, not calculator logic

New parameter group in `lib/Standards/tables/nl-2026.json`, `parameters.
bijtellingPrivegebruikAuto`, each leaf `{value, source, verified}` per the tables `SCHEMA.md`:

```jsonc
"bijtellingPrivegebruikAuto": {
  "standardPercent": {"value": 22, "source": "Belastingdienst — Bijtelling privégebruik auto 2026", "verified": true},
  "evReducedPercent": {"value": 18, "source": "Belastingdienst — Bijtelling privégebruik auto 2026", "verified": true},
  "evReducedCataloguswaardeCap": {"value": 30000, "source": "Belastingdienst — Bijtelling privégebruik auto 2026", "verified": true}
}
```

plus a `basedOn` citation `{"doc": "Belastingdienst — Bijtelling privégebruik auto 2026", "url":
"https://www.belastingdienst.nl/wps/wcm/connect/bldcontentnl/belastingdienst/zakelijk/winst/inkomstenbelasting/veranderingen-inkomstenbelasting-2026/bijtelling-privegebruik-auto-2026"}`.
All three leaves are `verified: true` — confirmed directly against the Belastingdienst page
itself during this design, not carried forward as a `placeholder`. A future tax year is a
data-only `nl-{year}.json` addition (tables `SCHEMA.md` annual re-issue discipline) — no PHP
changes, exactly like `payroll-core-engine`'s own tables.

### D3 — The formula, and exactly where it enters the taxable gross

For a `CarAssignment` covering the period, referencing `Vehicle` with cataloguswaarde `cw` and
`bijtellingCategorie`:

- **`standaard`**: `base = cw × standardPercent / 100`.
- **`elektrischGeplafonneerd`**: `base = min(cw, evReducedCataloguswaardeCap) ×
  evReducedPercent/100 + max(0, cw − evReducedCataloguswaardeCap) × standardPercent/100` (the
  blended two-tier rate — 18% up to €30.000, 22% on the excess).
- **`monthlyBijtellingCents = max(0, round(base_cents / 12) − eigenBijdrageCents)`** — cents-exact,
  same rounding-to-nearest-cent convention as every `PayrollCalculator` tijdvakbedrag.

`PayrollRunService::generate()` computes this **immediately after** the sick-pay substitution
(`$grossMonthlySalaryCents = $sickResult->payableGrossCents`, when an open `SickLeaveCase`
applies — the car remains available for private use regardless of illness, so bijtelling is not
suspended) and **immediately before** `CalculationInput` is constructed — literally the next two
lines in the existing method, the exact point the service already assembles the input
(payroll-core-engine design.md D2 step 1: `tvl = grossMonthlySalary`):

```php
$grossMonthlySalaryCents = $sickResult->payableGrossCents; // unchanged (sick-pay-calc)

$carAssignment    = $this->openCarAssignmentFor($employee, $carAssignmentsByEmployeeKey, $period);
$bijtellingCents  = 0;
if ($carAssignment !== null) {
    $vehicle          = $vehiclesById[$carAssignment['vehicleId']] ?? null;
    $bijtellingCents  = $this->bijtellingCentsFor($vehicle, $carAssignment, $tables);
    $grossMonthlySalaryCents += $bijtellingCents;
}

$input = new CalculationInput(grossMonthlySalaryCents: $grossMonthlySalaryCents, /* … unchanged … */);
$result = $this->calculator->calculate($input, $tables);
```

`PayrollCalculator::calculate()` is **not touched** — it receives a larger `tvl` and runs its
existing, unmodified equation chain over it. This is deliberate and is the entire point of the
design: every downstream figure the calculator already derives from `tvl` — tabelloon,
schijventarief, heffingskortingen, loonheffing, vakantiegeldreservering (8% of `tvl`), the Zvw
werkgeversheffing base and the Awf/Aof/Wko/Whk premieloon base — inherits the bijtelling
automatically, with no calculator-side special case. `Payslip.grossPay` therefore reports the
bijtelling-inclusive gross (`CalculationResult::grossPayCents` simply echoes the `tvl` it was
given); `Payslip.bijtelling` separately records the amount that was added, and
`Payslip.carAssignmentId` records which assignment produced it (null on any payslip with no
covering assignment — byte-identical to today, the `sickLeaveCaseId`/`payrollRunId` precedent).

### D4 — Worked anchor: €3.800 salary + €500 bijtelling → €4.300 taxable gross (hand-computed, digit-for-digit)

This reuses `payroll-core-engine`'s own D2 anchor input (€3.800,00 monthly, wit, korting applied,
below AOW, Awf low, Aof laag, Whk 1,52%, `nl-2026` tables) and adds ONE `CarAssignment`:

- **Vehicle**: `cataloguswaarde = €45.000,00`, `bijtellingCategorie = standaard` (22%).
- **CarAssignment**: `eigenBijdrage = €325,00`/maand, covering the period.

**Bijtelling**: `base = 45.000 × 22% = 9.900,00`; `9.900,00 / 12 = 825,00` (exact, no rounding
needed); `monthlyBijtelling = 825,00 − 325,00 = €500,00`.

**New taxable gross**: `tvl = 3.800,00 + 500,00 = €4.300,00` (`430.000` cents) — everything below
recomputed by hand from the primary Rekenvoorschriften formulas, the same D2 chain
`payroll-core-engine` implements, mirrored here in cents to avoid float ambiguity:

1. **Tabelloon**: `L = floor((430.000 × 12) / 5.400) × 5.400 = floor(5.160.000 / 5.400) × 5.400 =
   floor(955,555…) × 5.400 = 955 × 5.400 = 5.157.000` cents (**€51.570,00**).
2. **Bracket** (belowAow, `L` in bracket 2: `38.883 < L ≤ 78.426`): `a = 38.883`, `b = 37,56%`,
   `c = 13.900`. `X1_raw = (51.570 − 38.883) × 0,3756 + 13.900 = 12.687 × 0,3756 + 13.900 =
   4.765,2372 + 13.900 = 18.665,2372`; `X1 = floorEuro(18.665,2372) = €18.665,00`.
3. **AHK**: `ahkm1 = 3.115`, `ahkg1 = 29.736`, `ahka1 = 0,06398`. `AHK_raw = 3.115 − (51.570 −
   29.736) × 0,06398 = 3.115 − 21.834 × 0,06398 = 3.115 − 1.396,93932 = 1.718,06068`;
   `AHK = ceilEuro(1.718,06068) = €1.719,00`.
4. **ARK** (belowAow chain, `arkg1=11.965 arkg2=25.845 arkg3=45.592 arkg4=132.920
   arkm1=996 arkm2=5.300 arkm3=5.685 arko1=0,08324 arko2=0,31009 arko3=0,01950 arka1=0,06510`):
   `term1 = min(11.965 × 0,08324, 996) = min(995,9666, 996) = 995,9666`;
   `term2 = min(995,9666 + (25.845−11.965) × 0,31009, 5.300) = min(995,9666 + 13.880 × 0,31009,
   5.300) = min(995,9666 + 4.304,0492, 5.300) = min(5.300,0158, 5.300) = 5.300` (capped);
   `term3 = min(5.300 + (45.592−25.845) × 0,01950, 5.685) = min(5.300 + 19.747 × 0,0195, 5.685) =
   min(5.300 + 385,0665, 5.685) = min(5.685,0665, 5.685) = 5.685` (capped);
   tail (`L=51.570 > arkg3=45.592`): `chain = 5.685 − (51.570 − 45.592) × 0,06510 = 5.685 −
   5.978 × 0,0651 = 5.685 − 389,1678 = 5.295,8322`; `ARK = ceilEuro(5.295,8322) = €5.296,00`.
5. **Loonheffing**: `X = floorEuro(X1 − (AHK+ARK)) = floorEuro(18.665 − (1.719+5.296)) =
   floorEuro(18.665 − 7.015) = floorEuro(11.650) = €11.650,00`; `loonheffing = round2(11.650/12) =
   round2(970,8333…) = €970,83`; `arbeidskorting = round2(5.296/12) = round2(441,3333…) =
   €441,33`; `appliedTaxRate = round2(970,83 / 4.300 × 100) = round2(22,57744…) = 22,58`.
6. **Volksverzekeringen (informative)**: `vvRate = 17,90+0,10+9,65 = 27,65%` (belowAow);
   `vvJaar = min(51.570, 38.883) × 0,2765 = 38.883 × 0,2765 = 10.751,1495`;
   `volksverzekeringen = min(970,83, round2(970,83 × 10.751,1495 / 18.665)) = min(970,83,
   round2(559,2038)) = €559,20`.
7. **Zvw werkgeversheffing**: `zvwBase = min(4.300, 6.617,41) = 4.300`; `zvw = round2(4.300 ×
   6,10%) = €262,30`.
8. **Werknemersverzekeringen** (`pl = min(4.300, 6.617,41) = 4.300`, Awf low, Aof laag):
   `awf = round2(4.300 × 2,74%) = €117,82`; `aof = round2(4.300 × 6,27%) = €269,61`;
   `wko = round2(4.300 × 0,50%) = €21,50`; `whk = round2(4.300 × 1,52%) = €65,36`;
   `werknemersverzekeringen = 117,82+269,61+21,50+65,36 = €474,29`;
   `employerCharges = 474,29 + 262,30 = €736,59`.
9. **Vakantiegeldreservering**: `round2(4.300 × 8%) = €344,00` (`vakantiegeldRate = 8,0`).
10. **Netto**: `nettoPay = tvl − loonheffing = 4.300,00 − 970,83 = €3.329,17`.

**Anchor result** (every figure re-derivable from steps above, cents-exact): `bijtelling €500,00`,
`grossPay €4.300,00`, `loonheffing €970,83`, `arbeidskorting €441,33`, `appliedTaxRate 22,58`,
`volksverzekeringen €559,20`, `zvw €262,30` (`zvwRate 6,10`), `awf €117,82`, `aof €269,61`,
`wko €21,50`, `whk €65,36`, `werknemersverzekeringen €474,29`, `employerCharges €736,59`,
`vakantiegeldReserved €344,00` (`vakantiegeldRate 8,0`), `nettoPay €3.329,17`. This is the fixture
`tests/fixtures/payroll-2026/bijtelling-anchor.json` must byte-match.

Sanity cross-check against the pre-existing D2 anchor (€3.800 only, no bijtelling): loonheffing
rose €718,83→€970,83 (+€252,00), nettoPay rose €3.081,17→€3.329,17 (+€248,00) — net increase is
LESS than the €500,00 added because part of it is taxed away (252,00 of the 500,00), which is the
expected, correct direction for an addition to the taxable base.

### D5 — `NlFleetChecks`: the recorded bijtelling must match the formula, auto-discovered

`lib/Standards/Checks/NlFleetChecks.php` implements `CheckProvider` (no registration wiring —
`RuleEngine::providers()` globs `lib/Standards/Checks/*.php`, the `NlAssetChecks`/`NlEngineChecks`
precedent) and registers `nl-bijtelling-auto-privegebruik` (Payslip): **vacuous** when
`carAssignmentId` is null; else re-derives `monthlyBijtellingCents` from D3's formula using the
referenced `CarAssignment.eigenBijdrage` and `Vehicle.cataloguswaarde`/`bijtellingCategorie` (via
`RuleAuditService::audit()` context enrichment `fleet.carAssignmentsById` / `fleet.vehiclesById` —
the `payroll.runsById` precedent) and flags any cents-mismatch against `Payslip.bijtelling`. The
new rule entry lives in `lib/Standards/rules/payroll.json` (domain `tax`, framework
`nl-bijtelling-auto`, source Wet LB 1964 art. 13bis, `machineCheckable: true`) — after this change
it counts as enforced in the audit coverage metric, the `nl-engine-*` precedent.

### D6 — Declarative vs imperative (ADR-031)

| Behaviour | Path | Rationale |
|---|---|---|
| 2026 rate/cap | static tables (`nl-2026.json`) | annual-change, data-only, `payroll-core-schema`/`payroll-core-engine` precedent |
| Vehicle/CarAssignment records | declarative schema (register.d) | plain data, no workflow — no lifecycle needed (a `CarAssignment` is effective-dated, not stateful) |
| Bijtelling → gross fold | imperative (`PayrollRunService`) | must run BEFORE the pure calculator call, in the exact assembly point the service owns — same class as the sick-pay substitution |
| Bijtelling arithmetic | imperative (D3 formula, one method) | a two-tier percentage-of-cap formula is exactly what schema-declarative calculation cannot express — the `PayrollCalculator` ADR-031 exception, reused |
| Output contract | corpus rule + `CheckProvider` | the app's established exception, `nl-engine-output-consistency` precedent |

## Seed Data (ADR-001)

No new seed `Vehicle`/`CarAssignment` objects in the MVP: the golden fixture (D4) is this change's
canonical proof, computed independently of any seed data. A future change may seed one
`CarAssignment` against the existing seeded employee to exercise `occ hrmq:payroll:run` end-to-end
(the `payroll-core-engine` dev-container precedent), tracked as implementation detail, not a spec
requirement here.

## Risks / Trade-offs

- **Bijtelling cascades into vakantiegeldreservering, Zvw and werknemersverzekeringen bases too**,
  because all of them key off the same `tvl` `PayrollCalculator` receives (D3). This matches the
  statutory loon-begrip for de werknemersverzekeringen/Zvw (bijtelling is loon in natura for those
  purposes) but is **stricter than common CAO practice for vakantiegeld**, where many
  collectieve arbeidsovereenkomsten exclude bijtelling from the vakantiegeld-grondslag. This is a
  documented simplification, not a silent one: a CAO-specific vakantiegeld-grondslag exclusion is a
  named follow-up, not built here.
- **Self-consistent goldens can share a bug with the implementation** — mitigated exactly as
  `payroll-core-engine` mitigated it: D4's anchor is hand-computed from the primary Rekenvoorschriften
  formulas in this design (not by the future implementation), independently of any code.
- **`bijtellingCategorie` is a static, per-assignment input** — no 60-month DET re-rating, no
  automatic Belastingdienst staffel lookup beyond the two-tier standard/EV split. A wrong category
  yields a wrong bijtelling but never silently — `NlFleetChecks` only validates internal
  consistency (recorded value matches the formula given the stored inputs), not that the stored
  `bijtellingCategorie`/`cataloguswaarde` are themselves correct; that remains an HR data-entry
  responsibility, the same trust boundary `payroll-core-engine` already draws around
  `Employee.grossMonthlySalary`.

## Open Questions

- None blocking. Hydrogen/fully-solar uncapped rate, grijs-kenteken/bestelauto regimes, the
  ≤500km-privé exemption, 60-month DET re-rating, and an overlap/single-active-assignment guard
  are named follow-ups (proposal Non-goals).
