# Design — functiehuis-hr21

## Context

**Verified against HEAD 2026-07-18.** Three shipped/in-flight mechanisms this change reuses without
modification:

- **`cao-library`** (archived 2026-07-14) — `CaoRegistry`, the `{value, source, verified}` leaf
  discipline, and `EmploymentContract.cao`/`caoSchaal`.
- **`cao-sector-datasets`** (active, not yet merged) — adds `cao-gemeenten` with numeric schaal keys
  `"1"`–`"19"` (its own design.md D1 table: "VNG/HR21 numeric schalen 1–19"), every `payScales` leaf
  `placeholder: true` (its own honesty note: "the exact per-schaal-per-periodiek table was not
  fetched from a primary source"). `functiehuis-hr21` maps onto this SAME key space — it does not
  invent its own schaal vocabulary.
- **`comp-cycles`** (archived 2026-07-15) — `SalaryBand` (an employer's own internal min/reference/max
  structure) and `CompAdjustment` (the effective-dated compensation-change lifecycle). Neither is
  touched: HR21 classification maps a normfunctie to a CAO schaal, which is a different concern from
  an employer's internal band or a proposed adjustment.

The old draft's premise — "implement the complete HR21 job function library... forming the basis for
salary scale determination" — conflates two things that are architecturally separate in hrmq: the
CAO's OWN schaal-to-bedrag table (already `cao-library`'s job) and a *classification* mapping
(normfunctie → schaal) that decides which schaal applies to a given employee's actual work. This
change builds only the second, narrower thing.

## Goals / Non-Goals

**Goals:** a small, honestly-scoped `Normfunctie` reference library mapping standard municipal job
functions to a Cao Gemeenten schaal; a link from a contract to its assigned normfunctie; a
machine-checkable consistency rule catching a contract whose recorded `caoSchaal` disagrees with its
assigned normfunctie's designated schaal.

**Non-Goals (from the proposal, binding):** a claimed-complete ~150-function library, the
functietoekenning approval workflow, maatwerkfunctie business-case governance, the formal Awb
bezwaarrecht procedure, decision-letter generation, OR instemmingsrecht notification, loopbaanpaden,
and automatic salary-consequence calculation on reclassification.

## Decisions

### D1 — `Normfunctie` is seeded reference data, not versioned corpus code

Unlike CAO figures (universal facts, identical for every tenant, shipped as `lib/Standards/cao/*.json`
code), which normfuncties a given municipality actually uses — and, honestly, the exact
normfunctie-to-schaal mapping this change seeds — is closer to the `Cao` *display* projection
(`cao-library` D6/D7): register-backed, seeded, `allowCreate: false` reference data rather than
compiled-in corpus code. This also keeps the mapping's own `verified`/`placeholder` provenance
visible and editable at the register level, the same way a `Cao` display object can be corrected
without a code deploy once a maintainer confirms a figure.

### D2 — The link lives on EmploymentContract, mirroring `cao`/`caoSchaal` exactly

`normfunctieId` sits beside `cao`/`caoSchaal` on `EmploymentContract`, not on `Employee`: a function
assignment is a property of a specific contract term (it can change on reclassification or a new
contract), the same granularity `cao`/`caoSchaal` already use. No new schema pattern is introduced —
this is the identical shape `cao-library` D3 established for the CAO link itself.

### D3 — The consistency check is a mapping check, never a computation

`nl-hr21-schaal-consistentie` compares two already-recorded strings (`EmploymentContract.caoSchaal`
vs. the assigned `Normfunctie.caoSchaal`) — it computes no salary, proposes no adjustment, and
triggers no workflow. When they disagree, that is a signal for HR to investigate (possibly via a
`comp-cycles` `CompAdjustment` if a reclassification is warranted) — not an automatic consequence
this change applies. This is the literal reading of the task's instruction: "spec the mapping model
and a consistency check. Do NOT rebuild salary bands."

### D4 — Placeholder provenance on the mapping gates the check exactly like `cao-library` D5 gates the rate

Because this change depends on `cao-sector-datasets`' placeholder-marked `cao-gemeenten` schalen, and
because no primary HR21 normfunctie-to-schaal source was fetched in this research pass either, every
seeded `Normfunctie.caoSchaal` carries its own `verified: false` / `placeholder: true` / `source`
fields (expressed as leaf-shaped properties on the register object, not a corpus file — D1). The
predicate treats a placeholder mapping as a vacuous pass, so an unconfirmed classification never
raises a false mandatory violation — the identical discipline `cao-library`'s `nl-cao-minimumloon-schaal`
already applies to unverified pay scales, applied here to an unverified function-to-schaal mapping
instead of an unverified rate.

### D5 — Manifest placement follows the old draft's own stated intent AND the shipped precedent

The old draft itself named its placement "SUB_PAGE beneath Medewerkers › Functiehuis" — already
correctly reading ADR-001 Rule 1 (CAOs/regelingen are rulesets, not menus) even in May 2026.
`Normfuncties`/`NormfunctieDetail` land as a sibling of the shipped `Caos`/`SalaryBands` sub-pages in
the existing `Personeel` menu — no new top-level menu, no ADR amendment needed.

### Declarative vs imperative (ADR-031)

| Behaviour | Path | Rationale |
|---|---|---|
| `Normfunctie` reference rows (functiecode/naam/functiegroep/caoSchaal + provenance) | **register.d**, seeded | per-tenant-visible reference data with its own provenance, the `Cao` display-object precedent (D1) |
| `EmploymentContract.normfunctieId` | **register.d** | per-contract HR-set link, the `cao`/`caoSchaal` precedent (D2) |
| `nl-hr21-schaal-consistentie` rule statement | **corpus data** `lib/Standards/rules/payroll.json` | a universal compliance concern (schaal must match assigned function), identical shape for every tenant |
| The predicate | imperative `NlHr21Checks` | a simple two-field string comparison after resolving one reference — no cross-object context beyond the referenced `Normfunctie` |
| `Normfuncties` / `NormfunctieDetail` pages | declarative manifest | ADR-031 default; register-backed index/detail, `allowCreate:false` (D1) |

## Seed Data (ADR-001)

- 4–6 `Normfunctie` rows spanning 2–3 HR21 hoofdprocessen (e.g. "Beleid", "Beheer", "Management"),
  each with a `caoSchaal` value in `cao-gemeenten`'s `"1"`–`"19"` key space, every mapping
  `verified: false` / `placeholder: true` / `source` naming HR21/VNG as the authority to confirm
  against — an illustrative subset, explicitly not a claim of completeness.
- One `EmploymentContract` with `normfunctieId` set and `caoSchaal` matching the normfunctie's mapped
  schaal — clean pass.
- One `EmploymentContract` with `normfunctieId` set and a DIFFERENT `caoSchaal` than the normfunctie's
  mapped schaal — the violation branch.
- Every pre-existing seeded `EmploymentContract` keeps `normfunctieId` unset (null) — the rule must
  report zero violations against the entire pre-existing population.

## Risks / Trade-offs

- **Landing-order dependency on `cao-sector-datasets`.** If this change lands first, `cao-gemeenten`
  does not exist yet; `nl-hr21-schaal-consistentie` still functions (it compares two strings, neither
  of which requires `CaoRegistry` to resolve), but the seeded `Normfunctie.caoSchaal` values would
  reference a CAO the corpus does not yet carry. Documented as an accepted landing-order consequence,
  not a blocker — the check itself has no hard runtime dependency on `cao-sector-datasets`, only the
  seed data's intended meaning does.
- **Placeholder mappings enforce nothing.** By design (D4) — the same accepted trade-off
  `cao-library` already made for unverified pay scales, applied one layer up.
- **Only an illustrative subset is seeded, never presented as complete.** By design (Non-Goals) —
  growing the library is a data-only extension (the `cao-sector-datasets` precedent), never a code
  change.

## Open Questions

- None blocking. A complete normfunctie import, the approval workflow, maatwerkfunctie governance,
  the Awb bezwaarrecht procedure, and salary-consequence automation are named fast-follows, all
  blocked on capabilities (case-management, multi-step approval, cross-app objection/document
  workflow) that do not exist in hrmq today.
