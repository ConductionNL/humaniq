# Design — dga-payroll-mode

## Context

**Verified against HEAD 2026-07-15.** Consumes `payroll-core-engine` (merged 2026-07-14):
`lib/Payroll/PayrollCalculator.php`, `lib/Payroll/CalculationInput.php`,
`lib/Payroll/CalculationResult.php`, `lib/Payroll/TaxTables.php`,
`lib/Standards/tables/nl-2026.json`, `lib/Service/PayrollRunService.php`.

Read directly at HEAD (line references are from the file as it stands today):

- `PayrollCalculator::calculate()` step 9 (lines 148-160): computes `awfCents`/`aofCents`/
  `wkoCents`/`whkCents` from `$t->werknemersverzekeringen()` unconditionally, sums them into
  `werknemersverzekeringenCents`, and adds `zvwCents` into `employerChargesCents`. There is no
  branch today that skips this block.
- Step 10 (line 164): `$nettoPayCents = ($tvl - $loonheffingCents);` — **werknemersverzekeringen
  and Zvw never appear in this line.** This is confirmed by the archived
  `payroll-core-engine/design.md` D2 step 10: *"employer charges never reduce net; Zvw is the
  employer levy in the MVP mode."*
- `CalculationInput`'s constructor (lines 49-58) is 8 named, non-defaulted readonly properties;
  every call site in the codebase (`PayrollRunService`, `ProformaPayslipService`,
  `RetroAdjustmentService`, `NlRetroChecks`, and 5 test files) constructs it with **named
  arguments**, confirmed by `grep -rn "new CalculationInput("`. A 9th named parameter with a
  default is additive and touches none of them.
- `lib/Settings/register.d/hr-objects.json` `Employee` has no DGA/employment-type marker today
  (`grep` over its 24 properties confirms); the closest precedent for an optional-boolean +
  ancillary-rate/justification pair is `thirtyPercentRulingGranted` +
  `thirtyPercentRulingRate`/`thirtyPercentCappedAtWntNorm`.
- `lib/Standards/tables/nl-2026.json` `parameters` has 9 top-level groups (`loonheffing`,
  `heffingskortingen`, `volksverzekeringen`, `aow`, `zvw`, `werknemersverzekeringen`, `wml`,
  `vakantiebijslag`, `wkr`), each leaf shaped `{value, source, verified, ...}`; `basedOn` carries
  the 4 primary-source citations for the file.
- `lib/Standards/Checks/CheckProvider.php`: "auto-discovered by `RuleEngine::providers()`" — a new
  provider file needs **zero manual registration**, matching the task's "auto-discovered,
  RuleEngine-reachable" requirement.
- `lib/Standards/rules/payroll.json`: 104 rules, `severity` ∈ {`mandatory`, `conditional`,
  `recommended`}; `nl-engine-table-version`/`nl-engine-output-consistency` are the `payroll-core`
  framework precedent for engine-contract rules.
- ADR-001 Rule 4 (`openspec/architecture/adr-001-information-architecture.md` line 62):
  *"ZZP/DGA en eenmanszaak zijn modes, geen aparte app"* — `zzp-dga-single-person-mode` is a
  mode-switch on the existing data model, never a fork. `Employee.isDga` (a field, not a new
  schema/app/menu) is the mode-switch this rule mandates.

## Goals / Non-Goals

**Goals:** an Employee-level `isDga` marker that flows through `PayrollRunService` into
`CalculationInput.verzekeringsplichtig`, zeroing exactly the werknemersverzekeringen block in
`PayrollCalculator` while leaving loonheffing/Zvw/vakantiegeld untouched; a sourced, versioned 2026
gebruikelijkloon norm; a machine-checkable, auto-discovered flag for a DGA paid below the norm; a
hand-computed, digit-exact golden anchor for the build to pin.

**Non-Goals** (binding, from the proposal): doorbetaaldloonregeling, multiple-BV norm aggregation,
30%-ruling interaction with the norm, `gebruikelijkloonJustification` content validation.

## Decisions

### D1 — `verzekeringsplichtig` is a `CalculationInput` flag; the calculator gates step 9 on it, nothing else

`CalculationInput` gains one additive constructor parameter:
`public readonly bool $verzekeringsplichtig = true`. Default `true` preserves every existing call
site byte-for-byte (D1's grep confirms all are named-argument construction).

`PayrollCalculator::calculate()` step 9 becomes:

```
if ($in->verzekeringsplichtig === true) {
    $wnv     = $t->werknemersverzekeringen();
    $pl      = min($tvl, $wnv['maximumPremieloonMaand']);
    $awfRate = $in->awfTariff === 'high' ? $wnv['awfHoog'] : $wnv['awfLaag'];
    $aofRate = $in->aofTariff === 'hoog' ? $wnv['aofHoog'] : $wnv['aofLaag'];

    $awfCents = self::round2Cents(($pl * $awfRate) / 100);
    $aofCents = self::round2Cents(($pl * $aofRate) / 100);
    $wkoCents = self::round2Cents(($pl * $wnv['wkoOpslag']) / 100);
    $whkCents = self::round2Cents(($pl * $in->whkPercentage) / 100);
} else {
    // DGA / not verzekeringsplichtig (Wet financiering sociale verzekeringen):
    // no Awf/Aof/Wko/Whk premium is levied on either side — table lookups are
    // skipped entirely rather than computed-then-discarded, so a DGA payslip
    // never even reads a rate it does not owe.
    $awfCents = 0;
    $aofCents = 0;
    $wkoCents = 0;
    $whkCents = 0;
}

$werknemersverzekeringenCents = ($awfCents + $aofCents + $wkoCents + $whkCents);
$employerChargesCents         = ($werknemersverzekeringenCents + $zvwCents);
```

**Exactly these four lines zero for a DGA — nothing else**: `awfCents` (Awf/WW), `aofCents`
(Aof/WIA-WAO), `wkoCents` (Wko opslag), `whkCents` (Whk/Werkhervattingskas) — all four are drawn
from the same `$t->werknemersverzekeringen()` bucket and the same capped `premieloon` (`$pl`),
which is precisely the "DGA is not verzekeringsplichtig for the werknemersverzekeringen" boundary
(Wfsv — Wet financiering sociale verzekeringen — art. 6 lid 1 sub d jo. Regeling aanwijzing
directeur-grootaandeelhouder). **Kept unchanged**: `loonheffingCents` (step 3-6, tabelloon through
heffingskortingen — loonbelasting/premie volksverzekeringen via the combined heffing, Wet LB 1964
art. 27), `arbeidskortingCents`, `volksverzekeringenCents` (the informative AOW/Anw/Wlz split —
volksverzekeringen are a different statutory scheme from werknemersverzekeringen and a DGA remains
verzekerd for them), `zvwCents` (Zvw werkgeversheffing — Zvw is a separate law, Zvw 2005 art. 2,
and applies regardless of werknemersverzekeringen status), `vakantiegeldReservedCents`.
`nettoPayCents` stays `tvl - loonheffingCents`, structurally unaffected by step 9 either way (D2
below shows why this means netto is IDENTICAL to the non-DGA anchor at the same gross).

### D2 — The hand-computed DGA anchor (design-time recompute, not future-implementation output)

Same input as the `payroll-core-engine` anchor (verified digit-for-digit against that design.md):
€3.800,00 wit, korting applied, below AOW, Awf low, Aof laag, Whk 1,52, `nl-2026`, **now with
`isDga: true` → `verzekeringsplichtig: false`**. Steps 1-8 and step 10 are byte-identical to the
non-DGA anchor (D1: nothing upstream of step 9 or downstream reads `verzekeringsplichtig`); only
step 9 changes:

| Component | Non-DGA anchor (payroll-core-engine) | **DGA anchor (this change)** | Delta |
|---|---|---|---|
| vakantiegeldReserved | €304,00 | **€304,00** | — |
| loonheffing | €718,83 | **€718,83** | — |
| arbeidskorting | €473,75 | **€473,75** | — |
| appliedTaxRate | 18,92% | **18,92%** | — |
| volksverzekeringen (informative) | €470,86 | **€470,86** | — |
| zvw (werkgeversheffing 6,10%) | €231,80 | **€231,80** | — |
| awf | €104,12 | **€0,00** | -€104,12 |
| aof | €238,26 | **€0,00** | -€238,26 |
| wko | €19,00 | **€0,00** | -€19,00 |
| whk | €57,76 | **€0,00** | -€57,76 |
| **werknemersverzekeringen** | €419,14 | **€0,00** | **-€419,14** |
| **employerCharges** (wnv + zvw) | €650,94 | **€231,80** | **-€419,14** |
| **nettoPay** | €3.081,17 | **€3.081,17** | **— (unchanged)** |

**The grounding correction, stated precisely**: `nettoPay` does not rise for the DGA. It is
`tvl - loonheffing = 3.800,00 - 718,83 = 3.081,17` in both cases — werknemersverzekeringen were
never subtracted from it (D1/Context). What the DGA treatment actually changes is the **employer's
own cost of employment**: `employerCharges` drops from €650,94 to €231,80, exactly the zeroed
€419,14 — this is real money the employer no longer remits (it shows up in
`PayrollRun.totalEmployerCharges` and matters to glpost's GL posting and any total-cost-of-
employment reporting), but it is not a payslip/net-pay effect. Any future UI copy or documentation
for this feature MUST say "the employer no longer pays werknemersverzekeringen for this DGA" —
never "the DGA's net pay increases," which would misstate the engine's actual (and legally
correct, per the employer-levy Rekenvoorschriften model) behaviour.

This is the golden-fixture anchor: `tests/fixtures/payroll-2026/dga-anchor.json` pins the same
input as `tests/fixtures/payroll-2026/anchor.json` (payroll-core-engine) plus `isDga: true`,
against the **DGA anchor** column above, byte-exact.

### D3 — `Employee.isDga` is the mode-switch (ADR-001 Rule 4); `PayrollRunService` derives the flag, never the reverse

`Employee` gains:

- `isDga` (`boolean`, default `false`) — the DGA marker, alongside the existing
  `thirtyPercentRulingGranted`-style boolean flags.
- `gebruikelijkloonJustification` (`string`, `nullable`, optional) — free-text justification for a
  below-norm gebruikelijkloon (Non-Goal: content is not validated in MVP).

`Payslip` gains `isDga` (`boolean`) — a denormalized copy of `Employee.isDga` at calculation time,
the same "durable link on Employee, denormalized copy per record" convention already documented
for `nextcloudUserId`→`Payslip.userId`. This lets glpost/UPA/loonaangifte consumers of a Payslip
see WHY `werknemersverzekeringen` reads `€0,00` without re-joining to Employee or guessing at an
engine bug.

`PayrollRunService::generate()` (the `CalculationInput` construction site, current line ~378) adds
one line: `verzekeringsplichtig: (($employee['isDga'] ?? false) !== true)`, and
`payslipPayload()` adds `'isDga' => (($employee['isDga'] ?? false) === true)`. No other service
logic changes — the skip-reporting, upsert-keying, orphan-cleanup and totals roll-up are entirely
untouched (a DGA is selected, computed and stamped exactly like any other employee; only the
computed numbers differ, per D1/D2).

### D4 — The gebruikelijkloon 2026 norm is versioned table data, sourced exactly like every other nl-2026.json parameter

Verified directly against the Belastingdienst page *Loon en aanmerkelijk belang*
(`belastingdienst.nl/.../loon_en_aanmerkelijk_belang`, fetched 2026-07-15): *"ten minste € 58.000
in 2026 (in 2025 en 2024 was dat € 56.000)"*. **The 2026 figure is €58.000/jaar — not €56.000**
(€56.000 was the 2024/2025 norm; the task brief's placeholder figure is superseded and MUST NOT be
used). `nl-2026.json` `parameters` gains a 10th group, same leaf shape as every sibling:

```json
"gebruikelijkloon": {
  "jaarnorm": {
    "value": 58000,
    "source": "Belastingdienst — Loon en aanmerkelijk belang (gebruikelijkloonregeling, Wet LB 1964 art. 12a)",
    "sourceUrl": "https://www.belastingdienst.nl/wps/wcm/connect/bldcontentnl/belastingdienst/prive/vermogen_en_aanmerkelijk_belang/aanmerkelijk_belang/loon_en_aanmerkelijk_belang/loon_en_aanmerkelijk_belang",
    "verified": true,
    "note": "Minimum gebruikelijk loon per jaar for a DGA/aanmerkelijkbelanghouder in 2026 (2024/2025: EUR 56.000). The statutory norm is the HIGHEST of: comparable-employment salary, highest-paid-employee salary at the BV/related BV, or this floor -- the engine checks only the floor (MVP, non-goal: comparable-salary benchmarking)."
}
```

`basedOn` gains the same citation. `TaxTables::gebruikelijkloon(): array` follows the `zvw()`/
`wml()` precedent exactly: `['jaarnormCents' => self::euroToCents((float) $this->leaf(['gebruikelijkloon', 'jaarnorm', 'value']))]`.
No engine-behaviour code (`PayrollCalculator`) reads this getter — it exists solely for D5's check
and any future gebruikelijkloon-facing UI, matching how `wml()`'s `referentiemaandloonCents` is
consumed only by `SickPayCalculator`, not `PayrollCalculator`.

### D5 — `NlDgaChecks`: an auto-discovered CheckProvider flagging a below-norm DGA

New corpus rule in `lib/Standards/rules/payroll.json`:

```json
{
  "id": "nl-gebruikelijkloon-norm",
  "domain": "tax",
  "jurisdiction": "NL",
  "framework": "nl-gebruikelijkloonregeling",
  "source": "Wet LB 1964 art. 12a (gebruikelijkloonregeling)",
  "statement": "A DGA (director-major-shareholder, aanmerkelijkbelanghouder) employee's annualised loon shall be at least the gebruikelijkloon norm for the tax year, unless a lower amount is justified and recorded on file.",
  "severity": "conditional",
  "machineCheckable": true,
  "effectiveDate": "2026-01-01",
  "sourceUrl": "https://www.belastingdienst.nl/wps/wcm/connect/bldcontentnl/belastingdienst/prive/vermogen_en_aanmerkelijk_belang/aanmerkelijk_belang/loon_en_aanmerkelijk_belang/loon_en_aanmerkelijk_belang"
}
```

`severity: conditional` (not `mandatory`) matches the statute: a below-norm salary IS lawful when
justified — the same reason the `gebruikelijkloonJustification` exemption exists.

`lib/Standards/Checks/NlDgaChecks.php` implements `CheckProvider` (auto-discovered by
`RuleEngine::providers()`, zero manual registration — Context):

```php
public static function checks(): array
{
    return [
        'Employee' => [
            'nl-gebruikelijkloon-norm' => static fn(array $o): bool => self::meetsGebruikelijkloonNorm($o),
        ],
    ];
}

private static function meetsGebruikelijkloonNorm(array $o): bool
{
    if (($o['isDga'] ?? false) !== true) {
        return true; // vacuous: not a DGA
    }
    if (trim((string) ($o['gebruikelijkloonJustification'] ?? '')) !== '') {
        return true; // MVP: presence-only exemption (Non-Goal: content validation)
    }
    $grossMonthly = ($o['grossMonthlySalary'] ?? null);
    if (is_numeric($grossMonthly) === false) {
        return true; // no salary to evaluate yet (hourly/no-salary path is out of scope)
    }
    $annualCents = (int) round(((float) $grossMonthly) * 12 * 100);
    $ids         = TaxTables::availableIds();
    if ($ids === []) {
        return true; // no table loaded — defensive, never hit outside a broken install
    }
    $norm = TaxTables::load(max($ids))->gebruikelijkloon()['jaarnormCents'];
    return $annualCents >= $norm;
}
```

`TaxTables::availableIds()` (the existing memoised glob, `NlEngineChecks` precedent) picks the
latest table id (`max()` over `nl-YYYY` ids is a correct lexicographic max since all ids share the
`nl-` prefix and 4-digit years) — `Employee` carries no fiscal-year field to resolve a specific
table by period, so "latest available table" is the documented, honest choice (a fixed-year MVP
install with one table file resolves unambiguously; a future multi-year install picking last
year's norm for a mid-year check is a named limitation, not silently wrong). `seedSpec()` returns
`[]` — the existing seeded Employee (`isDga` absent → `false` default) stays vacuously compliant,
no seed backfill needed.

### Declarative vs imperative (ADR-031)

| Behaviour | Path | Rationale |
|---|---|---|
| Gebruikelijkloon norm | spec-1-style static table data (`nl-2026.json`) | same as every other nl-2026 parameter; versioned, sourced, re-issued yearly as data |
| Werknemersverzekeringen skip | imperative pure PHP (`PayrollCalculator` step 9 gate) | extends the existing imperative equation chain (ADR-031 exception already established by `payroll-core-engine`) |
| Below-norm flag | corpus rule + `CheckProvider` predicate | the app's established exception for cross-object/computed compliance checks (same class as `NlEngineChecks`) |
| DGA marker | Employee field (mode-switch) | ADR-001 Rule 4 — never a forked app or new menu |

## Risks / Trade-offs

- **`gebruikelijkloonJustification` is presence-only in MVP**: a DGA could write any non-empty
  string to silence the flag. Acceptable for MVP (the flag is `conditional`/advisory, not a
  blocking guard on payroll generation) and named explicitly as a follow-up (content validation).
- **Latest-available-table resolution for a fiscal-year-less Employee check**: correct for a
  single-tax-year install (today's reality — only `nl-2026.json` exists); a future multi-year
  install would need a real period input to this check. Documented, not silently wrong.
- **No multi-BV aggregation**: a DGA drawing salary from several linked BVs, each below the norm
  but summing above it, is NOT caught (each Employee record is evaluated in isolation). Named
  follow-up (Non-Goals).
- **30%-ruling interaction not wired**: `NlDgaChecks` compares the raw norm even when
  `Employee.thirtyPercentRulingGranted` is true (the ruling can lawfully lower the effective norm).
  Named follow-up (Non-Goals) — the check will over-flag a 30%-ruling DGA today; documented as a
  known false-positive class, not silently wrong.

## Open Questions

- None blocking. Multi-BV aggregation, 30%-ruling interaction and
  `gebruikelijkloonJustification` content validation are named follow-ups above.
