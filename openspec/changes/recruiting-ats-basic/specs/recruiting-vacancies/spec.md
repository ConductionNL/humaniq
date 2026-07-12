# Spec: recruiting-vacancies

**Status:** in-progress
**Scope:** hrmq
**Kind:** config (declarative schema + manifest + seed data)

**OpenSpec changes**
- `recruiting-ats-basic` (2026-07-12)

## Purpose

Open the frozen ADR-001 menu-6 `Onboarding & ATS` surface with the vacancy half of the recruiting MVP: a new `Vacancy` schema in a new `hr-ats.json` fragment with a declarative `concept → gepubliceerd → gesloten` publish lifecycle, the `Vacancies` index and `VacancyDetail` pages (lifecycle actions Publiceren/Sluiten plus the related-applications panel), and a seeded published vacancy. The menu group is declared with the exact tuple shared with the parallel `onboarding-wizard-mvp` change so the build-time merge is a clean union (design D6). Grounded in Spectr `hrmq-insight-ranked-buildlist` rank 6 and round-1 competitor research (Personio/BambooHR ship ATS as a core SMB module; Krip has none). External multiposting (werk.nl/LinkedIn) and the public career page are explicitly out of scope.

## ADDED Requirements

### Requirement: A new `Vacancy` schema SHALL model the vacancy with a declarative publish lifecycle (REQ-RCV-001)

A new fragment `lib/Settings/register.d/hr-ats.json` (`x-hrmq-fragment: hr-ats`) SHALL declare `Vacancy` (version 0.1.0, icon `BriefcaseSearchOutline`): `title` (string, required — job title), `description` (string, nullable — vacancy text), `department` (string, nullable), `status` (enum `concept`/`gepubliceerd`/`gesloten`, default `concept`, required — governed by the lifecycle), `publishedDate` (string, format date, nullable — description documents that it is stamped on the carrying write of `publiceren`, the Timesheet `approvedAt` pattern), `closingDate` (string, format date, nullable — application deadline, informational in the MVP). Required: `title`, `status`. `configuration` declares `x-openregister-lifecycle`: `field: status`, `initial: concept`, transitions `publiceren` (concept→gepubliceerd) and `sluiten` (gepubliceerd→gesloten), terminal `gesloten` — no re-open or publish-from-closed edge (a closed vacancy is re-created, not resurrected). Every property carries title + description (gate-28). Register `lib/Settings/hrmq_register.json` `info.version` bumps 0.3.0 → 0.4.0.

#### Scenario: Vacancy walks publish and close
- **GIVEN** a Vacancy created with `title: "Medior Vue-developer"` (status defaults to `concept`)
- **WHEN** `publiceren` is executed via the OpenRegister lifecycle endpoint with the carrying write setting `publishedDate`
- **THEN** the object's `status` is `gepubliceerd`; **AND WHEN** `sluiten` is executed **THEN** `status` is `gesloten` and no further transition is offered (terminal)

#### Scenario: Illegal transition rejected
- **GIVEN** a Vacancy in status `concept`
- **WHEN** the `sluiten` action is attempted
- **THEN** OpenRegister rejects the transition (`sluiten` is only declared from `gepubliceerd`)

#### Scenario: Incomplete vacancy rejected
- **WHEN** a Vacancy is written without `title`
- **THEN** OpenRegister schema validation rejects it (required-property violation)

### Requirement: The manifest SHALL add the `Onboarding & ATS` menu group with the exact coordination tuple (REQ-RCV-002)

`src/manifest.json` gains menu group `OnboardingAtsGroup` (label "Onboarding & ATS", icon `AccountPlus`, order 106 — the frozen ADR-001 menu-6 top-level entry, so no ADR amendment is needed; order 106 is the provisional slot between Verlof & verzuim 105 and Onkosten 110, final ordering owned by `hrmq-ia-navigation-alignment`) with children `Vacancies` ("Vacatures", `BriefcaseSearchOutline`) and `Applications` ("Sollicitaties", spec'd in `recruiting-applications`). The group tuple (id/label/icon/order) MUST be byte-identical to the one declared by the parallel `onboarding-wizard-mvp` change so the merge is a clean union of children under one group (design D6); if the group already exists when this change merges, only the children are added — the group is NOT re-declared with different values. deepLinks for `Vacancy` (`/apps/hrmq/vacancies/{uuid}`) are registered, and `src/icons.js` registers `AccountPlus` and `BriefcaseSearchOutline` (unregistered names fall back to a help-circle; the parallel change registers `AccountPlus` too — the identical import line unions cleanly). The manifest MUST validate against app-manifest-v2 (`npm run check:manifest`).

#### Scenario: Manifest stays valid
- **WHEN** `npm run check:manifest` runs
- **THEN** it exits 0

#### Scenario: Single union-merged group
- **WHEN** `src/manifest.json` is inspected after this change (and, once merged, after `onboarding-wizard-mvp`)
- **THEN** exactly one menu group with id `OnboardingAtsGroup` exists, with label "Onboarding & ATS", icon `AccountPlus`, order 106, and it contains the `Vacancies` and `Applications` children contributed by this change

### Requirement: `Vacancies` and `VacancyDetail` SHALL drive exactly the declared lifecycle (REQ-RCV-003)

The manifest SHALL add, under the group: `Vacancies` (index over `Vacancy`, route `/vacancies`: columns `title`, `department`, `status`, `publishedDate`, `closingDate`; filters `status`, `department`; sort `publishedDate` desc) and `VacancyDetail` (detail over `Vacancy`, route `/vacancies/:id`) carrying: a "Vacancy" data widget (all fields), a related widget "Sollicitaties" (incoming `Application.vacancyId` references resolve here — the per-vacancy candidate list), `lifecycleActions` exposing **exactly** `publiceren` ("Publiceren", from `concept`) and `sluiten` ("Sluiten", from `gepubliceerd`) — no invented edges — and an audit-history sidebar tab.

#### Scenario: Detail page walks the publish workflow
@e2e exclude declarative widget wiring is covered by the shared CnPageRenderer library tests; app-level e2e suite does not exist yet (tracked by active change hrmq-test-coverage-baseline)
- **GIVEN** a Vacancy in status `concept` opened on `VacancyDetail`
- **WHEN** the user executes Publiceren
- **THEN** the page reflects status `gepubliceerd` and offers Sluiten

#### Scenario: No invented edges
- **WHEN** the manifest's `lifecycleActions.transitions` for `VacancyDetail` are compared to the `x-openregister-lifecycle` in `lib/Settings/register.d/hr-ats.json`
- **THEN** they match action-for-action (`publiceren`/`sluiten`, same from/to) with no additional action

### Requirement: Seed data SHALL provide one published vacancy (REQ-RCV-004)

`lib/Settings/register.d/hr-seed.json` SHALL gain `vacancy-vue-developer` (title "Medior Vue-developer", department "Engineering", status `gepubliceerd`, publishedDate `2026-06-15`, closingDate `2026-08-31`, placeholder description) — the anchor object the three seeded applications (spec'd in `recruiting-applications`) reference via `vacancyId`. The seed MUST stay an obvious placeholder (no real vacancy content) and MUST import idempotently via the register Repair step.

#### Scenario: Idempotent seed
- **WHEN** the register Repair import runs twice
- **THEN** the vacancy exists exactly once
