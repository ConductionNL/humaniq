---
capability: recruiting-vacancies
status: in-progress
built_by: openspec/changes/recruiting-ats-basic
---

# recruiting-vacancies Specification

**Status**: in-progress
**Scope**: hrmq
**OpenSpec changes**:
- [recruiting-ats-basic](../../changes/recruiting-ats-basic/) _(active)_ — `Onboarding & ATS` menu group (frozen ADR-001 menu 6, tuple coordinated with the parallel `onboarding-wizard-mvp`), new `Vacancy` schema (fragment hr-ats.json) with declarative concept→gepubliceerd→gesloten lifecycle, Vacancies/VacancyDetail pages, seeded published vacancy (kind: config)

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

Detailed requirements (REQ-RCV-001 … REQ-RCV-004) are defined in the active
change's delta spec —
[`openspec/changes/recruiting-ats-basic/specs/recruiting-vacancies/spec.md`](../../changes/recruiting-ats-basic/specs/recruiting-vacancies/spec.md)
— and are merged here when the change is archived. The umbrella requirement
below anchors the capability until then.

### Requirement: Vacancies are declarative OpenRegister objects under the ADR-001 menu-6 surface (REQ-RCV-000)

The vacancy surface MUST consist solely of the `Vacancy` schema in
`lib/Settings/register.d/hr-ats.json` (declarative
`x-openregister-lifecycle` concept→gepubliceerd→gesloten) plus manifest
pages under the `OnboardingAtsGroup` menu group — no app-owned tables,
services, controllers, or external publication connectors.

#### Scenario: Vacancy surface is schema + manifest only

- GIVEN the hrmq codebase at HEAD after this change
- WHEN the vacancy feature is inspected
- THEN it consists of the `Vacancy` schema in `hr-ats.json` and the `Vacancies`/`VacancyDetail` manifest pages, with no vacancy-specific PHP service/controller and no werk.nl/LinkedIn integration code
- @e2e exclude declarative register + manifest configuration with no bespoke UI code; rendering is covered by the shared CnPageRenderer library tests, app-level e2e suite tracked by hrmq-test-coverage-baseline
