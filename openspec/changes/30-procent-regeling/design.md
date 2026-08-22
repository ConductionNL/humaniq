# Design — 30-procent-regeling

## Context

**Verified against HEAD 2026-07-17.** Consumes `jurisdiction-packs` (merged 2026-07-15, ADR-101):
the engine is now a country-pluggable declarative step-DSL executed by a pure interpreter.
`PayrollCalculator::calculate()` (`lib/Payroll/PayrollCalculator.php`) is a thin façade —
`$this->packs->resolve($in->jurisdiction, $in->period)` then
`$this->interpreter->run($this->mapper->toPackInputs($in), $pack, $t, $in->period)` then map the
16-key `PackRunResult` onto `CalculationResult`'s 18 fields. It holds **zero** Dutch tax logic;
all of it lives in `lib/Standards/packs/nl-2026.pack.json` as 16 declarative steps + ~20 bindings,
executed by `lib/Payroll/Dsl/PackInterpreter.php` (385 lines) via the op classes in
`lib/Payroll/Dsl/Ops/`. This is the AUTHORITATIVE path — not `PayrollCalculator`'s own body, which
no longer contains any calculation.

Also read directly at HEAD:

- `lib/Payroll/CalculationInput.php` — 10 constructor parameters, 2 already additive
  (`verzekeringsplichtig` from `dga-payroll-mode`, `jurisdiction` from `jurisdiction-packs` itself)
  with defaults; every call site uses named arguments (the `dga-payroll-mode` design.md D1 grep
  precedent, re-confirmed).
- `lib/Payroll/CalculationInputMapper.php` — the ONE place `CalculationInput`'s field names get
  translated into the pack's own `inputs` vocabulary (`toPackInputs()`); shared by
  `PayrollCalculator` and `PackValidator`'s self-test runner, so they cannot drift.
- `lib/Standards/packs/nl-2026.pack.json` — `grossRef: "@binding.tvl"`. The `tvl` binding
  (`clamp(@input.gross, min: 0)`) is used for TWO distinct purposes today, because until now they
  have always been the same number: (1) it is the base the entire tabelloon/heffingskortingen/
  Zvw/werknemersverzekeringen/vakantiegeld chain is computed over, and (2) it is `grossRef` — the
  value `PackInterpreter` resolves as `$gross` and folds `net = gross - sum(reduces-net)` against
  (`PackInterpreter.php` line 161: `$gross = (int) $this->vocab->refs()->resolve($pack->grossRef(),
  $ctx);`). This change is the FIRST one to need those two meanings to diverge.
- `lib/Payroll/Dsl/Ops/CappedRateOp.php` — `cappedRate(base, rate, cap) = min(base, cap) * rate /
  100`, already used 5 times in the NL pack (Zvw + Awf/Aof/Wko/Whk), all `employer-cost`. This is
  EXACTLY the 30%-ruling's exemption formula (Belastingdienst: "the 30%-benefit is calculated
  maximally over a salary of €262.000").
- `lib/Payroll/Dsl/Ops/ExprOp.php` grammar — `+ - * / min max abs round floor ceil`, refs and
  literals, already used for `max(0, @step.x1 - (...))` (the `loonheffingJaar` step). `max(0,
  @binding.tvl - @binding.thirtyPercentExemption)` is the same shape.
- `lib/Settings/register.d/hr-objects.json` `Employee` — ALREADY carries
  `thirtyPercentRulingGranted` (boolean), `thirtyPercentRulingRate` (number, nullable, "must not
  exceed 30 for 2025-2026"), `thirtyPercentCappedAtWntNorm` (boolean) — added by an earlier change
  for `lib/Standards/Checks/NlPayrollChecks.php`'s two STRUCTURAL checks
  (`nl-30-percent-regeling`, `nl-30-regeling-aftoppingsgrens`), neither of which reaches the
  engine or verifies a real amount.
- `lib/Standards/rules/payroll.json` — `nl-30-regeling-aftoppingsgrens`'s `statement` literally
  ends `"(verify cap amount)"` — an unresolved placeholder from whenever that rule was added.
  `nl-30-percent-regeling`'s `effectiveDate: "2027-01-01"` correctly anticipates the 27% change.
- `openspec/architecture/adr-101-jurisdiction-packs.md` — *"The DSL cannot do VCR or
  netto-operations... Same for the 30%-ruling netto-operation (an inverse solve, not a forward
  chain)."* This sentence is scoped narrowly: it names the netto-operation (grossing up an agreed
  NET salary under the ruling), not the forward exemption this change builds. humaniq issue #81
  tracks the netto-operation as a likely first `phpStep` customer — untouched by this change.

## Goals / Non-Goals

**Goals:** the 2026 30%-ruling parameters as versioned, verified table data; an Employee-level
marker (start date, applied rate/"phase", end date, reduced-norm flag) driving a real reduction of
the taxable wage the engine computes over; a digit-exact golden anchor: gross unchanged, taxable
base and every tax/premium component drop, `nettoPay` correctly RISES; 3 new machine-checkable
corpus rules (term, cap amount, salary norm).

**Non-Goals** (binding, from the proposal): the netto-operation inverse solve, partial
non-resident status, ET-regeling comparison/election, partial-year proration, multi-BV
aggregation, expiry-alert/intrekking/bewijspakket workflows.

## Decisions

### D1 — Verdict: the forward exemption fits the declarative DSL; it does NOT need `phpStep`

This is the load-bearing question the brief requires answering with evidence, not assertion.

**What the 30%-ruling actually requires, forward direction:** given a known monthly gross wage and
a granted percentage, compute `exemption = min(gross, WNT-cap) × rate / 100`, then compute the
taxable wage as `gross - exemption`, then run the EXISTING tabelloon/heffingskortingen/Zvw/
werknemersverzekeringen/vakantiegeld chain over that reduced base instead of the raw gross. Every
piece of that is:

- `min(base, cap) × rate / 100` — `CappedRateOp`, byte-identical shape to the Zvw/Awf/Aof/Wko/Whk
  steps already shipping in the NL pack.
- `max(0, a - b)` — `ExprOp`'s grammar, byte-identical shape to `loonheffingJaar`'s `max(0, @step.
  x1 - (@step.ahk + @step.ouk + @step.ark))`.
- Repointing 7 existing `base`/binding references from one binding to a new one — pure
  configuration, zero new interpreter behaviour.

No loop, no recursion, no cross-period state, no inverse solve. It is a two-binding addition using
vocabulary the pack already exercises for structurally identical computations. **This is precisely
the DSL's design center** — REQ-JP-002 lists `cappedRate` and `expr` as MVP-vocabulary ops for
exactly this shape of "a rate, capped at a ceiling" tax rule.

**What would force `phpStep`, and why this isn't that:** the escape hatch exists for genuine
national exotica the closed vocabulary cannot express — `piecewiseAccrue`'s round-then-cap
Rekenvoorschriften ordering is the shipped example, and the netto-operation (an inverse solve
requiring the interpreter to search for a gross that produces a target net) is ADR-101's own named
future example. The 30%-ruling's FORWARD exemption is neither: it is a single monotonic formula
over already-known inputs, computed once, in the same direction as every other step in the chain.
Reaching for `phpStep` here would mean the DSL's own headline primitive (`cappedRate`) exists for
no NL use — which it already isn't; the exemption is its SIXTH real use.

**Verdict, stated plainly: this ships as pack data — a new binding, a new input, 7 repointed
refs, `packVersion` bump. Zero interpreter changes. Zero `Ops/*.php` changes. Zero `phpStep`
handlers.** If a future change needs the netto-operation, THAT is the hatch's legitimate first
customer (hrmq#81) — this change is not it, and does not touch the hatch or its (currently empty)
handler registry.

### D2 — `belastbaarLoon` is a NEW binding; `grossRef` stays `@binding.tvl` unchanged, so net pay rises correctly

The naive approach — reducing `tvl` itself by the exemption — breaks the interpreter's own
`net = gross - sum(reduces-net)` fold, because `grossRef` currently resolves to `tvl`. If `tvl`
were reduced, `nettoPay` would become `(gross - exemption) - loonheffing`, i.e. the exempted cash
would be silently REMOVED from the employee's net pay — exactly backwards, since the exempted 30%
is real money the employee keeps, tax-free, in the SAME payslip.

The fix separates the two meanings `tvl` accidentally shared until now:

```jsonc
// NEW binding, inserted after "tvl":
{
  "id": "thirtyPercentExemption",
  "_note": "Wet LB 1964 art. 31a — the 30%-ruling's tax-free target allowance: min(gross, WNT-aftoppingsgrens) x the granted rate. Zero when not granted (thirtyPercentRulingRate defaults to 0, so cappedRate naturally degrades to 0 -- no `when` gate needed).",
  "op": "derive",
  "using": {
    "op": "cappedRate",
    "base": "@binding.tvl",
    "rate": "@input.thirtyPercentRulingRate",
    "cap": "@table.dertigProcentRegeling.aftoppingsgrens.maand:cents"
  },
  "round": {"mode": "nearest", "unit": "cent"}
}

// NEW binding, inserted immediately after:
{
  "id": "belastbaarLoon",
  "_note": "The TAXABLE wage after the 30%-ruling exemption. Every step below that used to read @binding.tvl as its tax/premium/vakantiegeld base now reads THIS instead. grossRef stays @binding.tvl (unreduced) -- the exempted cash never leaves net pay, only the tax base.",
  "op": "derive",
  "using": {
    "op": "expr",
    "expression": "max(0, @binding.tvl - @binding.thirtyPercentExemption)"
  }
}
```

Then exactly 7 existing refs repoint from `@binding.tvl` to `@binding.belastbaarLoon`: the
`annualised` binding (`@binding.tvl * tijdvakFactor` → `@binding.belastbaarLoon * tijdvakFactor` —
this is what drives `L`, and therefore the entire tabelloon/schijventarief/heffingskortingen
chain), and the `base` param of the `vakantiegeld`, `zvw`, `awf`, `aof`, `wko`, `whk` steps.
`grossRef` (`"@binding.tvl"`) is **not** touched — it still resolves to the un-exempted gross, so
`PackInterpreter`'s `net = gross - loonheffing` correctly yields `gross - (the now-smaller
loonheffing)`, i.e. net pay RISES by exactly the tax saved on the exempted amount. `appliedTaxRate`
(`(@step.loonheffing / @binding.tvl) * 100`) is **not** touched either — dividing by the
unreduced `tvl` yields the employee's effective tax rate on their FULL salary, which is the more
meaningful number and requires no change.

**Why Zvw/werknemersverzekeringen/vakantiegeld all move to `belastbaarLoon`, not just
loonheffing**: the 30%-ruling's target allowance (gerichte vrijstelling) is excluded from the
SV-loon and Zvw-loon bases under Wet LB 1964 art. 31a jo. Wfsv — unlike bijtelling (which IS
SV-loon), the exemption legitimately reduces ALL of these, not just income tax. **Flagged with
lower confidence**: the vakantiebijslag-grondslag treatment is applied the same way here for
engine consistency (the `belastbaarLoon` binding is shared), but was not independently re-verified
against a primary source the way Zvw/werknemersverzekeringen were — the same caveat class
`fleet-bijtelling` D6 recorded for ITS vakantiegeld base (there: CAO practice; here: an unconfirmed
assumption). Named as a risk below, not hidden.

### D3 — 2026 figures: verified via live web research, with one important correction to the brief

The task brief's framing ("the 2024+ 30/20/10 stepped phases") describes the ORIGINAL Belastingplan
2024 legislation. Live 2026 sources show that plan was **reversed before it ever took effect**:

- **Percentage — flat 30% for 2025 and 2026, NOT stepped.** The originally-legislated 30%→20%→10%
  step-down (each phase 20 months) was cancelled by the 2024 Voorjaarsnota coalition compromise.
  From 1 January 2027 a flat 27% applies for the WHOLE 5-year term (also not stepped — a further,
  separate change, not this change's concern). Corroborated across independent sources: [30%
  regeling: salariseisen voor 2026 — CROP](https://crop.nl/kennisbank/30-regeling-salariseisen-voor-2026-en-meer/),
  [Wat is de 30%-regeling in Nederland (2026) — byjoinwise](https://byjoinwise.com/kennisbank/werkgevers-info/30-procent-regeling/),
  [Vergoeding 30%-regeling expats wordt 27% — Ondernemersplein/Rijksoverheid](https://ondernemersplein.overheid.nl/wetswijzigingen/vergoeding-30-procent-regeling-expats-wordt-27-procent/).
  **Consequence: this change does NOT implement a stepped rate table.** `thirtyPercentRulingRate`
  is a single flat value per grant (30 for a 2025/2026 grant, will be 27 for a 2027+ grant when
  that table year ships) — a data-only `nl-2027.json` addition when the time comes, no engine
  change, exactly the tables `SCHEMA.md` annual-re-issue discipline.
- **Salary norm 2026 — €48.013 general, €36.497 reduced (under 30, qualifying master's).**
  Belastingdienst Nieuwsbrief Loonheffingen 2026, cited directly: [30% regeling in 2026 door
  Belastingdienst bevestigd — Salarisjobs](https://salarisjobs.nl/kennis/30-regeling-in-2026-door-belastingdienst-bevestigd/)
  ("The income threshold for the expatregeling as of January 1, 2026 is: €48,013 for a skilled
  worker, and €36,497 for a skilled worker under 30 years old with a master's degree" — up from
  €46.660/€35.468 in 2025). Corroborated by [CROP](https://crop.nl/kennisbank/30-regeling-salariseisen-voor-2026-en-meer/)
  and [aame.nl](https://www.aame.nl/en/news-update-expat-scheme-salary-thresholds-for-2026/).
- **WNT-aftoppingsgrens 2026 — €262.000/jaar, and UNCONDITIONAL from 2026.** The transitional
  carve-out that let pre-2023 rulings escape the cap expired 31 December 2025 ("Het overgangsrecht
  vervalt namelijk per 1 januari 2026"): [WNT-norm geldt vanaf 2026 voor alle expatregelingen —
  Moore DRV](https://www.moore-drv.nl/wnt-norm-expatregeling/), corroborated by
  [PwC](https://www.pwc.nl/nl/actueel-en-publicaties/belastingnieuws/loonbelasting-en-sociale-verzekeringen/maximering-30--regeling.html)
  and [Wesselman](https://www.wesselman-info.nl/kennis/wnt-norm-expatregeling-2026). This FIXES
  the pre-existing `nl-30-regeling-aftoppingsgrens` rule's `"(verify cap amount)"` placeholder —
  now `"€262.000"`, cited.
- **Maximum duration — 60 months (5 years) from the first working day, prior NL employment
  deducted.** Belastingdienst primary source: [Beschikking: geldigheid en toetsen voorwaarden —
  Belastingdienst](https://www.belastingdienst.nl/wps/wcm/connect/bldcontentnl/belastingdienst/zakelijk/internationaal/personeel/u_bent_niet_in_nederland_gevestigd_loonheffingen_inhouden/als_u_loonheffingen_gaat_inhouden/extraterritoriale_kosten_en_de_30procentregeling/voorwaarden_voor_de_30procentregeling1/beschikking_geldigheid_en_toetsen_voorwaarden).

All 5 leaves ship `verified: true` — every figure is corroborated by at least 2 independent
sources including the Belastingdienst's own published newsletter/page, per the tables `SCHEMA.md`
discipline (`verified: false` would require a `checkAgainst`, which none of these need).

```jsonc
"dertigProcentRegeling": {
  "percent": {
    "value": 30,
    "source": "Belastingdienst Nieuwsbrief Loonheffingen 2026 / Belastingplan 2025 (Voorjaarsnota 2024 coalitieakkoord)",
    "verified": true,
    "note": "Flat 30% for the full term for 2025/2026 grants. The Belastingplan 2024 stepped 30/20/10 step-down was REVERSED before taking effect -- NOT modelled. A flat 27% applies from 2027 (also not stepped); a future nl-2027.json data-only addition, no engine change."
  },
  "maxDurationMonths": {
    "value": 60,
    "source": "Belastingdienst -- Beschikking: geldigheid en toetsen voorwaarden",
    "sourceUrl": "https://www.belastingdienst.nl/wps/wcm/connect/bldcontentnl/belastingdienst/zakelijk/internationaal/personeel/u_bent_niet_in_nederland_gevestigd_loonheffingen_inhouden/als_u_loonheffingen_gaat_inhouden/extraterritoriale_kosten_en_de_30procentregeling/voorwaarden_voor_de_30procentregeling1/beschikking_geldigheid_en_toetsen_voorwaarden",
    "verified": true,
    "note": "Maximum 5 years (60 months) from the first working day in NL; prior NL employment/stay is deducted from the 5 years (not modelled -- MVP non-goal)."
  },
  "aftoppingsgrens": {
    "value": {"jaar": 262000, "maand": 21833.33},
    "source": "WNT-norm (Balkenende-norm) 2026 -- unconditional for every 30%-ruling from 1 Jan 2026 (transitional carve-out expired 31 Dec 2025)",
    "verified": true,
    "note": "Fixes the pre-existing nl-30-regeling-aftoppingsgrens rule's placeholder cap amount. maand = jaar / 12, rounded to the cent, the maximumpremieloon.maand precedent."
  },
  "salarisnormAlgemeen": {
    "value": 48013,
    "source": "Belastingdienst Nieuwsbrief Loonheffingen 2026",
    "verified": true,
    "note": "2025: EUR 46.660. Minimum annualised loon for a granted 30%-ruling (excl. the tax-free allowance itself)."
  },
  "salarisnormMasterOnder30": {
    "value": 36497,
    "source": "Belastingdienst Nieuwsbrief Loonheffingen 2026",
    "verified": true,
    "note": "2025: EUR 35.468. Reduced norm for employees under 30 with a qualifying (NL-equivalent) master's degree -- Employee.thirtyPercentRulingReducedNormApplies gates which norm nl-30-regeling-salarisnorm compares against."
  }
}
```

### D4 — Worked anchor: €3.800 salary + 30%-ruling → €1.140,00 exemption, €3.548,83 netto (hand-computed, cross-checked against a faithful DSL simulator)

This reuses `payroll-core-engine`'s own anchor input (€3.800,00 monthly, wit, korting toegepast,
below AOW, Awf low, Aof laag, Whk 1,52%, `nl-2026` tables — the SAME input the base anchor pins:
`loonheffing` €718,83, `arbeidskorting` €473,75, `nettoPay` €3.081,17), adding
`thirtyPercentRulingGranted: true`, `thirtyPercentRulingRate: 30.0`.

**The recompute was cross-checked, not just hand-carried**: every op below (`quantize`, `bracket`
affine, `taper`, `piecewiseAccrue` with its round-term-then-cap ordering, `cappedRate`, `expr`,
the `Rounder`'s `floorEuro`/`ceilEuro`/`round2cent` shapes) was ported line-for-line from
`lib/Payroll/Dsl/Ops/*.php` and `Rounder.php` into a standalone script, which FIRST reproduced the
existing base anchor exactly (`loonheffing` €718,83, `arbeidskorting` €473,75, `appliedTaxRate`
18,92, `nettoPay` €3.081,17 — all match) before being run on this anchor's input. That is the same
self-consistency mitigation `fleet-bijtelling`/`dga-payroll-mode` used (hand-compute independent of
the future implementation), strengthened by validating the computation method itself against a
KNOWN-correct existing result first.

**1. Exemption**: `base = tvl = 380.000` cents (€3.800,00, `clamp` is a no-op — positive gross).
`cap = @table.dertigProcentRegeling.aftoppingsgrens.maand:cents = 2.183.333` cents (€21.833,33 —
262.000/12, rounded to the cent, well above the gross, so the cap does NOT bind in this anchor).
`exemption = min(380.000, 2.183.333) × 30 / 100 = 380.000 × 0,30 = 114.000` cents = **€1.140,00**
(no rounding artifact — exact).

**2. Belastbaar loon**: `belastbaarLoon = max(0, 380.000 - 114.000) = 266.000` cents = **€2.660,00**
(exactly 70% of gross, since the cap did not bind).

**3. Tabelloon L**: `annualised = 266.000 × 12 = 3.192.000`; `Lv = 5.400` cents (€54,00);
`L = floor(3.192.000 / 5.400) × 5.400 = floor(591,111…) × 5.400 = 591 × 5.400 = 3.191.400` cents =
**€31.914,00**.

**4. Bracket X1** (belowAow bracket 1, since L=€31.914,00 < €38.883: `a=0`, `b=35,75%`, `c=0`):
`X1_raw = (3.191.400 - 0) × 0,3575 / 100 + 0`... in euro terms: `(31.914 - 0) × 0,3575 = 11.409,255`;
`X1 = floorEuro(11.409,255) = €11.409,00`.

**5. AHK** (`ahkm1=3.115`, `ahkg1=29.736`, `ahka1=0,06398`, belowAow): `excess = max(0, 31.914 -
29.736) = 2.178`; `AHK_raw = 3.115 - 2.178 × 0,06398 = 3.115 - 139,34844 = 2.975,65156`;
`AHK = ceilEuro(2.975,65156) = €2.976,00`.

**6. ARK** (piecewiseAccrue, belowAow: `arkg1=11.965 arkg2=25.845 arkg3=45.592`,
`arkm1=996 arkm2=5.300 arkm3=5.685`, `arko1=0,08324 arko2=0,31009 arko3=0,01950`, `arka1=0,06510`,
L=€31.914,00 — between `arkg2` and `arkg3`, so the descending TAIL is not reached):
`term1 = min(11.965 × 0,08324, 996) = min(995,9666, 996) = 995,9666`;
`term2 = min(995,9666 + (25.845-11.965) × 0,31009, 5.300) = min(995,9666 + 4.304,0492, 5.300) =
min(5.300,0158, 5.300) = 5.300` (capped);
`term3 = min(5.300 + (31.914-25.845) × 0,0195, 5.685) = min(5.300 + 118,3455, 5.685) =
min(5.418,3455, 5.685) = 5.418,3455` (NOT capped — L has not reached `arkg3`, so the tail never
applies); `ARK = ceilEuro(5.418,3455) = €5.419,00`.

**7. Loonheffing**: `X = floorEuro(X1 - (AHK+ARK)) = floorEuro(11.409 - (2.976+5.419)) =
floorEuro(11.409 - 8.395) = floorEuro(3.014) = €3.014,00`; `loonheffing = round2(3.014 / 12) =
round2(251,1666…) = €251,17`; `arbeidskorting = round2(5.419 / 12) = round2(451,5833…) = €451,58`;
`appliedTaxRate = round2(251,17 / 3.800 × 100) = round2(6,60973…) = 6,61` (divided by the
UNREDUCED `tvl` — D2).

**8. Volksverzekeringen (informative)**: `vvRate = 17,90+0,10+9,65 = 27,65%`;
`vvJaar = min(31.914, 38.883) × 0,2765 = 31.914 × 0,2765 = 8.824,221`;
`volksverzekeringen = min(251,17, round2(251,17 × 8.824,221 / 11.409)) = min(251,17,
round2(194,27…)) = €194,27`.

**9. Zvw werkgeversheffing**: `zvwBase = min(2.660, 6.617,41) = 2.660`; `zvw = round2(2.660 ×
6,10%) = €162,26`.

**10. Werknemersverzekeringen** (`pl = min(2.660, 6.617,41) = 2.660`, Awf laag, Aof laag):
`awf = round2(2.660 × 2,74%) = €72,88`; `aof = round2(2.660 × 6,27%) = €166,78`;
`wko = round2(2.660 × 0,50%) = €13,30`; `whk = round2(2.660 × 1,52%) = €40,43`;
`werknemersverzekeringen = 72,88+166,78+13,30+40,43 = €293,39`;
`employerCharges = 293,39 + 162,26 = €455,65`.

**11. Vakantiegeldreservering**: `round2(2.660 × 8%) = €212,80`.

**12. Netto** (the incidence fold, `grossRef` = unreduced `tvl` = €3.800,00 — D2):
`nettoPay = 3.800,00 - loonheffing = 3.800,00 - 251,17 = €3.548,83`.

**Anchor result**: `thirtyPercentRulingExemption €1.140,00`, `grossPay €3.800,00` (unchanged —
`grossRef` untouched), `loonheffing €251,17`, `arbeidskorting €451,58`, `appliedTaxRate 6,61`,
`volksverzekeringen €194,27`, `zvw €162,26` (`zvwRate 6,10`), `awf €72,88`, `aof €166,78`,
`wko €13,30`, `whk €40,43`, `werknemersverzekeringen €293,39`, `employerCharges €455,65`,
`vakantiegeldReserved €212,80` (`vakantiegeldRate 8,0`), `nettoPay €3.548,83`. This is the fixture
`tests/fixtures/payroll-2026/thirty-percent-ruling-anchor.json` must byte-match, and the vector
this change adds to `nl-2026.pack.json`'s own `selfTest` block (REQ-JP-006's keystone: the pack
proves its own arithmetic at upload/validation time).

**Sanity cross-check against the base €3.800 anchor (no ruling)**: `loonheffing` DROPS
€718,83→€251,17 (**-€467,66**); `nettoPay` RISES €3.081,17→€3.548,83 (**+€467,66**) — the two
deltas are equal and opposite BY CONSTRUCTION, because `nettoPay = (unchanged) gross - loonheffing`
(D2): net pay rises by exactly the tax saved, never by the raw €1.140,00 exemption itself (most of
which was never taxed at the marginal bracket rate anyway). This is the correct direction — a
17,4% x €2.660 marginal-ish effect landing well short of a 1:1 exemption-to-netto mapping is
expected, not a bug.

**A second scenario proves the WNT cap actually binds**: for a €25.000,00/month gross (well above
the €21.833,33 monthly cap), the uncapped exemption would be `25.000 × 30% = €7.500,00`; capped,
it is `min(25.000, 21.833,33) × 30% = 21.833,33 × 0,30 = €6.550,00` — a €950,00 difference. This
is the `nl-30-regeling-aftoppingsgrens-bedrag` check's positive-path fixture.

### D5 — `NlPayrollChecks` gains 3 checks; the 2 existing checks and their seed data are untouched

New rules in `lib/Standards/rules/payroll.json` (domain `tax`, jurisdiction `NL`, framework
`nl-loonheffingen`, source `Wet LB 1964 art. 31a`, `machineCheckable: true`, severity
`conditional` — matching the 2 existing 30%-ruling rules' severity):

- **`nl-30-regeling-looptijd-5jaar`** (Employee): vacuous when `thirtyPercentRulingGranted` is not
  `true`. Else requires `thirtyPercentRulingStartDate` present; flags when
  `thirtyPercentRulingEndDate` is absent, is more than `dertigProcentRegeling.maxDurationMonths`
  (60) after the start date, OR the ruling is still `granted: true` with an end date already in
  the past (a stale, unrevoked ruling) — the `retainedAtLeastYearsAfterEnd()` date-arithmetic
  precedent already in `NlPayrollChecks.php`.
- **`nl-30-regeling-aftoppingsgrens-bedrag`** (Payslip): vacuous when
  `thirtyPercentRulingExemption` is null. Else re-derives `min(grossPay,
  dertigProcentRegeling.aftoppingsgrens.maand) × employee.thirtyPercentRulingRate / 100` (via
  `RuleAuditService::audit()` context enrichment resolving the Payslip's `employeeId` — the
  `cao.employeesById`/`payroll.runsById` precedent) and flags a cents-mismatch against the
  recorded amount. This is the NUMERIC enforcement the existing boolean-only
  `nl-30-regeling-aftoppingsgrens` never provided, and doubles as a drift detector between
  `PayrollRunService`'s independent PHP re-derivation and the pack's own binding (D2/D4) — if they
  ever disagree, this is what catches it.
- **`nl-30-regeling-salarisnorm`** (Employee): vacuous when `thirtyPercentRulingGranted` is not
  `true`, or `grossMonthlySalary` is absent/non-numeric. Else `norm =
  thirtyPercentRulingReducedNormApplies ? salarisnormMasterOnder30 : salarisnormAlgemeen`; flags
  when `grossMonthlySalary × 12 < norm`.

The 2 EXISTING checks (`nl-30-percent-regeling`: rate ≤ 30; `nl-30-regeling-aftoppingsgrens`: the
`thirtyPercentCappedAtWntNorm` flag is `true` when granted) are **unchanged in behaviour** — only
`nl-30-regeling-aftoppingsgrens`'s corpus `statement`/`sourceUrl` are fixed (D3, the
`"(verify cap amount)"` placeholder). The existing seeded `Employee` (`thirtyPercentRulingGranted:
true, thirtyPercentRulingRate: 30.0, thirtyPercentCappedAtWntNorm: true`, `grossMonthlySalary:
3800.00`) needs NO seed changes: it has no `thirtyPercentRulingStartDate` (so
`nl-30-regeling-looptijd-5jaar` reads it as an incomplete-but-not-yet-flagged record — a MISSING
start date on an already-granted ruling is itself a data-quality signal worth a FOLLOW-UP check,
not built here to keep this change's corpus surface to exactly 3 new rules) and its annualised
€45.600 salary is comfortably above both norms (positive path for `nl-30-regeling-salarisnorm`).

### D6 — Declarative vs imperative (ADR-031)

| Behaviour | Path | Rationale |
|---|---|---|
| 2026 rate/cap/norms | static tables (`nl-2026.json`) | annual-change, data-only, every prior nl-2026 parameter's precedent |
| The exemption formula itself | **pack data** (`cappedRate`/`expr` bindings) | D1's verdict — the DSL's own design center, not the `PayrollCalculator`/`PayrollRunService` ADR-031 exception |
| Employee ruling marker | declarative schema (register.d) | plain data, no workflow — the `isDga`/`thirtyPercentRulingGranted` precedent |
| Exemption re-derivation for Payslip stamping | imperative (`PayrollRunService`, one small formula) | the `bijtelling` D3 precedent — a pure formula computed once at assembly time, independent of (but provably consistent with) the pack |
| Term/cap/norm enforcement | corpus rule + `CheckProvider` | the app's established exception, `nl-bijtelling-auto-privegebruik`/`nl-gebruikelijkloon-norm` precedent |

## Seed Data (ADR-001)

No new seed objects. The existing seeded `Employee` already carries `thirtyPercentRulingGranted:
true`/`thirtyPercentRulingRate: 30.0` (D5) — it exercises the POSITIVE (non-violating) path of all
5 checks once the 3 new date/flag fields are left absent-but-not-contradictory. The golden anchor
(D4) is this change's canonical engine proof, computed independently of seed data, exactly like
every prior engine-touching change's anchor.

## Risks / Trade-offs

- **Vakantiebijslag-grondslag reduction is an unverified assumption** (D2) — applied for engine
  consistency (the shared `belastbaarLoon` binding), not independently confirmed against a primary
  source for that specific sub-rule the way Zvw/werknemersverzekeringen were. Flagged, not hidden
  — the same caveat class `fleet-bijtelling` D6 recorded for its own vakantiegeld-grondslag choice.
- **`PayrollRunService`'s exemption re-derivation and the pack's `thirtyPercentExemption` binding
  are two independent implementations of the same formula** (D5) — they must agree by
  construction (same inputs, same formula), and `nl-30-regeling-aftoppingsgrens-bedrag` is the
  safety net if they ever drift, but this is a real duplication, not a single source of truth. A
  future refactor could have `PayrollCalculator` expose the binding value directly instead of
  `PayrollRunService` recomputing it — deferred because `CalculationResult`'s 18-field contract
  (`jurisdiction-packs` REQ-JP-007) is deliberately not widened by this change (see D2/proposal).
- **No auto-expiry**: a ruling past its `thirtyPercentRulingEndDate` but still `granted: true`
  keeps getting the exemption applied every payroll run — `nl-30-regeling-looptijd-5jaar` FLAGS
  this (a detective control, `occ humaniq:rules:audit`) but does not block the run or silently
  auto-correct it. This matches the fleet's established trust boundary (`fleet-bijtelling` D6:
  "the same trust boundary payroll-core-engine already draws around Employee.grossMonthlySalary")
  and the task's own phrasing ("flags", not "blocks").
- **`piecewiseAccrue` is still on probation** (ADR-101) — this change does not touch it; the ARK
  computation in D4 exercises it at a DIFFERENT point in its segment range (L between `arkg2` and
  `arkg3`, never reaching the descending tail) than the base/bijtelling/dga anchors did, which is
  incidental extra coverage of an already-shipped primitive, not new risk.

## Open Questions

- None blocking. The netto-operation, partial non-resident status, ET-regeling election,
  partial-year proration, multi-BV aggregation and the alert/intrekking/bewijspakket workflows are
  named follow-ups (proposal Non-Goals).
