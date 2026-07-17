---
kind: config
---

# CAO sector datasets — six Dutch sector CAOs added to the shipped cao-library corpus

## Why

`cao-library` (archived 2026-07-14, PR merged) shipped the mechanism: a versioned
`{value, source, verified}` CAO corpus under `lib/Standards/cao/`, the `CaoRegistry` loader, the
contract→CAO reference (`EmploymentContract.cao` + `caoSchaal`), the two below-CAO-minimum audit
checks (`nl-cao-minimumloon-schaal`, `nl-cao-verlof-minimum`), and the read-only `Caos`/`CaoDetail`
reference pages. It shipped with three MVP CAOs — `cao-generiek` (the WML-derived statutory floor,
`verified: true`) plus `cao-metaal-techniek` and `cao-horeca` (concrete sectors, `placeholder`-marked
pending confirmation against the official CAO-tekst). `cao/SCHEMA.md` already documents that "adding
a new CAO is also data-only: drop a new `{cao-id}.json` file ... and bump `CaoRegistry::VERSION`. The
CAO immediately appears in `CaoRegistry::availableCaos()` and, once re-seeded, on the read-only `Caos`
reference page — no code change required."

This change exercises exactly that data-only extension path for six of the largest Dutch public and
semi-public sector CAOs, closing the gap between the two illustrative MVP sectors and the CAOs that
cover the majority of Dutch salaried employment outside the private sector: Rijk (central government,
~130k medewerkers), Gemeenten (municipalities, ~180k), Onderwijs PO and VO (primary + secondary
education, ~300k combined), Ziekenhuizen (hospitals) and Zorg VVT (elderly/home care) — together the
core of the Nederlandse (semi-)publieke sector that AFAS, Employes, Nmbrs and Loket.nl already cover
in their CAO catalogues.

Four older draft OpenSpec changes (`spec/cao-rijk`, `spec/cao-gemeenten`, `spec/cao-onderwijs-po`,
`spec/cao-onderwijs-vo`, `spec/cao-ziekenhuizen`, `spec/cao-zorg-vvt`, all May-2026, obsolete flat
`specs.md` format, predating `payroll-core-engine` and `cao-library` entirely) each imagined a
bespoke, sector-specific calculation engine per CAO: IKB-budget spend ledgers (Rijk), multi-version
CAO history with retroactive nabetaling (Gemeenten, VO, VVT), automatic periodiek/trede progression
(Gemeenten, PO, VO), FWG 3.0 functiewaardering scoring (Ziekenhuizen, VVT), and minute-granular
stapelbare ORT (onregelmatigheidstoeslag) shift engines (Ziekenhuizen, VVT). None of that exists
today and none of it is in scope here — `cao-library`'s own non-goals already excluded "CAO-driven
computation" and "a CAO import/authoring UI". This change is **data for the shipped mechanism**: six
more `{cao-id}.json` corpus files in the existing leaf shape, nothing else. Where the old drafts
describe machinery `cao-library` cannot express (IKB spend, ORT computation, FWG derivation,
trede/periodiek progression, multi-version history), this proposal names the gap explicitly rather
than building a parallel path — see design.md "Named gaps".

**Honesty about verification**: real CAO loontabellen are long, sector-negotiated documents that
change with every CAO-akkoord (typically 1-2 year cycles) and none was fetched from a live primary
source during this research pass — only the archived-branch drafts (May-2026, already stale) and
general domain knowledge were available. Per the `cao-library` contract (`isUsableLeaf()` in
`CaoRegistry`, `cao/SCHEMA.md`), an unconfirmed figure must never silently pass as fact. All six
datasets therefore ship exactly like `cao-metaal-techniek`/`cao-horeca` did — structurally complete,
every leaf `verified: false` + `placeholder: true` + a `checkAgainst` pointer to the sector's own
publication (caoloon.nl, the CAO-tekst, or the employer association) — **not** like `cao-generiek`
(which is verified because it re-cites an already-`verified:true` statutory figure elsewhere in the
engine's own tax-table corpus, not a negotiated CAO amount). None of the six sector CAOs here has
that kind of already-verified anchor to re-cite.

## What Changes

- **NEW `lib/Standards/cao/cao-rijk.json`, `cao-gemeenten.json`, `cao-onderwijs-po.json`,
  `cao-onderwijs-vo.json`, `cao-ziekenhuizen.json`, `cao-zorg-vvt.json`** — six corpus files in the
  existing `{value, source, verified}` leaf shape (`payScales`, `allowances`, `leaveEntitlement`,
  `workingTime`), every leaf `verified: false` / `placeholder: true` / `checkAgainst` (see Why). No
  change to the leaf shape or top-level keys documented in `cao/SCHEMA.md`.
- **`lib/Standards/CaoRegistry.php`** — `VERSION` const bump only (the corpus loader, its glob, its
  resolvers, and `isUsableLeaf()`/`isWellFormed()` are read-only and already generic over every file
  in `cao/*.json`; zero logic change).
- **NO change** to `NlCaoChecks.php`, `RuleAuditService.php`, `lib/Standards/rules/payroll.json` /
  `labour.json`, `RuleCatalogue.php`, `hr-objects.json`, `hr-cao.json`, or `src/manifest.json` — the
  six new CAOs flow through every one of those unchanged, exactly as `cao/SCHEMA.md` promises (see
  design.md D1-D4 for why each specifically needs no edit).
- **Seed data** — the existing `occ hrmq:rules:seed-test-data` run (via `NlCaoChecks::seedObjects()` +
  `UpsertsObjects`) picks up all nine CAOs (three existing + six new) automatically once
  `CaoRegistry::availableCaos()` lists them; the `Caos`/`CaoDetail` pages render the new rows with the
  manifest unchanged.

### Non-goals (named fast-follows and exclusions)

- **CAO-driven computation** (IKB-budget accrual/spend, ORT time-segmentation, periodiek/trede
  auto-progression, FWG-score-to-schaal derivation) — already excluded by `cao-library`'s own
  non-goals ("the MVP audits the CAO; it does not yet compute CAO allowances into net pay"). The six
  old drafts describe exactly this kind of per-CAO bespoke calculator; none is built here. Named as
  explicit gaps in design.md, not silently dropped.
- **Multi-version CAO history / retroactive recalculation** — the corpus is one file per CAO holding
  the *current* `version`/`effectiveDate`; a new CAO-akkoord replaces the file (`cao/SCHEMA.md`
  "Annual / CAO-revision re-issue discipline"). No runtime version history, no peildatum-based
  historical lookup, no automatic nabetaling on retroactive ratification.
- **Automatic CAO-text ingestion/scraping** — every dataset is hand-authored + source-cited, exactly
  like the three existing CAOs; there is no importer.
- **A CAO import/authoring UI** — unchanged from `cao-library`: the corpus is maintained in code, the
  manifest page is read-only reference.
- **Per-scale periodiek/trede tables** — `payScales` stays one minimum figure per schaal (the
  `CaoRegistry::minMaandloonCents()` signature already only accepts a schaal, not a schaal+trede
  pair); a full periodiek ladder per schaal is out of scope (named gap, design.md).

## Capabilities

### New Capabilities

<!-- none — cao-sector-datasets extends the existing cao-library capability with corpus data; it does
     not introduce a new mechanism, registry, or check predicate -->

### Modified Capabilities

- `cao-library`: six additional CAOs in the corpus (`cao-rijk`, `cao-gemeenten`, `cao-onderwijs-po`,
  `cao-onderwijs-vo`, `cao-ziekenhuizen`, `cao-zorg-vvt`), all placeholder-marked pending confirmation
  against the official CAO-teksten. The loader, checks, contract reference, and reference pages are
  unchanged.

## Impact

- `lib/Standards/cao/cao-rijk.json`, `cao-gemeenten.json`, `cao-onderwijs-po.json`,
  `cao-onderwijs-vo.json`, `cao-ziekenhuizen.json`, `cao-zorg-vvt.json` — NEW corpus files.
- `lib/Standards/CaoRegistry.php` — `VERSION` const bump only.
- `tests/Unit/Standards/CaoRegistryTest.php` — extend fixture assertions to cover
  `availableCaos()` listing all nine CAOs and the six new files loading well-formed.
- Depends on `cao-library` (merged, archived 2026-07-14): the corpus loader, the `{value, source,
  verified}` leaf discipline, the two below-CAO-minimum checks, the seed, and the reference pages —
  all reused unchanged.
