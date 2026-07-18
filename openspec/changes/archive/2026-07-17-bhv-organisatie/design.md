# Design — bhv-organisatie

## Context

**Verified against HEAD 2026-07-17.** Read read-only, directly grounding this design:

- `lib/Standards/Checks/NlSignalChecks.php` — the `hr-signals` provider, `checks()` returns
  `['EmploymentContract' => ['nl-signaal-contract-verloopt' => ..., 'nl-aanzegtermijn-bewaking' =>
  ...]]`. `CheckProvider::checks()` returns `array<string, array<string, callable>>` keyed by object
  type — `RuleEngine::providers()` merges providers **additively per type** (confirmed by the
  `hr-signals` spec's own REQ-SIG-004: "RuleEngine merges providers per type additively, so the
  existing NlPayrollChecks/NlDocumentChecks contract checks are unaffected"), and nothing prevents
  one provider from registering checks under **multiple** type keys. Adding `'BhvCertificering' =>
  ['nl-bhv-certificaat-verloopt' => ...]` as a sibling array key inside the same `checks()` return
  is a same-class, same-file, additive change.
- `lib/Standards/rules/labour.json:333-345` — `nl-signaal-contract-verloopt`'s exact shape:
  `framework: "hr-signals"`, `severity: "recommended"`, `parameters: {"windowDays": 60}`. This is
  the literal template `nl-bhv-certificaat-verloopt` copies (different window, different field
  names, same framework, same severity tier).
- `src/manifest.json:1095-1125` — the "Aflopende contracten" `object-table` widget: `source:
  {register: hrmq, schema: EmploymentContract, filter: {type: temporary, endDate: {gte: "@today",
  lte: "@today+60d"}}}`, with an explicit `_note` documenting that the `60` must stay in sync with
  the corpus rule's `parameters.windowDays`. The BHV widget follows the identical shape and
  same-note convention against `BhvCertificering.certificaatGeldigTot`.
- Arbeidsomstandighedenwet (Arbowet) art. 15, https://wetten.overheid.nl/BWBR0007671, artikel 15 —
  lid 1: the werkgever laat zich bijstaan by one or more werknemers aangewezen als
  bedrijfshulpverlener (tenzij hij deze taak zelf vervult); lid 2: bij die aanwijzing houdt de
  werkgever rekening met de grootte van het bedrijf en met de aard van de in het bedrijf aanwezige
  risico's. **No numeric ratio appears in the article.** The commonly-cited operational guidance
  ("voldoende BHV'ers, gebaseerd op de RI&E") is exactly that — guidance derived from the RI&E, not
  a statutory formula — and this design does not promote it to a checkable number.
- `lib/Settings/register.d/hr-org.json` — `OrgUnit.type` enum is `afdeling`/`team`/`kostenplaats`;
  no physical-location concept exists anywhere in the register (confirmed:
  `grep -rn "location\|vestiging\|locatie" lib/Settings/register.d/*.json` matches only
  `AttendanceRecord.location` — an enum of `kantoor`/`thuis`/`klant`/`anders`, a work-mode flag for
  one clock-in record, not a site register).
- `lib/Settings/register.d/hr-assets.json` — `Asset.category` enum has no `ehbo`/`aed` value and
  the schema has no expiry/inspection-date property at all (`aanschafdatum` is a purchase date, not
  a recurring inspection date).

## Goals / Non-Goals

**Goals:** track who holds a valid BHV-related certification and when it expires; reuse the
existing expiry-alert mechanism exactly (same provider class, same framework, same widget shape);
give HR a grouping/visibility view by the one organisational concept hrmq already has (`OrgUnit`);
cite the actual statutory basis without inventing a number it does not set.

**Non-Goals (binding, from the proposal):** a Location entity; a numeric coverage formula; a
roster/scheduling generator (defer to `rostering`); an evacuation-plan document library; an
IoT/webhook alarm system; a standalone mobile app; AED/EHBO equipment inspection tracking (defer to
a future `asset-management` extension); push-notification delivery.

## Decisions

### D1 — One provider, one framework, three predicates: literal reuse, not a parallel mechanism

The task brief's instruction — "reuse hr-signals, do NOT build a second alerting mechanism" — is
honoured at the most literal level available: `nl-bhv-certificaat-verloopt` is added as a third
entry inside `NlSignalChecks::checks()`'s returned array, under the existing `hr-signals`
framework, at the existing `recommended` severity. There is no new `CheckProvider` class, no new
framework slug, no new context-building method on `RuleAuditService` (the predicate is object-
local — like `nl-signaal-contract-verloopt`'s date comparison, it needs only the object's own
`certificaatGeldigTot`, no cross-object successor lookup). The only new code is the predicate
function itself and its corpus row.

### D2 — No numeric coverage ratio; visibility grouped by OrgUnit instead

Arbowet art. 15 sets a qualitative standard the werkgever satisfies via their own RI&E (Context) —
there is no "1 per 50" to check against, and asserting one would be asserting a rule the statute
does not contain, precisely the failure mode the task brief named. This design's response is not
silence but **visibility**: the `BhvCertificeringen` index page groups/filters by `orgUnitId`
(the nearest existing concept to "which part of the organisation this coverage applies to") and
`rol`, so a safety officer can see "who is currently certified, in which team" and judge adequacy
themselves against their RI&E — a fact-surface, not a compliance-formula. This is the same
discipline `stagiair-bbl-admin` D2 applies to the stagevergoeding ceiling: state what is confirmed
(the duty exists, RI&E-driven), do not assert what is not (a specific number).

### D3 — `OrgUnit`, not a new Location schema, is the coverage-scoping concept

The draft's "per location" framing assumes a site register hrmq does not have (Context). Rather
than build one for this change alone — a Location entity would need its own address/floor-plan/
QR-code surface to be useful the way the draft imagined, well beyond a certificate-tracking
change's scope — `BhvCertificering.orgUnitId` reuses the existing `OrgUnit` `$ref`. This is an
honest downgrade from "physical location" to "organisational grouping", named as such, not
disguised as the thing the draft asked for.

### D4 — Plain record, no lifecycle

`BhvCertificering` carries no `x-openregister-lifecycle`. A certification either exists with a
`certificaatBehaaldOp`/`certificaatGeldigTot` pair, or it is renewed by creating a new record (the
draft's own "certificate lifecycle" language maps to "a new record after herhaling", not a state
machine on one record) — mirroring how `AttendanceRecord`'s `workedHours` is a stored fact rather
than a computed one: the simplest structure that is still honest about what changes over time
(a renewal is a new certification event, not a status flip).

### Declarative vs imperative (ADR-031)

| Behaviour | Path | Rationale |
|---|---|---|
| `BhvCertificering` record shape | declarative schema, no lifecycle | plain dated fact, renewal = new record (D4) |
| `nl-bhv-certificaat-verloopt` predicate | imperative `CheckProvider` (extends `NlSignalChecks`) | ADR-031's corpus convention; object-local, no context builder needed |
| "Aflopende BHV-certificaten" widget | declarative manifest `object-table` | the existing "Aflopende contracten" shape, ADR-031 default |
| Coverage visibility (grouping by OrgUnit/rol) | declarative manifest filters, no computed check | D2 — a fact-surface, not a formula |

## Seed Data (ADR-001)

Two `BhvCertificering` seeds against existing seeded employees:

1. **Clean**: `rol: bhv_basis`, `certificaatBehaaldOp` two years ago, `certificaatGeldigTot` one
  year from the seed anchor date (outside the 90-day window) — passes `nl-bhv-certificaat-verloopt`
  vacuously.
2. **Intended violation**: `rol: ehbo`, `certificaatGeldigTot` 45 days from the seed anchor date
  (inside the 90-day window) — the one deliberate violation, exercising the new predicate exactly
  as `hr-signals`' own two seeded contracts exercise the original two.

Dev-container verification gate: `occ hrmq:rules:audit` reports exactly one new violation (the
45-day-to-expiry seed → `nl-bhv-certificaat-verloopt`) and zero regressions on
`nl-signaal-contract-verloopt`/`nl-aanzegtermijn-bewaking`.

## Risks / Trade-offs

- **`orgUnitId`-scoped visibility is not "per physical location"** (D3) — an organisation with
  multiple buildings under one `OrgUnit` would not distinguish BHV coverage between them. Named,
  not silently assumed adequate; a genuine Location concept remains a real future need if that
  granularity matters to a deployment.
- **No adequacy check at all, only visibility** (D2) — a deployment relying on this change alone
  gets no red/green signal that coverage is insufficient, only a list of who is certified and when
  they expire. This is a deliberate trade against inventing an unfounded number, not an oversight.
- **Extending `NlSignalChecks.php` rather than adding a new class** (D1) grows one file's
  responsibility across two originally-EmploymentContract-only rules plus a new object type — kept
  small because the predicate itself is a single date comparison, the same shape as the two it
  joins; if a fourth, more complex BHV rule is added later, splitting into a dedicated class becomes
  the better trade, named here so it is not forgotten.

## Open Questions

- None blocking. A genuine Location concept, AED/EHBO equipment-inspection tracking, and any
  roster/document/IoT/mobile surface are named fast-follows (Non-Goals), not open blockers for this
  change's own scope.
