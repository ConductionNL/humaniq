---
capability: bhv-organisatie
status: done
built_by: openspec/changes/archive/2026-07-17-bhv-organisatie
---

# bhv-organisatie Specification

**Status**: done
**Scope**: hrmq
**OpenSpec changes**:
- [bhv-organisatie](../../changes/archive/2026-07-17-bhv-organisatie/) _(archived 2026-07-17)_ — `BhvCertificering` schema (plain dated facts: employee, role, obtained/valid-until dates, training provider, optional `OrgUnit` scope), a third `hr-signals` predicate (`nl-bhv-certificaat-verloopt`, extending `NlSignalChecks`), the "Aflopende BHV-certificaten" dashboard widget, the `BhvCertificeringen`/`BhvCertificeringDetail` pages under the existing `Verlof & verzuim` menu group, and two seeded certificates (one clean, one intended violation).

## Purpose

Track who holds a valid BHV-related certification (bedrijfshulpverlening) and
when it expires, and signal an expiring certificate through the **existing
`hr-signals` mechanism** rather than a second alerting mechanism — the same
provider class (`NlSignalChecks`), the same corpus framework (`hr-signals`),
the same "Aflopende ..." dashboard widget shape the expiring-contract signal
already uses. This change deliberately does **not** assert a numeric
BHV-to-employee coverage ratio: Arbeidsomstandighedenwet art. 15 requires a
qualitative, RI&E-driven aanwijzing (accounting for de grootte van het
bedrijf en de aard van de aanwezige risico's), not a fixed number — no
article sets "1 per 50" or any other ratio, so this change gives HR
**visibility** (certified personnel grouped by the existing `OrgUnit`
concept) instead of inventing a formula the law does not contain. It also
does not build a Location/site register, a roster generator, an
evacuation-plan document library, an IoT/webhook alarm system, or a
standalone mobile app — all named fast-follows or out-of-scope capabilities
the superseded 2026-05-23 draft bundled into one ten-feature platform.

## Requirements

### Requirement: A BhvCertificering schema SHALL record BHV-related certifications as plain dated facts (REQ-BHV-001)

`lib/Settings/register.d/hr-bhv.json` SHALL define a `BhvCertificering` schema carrying `employeeId` (`$ref` Employee, required), `rol` (enum `bhv_basis`/`hoofd_bhv`/`ehbo`/`ontruimingsleider`, required), `certificaatBehaaldOp` (date), `certificaatGeldigTot` (date, required — the expiry-alert anchor), `opleider` (string, nullable), `orgUnitId` (`$ref` OrgUnit, nullable), and `administrationId`. The schema SHALL carry no `x-openregister-lifecycle` — a renewal SHALL be recorded as a new `BhvCertificering` object, not a status transition on the existing one.

#### Scenario: A certification record validates
- **GIVEN** the imported hrmq register
- **WHEN** a `BhvCertificering` object is created with `employeeId`, `rol: bhv_basis`, `certificaatBehaaldOp`, and `certificaatGeldigTot`
- **THEN** the object validates

#### Scenario: A renewal is a new record, not a state change
- **GIVEN** an employee whose `bhv_basis` certification is renewed after a herhalingscursus
- **WHEN** the renewal is recorded
- **THEN** a second `BhvCertificering` object is created with a later `certificaatBehaaldOp`/`certificaatGeldigTot`, and the original record is left unmodified

**Verified 2026-07-17**: `lib/Settings/register.d/hr-bhv.json` defines exactly this schema, no lifecycle key present; JSON validated.

### Requirement: Certificate-expiry signalling SHALL extend the existing hr-signals mechanism, not a new one (REQ-BHV-002)

`lib/Standards/Checks/NlSignalChecks.php` SHALL gain a third predicate, `nl-bhv-certificaat-verloopt`, registered under a `BhvCertificering` key in the SAME `checks()` method that already registers `nl-signaal-contract-verloopt` and `nl-aanzegtermijn-bewaking` under `EmploymentContract`. The corresponding corpus row in `lib/Standards/rules/labour.json` SHALL use the EXISTING `hr-signals` framework (not a new framework slug), severity `recommended`, and `parameters: {"windowDays": 90}`. A `BhvCertificering` whose `certificaatGeldigTot` falls within the next 90 days SHALL be reported as an advisory violation; one whose `certificaatGeldigTot` is further out, or has already passed, SHALL pass vacuously (an already-expired certificate is a distinct, more urgent state this MVP does not separately classify — see design.md).

#### Scenario: An expiring certificate within the window is flagged
- **GIVEN** a `BhvCertificering` with `certificaatGeldigTot` 45 days from today
- **WHEN** `occ hrmq:rules:audit` runs
- **THEN** the record is reported with an `nl-bhv-certificaat-verloopt` violation at severity `recommended`

#### Scenario: A certificate outside the window passes
- **GIVEN** a `BhvCertificering` with `certificaatGeldigTot` one year from today
- **WHEN** the audit runs
- **THEN** no `nl-bhv-certificaat-verloopt` violation is reported

#### Scenario: The existing hr-signals predicates are unaffected
- **GIVEN** the seeded `EmploymentContract` fixtures exercising `nl-signaal-contract-verloopt`/`nl-aanzegtermijn-bewaking`
- **WHEN** the audit runs after this change
- **THEN** both predicates report the identical results they reported before this change

**Verified 2026-07-17**: `NlSignalChecks::checks()` registers `nl-bhv-certificaat-verloopt` under a new `BhvCertificering` array key, same file/framework, EmploymentContract keys untouched; `RuleCatalogue::VERSION` bumped `2026-07.26` → `2026-07.27`. `NlSignalChecksTest` (29 methods) and `RuleAuditServiceTest`'s bhv-organisatie block (7 methods) pin window edges, vacuous-pass cases, and the seeded end-to-end audit; full suite green (860 tests / 3182 assertions, `composer check:strict` ALL CHECKS PASSED).

### Requirement: No numeric BHV coverage ratio SHALL be asserted anywhere in the corpus or code (REQ-BHV-003)

This change SHALL NOT add a rule, check, or manifest computation asserting a numeric BHV-to-employee or BHV-to-visitor coverage ratio, because Arbeidsomstandighedenwet art. 15 requires an aanwijzing accounting for de grootte van het bedrijf en de aard van de aanwezige risico's — a qualitative, RI&E-driven standard — and sets no fixed number.

#### Scenario: No coverage-ratio rule exists in the corpus
- **GIVEN** the labour corpus after this change
- **WHEN** it is searched for a rule id matching `dekking`/`coverage`/`ratio`
- **THEN** no such rule exists

#### Scenario: The BHV pages present certified personnel, not a computed adequacy verdict
- **GIVEN** the `BhvCertificeringen` index page
- **WHEN** it is viewed
- **THEN** it lists certified employees grouped/filterable by `orgUnitId` and `rol`, with no red/green coverage-adequacy indicator

**Verified 2026-07-17**: no rule id in `labour.json` matches `dekking`/`coverage`/`ratio` (asserted by `RuleAuditServiceTest::testNoCoverageRatioRuleExistsInTheCorpus`); `BhvCertificeringen`'s columns/filters are plain facts (`employeeId`/`rol`/`certificaatGeldigTot`/`orgUnitId`), no computed adequacy field or badge anywhere in the manifest/corpus/docs diff.

### Requirement: BHV coverage visibility SHALL be scoped by the existing OrgUnit concept, not an invented Location entity (REQ-BHV-004)

The `BhvCertificeringen` index page SHALL support filtering and grouping by `BhvCertificering.orgUnitId` and `rol`. This change SHALL NOT introduce a `Location`/site/vestiging schema.

#### Scenario: Certifications can be viewed grouped by OrgUnit
- **GIVEN** `BhvCertificering` records referencing different `OrgUnit`s
- **WHEN** the `BhvCertificeringen` index page is filtered by a specific `orgUnitId`
- **THEN** only certifications referencing that `OrgUnit` are shown

#### Scenario: No Location schema exists in the register
- **GIVEN** the hrmq register after this change
- **WHEN** its schema list is enumerated
- **THEN** no `Location`, `Vestiging`, or equivalent site schema exists

**Verified 2026-07-17**: `BhvCertificeringen`'s `filters` array lists `orgUnitId`/`rol`; `orgUnitId` is the only scoping `$ref` on `BhvCertificering` (the other is `employeeId`); no `Location`/`Vestiging` schema was added anywhere in `lib/Settings/register.d/`.

### Requirement: BHV pages SHALL live under the existing Verlof & verzuim menu group, never a new top-level menu (REQ-BHV-005)

`src/manifest.json` SHALL expose `BhvCertificeringen` (index) and `BhvCertificeringDetail` (data + related Employee/OrgUnit + audit sidebar) as `SUB_PAGE`/`DETAIL_TAB` placements under the existing `Verlof & verzuim` menu group (`VerlofVerzuimGroup`), and SHALL add the "Aflopende BHV-certificaten" widget to the existing Dashboard page. No new top-level menu SHALL be added.

#### Scenario: BHV pages are reachable under Verlof & verzuim
- **GIVEN** the manifest after this change
- **WHEN** a user navigates to `Verlof & verzuim`
- **THEN** a `BhvCertificeringen` entry is present, and the top-level menu count is unchanged from ADR-001's frozen 9 (8 menus + Configuratie)

#### Scenario: The dashboard widget mirrors the existing contracten-expiry widget shape
- **GIVEN** the Dashboard page after this change
- **WHEN** its widget list is inspected
- **THEN** "Aflopende BHV-certificaten" is an `object-table` widget with the same structural shape (`source`/`filter`/`order`/`limit`) as the existing "Aflopende contracten" widget, filtering `BhvCertificering.certificaatGeldigTot`

**Verified 2026-07-17**: `BhvCertificeringen` added as a child of `VerlofVerzuimGroup` (no new top-level `menu` entry — diff-checked: only `BhvCertificering*`-named ids were added, zero new top-level menu ids); the Dashboard's "Aflopende BHV-certificaten" widget is `object-table` with `source`/`filter`/`order`/`limit`, gridY 18 (directly below "Aflopende contracten" at gridY 13-18, no overlap); `npm run check:manifest` PASS (0 errors, 104 pages).
