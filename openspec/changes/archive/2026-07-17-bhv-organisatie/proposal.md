---
kind: config
---

# BHV-organisatie (bedrijfshulpverlening) — certificaat-signalering, geen tweede alarmplatform

## Why

The 2026-05-23 draft `spec/bhv-organisatie` designed a ten-feature platform: a location/personnel
register, a roster generator, an evacuation-plan document library with QR-code on-site access, an
IoT/webhook alarm-and-incident-logging system, a standalone mobile app, and a numeric coverage
formula ("1 BHV per 50 attendees + 1 extra per 100"). **Verified against HEAD 2026-07-17**: hrmq
already ships exactly the mechanism this domain needs — `hr-signals` (`lib/Standards/Checks/
NlSignalChecks.php`, framework `hr-signals`) signals an expiring `EmploymentContract` (advisory,
configurable window) and renders it as an "Aflopende contracten" `object-table` dashboard widget
(`src/manifest.json`) that filters `endDate` within a window matching the corpus rule's
`parameters.windowDays` — the exact shape a BHV-certificate expiry warning needs. Building a second
alerting mechanism (the draft's push-notification/task-assignment/IoT-webhook stack) for the same
underlying need — "tell someone before a date passes" — would duplicate what already exists.

The draft's numeric coverage formula has no statutory basis this change can cite: Arbeidsomstandig-
hedenwet art. 15 (https://wetten.overheid.nl/BWBR0007671, artikel 15) requires the werkgever to be
bijgestaan by one or more aangewezen bedrijfshulpverleners, and art. 15 lid 2 requires the
aanwijzing to account for de grootte van het bedrijf en de aard van de in het bedrijf aanwezige
risico's — a **qualitative, RI&E-driven adequacy standard**, not a numeric ratio. No article sets
"1 per 50" or any other fixed number. This change does not invent one (task brief instruction,
honoured directly): it gives HR **visibility** (certified BHV'ers grouped by the existing `OrgUnit`
concept) so they can judge adequacy against their own RI&E, and it does not claim to compute or
enforce a coverage formula the law itself does not set.

hrmq has no `Location` concept (`OrgUnit.type` is `afdeling`/`team`/`kostenplaats` — an
organisational grouping, not a physical site); "per location" coverage from the draft is aspirational
against hrmq's actual data model. `Asset` (`hr-assets.json`, `category` enum `laptop`/`telefoon`/
`voertuig`/`gereedschap`/`toegangspas`/`kleding`/`overig`) already models company equipment
generically and `overig` could hold an EHBO-trommel/AED — but `Asset` carries no inspection-expiry
field at all. A future small change adding that field would reuse this same expiry-alert mechanism
(named as a fast-follow, not built here) — the two domains (personnel certification, equipment
inspection) are related but distinct, and this change stays scoped to personnel.

## What Changes

- **NEW fragment `lib/Settings/register.d/hr-bhv.json`** — `BhvCertificering` schema: one
  certification record per BHV-qualified employee. `employeeId` (`$ref` Employee), `rol` (enum
  `bhv_basis`/`hoofd_bhv`/`ehbo`/`ontruimingsleider`), `certificaatBehaaldOp` (date),
  `certificaatGeldigTot` (date — the expiry-alert anchor), `opleider` (string, nullable — the
  training provider), `orgUnitId` (`$ref` OrgUnit, nullable — the coverage-visibility grouping,
  the closest concept hrmq has to "location"), `administrationId`. Plain record, no lifecycle — a
  certificate either exists and has a date, or it does not; no state machine is needed.
- **`NlSignalChecks.php` gains a third predicate**, `nl-bhv-certificaat-verloopt` (advisory,
  parameterised `windowDays`, same corpus framework `hr-signals`) — literally the same class, the
  same framework, the same "expiring date, no successor-equivalent needed" shape as `nl-signaal-
  contract-verloopt`, extended to a third object type (`BhvCertificering`) rather than a new
  provider class or a new alerting mechanism.
- **Dashboard widget "Aflopende BHV-certificaten"** — an `object-table` widget, added to the
  existing Dashboard (ADR-001 menu 1) in the exact shape "Aflopende contracten" already uses:
  `source: {register: hrmq, schema: BhvCertificering, filter: {certificaatGeldigTot: {gte: @today,
  lte: @today+90d}}}`, the 90 kept in sync with the corpus rule's `parameters.windowDays` (the
  existing contracten-widget's own documented in-sync convention).
- **Manifest**: `BhvCertificeringen` index (columns: employee via related, rol, geldigTot, orgUnit)
  + `BhvCertificeringDetail` under the existing **`Verlof & verzuim`** menu group — ADR-001 already
  lists "BHV" as content of that group; no new top-level menu.
- **Coverage visibility, not a coverage formula**: the index page supports grouping/filtering by
  `orgUnitId` and `rol`, giving HR a "who is currently certified, in which team, expiring when"
  view. No numeric adequacy check, no red/green coverage badge — the law sets no number this change
  could check against (Why, above).
- **Seed data**: two `BhvCertificering` records against existing seeded employees — one comfortably
  valid, one expiring within the alert window (the intended-violation seed for `nl-bhv-certificaat-
  verloopt`, exercising the new predicate exactly as `hr-signals`' own seed exercises the original
  two).

### Non-goals (named fast-follows and exclusions)

- **No Location entity, no per-location coverage formula, no numeric BHV-ratio** — Arbowet art. 15
  sets a qualitative RI&E-driven standard, not a number; inventing one would be worse than not
  checking at all (Why, above).
- **No roster/scheduling generator** — `rostering` (`Shift`/`Roster`/`RosterAssignment`) already
  exists as a general-purpose scheduling capability; if a future change wants BHV-aware rostering,
  it composes with `rostering`, it does not fork a second scheduler.
- **No evacuation-plan document library, no QR-code on-site access, no IoT/webhook alarm system, no
  standalone mobile app** — none of these exist anywhere in hrmq today (no document-versioning
  capability, no IoT ingestion endpoint, no mobile shell); each is a substantial, unrelated
  capability the draft bundled into one ten-feature platform. Named as separate future scope, not
  silently dropped.
- **No AED/EHBO equipment inspection tracking via `Asset`** — `Asset` has no inspection-expiry field
  today; adding one (and reusing this same `hr-signals`-style alert) is a natural, small follow-up,
  deliberately not built in this personnel-scoped change.
- **No push-notification delivery** — the alert surfaces on `occ hrmq:rules:audit` and the dashboard
  widget, the exact non-push posture `hr-signals`' own proposal already states as out of scope.

## Capabilities

### New Capabilities

- `bhv-organisatie`: the `BhvCertificering` schema, the `nl-bhv-certificaat-verloopt` corpus rule
  (extending the existing `NlSignalChecks` provider), the "Aflopende BHV-certificaten" dashboard
  widget, and the `Verlof & verzuim` manifest surface.

### Modified Capabilities

- `hr-signals` — `NlSignalChecks.php` gains a third predicate under a third object-type key
  (`BhvCertificering`); the two existing `EmploymentContract` predicates
  (`nl-signaal-contract-verloopt`, `nl-aanzegtermijn-bewaking`) are unchanged.

## Impact

- `lib/Settings/register.d/hr-bhv.json` — NEW (`BhvCertificering` schema).
- `lib/Settings/hrmq_register.json` — `info.version` bump (new fragment).
- `lib/Standards/rules/labour.json` — 1 new rule under the existing `hr-signals` framework;
  `RuleCatalogue::VERSION` bumps.
- `lib/Standards/Checks/NlSignalChecks.php` — MODIFIED, one new predicate + object-type key, the
  two existing predicates untouched.
- `src/manifest.json` — `BhvCertificeringen`/`BhvCertificeringDetail` pages under
  `VerlofVerzuimGroup`; "Aflopende BHV-certificaten" `object-table` widget on the Dashboard page;
  `npm run check:manifest` passes.
- `lib/Settings/register.d/hr-seed.json` — 2 `BhvCertificering` seeds (1 clean, 1 intended
  violation).
- `tests/Unit/Standards/Checks/NlSignalChecksTest.php` — extended with the new predicate's cases.
- `README.md` — the "no numeric coverage ratio, RI&E-driven adequacy" note and the Asset/AED
  fast-follow pointer.
- Related: the superseded `spec/bhv-organisatie` draft branch is the source material; its roster/
  document-library/IoT-alarm/mobile scope is recorded above as separate future capabilities, not
  silently dropped. `hr-signals` (archived) owns the expiry-alert mechanism this change extends.
  `asset-management` (archived) owns the `Asset` schema a future equipment-inspection change would
  extend.
