# Design — payroll-core-schema

## Context

**Verified against HEAD 2026-07-13.** `Employee` (hr-objects.json v0.3.0) carries
`grossMonthlySalary`, `taxTableColor` (`wit|groen`), `dateOfBirth`, `loonheffingenVerklaringOnFile`
and the 30%-ruling flags. `EmploymentContract` carries `hoursPerWeek`, `hourlyWage`, `type`,
`writtenContract`, `awfTariff` (`low|high`). `Payslip` (v0.2.0, ~54 fields) already has a home for
every NL engine output **except** the applied arbeidskorting: `grossPay`, `hoursWorked`,
`loonheffing`, `volksverzekeringen`, `werknemersverzekeringen`, `zvw`/`zvwMode`/`zvwRate`,
`appliedTaxRate`, `nettoPay`, `vakantiegeldReserved`/`vakantiegeldRate`, `pensionContribution`,
plus the wkr/anoniementarief/statement-content fields the audit rules read. `PayrollRun` (v0.1.0)
has `period`, `administrationId`, `status` (`draft|approved|posted|paid` — plain enum, no
lifecycle) and the five totals + GL-reconciliation fields. The rule corpus
(`lib/Standards/rules/payroll.json`, catalogue `2026-07.5`) already fixes the 2026 Zvw percentages
(6,10/4,85), the Awf laag/hoog principle, WML €14,71→€14,99 and vakantiebijslag ≥8% —
the corpus becomes the engine's own self-check.

**Rate verification (2026-07-13, primary sources):**

- Belastingdienst, *Rekenvoorschriften voor de geautomatiseerde loonadministratie 2026*, uitgave
  januari 2026 versie 2 (LH 991-Z62FD) —
  <https://download.belastingdienst.nl/belastingdienst/docs/rekenvoorschriften_voor_geautomatiseerde_loonadministratie_lh991z62fd.pdf>
  — read in full for tables 1a/1b (Lv, Lmax, tijdvakfactoren), 2 (schijventarief), 3 (AHK),
  4 (OUK), 5 (AOK), 6 (ARK), and the §2.1–2.2.4 formula chain (rounding rules included).
- SZW, *Regeling premiepercentages en maximumpremieloon werknemers- en volksverzekeringen 2026*,
  bijlagen (open.overheid.nl,
  <https://open.overheid.nl/documenten/916b30f3-eafd-4acf-bd5a-f58319dae544/file>) — read in full:
  maximumpremieloon/-bijdrageloon €79.409 (€6.617,41/maand), AOW 17,9 / Anw 0,1 / Wlz 9,65,
  Aof 7,63/6,27, Whk gemiddeld 1,52, AWf 2,74/7,74, Wko-opslag 0,50, Zvw 6,1/4,85,
  referentieminimumloon bruto €2.294,40/maand.
- Rijksoverheid, *Bedragen minimumloon 2026* — €14,71/uur per 1-1-2026, €14,99 per 1-7-2026;
  since 2024 there is **no** statutory fixed monthly minimum (monthly = hourly × actual hours).
- Rijksoverheid *AOW-leeftijd* — 67 jaar in 2025/2026/2027 (67+3 mnd from 2028).
- Belastingdienst *Tabel algemene heffingskorting 2026* + *Voorlopige aanslag 2026 tarieven* —
  cross-checks of the bracket/korting figures (consistent with the Rekenvoorschriften).

The only value **not** pinnable from a national publication is the employer-specific Whk
percentage (gedifferentieerde premie Werkhervattingskas — set per employer by Belastingdienst
beschikking, published in the UWV nota *Gedifferentieerde premies WGA en Ziektewet 2026*). It
ships as the national gemiddelde 1,52% with `verified: true` **for the average** and an explicit
`placeholder: true` + note that a real administration must configure its beschikking value.

## Goals / Non-Goals

**Goals:** one verified, versioned, annually re-issuable parameter file the engine is a pure
function of; the three minimal schema deltas; the engine-output contract as corpus rules; zero
behaviour change in this spec (config head — mid-chain merge is safe, ADR-032).

**Non-Goals:** any PHP beyond the VERSION bump constant (the calculator, service, occ commands,
checks and tests are spec 2); CAO rules; groene-tabel completeness beyond structure; bijzonder
tarief; 30%-ruling netto-operation; loonaangifte message generation; pension premie calculation;
multi-year tables. (Proposal names each with rationale.)

## Decisions

### D1 — Tax-year parameters are versioned static data, one file per year (rule-corpus philosophy)

Tax-year parameters are universal facts (identical for every tenant, changing only with
regulation), exactly like the rule corpus — so they live in code as
`lib/Standards/tables/nl-<year>.json`, NOT in OpenRegister (per-tenant config only). The annual
re-issue is a new data file + catalogue bump; no engine code changes. The file id doubles as the
engine version stamp: `PayrollRun.engineVersion = "nl-2026"` (rule `nl-engine-table-version`).

### D2 — Every value carries `source` + `verified`; placeholders are flagged, never silent

File shape (normative; SCHEMA.md documents it):

```jsonc
{
  "id": "nl-2026",
  "jurisdiction": "NL",
  "year": 2026,
  "issued": "2026-07-13",
  "basedOn": [
    {"doc": "Rekenvoorschriften geautomatiseerde loonadministratie 2026, uitgave januari 2026 versie 2 (LH 991-Z62FD)", "url": "https://download.belastingdienst.nl/belastingdienst/docs/rekenvoorschriften_voor_geautomatiseerde_loonadministratie_lh991z62fd.pdf"},
    {"doc": "Regeling premiepercentages en maximumpremieloon werknemers- en volksverzekeringen 2026 (bijlagen)", "url": "https://open.overheid.nl/documenten/916b30f3-eafd-4acf-bd5a-f58319dae544/file"},
    {"doc": "Rijksoverheid — Bedragen minimumloon 2026", "url": "https://www.rijksoverheid.nl/onderwerpen/minimumloon/bedragen-minimumloon/bedragen-minimumloon-2026"}
  ],
  "parameters": { /* groups below; every leaf = {"value": ..., "source": "...", "verified": true|false} */ }
}
```

Anything the implementer cannot confirm against the named document MUST keep `verified: false` +
a `checkAgainst` note naming the official document. Amounts are in **euros with 2 decimals** in
the data file (human-auditable against the sources); the engine converts to integer cents on load
(spec 2).

### D3 — The complete verified parameter content (normative — apply copies this)

All values below were verified 2026-07-13 against the named primary sources. Sources in the file:
`RV2026` = Rekenvoorschriften 2026 (jan-2026 v2), table/§ named; `RPP2026` = Regeling
premiepercentages 2026 bijlagen; `RO-WML` = rijksoverheid bedragen minimumloon 2026; `RO-AOW` =
rijksoverheid AOW-leeftijd.

**`loonheffing` group** (RV2026):

| key | value | source |
|---|---|---|
| `Lv` (brontabel loonstap, jaar) | 54 | RV2026 tabel 1a |
| `Lmax` (hoogste tabelloon, jaar) | 133110 | RV2026 tabel 1a |
| `tijdvakFactoren` | kwartaal 4, maand 12, vierweken 13, week 52, dag 260 | RV2026 tabel 1b |
| `schijven.belowAow` | [≤38883 @ 35,75%, a=0, c=0] · [≤78426 @ 37,56%, a=38883, c=13900] · [>78426 @ 49,50%, a=78426, c=28752] | RV2026 tabel 2 |
| `schijven.aowBorn1946OrLater` | [≤38883 @ 17,85%, c=0] · [≤78426 @ 37,56%, a=38883, c=6940] · [>78426 @ 49,50%, a=78426, c=21792] | RV2026 tabel 2 |
| `schijven.aowBorn1945OrEarlier` | [≤41123 @ 17,85%, c=0] · [≤78426 @ 37,56%, a=41123, c=7340] · [>78426 @ 49,50%, a=78426, c=21351] | RV2026 tabel 2 |

**`heffingskortingen` group** (RV2026):

| key | belowAow | aowAge | source |
|---|---|---|---|
| AHK max (`ahkm1`) | 3115 | 1556 | RV2026 tabel 3 |
| AHK afbouw start (`ahkg1`) | 29736 | 29736 | RV2026 tabel 3 |
| AHK afbouw einde (`ahkg2` = a3) | 78426 | 78426 | RV2026 tabel 3 |
| AHK afbouwfactor (`ahka1`) | 0.06398 | 0.03195 | RV2026 tabel 3 |
| ARK opbouwfactoren (`arko1..3`) | 0.08324 / 0.31009 / 0.01950 | 0.04156 / 0.15483 / 0.00974 | RV2026 tabel 6 |
| ARK afbouwfactor (`arka1`) | 0.06510 | 0.03250 | RV2026 tabel 6 |
| ARK grenzen (`arkg1..4`) | 11965 / 25845 / 45592 / 132920 | same | RV2026 tabel 6 |
| ARK maxima (`arkm1..3`) | 996 / 5300 / 5685 | 498 / 2647 / 2840 | RV2026 tabel 6 |
| OUK (`oukm1`/`oukg1`/`oukg2`/`ouka1`) | n.v.t. | 2067 / 46002 / 59782 / 0.15000 | RV2026 tabel 4 |
| AOK (`aok1`) | n.v.t. | 540 | RV2026 tabel 5 |

Formula chain the parameters feed (documented in the file's `_notes` and normative for spec 2,
straight from RV2026 §2.1–2.2.4): `L = floor((tvl × F) / Lv) × Lv` (for `tvl×F ≤ Lmax`);
`X1 = floor((L − a) × b/100 + c)`; AHK/OUK rounded **up** to whole euros, ARK terms rounded to 5
decimals with the cumulative-cap chain (`arkm1`/`arkm2`/`arkm3`) then rounded **up**;
`X = max(0, X1 − (AHK + OUK + ARK + AOK))` floored to whole euros; tijdvakbedrag `x = X / F`
rounded to 2 decimals. ARK applies **only** to the witte tabel (§2.2.3.4); the groene path uses
the same schijven + AHK (+ OUK/AOK for AOW-age) without ARK — that IS the structural groene
support, nothing more claimed. Above `Lmax`: `x = y + floor2((L/F − Lmax/F) × bmax/100)` (RV2026
systematiek 1) — recorded for completeness; MVP fixtures stay below Lmax.

**`volksverzekeringen` group** (RPP2026): AOW 17,90 · Anw 0,10 · Wlz 9,65 (sum 27,65; AOW-age pays
9,75). Marked `informativeSplitOnly: true` — the withholding is the *combined* loonheffing
(existing rule `nl-loonheffingen-volksverzekeringen`); these rates only drive the informative
`Payslip.volksverzekeringen` split (D6 in spec 2's design).

**`aow` group**: `leeftijdJaren` 67 (2026; fixed 67 for 2025–2027, RO-AOW). The engine's AOW-age
variant switches bracket set + korting column from the first day of the month the employee reaches
AOW-leeftijd (RV2026 tabel 3 toelichting).

**`zvw` group** (RPP2026 + the corpus's existing Belastingdienst percentages page):
werkgeversheffing 6,10 · inhouding 4,85 · maximumbijdrageloon 79409/jaar.

**`werknemersverzekeringen` group** (RPP2026): maximumpremieloon 79409/jaar (6617,41/maand,
6108,38/4wk) · Awf laag 2,74 / hoog 7,74 · Aof laag 6,27 / hoog 7,63 · Wko-opslag 0,50 ·
Whk gemiddeld 1,52 with `placeholder: true` + `checkAgainst: "UWV nota Gedifferentieerde premies
WGA en Ziektewet 2026 / Belastingdienst Whk-beschikking (employer-specific)"` — the **only**
placeholder-semantics value in the file. (Aof small/other-employer classification is also
per-employer; the file carries both rates verified, selection is engine input in spec 2.)

**`wml` group** (RO-WML): hourly 21+ `2026-01-01: 14,71` · `2026-07-01: 14,99`;
`referentiemaandloon: 2294,40` (RPP2026 bijlage 3) with note: since 2024 no statutory fixed
monthly minimum exists — monthly minimum = hourly × actual hours (the corpus rule
`nl-minimumuurloon-wet` already states this).

**`vakantiebijslag` group**: `minRatePercent: 8.0` (WML art. 15 — statute, existing corpus rule
`nl-vakantiebijslag-8procent`).

**Verified-status summary:** every value above `verified: true` against a primary source, except
`werknemersverzekeringen.whk` which is verified-as-national-average and flagged
`placeholder: true` (employer-specific by beschikking).

### D4 — Schema deltas are minimal and justified per field

| Schema | Field | Why (and why nothing else) |
|---|---|---|
| Employee 0.3.0→0.4.0 | `loonheffingskortingToegepast` (boolean, default `true`) | The loonbelastingverklaring election — RV2026 §2.2.3: kortingen apply only "als de loonheffingskorting van toepassing is". `loonheffingenVerklaringOnFile` (exists) = the statement is archived; this = what it elected. No other Employee field is demanded by the formulas: `dateOfBirth` (AOW variant) and `taxTableColor` (wit/groen) exist. |
| PayrollRun 0.1.0→0.2.0 | `calculatedAt` (string, date-time, nullable) + `engineVersion` (string, nullable) | Traceability pair: an audited run points at the exact table file that produced it (rule `nl-engine-table-version`). Nullable — hand-entered runs (all current seeds) stay valid and outside the engine rules' scope. |
| Payslip 0.2.0→0.3.0 | `arbeidskorting` (number, nullable) | RV2026 §2.2.3.4: the ark tijdvakbedrag is a separate witte-tabel column the inhoudingsplichtige **must record in the loonstaat** — the one computed component with no home among the ~54 fields (checked field-by-field; every other output maps to an existing field, see Context). Nullable — non-engine payslips unaffected. |
| Payslip (same bump) | `payrollRunId` (string, uuid, `$ref` PayrollRun, nullable) | Not a computed component but the association spec 2 cannot function without: idempotent per-(period, administration) recalculation and `hrmq:payroll:verify --period` (audit scoped to the run's objects) both need to find exactly the payslips a run produced — period-string matching breaks with two administrations. Mirrors the existing `PensionFiling.payrollRunId` `$ref` precedent (ADR-062 rule 7: the target exists in this register set). Also unlocks the Payslip child list PayrollRunDetail's `_note` explicitly declines to fabricate today. Nullable — hand-entered payslips stay valid. |

Explicitly NOT added: per-payslip AHK amount (embedded in the combined loonheffing; no loonstaat
column mandates it), tabelloon (derivable: `grossPay` is the tijdvakloon). All new fields get full
descriptions naming their rules, matching fleet convention.

### D5 — The engine-output contract is two corpus rules; checks are spec 2 (split declared)

| id | domain | jur. | framework | severity | mc | statement (short) |
|---|---|---|---|---|---|---|
| `nl-engine-table-version` | tax | NL | payroll-core | mandatory | true | A payroll run that carries `engineVersion` (i.e. was produced by the calculation engine) shall reference an existing versioned tax-year table file (`lib/Standards/tables/<engineVersion>.json`) and carry `calculatedAt`, so every computed amount is traceable to the parameter set that produced it. |
| `nl-engine-output-consistency` | tax | NL | payroll-core | mandatory | true | On a payslip produced by the calculation engine, the net wage shall reconcile cents-exact to the declared equation: `nettoPay = grossPay − loonheffing − pensionContribution (null→0) − (zvw if zvwMode = inhouding)`; the employer-borne charges (`werknemersverzekeringen`, `zvw` under werkgeversheffing) never reduce net. |

`source`: "Rekenvoorschriften geautomatiseerde loonadministratie 2026 (traceability + gross-to-net
arithmetic)", `sourceUrl` = the RV2026 PDF, `effectiveDate: 2026-01-01`. Scope guard: both rules
are vacuous (pass) on objects without `engineVersion` / on payslips not stamped by the engine —
the engine marks its payslips (spec 2 stamps `appliedTaxRate` + `arbeidskorting`; the predicate
keys on the run's `engineVersion` via audit context) so hand-entered records never violate.
**Check provider `NlEngineChecks.php` is SPEC'D here, IMPLEMENTED in `payroll-core-engine`** —
the config head declares the contract; until spec 2 lands, `occ hrmq:rules:audit` reports the two
rules as machine-checkable-but-unenforced (the SCHEMA.md "enforced ÷ machine-checkable" metric
stays honest; the existing xc-payroll GL rules already cover the GL side and are untouched).

`RuleCatalogue::VERSION` bumps `2026-07.5` → `2026-07.6`.

### Declarative vs imperative (ADR-031)

| Behaviour | Path | Rationale |
|---|---|---|
| Tax-year parameters | versioned static JSON (`lib/Standards/tables/`) | universal facts, rule-corpus philosophy (SCHEMA.md), NOT OpenRegister |
| Schema deltas | declarative fragment edit (`hr-objects.json`) | ADR-031 default; Repair re-import picks up version bumps |
| Engine-output contract | corpus rules (data) + CheckProvider predicates (spec 2) | the app's established ADR-031 corpus exception |
| Calculation itself | **none in this change** | spec 2 (`kind: code`) — that is the point of the chain |

## Schema delta (hr-objects.json, exact)

- `Employee.properties.loonheffingskortingToegepast`: `{"type": "boolean", "default": true,
  "description": "Whether the employee elected on the loonbelastingverklaring to have the
  loonheffingskorting (heffingskortingen) applied by this employer (Rekenvoorschriften 2026
  §2.2.3; drives the AHK/ARK application in the payroll engine — payroll-core-engine). Apply with
  at most one employer at a time; loonheffingenVerklaringOnFile records that the statement itself
  is archived."}`; `version` → `0.4.0`.
- `PayrollRun.properties.calculatedAt`: `{"type": "string", "format": "date-time", "nullable":
  true, "description": "When the payroll engine (re)calculated this run's payslips and totals
  (nl-engine-table-version). Null for hand-entered runs."}`;
  `PayrollRun.properties.engineVersion`: `{"type": "string", "nullable": true, "description":
  "Versioned tax-year table file that produced this run (e.g. nl-2026 →
  lib/Standards/tables/nl-2026.json) (nl-engine-table-version). Null for hand-entered runs."}`;
  `version` → `0.2.0`.
- `Payslip.properties.arbeidskorting`: `{"type": "number", "nullable": true, "description": "NL:
  applied arbeidskorting for the period (tijdvakbedrag) — the witte-tabel column the
  Rekenvoorschriften §2.2.3.4 require the loonadministratie to record. Set by the payroll engine;
  null on hand-entered payslips (nl-engine-output-consistency context)."}`;
  `Payslip.properties.payrollRunId`: `{"type": "string", "format": "uuid", "$ref": "PayrollRun",
  "nullable": true, "description": "The PayrollRun that generated this payslip
  (payroll-core-engine). Null for hand-entered payslips. Drives idempotent recalculation and the
  run-scoped compliance verify (nl-engine-output-consistency scoping)."}`; `version` → `0.3.0`.

## Seed Data (ADR-001)

No new seed objects and no seed edits: `loonheffingskortingToegepast` defaults `true` (matches the
seeded employees' `loonheffingenVerklaringOnFile: true`), the PayrollRun/Payslip additions are
nullable, and both new rules are vacuous on non-engine records — `occ hrmq:rules:audit` against
existing seeds must stay exactly as green as before this change. (Engine-produced seed/golden data
arrives with spec 2's fixtures, where it can actually be computed.)

## Risks / Trade-offs

- **Wrong number in the corpus = wrong payroll for every consumer.** Mitigated three ways: primary
  sources only (both PDFs read, not aggregator blogs — aggregators disagreed with the
  Rekenvoorschriften on bracket 1 (35,70 vs 35,75) and the premieloon max (79.412 vs 79.409),
  which is exactly why the primary documents are normative); `verified`/`placeholder` flags in the
  data; spec 2's golden tests recompute fixtures from this file and reserve slots for the official
  Belastingdienst test cases.
- **Rules land before their checks** (chain trade-off, ADR-032): two corpus rules are unenforced
  for the life of one change. Accepted — the audit reports the gap honestly, and the alternative
  (mixed spec) is the anti-pattern.
- **Whk placeholder**: a real administration's Whk differs from 1,52. Flagged in-data and surfaced
  again in spec 2's config surface; never silently wrong.
- **File-vs-rule duplication**: Zvw 6,10/4,85 and WML 14,71 now exist in both a rule statement and
  the tables file. Deliberate — rules assert obligations, tables feed computation; the spec-2
  golden tests cross-check the two (a divergence fails the build).

## Open Questions

- None blocking.
