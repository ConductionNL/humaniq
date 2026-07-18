---
kind: config+code
depends_on: [cao-sector-datasets]
---

# Functiehuis HR21 — a normfunctie→schaal reference library and a contract-consistency check

## Why

HR21 is the VNG-owned functiewaarderingssysteem for the Dutch municipal sector: the Cao Gemeenten and
Cao SGO both name it as the applicable job-evaluation system unless an employer has chosen another
one recognized by the unions, and roughly 80% of Dutch municipalities hold an HR21 license (VNG,
"15 jaar HR21"). It organizes municipal work into a library of standard job functions
("normfuncties"), grouped by hoofdproces (management, beleid, beheer, etc.), each of which maps to a
salary scale ("schaal") under Cao Gemeenten.

**Check the actual salary machinery first, as instructed.** hrmq already ships everything HR21's
compensation side needs: `cao-library` + the active sibling `cao-sector-datasets` add `cao-gemeenten`
with numeric schaal keys `"1"`–`"19"` (VNG/HR21's own convention, `payScales`), `comp-cycles` ships
`SalaryBand` for an employer's internal min/reference/max structure, and `EmploymentContract` already
carries `cao` + `caoSchaal`. **HR21 does not need a second salary-band mechanism.** What it adds is
one thing none of the above provides: a library that says *which* schaal a given standard function
maps to, so a contract's `caoSchaal` can be checked for consistency against the function the employee
actually performs. That is the entire genuine delta.

The old draft branch `spec/functiehuis-hr21` (May 2026) proposed a full function-classification
case-management system: a ~150-normfunctie import/search library, a formal
HR-advisor→manager→employee approval chain per (re)classification, automatic salary-consequence
calculation on reclassification, a maatwerkfunctie (custom function) business-case workflow with
governance dashboards, a formal Awb bezwaarrecht (objection) procedure integrated with `decidesk`,
auto-generated decision letters via `docudesk`, and OR (ondernemingsraad) instemmingsrecht
notifications. None of that exists in hrmq today, and hrmq has no case-management, multi-step
approval-chain, or cross-app objection-procedure capability to build it on — inventing one here would
be exactly the padding this task's honesty bar forbids. This change builds only the reference library
and the mapping consistency check; see design.md "Named gaps" for the explicit disposition of every
old-draft feature.

**Honesty about verification.** HR21's exact normfunctie count is reported informally as roughly 150
by the old draft and could not be independently confirmed from a primary VNG source in this pass;
this change does not assert that count as fact and seeds only a small illustrative subset (not a
claimed-complete library). Because it depends on `cao-sector-datasets`' `cao-gemeenten` schalen,
which that change's own honesty note records as **placeholder** (the per-schaal-per-periodiek table
was not fetched from a primary source), every seeded `Normfunctie.caoSchaal` in this change is
correspondingly `verified: false` / `placeholder: true` — an unconfirmed mapping must never silently
pass as fact, the `cao-library` D5 precedent.

## What Changes

- **NEW `Normfunctie` reference schema** (`lib/Settings/register.d/hr-hr21.json`, new `hr-hr21`
  fragment, `allowCreate: false`) — `functiecode`, `naam`, `functiegroep` (the HR21 hoofdproces),
  `caoSchaal` (string, the Cao Gemeenten schaal this normfunctie maps to — the same key space
  `cao-gemeenten.payScales` uses), `verified`/`placeholder`/`source` leaf fields on the mapping
  itself (mirroring the corpus provenance discipline, expressed on register objects since this is
  reference DATA, seeded like `Cao`, not versioned code).
- **`EmploymentContract` schema** (`lib/Settings/register.d/hr-objects.json`) gains `normfunctieId`
  (nullable, `$ref: Normfunctie`) — the assigned normfunctie for this contract, HR-set.
- **NEW corpus rule `nl-hr21-schaal-consistentie`** (`lib/Standards/rules/payroll.json`, framework
  `hr21`, `EmploymentContract`, `mandatory`, `machineCheckable: true`) — a contract whose
  `normfunctieId` resolves to a `Normfunctie` with a verified (non-placeholder) `caoSchaal` violates
  when its own `caoSchaal` does not match. Vacuous when `normfunctieId` is null, unresolvable, or the
  normfunctie's mapping is itself unverified/placeholder (the `cao-library` D5 precedent, applied to
  a mapping's provenance instead of a rate's).
- **NEW `lib/Standards/Checks/NlHr21Checks.php`** (auto-discovered `CheckProvider`) registering the
  predicate and a `SeedsObjects` seed of the `Normfunctie` reference objects.
- **Manifest**: a read-only `Normfuncties` index + `NormfunctieDetail` detail page (`allowCreate:
  false`, the `Caos` precedent), landing as a sibling sub-page of `CAO's` in the existing `Personeel`
  menu — matching the old draft's OWN placement intent ("SUB_PAGE beneath Medewerkers ›
  Functiehuis") and ADR-001 Rule 1's "CAOs/regelingen are rulesets, not menus" spirit, following the
  shipped `Caos`/`SalaryBands` precedent rather than inventing a new menu. `EmploymentContractDetail`
  surfaces the assigned normfunctie.
- **Seed data**: a small illustrative subset of `Normfunctie` rows (not a claimed-complete ~150
  library) spanning 2–3 hoofdprocessen, one contract seeded with a matching `caoSchaal` (clean pass)
  and one with a mismatched `caoSchaal` (the violation branch).

### Non-goals (named fast-follows and exclusions)

- **A complete ~150-normfunctie imported library** — the count is unverified (see Why); this change
  seeds a small illustrative subset and documents the library as data-extensible without code changes
  (the `cao-sector-datasets` "adding a CAO is data-only" precedent, applied to normfuncties).
- **Functietoekenning approval workflow** (HR advisor → manager → employee chain), **maatwerkfunctie
  business-case workflow with governance dashboard**, **formal Awb bezwaarrecht procedure**,
  **auto-generated decision letters**, **OR instemmingsrecht notifications**, **loopbaanpaden /
  career-path insights** — all require case-management, multi-step approval, or cross-app
  document/objection-procedure machinery hrmq does not have. `normfunctieId` is HR-set directly on
  the contract, the same way `cao`/`caoSchaal` already are.
- **Automatic salary-consequence calculation on reclassification** — out of scope; `comp-cycles`
  already owns the compensation-adjustment lifecycle (`CompAdjustment`, band-checked, effective-dated)
  for exactly this kind of change. A future fast-follow could pre-fill a `CompAdjustment` proposal
  from a normfunctie re-mapping; this change does not build that integration.
- **Rebuilding salary-band machinery** — this change introduces no new band/scale storage; it maps a
  normfunctie to the EXISTING `cao-gemeenten` schaal key space and checks contracts against it.

## Capabilities

### New Capabilities

- `functiehuis-hr21`: the `Normfunctie` reference library, the `EmploymentContract.normfunctieId`
  link, and the `nl-hr21-schaal-consistentie` mapping-consistency check.

### Modified Capabilities

<!-- none — this change reads cao-gemeenten's schaal key space but does not modify cao-library,
     cao-sector-datasets, or comp-cycles -->

## Impact

- `lib/Settings/register.d/hr-hr21.json` — NEW fragment, `Normfunctie` schema.
- `lib/Settings/register.d/hr-objects.json` — `EmploymentContract` +1 field
  (`normfunctieId`).
- `lib/Standards/rules/payroll.json` — +1 rule (new `hr21` framework, added to `SCHEMA.md`);
  `lib/Standards/RuleCatalogue.php` — `VERSION` bump.
- `lib/Standards/Checks/NlHr21Checks.php` — NEW (`CheckProvider` + `SeedsObjects`).
- `src/manifest.json` — `Normfuncties` + `NormfunctieDetail` pages, `Personeel` menu entry,
  `EmploymentContractDetail` note update.
- `lib/Settings/register.d/hr-seed.json` — `Normfunctie` seeds + 2 `EmploymentContract` edits.
- `tests/Unit/Standards/NlHr21ChecksTest.php` — NEW.
- Depends on `cao-sector-datasets` (active, not yet merged) for `cao-gemeenten`'s schaal key space —
  see design.md Risks for the landing-order contingency.

## Sources

- VNG, "15 jaar HR21: vaste basis voor functiewaardering", <https://vng.nl/nieuws/15-jaar-hr21-vaste-basis-voor-functiewaardering> —
  HR21 adoption (≈80% of gemeenten), CAO Gemeenten/SGO naming HR21 as the applicable system.
  **Verified.**
- Leeuwendaal, "HR21, het functiewaarderingssysteem voor de sector Gemeenten",
  <https://www.leeuwendaal.nl/diensten/hr21/> — HR21 structure (hoofdprocessen, normfuncties).
  **Verified**, general description only — no specific normfunctie/schaal mapping asserted as fact.
- Exact normfunctie count (~150, per the old draft) — **not independently verified** in this pass;
  not asserted as fact anywhere in this change's seed data or requirements.
