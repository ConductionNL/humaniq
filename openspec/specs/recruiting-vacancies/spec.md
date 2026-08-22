---
capability: recruiting-vacancies
status: done
built_by: openspec/changes/archive/2026-07-13-recruiting-ats-basic
---

# recruiting-vacancies Specification

**Status**: done
**Scope**: humaniq
**OpenSpec changes**:
- [recruiting-ats-basic](../../changes/archive/2026-07-13-recruiting-ats-basic/) _(archived 2026-07-13)_ — `Onboarding & ATS` menu group (frozen ADR-001 menu 6, tuple coordinated with the parallel `onboarding-wizard-mvp`), new `Vacancy` schema (fragment hr-ats.json) with declarative concept→gepubliceerd→gesloten lifecycle, Vacancies/VacancyDetail pages, seeded published vacancy (kind: config)

## Purpose

Open the frozen ADR-001 menu-6 `Onboarding & ATS` surface with the vacancy
half of the recruiting MVP: a `Vacancy` schema with a declarative publish
lifecycle (`publiceren` stamps `publishedDate` on the carrying write;
`sluiten` closes; terminal), index/detail pages with the per-vacancy
candidate list via the related panel, and a seeded published vacancy.
Grounded in the 2026-07-12 market deep-research (Spectr
`hrmq-insight-ranked-buildlist`, rank 6): Personio and BambooHR ship an ATS
as a core SMB-suite module; Krip — the only generic Nextcloud HR app — has
none. External multiposting (werk.nl/LinkedIn via OpenConnector) and the
public career page (portaliq per ADR-046) are explicitly out of scope.

## Requirements

### Requirement: A new `Vacancy` schema SHALL model the vacancy with a declarative publish lifecycle (REQ-RCV-001)

A new fragment `lib/Settings/register.d/hr-ats.json` (`x-humaniq-fragment: hr-ats`) SHALL declare `Vacancy` (version 0.1.0, icon `BriefcaseSearchOutline`): `title` (string, required — job title), `description` (string, nullable — vacancy text), `department` (string, nullable), `status` (enum `concept`/`gepubliceerd`/`gesloten`, default `concept`, required — governed by the lifecycle), `publishedDate` (string, format date, nullable — description documents that it is stamped on the carrying write of `publiceren`, the Timesheet `approvedAt` pattern), `closingDate` (string, format date, nullable — application deadline, informational in the MVP). Required: `title`, `status`. `configuration` declares `x-openregister-lifecycle`: `field: status`, `initial: concept`, transitions `publiceren` (concept→gepubliceerd) and `sluiten` (gepubliceerd→gesloten), terminal `gesloten` — no re-open or publish-from-closed edge (a closed vacancy is re-created, not resurrected). Every property carries title + description (gate-28). Register `lib/Settings/humaniq_register.json` `info.version` bumped 0.3.0 → 0.4.0.

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

`src/manifest.json` gains menu group `OnboardingAtsGroup` (label "Onboarding & ATS", icon `AccountPlus`, order 106 — the frozen ADR-001 menu-6 top-level entry, so no ADR amendment is needed; order 106 is the provisional slot between Verlof & verzuim 105 and Onkosten 110, final ordering owned by `humaniq-ia-navigation-alignment`) with children `Vacancies` ("Vacatures", `BriefcaseSearchOutline`) and `Applications` ("Sollicitaties", spec'd in `recruiting-applications`). The group already existed at HEAD (declared by the merged `onboarding-wizard-mvp` with an `Onboardings` child); this change adds `Vacancies` and `Applications` as further children without re-declaring the group's id/label/icon/order — a clean union (design D6). deepLinks for `Vacancy` (`/apps/humaniq/vacancies/{uuid}`) are registered, and `src/icons.js` registers `AccountPlus` (pre-existing) and `BriefcaseSearchOutline`. The manifest validates against app-manifest-v2 (`npm run check:manifest`).

#### Scenario: Manifest stays valid
- **WHEN** `npm run check:manifest` runs
- **THEN** it exits 0

#### Scenario: Single union-merged group
- **WHEN** `src/manifest.json` is inspected after this change
- **THEN** exactly one menu group with id `OnboardingAtsGroup` exists, with label "Onboarding & ATS", icon `AccountPlus`, order 106, and it contains the `Onboardings`, `Vacancies` and `Applications` children

### Requirement: `Vacancies` and `VacancyDetail` SHALL drive exactly the declared lifecycle (REQ-RCV-003)

The manifest SHALL add, under the group: `Vacancies` (index over `Vacancy`, route `/vacancies`: columns `title`, `department`, `status`, `publishedDate`, `closingDate`; filters `status`, `department`; sort `publishedDate` desc) and `VacancyDetail` (detail over `Vacancy`, route `/vacancies/:id`) carrying: a "Vacancy" data widget (all fields), a related widget "Sollicitaties" (incoming `Application.vacancyId` references resolve here — the per-vacancy candidate list), `lifecycleActions` exposing **exactly** `publiceren` ("Publiceren", from `concept`) and `sluiten` ("Sluiten", from `gepubliceerd`) — no invented edges — and an audit-history sidebar tab.

#### Scenario: Detail page walks the publish workflow
@e2e exclude declarative widget wiring is covered by the shared CnPageRenderer library tests; app-level e2e suite does not exist yet (tracked by active change humaniq-test-coverage-baseline)
- **GIVEN** a Vacancy in status `concept` opened on `VacancyDetail`
- **WHEN** the user executes Publiceren
- **THEN** the page reflects status `gepubliceerd` and offers Sluiten

#### Scenario: No invented edges
- **WHEN** the manifest's `lifecycleActions.transitions` for `VacancyDetail` are compared to the `x-openregister-lifecycle` in `lib/Settings/register.d/hr-ats.json`
- **THEN** they match action-for-action (`publiceren`/`sluiten`, same from/to) with no additional action

### Requirement: Seed data SHALL provide one published vacancy (REQ-RCV-004)

`lib/Settings/register.d/hr-seed.json` SHALL gain `vacancy-vue-developer` (title "Medior Vue-developer", department "Engineering", status `gepubliceerd`, publishedDate `2026-06-15`, closingDate `2026-08-31`, placeholder description) — the anchor object the three seeded applications (spec'd in `recruiting-applications`) reference via `vacancyId`. The seed is an obvious placeholder (no real vacancy content) and imports idempotently via the register Repair step.

#### Scenario: Idempotent seed
- **WHEN** the register Repair import runs twice
- **THEN** the vacancy exists exactly once
