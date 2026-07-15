# Design — wkr-administration

## Context

**Verified against HEAD 2026-07-15.** Everything this change stands on already exists in the repo:

- **Payslip WKR fields already present** (`lib/Settings/register.d/hr-objects.json`, verified lines
  111–114): `wkrUsed` (designated allowances charged this period against the vrije ruimte),
  `wkrVrijeRuimteRemaining`, `wkrExcess`, `wkrEindheffingRate` (nullable). The seeded Payslip carries
  `wkrUsed: 0.00`, `wkrVrijeRuimteRemaining: 1200.00`, `wkrExcess: 0.00`, `wkrEindheffingRate: null`.
  This change **consumes** `wkrUsed` + `grossPay` per payslip; it does not change those fields or the
  per-payslip `NlPayrollChecks` predicates that read them.
- **Corpus WKR rules already present** (`lib/Standards/rules/payroll.json`): `nl-wkr-vrije-ruimte`
  (statement hardcodes "2,00% … up to EUR 400.000 plus 1,18% … (verify percentage)",
  `machineCheckable: true`), `nl-wkr-eindheffing-80` (80% over the excess, per-payslip predicate),
  `nl-wkr-gerichte-vrijstelling` (`machineCheckable: false`, judgemental).
- **Tables corpus mechanism** (`lib/Standards/tables/nl-2026.json` + `tables/SCHEMA.md`): parameters
  are sourced leaves `{value, source, verified}`, grouped; a new tax year is a **data-only** re-issue
  + a `RuleCatalogue::VERSION` bump. Today there is no `wkr` group — the percentage lives only in the
  rule prose, so this change moves it into a leaf.
- **CheckProvider auto-discovery** (`lib/Standards/RuleEngine.php` `providers()`): globs
  `lib/Standards/Checks/*.php`, keeps every class implementing `CheckProvider`. A new provider file is
  discovered with no engine edit. `RuleEngine::evaluate($type, $object, $context)` runs each predicate
  keyed to `$type` as `fn(array $object, array $context): bool`.
- **Cross-object context idiom** (`lib/Service/RuleAuditService.php`): `audit()` builds
  sibling-index maps (`buildPayrollContext` → `payroll.runsById`, `buildGlPostContext` → `glpost`,
  `buildRetroContext` → `retro`, …) once per run and injects them into `$context` so a per-object
  predicate can see siblings without re-querying. `auditPayrollRunScope()` builds the same maps for
  the scoped verify. This is exactly the seam a whole-administration loonsom aggregate hangs on.
- **Idempotent computed-report object precedent**: `PayrollMutationReport` (keyed `(fromRunId,
  toRunId)`, produced only by its service, upsert-in-place) is the shape `WkrAssessment` copies.
- **Reporting menu**: `src/manifest.json` `menu` has the frozen groups; `PayrollGroup`
  ("Loonadministratie") already hosts `Payslips`/`PayrollRuns`/`PayrollMutations`/… — WKR pages file
  here, adding **no** top-level menu (ADR-001).

## Goals / Non-Goals

**Goals:** a recorded ledger of WKR vergoedingen/verstrekkingen; the vrije-ruimte + eindheffing
percentages as sourced versioned table data (annual change = data-only); a deterministic,
integer-cents, cross-object roll-up of the fiscale loonsom + declarations into a per-(administration,
year) `WkrAssessment`; a machine-checkable administration-level eindheffing-exposure rule that is
RuleEngine-reachable through the existing context idiom (never an orphaned capability); an occ
command; a reporting surface under Loonadministratie.

**Non-Goals (binding, from the proposal):** automated eindheffing payment/filing (named fast-follow),
gerichte-vrijstelling normbedrag validation (stays judgemental), concernregeling pooling, rewriting
the per-payslip WKR fields/predicates.

## Decisions

### D1 — Two objects: `WkrDeclaration` (input) and `WkrAssessment` (computed + reporting record)

`WkrDeclaration` is the employer's ledger line: `administrationId`, `year`, `date`, `description`,
`amount` (euro `number`), `wkrCategory` enum, optional `employeeId` `$ref` (null = collective
allowance), `sourceReference`. The `wkrCategory` is the single most important field:

- `vrije-ruimte` — a designated allowance that **consumes** the vrije ruimte.
- `gericht-vrijgesteld` — a statutory gerichte vrijstelling that stays **outside** the vrije ruimte
  (`nl-wkr-gerichte-vrijstelling`); recorded for the audit trail, excluded from `vrijeRuimteUsed`.
- `eindheffing` — loon the employer already designated as eindheffingsloon (outside the vrije-ruimte
  budget); recorded, excluded from the vrije-ruimte used-total.

`WkrAssessment` is both the computed summary and the persisted reporting record, keyed idempotently
on `(administrationId, year)`: `fiscaleLoonsom`, `vrijeRuimte`, `vrijeRuimteUsed`,
`vrijeRuimteRemaining`, `excess`, `eindheffingRate`, `eindheffingDue`, `status`, `engineVersion`,
`assessedAt`. Modelling the assessment as a real object (not a transient calculation) is what makes
the eindheffing-exposure rule **RuleEngine-reachable**: the audit loop already loads every object of
every engine-supported type and evaluates its predicates, so a persisted `WkrAssessment` is checked
automatically — no bespoke invocation, no orphaned write.

### D2 — The vrije-ruimte + eindheffing percentages live in a `nl-2026.json` `wkr` group (data-only)

Add to `parameters`:

```jsonc
"wkr": {
  "vrijeRuimteTranche1Percent": {"value": 2.00,   "source": "Belastingdienst — Werkkostenregeling (vrije ruimte 2026)", "verified": true},
  "vrijeRuimteTranche1Grens":   {"value": 400000, "source": "Belastingdienst — Werkkostenregeling (vrije ruimte 2026)", "verified": true, "note": "Euro grens of the first tranche of the fiscale loonsom."},
  "vrijeRuimteTranche2Percent": {"value": 1.18,   "source": "Belastingdienst — Werkkostenregeling (vrije ruimte 2026)", "verified": true},
  "eindheffingPercent":         {"value": 80,     "source": "Belastingdienst — Werkkostenregeling (eindheffing)",       "verified": true}
}
```

`basedOn` gains the Belastingdienst WKR page
`https://www.belastingdienst.nl/wps/wcm/connect/nl/personeel-en-loon/content/werkkostenregeling`
(the 2026 figures 2,00% / €400.000 / 1,18% / 80% were read from it during this change's research —
hence `verified: true`; if a downstream reviewer cannot re-confirm the exact figure they flip the
leaf to `verified: false` with a `checkAgainst` note, never silently). A new tax year adds
`nl-2027.json` with `vrijeRuimteTranche1Percent: 2.16` and bumps `RuleCatalogue::VERSION` — **zero
PHP change**, the tables `SCHEMA.md` annual-re-issue discipline. The `nl-wkr-vrije-ruimte` rule
statement is tightened to drop the inline "(verify percentage)" and cite "the `wkr` group of the
active tax-year table" so the authoritative number is data, not prose.

### D3 — The cross-object loonsom aggregate: `RuleAuditService::buildWkrContext()`

The whole point of WKR is that the budget is a percentage of the **fiscale loonsom of the entire
administration**, and the used amount spans **every** payslip and **every** declaration — a single
`WkrAssessment` object cannot answer "is used > available?" from its own fields without trusting them.
So the check recomputes from siblings, exactly like `nl-engine-output-consistency` recomputes from
`payroll.runsById`. `buildWkrContext()` returns:

```
[administrationId][year] => {
  loonsom:            Σ Payslip.grossPay        (over that administration + year — the fiscale-loonsom proxy),
  payslipWkrUsed:     Σ Payslip.wkrUsed,
  vrijeRuimteDeclared:Σ WkrDeclaration.amount   where wkrCategory = 'vrije-ruimte',
  eindheffingDeclared:Σ WkrDeclaration.amount   where wkrCategory = 'eindheffing'
}
```

built once from `loadAll('Payslip')` + `loadAll('WkrDeclaration')` (degrading to an empty map when
either schema is absent — the `buildDocumentsContext` graceful-degradation precedent). A Payslip's
year is derived from its `period` (`YYYY-MM`/`YYYY-Pnn` → the `YYYY` prefix). It is injected as
`$context['wkr']` in BOTH `audit()` and `auditPayrollRunScope()`, next to the existing
`payroll`/`glpost`/`retro` maps. **This is the cross-object loonsom context the brief asks for**, and
because it is assembled in the audit service the predicate itself stays a pure fn(object, context).

### D4 — `NlWkrChecks`: the RuleEngine-reachable administration-level predicate

`lib/Standards/Checks/NlWkrChecks.php` implements `CheckProvider` and registers ONE predicate keyed
to `WkrAssessment` for rule `nl-wkr-eindheffing-exposure`:

- Resolve `agg = $context['wkr'][administrationId][year]`. **Vacuous** (returns `true`) when the
  aggregate is absent — an assessment for an administration/year with no payslips is not a violation.
- Recompute available vrije ruimte from the table `wkr` group:
  `available = loonsom ≤ grens ? loonsom × t1% : grens × t1% + (loonsom − grens) × t2%` (integer
  cents).
- `used = agg.payslipWkrUsed + agg.vrijeRuimteDeclared` (gericht-vrijgesteld and eindheffing
  categories are excluded by construction).
- **When `used ≤ available`**: satisfied (no exposure).
- **When `used > available`**: satisfied **only if** the assessment recorded the exposure —
  `status = 'eindheffing-verschuldigd'`, `excess` cents-equal to `used − available`, `eindheffingRate
  = 80`, and `eindheffingDue` cents-equal to `round2(excess × 80%)`. Otherwise it is a violation: the
  administration is over its vrije ruimte and the 80% eindheffing exposure was not flagged.

The provider globs the tables dir / loads `TaxTables` once (the `NlEngineChecks` construction-time
glob precedent — no per-object IO). Auto-discovered by `RuleEngine::providers()`. Because the
predicate is keyed to a real, persisted, audit-loaded object type, it runs on every
`occ hrmq:rules:audit` and every `hrmq:wkr:assess`-produced assessment — the capability has a caller
by construction (no orphaned-write defect).

### D5 — `WkrService`: idempotent per (administration, year); pure integer-cents; no payroll recompute

`WkrService::assess(string $administrationId, int $year): array` reads the SAME cross-object aggregate
(via a shared private helper the audit context also uses, or its own `loadAll` pass — same numbers),
computes `available`/`used`/`excess`/`eindheffingDue`/`status` with the D4 arithmetic, and **upserts**
the `WkrAssessment` keyed on `(administrationId, year)` through the container-resolved
`OCA\OpenRegister\Service\ObjectService` (register `hrmq`, the `PayrollRunService` idiom: probe →
create-or-update, never-throw degradation). It stamps `engineVersion` (the tables id) + `assessedAt`.
It **never recomputes payroll** — the fiscale loonsom is Σ persisted `grossPay`, so the assessment
cannot drift from the engine (the `PayrollMutationService` "reads and subtracts, never recomputes"
discipline). `--all` iterates the distinct `(administrationId, year)` pairs found across payslips.

### D6 — occ trigger + reporting surface under Loonadministratie (ADR-001, no new menu)

- `occ hrmq:wkr:assess --administration ADM --year YYYY [--all]` computes/persists and prints the
  outcome (loonsom, vrije ruimte, used, remaining, excess, eindheffing). Registered in
  `appinfo/info.xml`.
- Manifest: `WkrDeclarations` index + `WkrDeclarationDetail` (the input ledger; `allowCreate: true` —
  declarations ARE hand-entered, unlike engine-written payslips), and `WkrAssessments` index +
  `WkrAssessmentDetail` (stat KPIs: fiscale loonsom, vrije ruimte, vrije-ruimte used, eindheffing due;
  a "Beoordelen" `api-call`/`open-form` action to (re)assess). All filed under the existing
  `PayrollGroup` menu — WKR is administration/reporting inside Loonadministratie, so no new top-level
  menu group is added (ADR-001: the menu groups are frozen).

### Declarative vs imperative (ADR-031)

| Behaviour | Path | Rationale |
|---|---|---|
| Vrije-ruimte / eindheffing percentages | **static tables** (`nl-2026.json` `wkr` group) | universal annual facts — the tables corpus, data-only re-issue (SCHEMA.md) |
| Declaration records | declarative register.d schema (`WkrDeclaration`) | ADR-031 default; hand-entered ledger, standard object CRUD |
| Loonsom→vrije-ruimte roll-up | **imperative service** (`WkrService`) | a cross-object aggregation with tranche arithmetic + idempotent upsert cannot be expressed schema-declaratively (the `PayrollRunService`/`PayrollMutationService` class of exception) |
| Cross-object aggregate for the check | imperative context builder (`RuleAuditService::buildWkrContext`) | the established sibling-index idiom (`buildPayrollContext` precedent) |
| Eindheffing-exposure obligation | corpus rule + CheckProvider predicate | the app's established compliance-check exception (ADR-031); RuleEngine-reachable |
| Assessment record | declarative register.d schema (`WkrAssessment`) | ADR-001 default; same fragment as PayrollRun/Payslip/PayrollMutationReport |
| Trigger | occ command + manifest action | operator-demand; no lifecycle to hang a declarative action on (the glpost/mutation precedent) |
| Report pages | declarative manifest under PayrollGroup | ADR-031 default; ADR-001 frozen menu |

## Seed Data (ADR-001)

Two seed objects prove the happy path end-to-end without tripping the new mandatory-nothing check
(the rule is `conditional`, and the seeded case stays within the vrije ruimte, so zero violations):

- **One `WkrDeclaration`** — `{administrationId: "ADM-001", year: 2026, date: "2026-06-15",
  description: "Personeelsuitje juni", amount: 300.00, wkrCategory: "vrije-ruimte", employeeId: null,
  sourceReference: "INV-2026-0612"}` — a collective allowance consuming the vrije ruimte.
- **One `WkrAssessment`** — the computed summary for `(ADM-001, 2026)` consistent with the seeded
  Payslip (`grossPay` → `fiscaleLoonsom`, `wkrUsed 0.00`) plus the seeded declaration: `vrijeRuimte =
  fiscaleLoonsom × 2,00%`, `vrijeRuimteUsed = 300.00`, `vrijeRuimteRemaining > 0`, `excess: 0.00`,
  `eindheffingRate: null`, `eindheffingDue: 0.00`, `status: "binnen-vrije-ruimte"`, `engineVersion:
  "nl-2026"`. Satisfies `nl-wkr-eindheffing-exposure` (used ≤ available → vacuously compliant).

The dev-container gate exercises the real path instead of trusting the seed:
`occ hrmq:wkr:assess --administration ADM-001 --year 2026` recomputes the assessment from the live
payslips + declaration, then `occ hrmq:rules:audit` reports **zero** `nl-wkr-eindheffing-exposure`
violations; adding a second `vrije-ruimte` declaration large enough to breach the vrije ruimte and
re-running assess flips `status` to `eindheffing-verschuldigd` with a non-zero `eindheffingDue`, and
tampering the assessment (blanking `eindheffingDue` while over-budget) makes the audit report the
violation — proving the check is genuinely reachable and cross-object.

## Risks / Trade-offs

- **Fiscale loonsom proxy.** The true WKR loonsom is *kolom 14 loon uit tegenwoordige dienstbetrekking*
  with statutory in/exclusions, not simply Σ `grossPay`. The MVP uses Σ `grossPay` as an honest,
  auditable proxy and says so in the assessment's description/`_note`; a kolom-14-exact loonsom is a
  named fast-follow. This never over-reports eindheffing silently — the assessment shows the loonsom
  it used.
- **Annual vs cumulative timing.** The vrije ruimte is a whole-year budget settled after year-end; an
  interim assessment (mid-year) is a *projection*, not a final eindheffing. The `year` key + the
  disclaimer in the assessment `_note` make that explicit; the exposure alert is deliberately early
  (the point is to warn before year-end), not a filed figure.
- **verified flag on the percentages.** The 2026 figures were read from the Belastingdienst WKR page
  during this change (`verified: true`); should a reviewer be unable to re-confirm, the tables
  `SCHEMA.md` discipline is to flip to `verified: false` + `checkAgainst`, never to guess.

## Open Questions

- None blocking. Kolom-14-exact loonsom, concernregeling pooling and automated eindheffing filing are
  named fast-follows; the tranche math and the cross-object check land in this MVP.
