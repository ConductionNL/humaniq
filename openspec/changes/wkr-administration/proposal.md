---
kind: code+config
depends_on: []
---

# WKR Administration — track vergoedingen/verstrekkingen against the vrije ruimte and flag the 80% eindheffing

## Why

The Payslip already carries per-slip werkkostenregeling (WKR) fields — `wkrUsed`,
`wkrVrijeRuimteRemaining`, `wkrExcess`, `wkrEindheffingRate` — and the corpus already states the WKR
rules `nl-wkr-vrije-ruimte`, `nl-wkr-eindheffing-80` and `nl-wkr-gerichte-vrijstelling`
(`lib/Standards/rules/payroll.json`). But three things are missing, and together they make the WKR
capability *look* present while doing nothing at the administration level:

1. **There is no object to record a vergoeding/verstrekking.** WKR is administered from the
   employer's ledger of allowances/benefits, not from the payslip alone — a company Christmas
   hamper, a staff outing, a fixed-cost allowance are booked once for the whole administration, not
   per employee. Nothing in the register captures them, so `wkrUsed` on a Payslip is a hand-typed
   number with no auditable origin.
2. **The vrije-ruimte percentages are trapped in prose.** The `nl-wkr-vrije-ruimte` rule statement
   hardcodes "2,00% of the fiscal wage bill up to EUR 400.000 plus 1,18% of the excess *(verify
   percentage)*" — a narrative string no calculator can read, so the annual regulation change is a
   *code* change, not the data-only re-issue the tables corpus is designed for.
3. **The only WKR checks are per-payslip.** `NlPayrollChecks` decides `nl-wkr-vrije-ruimte` and
   `nl-wkr-eindheffing-80` from one Payslip's own fields — but the vrije ruimte is a *whole-
   administration, whole-year* budget: used = Σ designated allowances across every payslip **and**
   every stand-alone declaration, available = a percentage of the Σ fiscal loonsom across every
   payslip. No object and no check computes that cross-object aggregate, so an administration can
   silently blow past its vrije ruimte and never see the 80% eindheffing exposure.

This change closes all three: a `WkrDeclaration` input object, the vrije-ruimte/eindheffing
percentages as sourced versioned table data (annual change = data-only), a `WkrService` that rolls
the cross-object loonsom + declarations into an idempotent `WkrAssessment` per (administration,
year), a machine-checkable administration-level rule enforced through the existing
`RuleAuditService` cross-object context idiom (so it is RuleEngine-reachable, never an orphaned
capability), an occ command, and a reporting surface under the existing Loonadministratie menu.

## What Changes

- **NEW `WkrDeclaration` object** (`lib/Settings/register.d/hr-objects.json`) — one recorded
  vergoeding/verstrekking/terbeschikkingstelling: `administrationId`, `year`, `date`, `description`,
  `amount` (euro), a `wkrCategory` enum (`gericht-vrijgesteld` | `vrije-ruimte` | `eindheffing`),
  optional `employeeId` `$ref` (null for a collective allowance), and a `sourceReference`
  (invoice/journal id). Only `vrije-ruimte`-category amounts consume the vrije ruimte;
  `gericht-vrijgesteld` are the statutory gerichte vrijstellingen that stay outside it
  (`nl-wkr-gerichte-vrijstelling`); `eindheffing` amounts are those the employer already designated
  as eindheffingsloon.
- **NEW `WkrAssessment` object** (same fragment) — the per-(administrationId, year) computed
  summary AND the reporting record: `fiscaleLoonsom`, `vrijeRuimte` (available), `vrijeRuimteUsed`,
  `vrijeRuimteRemaining`, `excess`, `eindheffingRate`, `eindheffingDue`, `status`
  (`binnen-vrije-ruimte` | `eindheffing-verschuldigd`), `engineVersion` (the tables id it was
  computed against), `assessedAt`. Idempotently keyed on (administrationId, year): recomputing
  upserts in place, never duplicates (the `PayrollMutationReport` precedent).
- **NEW `wkr` parameter group in `lib/Standards/tables/nl-2026.json`** —
  `vrijeRuimteTranche1Percent` (2,00), `vrijeRuimteTranche1Grens` (400000 euro),
  `vrijeRuimteTranche2Percent` (1,18), `eindheffingPercent` (80), each a sourced leaf citing the
  Belastingdienst WKR page. This is the annual knob: a new tax year drops the year's figures in a
  new table file and bumps `RuleCatalogue::VERSION` — **no PHP change** (tables `SCHEMA.md` annual
  re-issue discipline).
- **NEW rule `nl-wkr-eindheffing-exposure`** (`lib/Standards/rules/payroll.json`, machineCheckable,
  conditional) — the administration-level obligation: where the designated allowances charged to the
  vrije ruimte across the administration's payslips and declarations for a year exceed the available
  vrije ruimte, the employer owes an 80% eindheffing over the excess, and the assessment must record
  that exposure. The existing `nl-wkr-vrije-ruimte` statement is tightened to drop the "(verify
  percentage)" and point at the `nl-2026.json` `wkr` group as the authoritative figure (the value
  moves from prose to sourced data; the statement stays narrative).
- **NEW `lib/Standards/Checks/NlWkrChecks.php`** — a `CheckProvider` registering the
  `nl-wkr-eindheffing-exposure` predicate keyed to `WkrAssessment`. It is a pure
  `fn(array $assessment, array $context): bool`: it reads the cross-object aggregate from
  `$context['wkr'][administrationId][year]` (Σ payslip `grossPay` = fiscale loonsom, Σ payslip
  `wkrUsed` + Σ `vrije-ruimte` `WkrDeclaration.amount` = used), recomputes available from the table
  percentages, and asserts the assessment recorded the excess and the 80% eindheffing when used
  exceeds available. Auto-discovered by `RuleEngine::providers()` — no engine edit.
- **NEW cross-object context `RuleAuditService::buildWkrContext()`** — the loonsom aggregate. Keyed
  `[administrationId][year] => {loonsom, payslipWkrUsed, vrijeRuimteDeclared, eindheffingDeclared}`,
  built once per audit from `loadAll('Payslip')` + `loadAll('WkrDeclaration')` (the
  `buildPayrollContext`/`buildGlPostContext` precedent), and injected as `$context['wkr']` in both
  `audit()` and `auditPayrollRunScope()`. This is the "loonsom across payslips" the check needs;
  the predicate stays a pure function of `$context`.
- **NEW `lib/Service/WkrService.php`** — `assess(administrationId, year)`: reads the same
  cross-object aggregate, computes vrije ruimte from `TaxTables` (the `wkr` group), used, excess,
  `eindheffingDue = round2(excess × 80%)`, `status`, and upserts the `WkrAssessment` via
  ObjectService (container-resolved, register `hrmq` — the `PayrollRunService` idiom). Idempotent
  per (administrationId, year); pure integer-cents arithmetic; never-throw degradation.
- **NEW occ command `hrmq:wkr:assess --administration ADM --year YYYY [--all]`**
  (`lib/Command/WkrAssessCommand.php`, registered in `appinfo/info.xml`) — computes/persists the
  assessment(s) and prints the vrije-ruimte-used/available/excess/eindheffing outcome.
- **Manifest** (`src/manifest.json`) — a `WkrDeclarations` index + `WkrDeclarationDetail`, and a
  `WkrAssessments` index + `WkrAssessmentDetail` (stat KPIs: fiscale loonsom, vrije ruimte, used,
  eindheffing due) with an "Beoordelen" `api-call`/`open-form` action, all filed under the **existing
  `PayrollGroup` (Loonadministratie)** menu — no new top-level menu (ADR-001: the 9-10 menu groups
  are frozen; WKR is a reporting/administration surface inside Loonadministratie).

### Non-goals (named fast-follows and exclusions)

- **Automated eindheffing payment or filing** — the assessment computes and *flags* the 80%
  eindheffing exposure and the `eindheffingDue` amount, but actually paying/declaring it in the
  loonaangifte (the WKR eindheffing is settled in the first aangifte of the following year) is a
  **named fast-follow**, owned by a future `wkr-eindheffing-filing` change. This MVP is
  administration + reporting + exposure alert only.
- **Gerichte-vrijstelling normbedrag validation** — whether a `gericht-vrijgesteld` declaration
  actually meets its statutory conditions/normbedrag (travel €0,23/km, home-working allowance, etc.)
  stays `nl-wkr-gerichte-vrijstelling` (`machineCheckable: false`, judgemental); this change trusts
  the declared category and only excludes it from the vrije ruimte.
- **Concernregeling (group-wide vrije ruimte pooling)** — the assessment is per single
  administration; pooling multiple administrations' vrije ruimte is out of scope.
- **Payslip-level `wkrVrijeRuimteRemaining` back-fill** — the per-payslip fields keep their existing
  `NlPayrollChecks` predicates unchanged; this change adds the administration-level layer above them,
  it does not rewrite the payslip fields.

## Capabilities

### New Capabilities

- `wkr-administration`: the `WkrDeclaration` input object, the sourced vrije-ruimte/eindheffing
  table parameters, the `WkrService` cross-object loonsom→vrije-ruimte roll-up into an idempotent
  `WkrAssessment`, the RuleEngine-reachable administration-level `nl-wkr-eindheffing-exposure` check
  (via the `RuleAuditService` cross-object context), the occ assess command, and the reporting
  surface under Loonadministratie.

## Modified Capabilities

<!-- none — the per-payslip WKR predicates in NlPayrollChecks are untouched; this change adds the administration-level layer above them -->

## Impact

- `lib/Settings/register.d/hr-objects.json` — NEW `WkrDeclaration` + `WkrAssessment` schemas; the
  register + manifest gates pass.
- `lib/Standards/tables/nl-2026.json` — NEW `wkr` parameter group (sourced leaves).
- `lib/Standards/rules/payroll.json` — NEW `nl-wkr-eindheffing-exposure` rule; `nl-wkr-vrije-ruimte`
  statement tightened to reference the table group. `lib/Standards/RuleCatalogue.php` — `VERSION`
  bump (table + rule change).
- `lib/Standards/Checks/NlWkrChecks.php` — NEW `CheckProvider` (auto-discovered).
- `lib/Service/RuleAuditService.php` — NEW `buildWkrContext()` + `$context['wkr']` enrichment in
  `audit()` and `auditPayrollRunScope()`.
- `lib/Service/WkrService.php` — NEW (ObjectService idiom per `PayrollRunService`); `lib/Payroll/`
  is untouched (the assessment reads persisted `grossPay`, it does not recompute payroll).
- `lib/Command/WkrAssessCommand.php` — NEW; `appinfo/info.xml` +1 `<command>`.
- `src/manifest.json` — `WkrDeclarations`/`WkrDeclarationDetail`/`WkrAssessments`/
  `WkrAssessmentDetail` pages under `PayrollGroup`; `npm run check:manifest` passes.
- `tests/Unit/Service/WkrServiceTest.php`, `tests/Unit/Standards/NlWkrChecksTest.php` — NEW (mocked
  ObjectService; the tranche math, the used>available exposure path, and the vacuous scopes).
- No `depends_on`: every dependency (Payslip `grossPay`/`wkrUsed`, `administrationId`, the tables
  file, the RuleEngine/RuleAuditService context idiom) already exists at HEAD.
