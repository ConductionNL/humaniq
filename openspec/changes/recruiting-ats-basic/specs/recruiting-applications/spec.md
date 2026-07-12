# Spec: recruiting-applications

**Status:** in-progress
**Scope:** hrmq
**Kind:** config (declarative schema + manifest + corpus data; check methods are the app's established rule-corpus exception)

**OpenSpec changes**
- `recruiting-ats-basic` (2026-07-12)

## Purpose

The application half of the recruiting MVP: a new `Application` schema (in `hr-ats.json`) that keeps candidate PII **inside** the application object — no separate Candidate entity, per the AVG data-minimisation decision (design D2: one object = one retention clock = one delete) — with a declarative pipeline lifecycle `nieuw → screening → gesprek → aanbod → aangenomen/afgewezen`, a stored-but-rule-checked `retentionExpiryDate` implementing the Autoriteit Persoonsgegevens sollicitatie-richtlijn (delete at the latest 4 weeks after rejection; at most 1 year with explicit talent-pool consent), two machine-checkable AVG rules in a new `privacy.json` corpus file with a `NlAtsChecks` provider, the `Applications`/`ApplicationDetail` pages, and seed data that exercises the mandatory expired-retention violation. The kanban board, candidate-facing career page, interviews, offers and automatic hire hand-off are explicitly out of scope.

## ADDED Requirements

### Requirement: A new `Application` schema SHALL carry the candidate PII and a declarative pipeline lifecycle (REQ-RCA-001)

`lib/Settings/register.d/hr-ats.json` SHALL declare `Application` (version 0.1.0, icon `FileAccountOutline`): `vacancyId` (string, format uuid, `$ref` Vacancy, required), `candidateName` (string, required), `email` (string, format email, required), `phone` (string, nullable), `cvFile` (string, nullable — reference (Nextcloud Files path or OpenRegister file id) to the uploaded CV, the `Expense.receiptFile` pattern), `motivation` (string, nullable — free-text motivation), `status` (enum `nieuw`/`screening`/`gesprek`/`aanbod`/`aangenomen`/`afgewezen`, default `nieuw`, required), `rejectedDate` (string, format date, nullable — description documents that it is stamped on the carrying write of `afwijzen`), `talentPoolOptIn` (boolean, default false — description documents explicit candidate consent to extended talent-pool retention, AP maximum 1 year), `retentionExpiryDate` (string, format date, nullable — description cites the AP 4-weken richtlijn and the stored-but-rule-checked decision, design D4). Required: `vacancyId`, `candidateName`, `email`, `status`. The schema description states that candidate PII lives on this object and deleting the application deletes the candidate's data (design D2); **no `Candidate` schema exists anywhere in the register**. `configuration` declares `x-openregister-lifecycle`: `field: status`, `initial: nieuw`, transitions `screenen` (nieuw→screening), `uitnodigen` (screening→gesprek), `aanbieden` (gesprek→aanbod), `aannemen` (aanbod→aangenomen; description documents the MANUAL onboarding hand-off — Employee creation from the application data is a manual follow-up action in the MVP) and `afwijzen` (nieuw|screening|gesprek|aanbod→afgewezen; description documents that `rejectedDate` and `retentionExpiryDate` are stamped on the carrying write), terminal `aangenomen` and `afgewezen`. Every property carries title + description (gate-28).

#### Scenario: Application walks the pipeline to hire
- **GIVEN** an Application created against the seeded vacancy (status defaults to `nieuw`)
- **WHEN** `screenen`, `uitnodigen`, `aanbieden` and `aannemen` are executed in order via the OpenRegister lifecycle endpoint
- **THEN** the object's `status` is `aangenomen` and no further transition is offered (terminal)

#### Scenario: Rejection stamps the retention clock
- **GIVEN** an Application in status `screening` with `talentPoolOptIn: false`
- **WHEN** `afwijzen` is executed with the carrying write setting `rejectedDate` and `retentionExpiryDate` (= rejectedDate + 28 days)
- **THEN** the object's `status` is `afgewezen` and both dates are persisted

#### Scenario: Illegal transition rejected
- **GIVEN** an Application in status `nieuw`
- **WHEN** the `aannemen` action is attempted
- **THEN** OpenRegister rejects the transition (`aannemen` is only declared from `aanbod`)

#### Scenario: No separate Candidate entity
- **WHEN** the schemas of the hrmq register fragments in `lib/Settings/register.d/` are enumerated
- **THEN** no `Candidate` (or equivalent standalone candidate-PII) schema exists — the candidate's name, email, phone, CV reference and motivation are properties of `Application` only

### Requirement: The rule corpus SHALL gain two machine-checkable AVG-retention rules in a new `privacy.json` (REQ-RCA-002)

A new corpus file `lib/Standards/rules/privacy.json` (`{"domain": "privacy", "version": "2026-07", "rules": [...]}` — payroll.json is tax/reporting, labour.json is labour law; sollicitatie-bewaartermijnen are privacy law, and SCHEMA.md prescribes one file per sub-domain) SHALL gain `nl-ats-retentie-derivatie` (a rejected Application carries `rejectedDate` and `retentionExpiryDate = rejectedDate + 4 weken` without talent-pool consent or `+ 1 jaar` with `talentPoolOptIn: true`; on `afgewezen` neither field may be null; carries `parameters.retentionDays: 28` and `parameters.optInRetentionDays: 365` so the offsets are rule data, not PHP constants) and `nl-ats-retentie-verlopen` (an Application whose `retentionExpiryDate` lies in the past must no longer exist un-anonymised in the register; one still present at audit time is a violation). Both: `domain: gdpr-recruitment`, `jurisdiction: NL`, `framework: gdpr`, `severity: mandatory`, `machineCheckable: true`, `effectiveDate: 2018-05-25`, `source` citing AVG art. 5 lid 1 sub e (opslagbeperking), the Autoriteit Persoonsgegevens richtlijn sollicitatiegegevens and the UAVG (`https://wetten.overheid.nl/BWBR0040940`), `sourceUrl: https://autoriteitpersoonsgegevens.nl/themas/werk-en-uitkering/sollicitaties` (verified live 2026-07-12). `RuleCatalogue::VERSION` bumps to `2026-07.1`.

#### Scenario: Corpus stays loadable and versioned
- **WHEN** `occ hrmq:rules:audit` runs after the corpus edit
- **THEN** the RuleCatalogue loads payroll.json, labour.json AND privacy.json without error and reports the two new rules as enforced (each has a CheckProvider predicate)

### Requirement: `NlAtsChecks` SHALL enforce derivation and expiry as single-object predicates (REQ-RCA-003)

A new auto-discovered provider `lib/Standards/Checks/NlAtsChecks.php` (implements `CheckProvider`) SHALL register, under object type `Application`:
1. **Retentie-derivatie** — evaluated only when `status` is `afgewezen`: violation when `rejectedDate` or `retentionExpiryDate` is null, or when `retentionExpiryDate` differs from `rejectedDate` plus the rule-parameterised offset (28 days without opt-in, 365 days with `talentPoolOptIn: true` — offsets read from the rule's `parameters`, not hard-coded). All other statuses pass vacuously (nothing to derive).
2. **Retentie-verlopen** — evaluated against the audit run date (the `nl-loonaangifte-deadline-alert` pattern): violation when a non-null `retentionExpiryDate` lies in the past — the object should have been deleted or anonymised.

Each predicate is side-effect free and keyed by its corpus rule id.

#### Scenario: Expired rejected application flagged
- **GIVEN** the seed application `application-voorbeeld-afgewezen` (`rejectedDate: 2026-06-01`, `talentPoolOptIn: false`, `retentionExpiryDate: 2026-06-29`)
- **WHEN** `occ hrmq:rules:audit` runs on any date after 2026-06-29
- **THEN** a `nl-ats-retentie-verlopen` violation with mandatory severity is reported for that object

#### Scenario: Correct derivation passes
- **GIVEN** the same seed application (expiry exactly rejectedDate + 28 days)
- **WHEN** the audit runs
- **THEN** no `nl-ats-retentie-derivatie` violation is reported for that object

#### Scenario: Wrong derivation flagged
- **GIVEN** an Application with `status: afgewezen`, `rejectedDate: 2026-06-01`, `talentPoolOptIn: false` and `retentionExpiryDate: 2026-07-15`
- **WHEN** the audit runs
- **THEN** a `nl-ats-retentie-derivatie` violation is reported (expected 2026-06-29)

#### Scenario: Talent-pool consent extends retention to one year
- **GIVEN** an Application with `status: afgewezen`, `rejectedDate: 2026-06-01`, `talentPoolOptIn: true` and `retentionExpiryDate: 2027-06-01`
- **WHEN** the audit runs on 2026-07-12
- **THEN** no ATS-rule violation is reported for that object (derivation matches the 365-day opt-in offset; expiry lies in the future)

#### Scenario: Active application passes vacuously
- **GIVEN** the seed application `application-voorbeeld-nieuw` (status `nieuw`, no retention fields)
- **WHEN** the audit runs
- **THEN** no ATS-rule violation is reported for that object

### Requirement: The application pages SHALL surface the pipeline, the retention clock, and the CV under `Onboarding & ATS` (REQ-RCA-004)

`src/manifest.json` gains, under the `OnboardingAtsGroup` menu group (REQ-RCV-002): `Applications` (index over `Application`, route `/applications`: columns `candidateName`, `vacancyId`, `status`, `talentPoolOptIn`, `retentionExpiryDate`; filters `status` — the MVP pipeline surface: each stage is one filter value, the kanban board is a deferred nc-vue widget follow-up (design D7); sort `candidateName` asc — submission time is OpenRegister object metadata, no fake recency column (design D8)) and `ApplicationDetail` (detail, route `/applications/:id`: an "Application" data widget excluding `vacancyId` and the three privacy fields, a "Privacy & retention" data widget with `talentPoolOptIn`/`rejectedDate`/`retentionExpiryDate`, a related widget, a files widget "CV & motivatiebrief", `lifecycleActions` exposing exactly `screenen`/`uitnodigen`/`aanbieden`/`aannemen`/`afwijzen` with Dutch labels, an audit-history sidebar tab, and a page `_note` documenting the PII-inside-Application decision and the manual onboarding hand-off on `aannemen`). An `Application` deepLink (`/apps/hrmq/applications/{uuid}`) is registered, and `src/icons.js` registers `FileAccountOutline`. The manifest MUST validate (`npm run check:manifest`).

#### Scenario: Manifest stays valid
- **WHEN** `npm run check:manifest` runs
- **THEN** it exits 0

#### Scenario: Detail page drives the pipeline
@e2e exclude declarative widget wiring is covered by the shared CnPageRenderer library tests; app-level e2e suite does not exist yet (tracked by active change hrmq-test-coverage-baseline)
- **GIVEN** an Application in status `nieuw` opened on `ApplicationDetail`
- **WHEN** the user executes Screenen
- **THEN** the page reflects status `screening` and offers Uitnodigen and Afwijzen

#### Scenario: No invented edges
- **WHEN** the manifest's `lifecycleActions.transitions` for `ApplicationDetail` are compared to the `x-openregister-lifecycle` in `lib/Settings/register.d/hr-ats.json`
- **THEN** they match action-for-action (`screenen`/`uitnodigen`/`aanbieden`/`aannemen`/`afwijzen`, same from/to) with no additional action

### Requirement: Seed data SHALL exercise a fresh application, an expired rejection, and a hire (REQ-RCA-005)

`lib/Settings/register.d/hr-seed.json` SHALL gain the three Application objects from design.md, all referencing `vacancy-vue-developer` and carrying placeholder PII only — the seeds MUST stay obvious placeholders (Voorbeeld names, `example.org` addresses, nil-UUID file refs): `application-voorbeeld-nieuw` (Jan Voorbeeld, `voorbeeld@example.org`, status `nieuw`, cvFile nil UUID), `application-voorbeeld-afgewezen` (status `afgewezen`, rejectedDate `2026-06-01`, retentionExpiryDate `2026-06-29` — exact +28-day derivation, past the reference audit date → the mandatory violation), `application-voorbeeld-aangenomen` (status `aangenomen`, no retention fields).

#### Scenario: Idempotent seed
- **WHEN** the register Repair import runs twice
- **THEN** the three applications exist exactly once

#### Scenario: Seeded audit shows exactly the intended ATS violations
- **GIVEN** a fresh import of the seed data
- **WHEN** `occ hrmq:rules:audit` runs on 2026-07-12
- **THEN** the ATS violations are exactly: one mandatory `nl-ats-retentie-verlopen` for `application-voorbeeld-afgewezen`, and no `nl-ats-retentie-derivatie` violations anywhere
