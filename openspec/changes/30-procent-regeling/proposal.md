---
kind: code
---

# 30%-regeling — tax-free expat allowance reducing the taxable wage

## Why

**Verified against HEAD 2026-07-17.** `lib/Settings/register.d/hr-objects.json`'s `Employee`
already carries `thirtyPercentRulingGranted`/`thirtyPercentRulingRate`/`thirtyPercentCappedAtWntNorm`,
and `lib/Standards/Checks/NlPayrollChecks.php` already enforces two STRUCTURAL checks on them
(`nl-30-percent-regeling`: rate ≤ 30; `nl-30-regeling-aftoppingsgrens`: the capped flag is `true`
when granted). **None of this reaches the payroll engine.** `CalculationInput` has no
30%-ruling field, `lib/Standards/packs/nl-2026.pack.json` has no exemption step, and
`lib/Standards/tables/nl-2026.json` has no `dertigProcentRegeling` parameter group — a granted
ruling is recorded but never lowers a single euro of `loonheffing`. This is the same class of gap
`fleet-bijtelling` closed for bijtelling and `dga-payroll-mode` closed for the gebruikelijkloon
norm: a marker that exists on paper but is engine-blocked.

**The engine's authoritative path is the DSL, not raw PHP.** `jurisdiction-packs` (merged
2026-07-15, ADR-101) re-expressed the entire NL chain as `lib/Standards/packs/nl-2026.pack.json`,
executed by the pure `PackInterpreter`; `PayrollCalculator::calculate()` is now a thin façade
(`resolve pack → interpreter->run() → map to CalculationResult`) that holds **zero** Dutch tax
logic. Any new NL rule lands as pack DATA, not PHP, or it does not belong in this engine.

**A load-bearing design question, resolved with evidence.** ADR-101's own "Alternatives
rejected" section states: *"The DSL cannot do VCR or netto-operations... Same for the 30%-ruling
netto-operation (an inverse solve, not a forward chain). A pure-DSL decision would hit that wall
with no exit."* That sentence is about a DIFFERENT problem — grossing up an agreed NET salary
under the ruling, an inverse solve — which this change does **not** build (see Non-Goals). The
problem this change DOES build — exempting up to 30% of a known GROSS wage from tax, a forward
computation of `min(gross, cap) × rate/100` — is exactly the `cappedRate` primitive the DSL
already ships and already uses five times (Zvw + Awf/Aof/Wko/Whk). **Verdict: this fits the
declarative DSL. It does not need `phpStep`.** Full reasoning in design.md D1.

**The exemption changes which base every downstream figure is computed over, and gets the
gross/net split backwards if done naively.** `nl-2026.pack.json` declares `grossRef:
"@binding.tvl"` — the SAME binding both feeds the tabelloon/heffingskortingen chain AND is the
"gross" the interpreter's `net = gross - loonheffing` fold subtracts from. Reducing `tvl` itself
by the exemption would make `nettoPay` UNDERSTATE the employee's real take-home pay by exactly the
exempted amount, because the exempted 30% is real cash the employee keeps tax-free — it must not
vanish from net pay, only from the TAXABLE base. Design.md D2 is the fix: a NEW binding
(`belastbaarLoon`) carries the reduced taxable base for the tax/premium/vakantiegeld chain, while
`grossRef` keeps pointing at the unreduced `tvl` — so `nettoPay` correctly RISES for a granted
ruling instead of silently dropping.

**2026 figures verified via web research, not carried over from the (obsolete, May-2026, pre-
engine) draft brief.** The draft names "the 2024+ 30/20/10 stepped phases" as current law. Live
2026 sources (Belastingdienst Nieuwsbrief Loonheffingen 2026; multiple corroborating practitioner
summaries — CROP, PwC, Moore DRV, Grant Thornton, all citing the same figures) show the
Belastingplan-2024 step-down was **reversed by the 2024 Voorjaarsnota coalition compromise before
it ever took effect**: 2025 and 2026 both apply a FLAT 30% for the full term; a flat 27% (also not
stepped) applies from 2027. The stepped 30/20/10 mechanism is **not implemented** — implementing it
would encode a rule that was never actually in force. See design.md D3 for the full citation set
and the figures actually shipped: salary norm €48.013/€36.497, WNT-aftoppingsgrens €262.000, max
duration 60 months (5 years) — all `verified: true` with primary/corroborated sources.

## What Changes

- **NEW `parameters.dertigProcentRegeling` group in `lib/Standards/tables/nl-2026.json`** — flat
  `percent` (30), `maxDurationMonths` (60), `aftoppingsgrens` (WNT-norm, `{jaar: 262000, maand:
  21833.33}`), `salarisnormAlgemeen` (48013), `salarisnormMasterOnder30` (36497). Every leaf
  `verified: true`, sourced (design.md D3). Documents, rather than implements, the reversed
  30/20/10 step-down (REQ-30P-001).
- **Employee gains 3 NEW fields**: `thirtyPercentRulingStartDate`, `thirtyPercentRulingEndDate`
  (date, nullable — the beschikking's ingangsdatum/einddatum, anchoring the 60-month term),
  `thirtyPercentRulingReducedNormApplies` (boolean, default `false` — the under-30-with-qualifying-
  master's reduced salary norm gate). The 3 EXISTING fields (`thirtyPercentRulingGranted`,
  `thirtyPercentRulingRate`, `thirtyPercentCappedAtWntNorm`) are kept and their descriptions
  clarified: `thirtyPercentRulingRate` is now explicitly the engine-consumed applied percentage
  (REQ-30P-002).
- **`CalculationInput` gains ONE additive field**: `thirtyPercentRulingRate` (float, default
  `0.0`) — every existing named-argument call site is unaffected. **`nl-2026.pack.json` gains ONE
  new binding** (`thirtyPercentExemption`, a `cappedRate` over `@input.thirtyPercentRulingRate`)
  **and ONE new derived binding** (`belastbaarLoon`, `max(0, tvl - exemption)`) that the tabelloon/
  heffingskortingen/Zvw/Awf/Aof/Wko/Whk/vakantiegeld steps are repointed to instead of `@binding.
  tvl`; `grossRef` stays `@binding.tvl` unchanged, so `nettoPay` (the incidence fold) correctly
  reflects the full gross minus the (now smaller) loonheffing. `packVersion` bumps; `dslVersion`
  and every interpreter/`Ops/*.php` file are **untouched** — this is a pack-data-only change, the
  exact promise ADR-101 makes for a new tax rule (REQ-30P-003).
- **`PayrollRunService`** derives `thirtyPercentRulingRate` from `Employee.thirtyPercentRulingGranted
  ? Employee.thirtyPercentRulingRate : 0.0` when constructing `CalculationInput`, and
  independently re-derives the exemption amount (the same `cappedRate` formula, in PHP, the
  `bijtelling` D3 precedent) to stamp the ONE new `Payslip.thirtyPercentRulingExemption` field
  (nullable number; null when not applicable). `CalculationResult` is **not** touched — its 18
  fields stay exactly as `jurisdiction-packs` REQ-JP-007 left them (REQ-30P-003).
- **A hand-computed, simulator-cross-checked golden anchor** — same €3.800,00 wit/maand input as
  the `payroll-core-engine` anchor, `thirtyPercentRulingGranted: true`, `thirtyPercentRulingRate:
  30.0`: exemption €1.140,00, `belastbaarLoon` €2.660,00, `loonheffing` €251,17, `nettoPay`
  €3.548,83 (up from €3.081,17 — the tax saved flows straight into net pay, by construction).
  Full recompute in design.md D4 (REQ-30P-003).
- **3 NEW machine-checkable corpus rules** (`lib/Standards/Checks/NlPayrollChecks.php`,
  `lib/Standards/rules/payroll.json`): `nl-30-regeling-looptijd-5jaar` (Employee — flags a ruling
  applied beyond its 60-month term), `nl-30-regeling-aftoppingsgrens-bedrag` (Payslip — re-derives
  the WNT-capped exemption and flags a cents-mismatch against the recorded amount; this is the
  numeric enforcement the EXISTING boolean-only `nl-30-regeling-aftoppingsgrens` never provided),
  `nl-30-regeling-salarisnorm` (Employee — flags a granted ruling whose annualised
  `grossMonthlySalary` is below the applicable norm). The 2 EXISTING checks are unchanged
  (REQ-30P-004).
- **Fixes a pre-existing placeholder**: `nl-30-regeling-aftoppingsgrens`'s corpus `statement` in
  `lib/Standards/rules/payroll.json` literally reads *"(verify cap amount)"* — this change
  verifies it (€262.000, design.md D3) and updates the statement/`sourceUrl` accordingly (fixed as
  part of REQ-30P-004, encountered while implementing it).

### Non-Goals (named follow-ups and exclusions)

- **The 30%-ruling netto-operation** (grossing up an agreed NET salary under the ruling — an
  inverse solve) — explicitly out of scope per ADR-101; the DSL cannot express it (design.md D1).
  humaniq issue #81 tracks it as the DSL escape hatch's likely first customer.
- **Partial non-resident (partieel buitenlands belastingplichtige) status** — the box-2/box-3
  election some 30%-ruling holders can make; unrelated to the loon/loonheffing computation this
  change touches. Named follow-up.
- **ET-regeling comparison** (the employer choosing the actual extraterritoriale-kostenvergoeding
  route over the flat 30% when it is more favourable) — no election, comparison, or optimisation
  logic; the engine applies the stored rate only.
- **30%-vs-extraterritoriale-kosten election** — no UI or workflow for an employer to switch
  between the two regimes mid-term; `thirtyPercentRulingGranted`/`Rate` are HR-maintained facts,
  not a computed election.
- **Partial-year proration** — a ruling starting or ending mid-period is not pro-rated within a
  wage period; the engine reads whatever `thirtyPercentRulingGranted`/`Rate` say for the whole
  period. `nl-30-regeling-looptijd-5jaar` flags a STALE ruling (past its term) but does not
  auto-expire or proration it.
- **Multi-BV / doorbetaaldloon aggregation, 150-km-vóór-aanvraag toetsing, expiry-alert
  workflows, intrekking/correctieaangifte automation, bewijspakket export** — all present in the
  obsolete May-2026 draft (`origin/spec/30-procent-regeling`), all out of MVP scope here — this
  change ships the engine integration and compliance-detection surface only, the same tight MVP
  cut `fleet-bijtelling`/`dga-payroll-mode` took.
- **`gebruikelijkloonJustification`-style content validation** — not applicable; there is no
  justification exemption for the 30%-ruling checks (unlike gebruikelijkloon, a below-norm/
  above-cap/expired ruling has no MVP override path).

## Capabilities

### New Capabilities

- `30-procent-regeling`: the versioned 2026 table data (flat 30%, 60-month max, WNT-aftoppingsgrens,
  general + reduced salary norms), the Employee-level ruling marker (start/end date, reduced-norm
  flag, alongside the 3 existing fields), the pack-level `belastbaarLoon` exemption binding that
  reduces the taxable base fed to loonheffing/heffingskortingen/Zvw/werknemersverzekeringen/
  vakantiegeld while leaving the net-fold's gross untouched, the `Payslip.thirtyPercentRulingExemption`
  record, and the 3 new `NlPayrollChecks` corpus rules.

### Modified Capabilities

- `jurisdiction-packs`: `lib/Standards/packs/nl-2026.pack.json` gains 2 new bindings and 7
  repointed `base`/binding references (`packVersion` bump); `CalculationInput` gains 1 additive
  field; `CalculationInputMapper` gains 1 mapped line. **The interpreter (`PackInterpreter.php`),
  every `Dsl/Ops/*.php` file, `Rounder.php` and `RefResolver.php` are untouched** — this is a
  pack-DATA-only change using the EXISTING `cappedRate`/`expr`/`derive` vocabulary, which is the
  evidence for the design verdict above: a genuinely new, correctness-critical NL tax rule shipped
  without touching the DSL/interpreter at all.

## Impact

- `lib/Standards/tables/nl-2026.json` — NEW `parameters.dertigProcentRegeling` group + `basedOn`
  citations; `RuleCatalogue::VERSION` bump.
- `lib/Settings/register.d/hr-objects.json` — `Employee` +3 fields, description clarifications on
  3 existing fields; `Payslip` +1 field (`thirtyPercentRulingExemption`); version bumps.
- `lib/Payroll/CalculationInput.php` — +1 additive named parameter
  (`thirtyPercentRulingRate`, default `0.0`).
- `lib/Payroll/CalculationInputMapper.php` — +1 mapped line.
- `lib/Standards/packs/nl-2026.pack.json` — +1 declared input, +2 bindings, 7 repointed base
  refs, +1 `selfTest` vector (this change's golden anchor), `packVersion` bump.
- `lib/Service/PayrollRunService.php` — `CalculationInput` construction (+1 line), NEW
  `thirtyPercentExemptionCentsFor()` helper, `payslipPayload()` (+1 line).
- `lib/Standards/Checks/NlPayrollChecks.php` — +3 checks (`nl-30-regeling-looptijd-5jaar` on
  Employee, `nl-30-regeling-aftoppingsgrens-bedrag` on Payslip, `nl-30-regeling-salarisnorm` on
  Employee); `seedObjects()` unchanged (existing seed already satisfies all 5 checks vacuously/
  positively).
- `lib/Standards/rules/payroll.json` — +3 rule entries; 1 existing entry's `statement`/
  `sourceUrl` fixed (pre-existing placeholder removed).
- `lib/Service/RuleAuditService.php` — context enrichment so the new Payslip-scoped check can
  resolve the referenced Employee's rate (the `cao.employeesById`/`payroll.runsById` precedent).
- `tests/fixtures/payroll-2026/thirty-percent-ruling-anchor.json` — NEW golden fixture (this
  change's design.md D4 anchor).
- `src/manifest.json` — the Employee detail page's existing "Employment & compliance" widget
  (already lists "30%-ruling" among its fields) gains the 3 new fields; no new page/widget.
- No change to `lib/Payroll/PayrollCalculator.php`, `lib/Payroll/Dsl/PackInterpreter.php`, any
  `lib/Payroll/Dsl/Ops/*.php`, `Rounder.php`, or `RefResolver.php`.
