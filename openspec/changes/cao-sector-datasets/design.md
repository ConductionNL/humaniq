# Design — cao-sector-datasets

## Context

**Verified against HEAD 2026-07-17.** This change adds six corpus files to the `cao-library`
mechanism (archived 2026-07-14) and touches nothing else. Read at HEAD, read-only, for this change:

- `lib/Standards/cao/SCHEMA.md` — already documents the extension path this change exercises
  verbatim: *"Adding a new CAO is also data-only: drop a new `{cao-id}.json` file (minimally with
  `verified: false` + `placeholder: true` + `checkAgainst` leaves ...) and bump
  `CaoRegistry::VERSION`. The CAO immediately appears in `CaoRegistry::availableCaos()` and, once
  re-seeded, on the read-only `Caos` reference page — no code change required."*
- `lib/Standards/CaoRegistry.php` — `all()` globs `cao/*.json` with no per-file allowlist;
  `minMaandloonCents(caoId, schaal)` and `minLeaveHours(caoId, contractHoursPerWeek)` index into
  whatever `payScales`/`leaveEntitlement` leaves exist for whatever `caoId` is asked for. Nothing in
  the loader assumes exactly three CAOs.
- `lib/Standards/Checks/NlCaoChecks.php` — `seedObjects()` iterates
  `CaoRegistry::availableCaos()` (not a hardcoded id list); `minimumloonSchaalSatisfied()` /
  `verlofMinimumSatisfied()` resolve `cao`/`caoSchaal` from the object under audit, never from a
  fixed set of known CAO ids.
- `lib/Settings/register.d/hr-objects.json` — `EmploymentContract.cao` is already documented as
  "the id of a CAO in the maintained library ... e.g. cao-generiek / cao-metaal-techniek /
  cao-horeca" (a free-text id, not an enum) — a contract can already name any corpus id, including
  one that does not exist yet.
- `src/manifest.json` `Caos` / `CaoDetail` pages — register+schema-backed
  (`register: hrmq`, `schema: Cao`), no per-CAO wiring; rows are whatever `Cao` objects exist.

Every one of those five surfaces is already generic over "however many CAOs the corpus contains."
This change is therefore data-only in the strictest sense: six new `cao/*.json` files plus a
`CaoRegistry::VERSION` bump. No loader change, no predicate change, no context-enrichment change, no
schema change, no manifest change — confirmed per-surface below (D5), not assumed.

## Goals / Non-Goals

**Goals:** add `cao-rijk`, `cao-gemeenten`, `cao-onderwijs-po`, `cao-onderwijs-vo`,
`cao-ziekenhuizen`, `cao-zorg-vvt` to the corpus in the existing leaf shape; be honest about which
figures are sourced vs placeholder; confirm (not merely assert) that the loader, checks, contract
reference, and reference pages need zero changes; name explicitly where the old draft changes
imagined machinery `cao-library` cannot express.

**Non-Goals (from the proposal, binding):** CAO-driven computation (IKB spend ledgers, ORT
time-segmentation, periodiek/trede auto-progression, FWG-score-to-schaal derivation), multi-version
CAO history / retroactive recalculation, automatic CAO-text ingestion, a CAO authoring UI, and
per-scale periodiek tables. See "Named gaps" below — each of these is a real thing a sector CAO
needs that the shipped mechanism cannot do; none is quietly built as a parallel path here.

## Decisions

### D1 — Six new corpus files, identical top-level shape, no schema change

Each `lib/Standards/cao/{cao-id}.json` carries exactly the `cao/SCHEMA.md` shape: `id`, `name`,
`sector`, `version`, `effectiveDate`, `basedOn`, and the four leaves `payScales` / `allowances` /
`leaveEntitlement` / `workingTime`, each `{value, source, verified}` (+ `placeholder`/`checkAgainst`
where unconfirmed). No new top-level key, no new leaf group — the six sector CAOs need nothing the
existing three didn't already need.

### D2 — `schaal` is an opaque string key; three different sector naming conventions need no loader change

`CaoRegistry::minMaandloonCents(string $caoId, string $schaal)` does `$scales[$schaal]` — `$schaal`
is never parsed, only looked up. The six new sectors use three different scale-naming conventions,
and all three pass through unchanged:

| Sector | Scale identifiers | Example |
|---|---|---|
| `cao-rijk` | BBRA numeric schalen 1–18 + chief subschalen | `"11"`, `"15a"` |
| `cao-gemeenten` | VNG/HR21 numeric schalen 1–19 | `"10"` |
| `cao-onderwijs-po` | Onderwijs letter-schalen + OOP/DIR bands | `"L11"`, `"OOP-6"`, `"DIR-A"` |
| `cao-onderwijs-vo` | Onderwijs letter-schalen | `"LB"`, `"LC"`, `"LD"` |
| `cao-ziekenhuizen`, `cao-zorg-vvt` | FWG 3.0 functiegroepen | `"FWG-40"`, `"FWG-55"` |

This confirms (D1's claim) rather than assumes it: the resolver's contract was already
schema-naming-agnostic before this change: `payScales.value` is `{schaal: minimum maandloon}` for
*any* string key, and this holds across five different sector conventions.

### D3 — Verification stance: placeholder by default; only a directly-fetched primary-source figure gets `verified: true`

Per the proposal's honesty requirement, every leaf defaults to `verified: false` / `placeholder:
true` / `checkAgainst` — none of the six sector loontabellen was transcribed from a primary source
in this pass (see Seed Data below for exactly which sources exist per CAO). The one exception:
`cao-rijk`'s `allowances` (IKB — Individueel Keuzebudget) and `workingTime` leaves are `verified:
true`, sourced from a direct fetch of the official CAO Rijk page
(`https://www.caorijk.nl/cao-rijk/1.-algemeen/hoofdstuk-9/individueel-keuzebudget`, retrieved
2026-07-17), which states verbatim: *"Uw IKB-budget bedraagt 16,50% van uw salaris, waarbij een
minimumbedrag geldt van € 452 per maand"* and *"U krijgt ieder jaar 64 IKB-uren"* for *"een fulltime
dienstverband van gemiddeld 36 uur per week."* This mirrors the `cao-generiek`/`cao-metaal-techniek`
precedent exactly: one verified anchor among several placeholder-marked CAOs, not "verified because
plausible" but "verified because a primary source was actually read." `allowances.ikb.value.pct`
(16.50) SHOULD NOT be confused with the 16.37% figure in the obsolete `spec/cao-rijk` draft — that
number is superseded (2026 CAO Rijk raised it), which is itself evidence for why a stale draft must
never be ported as fact (proposal.md Why).

### D4 — `workingTime` stays free-form and display-only; annual vs weekly units need no resolver change

`cao/SCHEMA.md` already documents `workingTime` as "informational/display-only — not read by any
resolver," and `CaoRegistry` confirms this (`minMaandloonCents`/`minLeaveHours` never touch
`workingTime`). `cao-onderwijs-po`'s working-time norm is expressed in *annual* hours (the
**normjaartaak**, 1659 hours/year for a fulltime aanstelling — the CAO PO structural unit, distinct
from the weekly `fulltimeHoursPerWeek` the other eight CAOs use), decomposed into lesgevende taak
(max 940u), lesgebonden taken (35–45% opslagfactor), professionalisering (83u), duurzame
inzetbaarheid (40u) and overige taken. Because `workingTime.value` is a free-form object (no fixed
key list is enforced by `CaoRegistry::isWellFormed()`, which only checks the four leaf groups
exist, not their internal keys), `cao-onderwijs-po.json` simply uses
`normjaartaakUrenPerJaar: 1659` instead of `fulltimeHoursPerWeek` — no `CaoRegistry` change, no
`Cao` schema change (`workingTime` is `type: object` on `hr-cao.json`). This is left `verified:
false` (not directly fetched from a primary CAO PO source in this pass — the corroborating sources
are third-party explainers, not `poraad.nl` itself), despite high cross-source agreement.

### D5 — Confirmed unchanged: checks, context enrichment, rules, register.d, manifest

Per-surface confirmation that nothing beyond the corpus + `CaoRegistry::VERSION` needs to change:

- **`NlCaoChecks`** — `checks()` returns the same two predicates regardless of which CAOs exist in
  the corpus; `seedObjects()` already iterates `CaoRegistry::availableCaos()`. Zero lines change.
- **`RuleAuditService::audit()`** `cao.*` context (`cao.employeesById`, `cao.caoByEmployeeId`) is
  built from `EmploymentContract`/`Employee` objects actually present, not from a CAO allowlist.
  Zero lines change.
- **`lib/Standards/rules/payroll.json` / `labour.json`** — `nl-cao-minimumloon-schaal` /
  `nl-cao-verlof-minimum` are generic rules (`EmploymentContract`, `LeaveBalance`); they do not name
  a CAO. **`RuleCatalogue::VERSION` does NOT bump** — only `CaoRegistry::VERSION` does (D6).
- **`hr-objects.json`** — `EmploymentContract.cao` is already a free-text id reference (see
  Context); `caoSchaal` is already a free-text scale reference. Zero schema change.
- **`hr-cao.json`** (`Cao` schema) — already generic (`caoId`, `name`, `sector`, `payScales`,
  `allowances`, `leaveEntitlement`, `workingTime`, `*Verified` flags, `source`); the seed maps
  1:1 from any corpus entry regardless of sector. Zero schema change.
- **`src/manifest.json`** `Caos`/`CaoDetail` — register+schema-backed with `allowCreate: false`;
  new rows appear because new `Cao` objects exist, not because the manifest names CAO ids. Zero
  manifest change. `npm run check:manifest` is unaffected (no manifest edit to check).

### D6 — Maintenance model: `CaoRegistry::VERSION` bumps, `RuleCatalogue::VERSION` does not

`cao/SCHEMA.md`'s "Annual / CAO-revision re-issue discipline" is explicit: a new CAO revision is
"a **data-only** change: add/replace `{cao-id}.json` with sourced, verified values and bump
`CaoRegistry::VERSION` — no PHP changes." `RuleCatalogue::VERSION` only bumps when a rule
*definition* changes (`rules/*.json` — as it did in the original `cao-library` change, task 9,
because that change added the two rule ids). Adding CAO *datasets* is not adding rule ids, so this
change — and every future "new CAO year" or "new sector CAO" change — bumps `CaoRegistry::VERSION`
only. Stated explicitly here because the task brief that seeded this proposal named
`RuleCatalogue::VERSION`; `CaoRegistry::VERSION` is the one the shipped code and `SCHEMA.md` actually
document for a corpus-data change, so this design corrects to that.

### D7 — Seeding and reference pages: automatic, no manifest surgery

`NlCaoChecks::seedObjects()` (via `UpsertsObjects`, upserted on `caoId`) already reads
`CaoRegistry::availableCaos()` fresh on every call. Once the six files exist, the next
`occ hrmq:rules:seed-test-data` run upserts six new `Cao` objects with no duplicate risk (existing
three converge, unaffected). The `Caos` index and `CaoDetail` pages render them immediately —
register+schema-backed pages have no per-row wiring to add.

### Declarative vs imperative (ADR-031)

| Behaviour | Path | Rationale |
|---|---|---|
| Six new CAOs' facts (scales, allowances, leave, working-time) | **corpus data** `lib/Standards/cao/{cao-id}.json` | same universal-sector-fact precedent as the three existing CAOs; not OpenRegister |
| Corpus loading + resolvers | **unchanged** imperative `CaoRegistry` | already generic over any `cao/*.json` file and any `schaal` string (D2) |
| Below-CAO checks | **unchanged** `NlCaoChecks` predicates | already generic over `cao`/`caoSchaal` on the object under audit (D5) |
| Context enrichment | **unchanged** imperative `RuleAuditService` | already generic over whichever contracts/employees exist (D5) |
| Contract → CAO link | **unchanged** register.d (`cao`, `caoSchaal`) | already a free-text id reference, not an enum (Context) |
| `Cao` reference display objects | **unchanged** register.d schema + seed | already generic (D5, D7) |
| Reference pages | **unchanged** declarative manifest | already register+schema-backed, no per-CAO wiring (D5) |

## Seed Data (ADR-001)

Six sector CAOs, all added to the corpus as structurally complete, source-cited datasets. Per D3,
only `cao-rijk`'s `allowances` (IKB) and `workingTime` (36u/week) leaves are `verified: true` — the
one directly-fetched primary-source figure in this batch. Every `payScales` and `leaveEntitlement`
leaf across all six, and every other leaf on the other five CAOs, ships `verified: false` +
`placeholder: true` + `checkAgainst`, per the shipped `cao-metaal-techniek`/`cao-horeca` precedent —
none of these six is a statutory-floor anchor like `cao-generiek`.

- **`cao-rijk`** (Rijksoverheid/ambtenaren) — BBRA-schalen 1–18 + chief subschalen 15a/16a/17a/18a
  (`payScales`, placeholder — checkAgainst: official BBRA loontabel, `caorijk.nl` / P-Direkt);
  **IKB 16,50% van salaris, min. €452/maand, 64 IKB-uren/jaar** (`allowances`, **verified: true** —
  source: `caorijk.nl` hoofdstuk 9, fetched 2026-07-17); wettelijk + bovenwettelijk verlof
  (`leaveEntitlement`, placeholder — checkAgainst: CAO Rijk verlofhoofdstuk); **36 uur/week fulltime
  referentie** (`workingTime`, **verified: true** — same `caorijk.nl` fetch, which states the IKB
  minimum applies "voor een fulltime dienstverband van gemiddeld 36 uur per week").
- **`cao-gemeenten`** (Gemeenten/VNG) — schalen 1–19 (`payScales`, placeholder — checkAgainst:
  `vng.nl` "Eindresultaat Cao Gemeenten en Cao SGO 2025-2027" + LOGA salarisbrief PDF, both fetched
  as search results 2026-07-17, confirming a 2025-2027 akkoord with a €16/uur wettelijk-uurloon
  vloer per 2026-01-01 — the exact per-schaal-per-periodiek table was not fetched, hence
  placeholder); leave + working-time placeholder — checkAgainst: `caogemeenten.nl` CAO-tekst.
- **`cao-onderwijs-po`** (Primair onderwijs) — L10/L11/LA/LB/LC leraarschalen + OOP-schalen
  (onderwijsondersteunend personeel) + DIR-schalen (`payScales`, placeholder — checkAgainst:
  PO-Raad `poraad.nl` CAO PO 2024-2025 tekst + opvolger); leave placeholder; **normjaartaak 1659
  uur/jaar** (`workingTime`, placeholder per D4 despite high cross-source agreement — checkAgainst:
  PO-Raad CAO PO tekst, hoofdstuk werktijden/taakbeleid).
- **`cao-onderwijs-vo`** (Voortgezet onderwijs) — LB/LC/LD leraarschalen (`payScales`, placeholder
  — checkAgainst: VO-raad `vo-raad.nl` "Salarisschalen cao vo 2025-2027 bekend", confirming a
  2025-2027 akkoord effective 2025-11-01 with a further 1.2% step 2026-11-01 — exact bedragen not
  independently fetched from a primary table); leave + working-time placeholder — checkAgainst:
  `vo-raad.nl` CAO VO tekst.
- **`cao-ziekenhuizen`** (Ziekenhuizen/NVZ) — FWG-functiegroepen 5–80 (14 salarisschalen, FWG-15
  t/m FWG-80 per the current NVZ CAO) (`payScales`, placeholder — checkAgainst: `cao-ziekenhuizen.nl`
  "Salarisschalen, premies & vergoedingen", NVZ's own page); ORT (onregelmatigheidstoeslag) reference
  rates (`allowances`, placeholder — display-only, see Named gaps: no computation exists —
  checkAgainst: `cao-ziekenhuizen.nl` "Salariëring en vakantiebijslag"); leave + working-time
  placeholder.
- **`cao-zorg-vvt`** (Verpleeg-, verzorgingshuizen en thuiszorg) — shares the FWG 3.0
  functiegroep system with `cao-ziekenhuizen` (`payScales`, placeholder — checkAgainst: ActiZ
  `actiz.nl` CAO VVT 2025-2026 tekst); ORT reference rates (`allowances`, placeholder — a real,
  fetched 2026 change exists: weekday-night 00:00–06:00/22:00–24:00 rose 44%→47%, Saturday
  00:00–06:00/22:00–24:00 rose 49%→52% per 1 January 2026, per `actiz.nl` "4.4 Onregelmatig werken
  en de vergoeding die je daarvoor ontvangt" — still placeholder here because the full day/time/
  holiday/regeling-A-vs-B stacking matrix was not transcribed, and see Named gaps: no computation
  engine exists to apply it); leave + working-time placeholder.

## Named gaps (where `cao-library` genuinely cannot express what a sector CAO needs)

The six obsolete draft changes (`spec/cao-rijk` et al.) each assumed bespoke machinery. None of it
exists in `cao-library`, and none is built by this change — named explicitly rather than silently
dropped or half-built as a parallel path:

1. **IKB accrual/spend ledger** (`cao-rijk`, also present in `cao-gemeenten`'s CAO). The corpus can
   hold the IKB *rate* (D3 — `allowances.ikb.value.pct`) as reference data, but `cao-library` has no
   per-employee cumulative-budget object, no spend-transaction ledger, and no
   `RuleAuditService`-level notion of "remaining budget." This is squarely the `cao-library`
   non-goal "CAO-driven computation ... not yet computed into net pay" — restated here because the
   old draft's REQ-002 (IKB budget calculation, spend deduction, insufficient-budget rejection) is
   exactly the shape of thing that non-goal excludes. Fast-follow, not in scope.
2. **ORT (onregelmatigheidstoeslag) time-segmentation engine** (`cao-ziekenhuizen`, `cao-zorg-vvt`).
   Real ORT computation needs a shift/roster object (which does not exist anywhere in `hrmq`'s
   schema today — confirmed absent from `register.d`), minute-level day/time/holiday segmentation,
   and stackability rules between overlapping ORT bands (e.g. nacht + feestdag). The `allowances`
   leaf can hold the *rate table* as reference data (D3/Seed Data), but there is no check, resolver,
   or payroll-calculator hook that applies it to an actual worked shift. Fast-follow.
3. **FWG-score-to-schaal derivation** (`cao-ziekenhuizen`, `cao-zorg-vvt`). FWG 3.0 assigns a
   functiegroep from nine weighted sub-scores (kennis, zelfstandigheid, sociale vaardigheden, ...).
   `CaoRegistry`/`NlCaoChecks` only ever *consume* a schaal already recorded on
   `EmploymentContract.caoSchaal` (D2) — there is no mechanism to derive that schaal from a raw FWG
   assessment. Out of scope, matching `cao-library`'s "no per-CAO bespoke calculators" non-goal;
   the FWG functiegroep id must be entered directly as `caoSchaal` (e.g. `"FWG-40"`).
4. **Periodiek/trede progression** (`cao-gemeenten`, `cao-onderwijs-po`, `cao-onderwijs-vo`,
   partially `cao-ziekenhuizen`). `payScales.value` is `{schaal: single minimum bedrag}` — one
   figure per schaal, not a `{schaal: {trede: bedrag}}` ladder, and
   `CaoRegistry::minMaandloonCents(caoId, schaal)` has no trede parameter to resolve one even if the
   corpus held it. There is no automatic "eindperiodiek bereikt" detection, no annual step-up job,
   and no horizontale-inschaling-bij-promotie logic. Already named in `cao-library`'s own non-goals
   ("Per-trede (step) progression, age tables, part-time proration nuance"); restated here because
   four of the six new sectors' old drafts centred entirely on this. The shipped check treats each
   schaal's stored figure as a floor (in practice, the lowest/entry trede) — a contract paid above
   that floor at any trede passes; the check cannot validate *which* trede is correct for years of
   service.
5. **Multi-version CAO history / retroactive recalculation** (`cao-gemeenten`, `cao-onderwijs-vo`,
   `cao-zorg-vvt`). The corpus is one file per CAO holding the file's *current* `version`/
   `effectiveDate` (D1); there is no runtime store of prior versions, no peildatum-based historical
   lookup (`CaoRegistry::get()` always returns the file currently on disk), and no nabetaling/
   back-pay generation when a CAO is signed retroactively. A new CAO-akkoord is a
   replace-the-file-and-bump-`VERSION` operation (D6); git history is the only "previous version"
   record. This is an inherent property of the corpus-as-code design (shared with `TaxTables`/
   `RuleCatalogue`, neither of which has runtime version history either), not something specific to
   this change — named here because three of the six old drafts assumed live multi-version storage.

None of these five gaps is silently worked around; each maps to a specific non-goal already in
`cao-library` or stated fresh in this proposal, and each is a legitimate fast-follow candidate, not
a defect in this change's scope.

## Risks / Trade-offs

- **All six sector loontabellen enforce nothing until verified.** By design (D3, mirroring the
  shipped `verified→enforce`/`placeholder→vacuous` lever, `cao-library` REQ-CAO-001/D5): a
  transcribed-but-unconfirmed schaalbedrag must never raise a false mandatory violation. Coverage
  grows only as a maintainer confirms figures against the primary CAO-tekst and flips
  `verified: true` — not as a side effect of this change.
- **`cao-rijk`'s two `verified: true` leaves (IKB, working-time) are display-only facts, not
  enforcement-critical.** Neither `allowances` nor `workingTime` is read by
  `minMaandloonCents`/`minLeaveHours` (D4/Context) — marking them verified changes nothing about
  which checks fire; it only affects what a maintainer sees on `CaoDetail`. Low risk by
  construction.
- **Search-derived corroboration is not the same rigor as a fetched primary loontabel.** The
  `checkAgainst` citations for `cao-gemeenten`/`cao-onderwijs-po`/`cao-onderwijs-vo`/
  `cao-ziekenhuizen`/`cao-zorg-vvt` point at real, current, named sources (VNG, PO-Raad, VO-raad,
  NVZ, ActiZ) rather than guesses, which is strictly better than the obsolete drafts' unsourced
  numbers — but none of those five CAOs' `payScales`/`leaveEntitlement` was independently
  cross-checked against a primary document table in this pass, hence `verified: false` throughout
  (D3).
- **Scale-naming diversity (D2) is unenforced by any schema constraint.** `CaoRegistry` never
  validates `schaal` string shape, so a maintainer authoring `cao-ziekenhuizen.json` could
  accidentally key `payScales` inconsistently (e.g. `"FWG40"` vs `"FWG-40"`) against what a contract
  later records in `caoSchaal`; a mismatch resolves to `null` (advisory, never a wrong number) rather
  than a validation error. Mitigated by documenting the convention per CAO in Seed Data; a stricter
  guard is a possible fast-follow, not required by this change.

## Open Questions

- None blocking. The five named gaps above are candidate fast-follows, tracked here rather than as
  open questions because each already has a clear non-goal home. Confirming the six loontabellen
  against their primary CAO-teksten (flipping the placeholder leaves to `verified: true`) is ongoing
  maintenance work tracked by each leaf's `checkAgainst` pointer, exactly like the two existing
  placeholder CAOs.
