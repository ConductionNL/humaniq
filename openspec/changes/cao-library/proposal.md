---
kind: config+code
---

# CAO ruleset library — maintained, versioned collective-labour-agreement corpus wired into the payroll engine

## Why

A CAO (collectieve arbeidsovereenkomst) is the sector-specific ruleset that overrides the statutory
floor: it defines minimum pay scales (schaal → bedrag), standard allowances (e.g. ploegentoeslag),
leave entitlement (vakantiedagen incl. bovenwettelijk) and working-time norms. A maintained CAO
library is table-stakes in every NL payroll competitor — **AFAS ships 200+ CAOs, Employes 35+,
Nmbrs and Loket.nl each ship a maintained CAO catalogue** — and the 2026-07-12/13 market
deep-research (logged in Spectr, insights `hrmq-insight-ranked-buildlist` / `hrmq-insight-afas-baseline`)
ranked "no CAO support" as a headline gap: an HR/payroll product without CAOs cannot answer the
first question a Dutch employer asks — "does this contract meet our CAO?".

This was **BLOCKED on the payroll engine**. A CAO without an engine to enforce it against is dead
config — a table nobody reads. The engine now exists: `lib/Payroll/PayrollCalculator.php` (the 2026
gross-to-net engine, `payroll-core-engine`, PR #46) computes real money against the versioned
`lib/Standards/tables/nl-2026.json` corpus, and `RuleAuditService` + the `Nl*Checks` providers audit
every payroll object against machine-checkable corpus rules. The CAO library is the delivery vehicle
that turns that engine into a *compliance* engine: the versioned CAO rule/table corpus gives the
audit something sector-specific to check a contract against.

This change models CAOs the way `payroll-core-engine` modelled tax-year parameters — as a versioned,
source-cited corpus in code (`{value, source, verified}` leaves, exactly like `nl-2026.json`) — adds
a `CaoRegistry` loader (mirroring `RuleCatalogue` / `TaxTables`), links a contract to its CAO, and
wires the selected CAO into the audit as two machine-checkable **checks**: salary below the CAO
minimum scale is a violation, and leave entitlement below the CAO minimum is a violation. The corpus
is the engine's own reference data; the audit is where the CAO earns its keep.

## What Changes

- **NEW `lib/Standards/cao/{cao-id}.json`** — the CAO corpus, one versioned file per CAO (loaded and
  merged like the `rules/*.json` corpus), every leaf sourced `{value, source, verified}` with
  `placeholder: true` + `checkAgainst` on figures not yet confirmed against the official CAO-tekst.
  Each CAO carries `id`, `name`, `sector`, `version`, `effectiveDate`, `payScales` (schaal → minimum
  maandloon), `allowances` (e.g. ploegentoeslag), `leaveEntitlement` (statutory + bovenwettelijk
  vakantiedagen) and `workingTime` norms.
- **NEW `lib/Standards/CaoRegistry.php`** — read-only loader mirroring `RuleCatalogue` / `TaxTables`:
  a `VERSION` const (bumped on any corpus change), globs + merges the `cao/*.json` files ONCE
  (memoised — no per-object IO), validates the leaf shape, and exposes `availableCaos()`,
  `get(string $caoId)`, `minMaandloonCents(caoId, schaal)` and `minLeaveHours(caoId, contractHoursPerWeek)`
  resolvers the checks consume.
- **Corpus rules** — `lib/Standards/rules/payroll.json` gains `nl-cao-minimumloon-schaal`
  (EmploymentContract, mandatory, machineCheckable); `lib/Standards/rules/labour.json` gains
  `nl-cao-verlof-minimum` (LeaveBalance, mandatory, machineCheckable). Bump `RuleCatalogue::VERSION`.
- **NEW `lib/Standards/Checks/NlCaoChecks.php`** — auto-discovered `CheckProvider` registering both
  predicates against `CaoRegistry`, plus `SeedsObjects` seeding the read-only `Cao` display objects
  idempotently from the corpus (keyed on cao id). Because `RuleEngine` attaches **one static severity
  per rule id**, the predicate — not the severity — carries the nuance: a scale/leave minimum whose
  source leaf is `verified: false` / `placeholder: true` is treated as advisory (vacuous pass), so an
  unconfirmed placeholder figure never raises a false mandatory violation.
- **`lib/Service/RuleAuditService.php`** — context enrichment (the glpost/engine `runsById`
  precedent): `cao.caosById`, `cao.employeesById` (salary resolution for the pay-scale check) and
  `cao.caoByEmployeeId` (each employee's active-contract CAO, for the leave check).
- **register.d** — NEW `lib/Settings/register.d/hr-cao.json` with a read-only `Cao` schema (the
  reference-page display surface, seeded from the corpus). `EmploymentContract` (in `hr-objects.json`)
  gains `caoSchaal` and its existing free-text `cao` field is redefined to reference a CAO `id` in the
  library.
- **NEW seed data** — 2–3 real NL sector CAOs as MVP: `cao-generiek` (the statutory-floor baseline),
  `cao-metaal-techniek` (Metaal & Techniek) and `cao-horeca` (Horeca NL), with `placeholder`-marked
  figures where the exact schaalbedragen are not yet verified against the CAO-tekst.
- **Manifest** — `src/manifest.json` gains a read-only `Caos` index page + `CaoDetail` detail page
  (available-CAO reference, `allowCreate: false`) and a menu entry; the selected CAO (`cao` +
  `caoSchaal`) renders on `EmploymentContractDetail` (already inside the `ct-data` widget's field set,
  which excludes only `employeeId`). `npm run check:manifest` passes.

### Non-goals (named fast-follows and exclusions)

- **CAO-driven computation** (ploegentoeslag/overwerk actually *added* to gross by the calculator,
  bijzonder-tarief vakantiegeld payout, CAO pension premie) — the MVP **audits** the CAO (below-min
  checks); it does not yet compute CAO allowances into net pay. The calculator's table-driven shape is
  the extension point.
- **Per-trede (step) progression, age tables, part-time proration nuance** — pay scales are modelled
  as a per-schaal full-time monthly minimum; the check notes proration as a documented limitation.
- **A CAO import/authoring UI** — the corpus is maintained in code (like the rules/tables corpus);
  the manifest page is read-only reference.
- **The full 200+ AFAS catalogue** — three seed CAOs establish the mechanism; the corpus is
  data-extensible without code changes.

## Capabilities

### New Capabilities

- `cao-library`: the versioned CAO corpus + `CaoRegistry` loader, the contract→CAO reference, the two
  machine-checkable below-CAO-minimum audit checks (salary and leave) with placeholder-aware
  predicates, the idempotent seed of the read-only `Cao` display objects, and the read-only CAO
  reference page.

### Modified Capabilities

<!-- none — payroll-core-engine and the labour/payroll corpora are consumed and extended, not modified -->

## Impact

- `lib/Standards/cao/cao-generiek.json`, `cao-metaal-techniek.json`, `cao-horeca.json` — NEW corpus.
- `lib/Standards/CaoRegistry.php` — NEW (pure PHP, no NC deps → unit-testable without stubs).
- `lib/Standards/Checks/NlCaoChecks.php` — NEW (`CheckProvider` + `SeedsObjects`).
- `lib/Standards/rules/payroll.json`, `lib/Standards/rules/labour.json` — +1 rule each;
  `lib/Standards/RuleCatalogue.php` — `VERSION` bump.
- `lib/Service/RuleAuditService.php` — `cao.*` context enrichment.
- `lib/Settings/register.d/hr-cao.json` — NEW (`Cao` schema); `lib/Settings/register.d/hr-objects.json`
  — `EmploymentContract` `cao` redefinition + `caoSchaal`.
- `src/manifest.json` — `Caos` + `CaoDetail` pages, menu entry, `EmploymentContractDetail` `_note`;
  `npm run check:manifest` passes.
- `tests/Unit/Standards/CaoRegistryTest.php`, `tests/Unit/Standards/NlCaoChecksTest.php` — NEW.
- Depends on `payroll-core-engine` (merged, PR #46): the `RuleAuditService` context mechanism, the
  `CheckProvider`/`SeedsObjects` auto-discovery, and the corpus-as-code convention this change reuses.
