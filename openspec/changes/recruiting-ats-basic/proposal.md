---
kind: config
---

# Recruiting ATS Basic (vacatures + sollicitaties-pipeline + AVG-bewaartermijnen)

## Why

The 2026-07-12 market deep-research (logged in Spectr, insight `hrmq-insight-ranked-buildlist`, rank 6) put a basic recruiting/ATS module in hrmq's top build list: Personio and BambooHR both ship an ATS as a core module of their SMB HR suites, while Krip — the only generic Nextcloud HR app — has no recruiting surface at all (round-1 competitor research). For an MKB employer today the sollicitatie-inbox is a shared mailbox plus an Excel sheet, and rejected CVs are retained for years without consent — a concrete AVG exposure: the Autoriteit Persoonsgegevens sollicitatie-richtlijn says rejected candidates' data must be deleted at the latest 4 weeks after the end of the procedure, extendable to at most 1 year only with the candidate's consent. hrmq has the frozen ADR-001 menu-6 slot (`Onboarding & ATS`) reserved for exactly this surface, an OpenRegister register that models workflow objects declaratively, and a versioned rule corpus + RuleEngine that can make the bewaartermijn machine-checkable. This change ships the MVP: vacancies with a publish lifecycle, an application pipeline on a declarative state machine, and AVG-retention rules that surface violations via the existing audit.

## What Changes

- **`Onboarding & ATS` menu group** — the frozen ADR-001 menu-6 top-level entry (icon `account-plus`) appears in `src/manifest.json`, carrying all pages below. No new top-level menu is invented — this IS the ADR-001 placement. The parallel `onboarding-wizard-mvp` change declares the **same** group (identical id/label/icon/order) so the merge is a clean union; see design D6.
- **New `Vacancy` schema** in a new fragment `lib/Settings/register.d/hr-ats.json` — title, description, department, `publishedDate`, `closingDate`, and a declarative `x-openregister-lifecycle` `concept → gepubliceerd → gesloten` (`publiceren` stamps `publishedDate` on the carrying write; `sluiten` closes).
- **New `Application` schema** in the same fragment — candidate PII lives **inside** the Application (candidateName, email, phone, cvFile, motivation); there is deliberately **no separate Candidate entity** in the MVP (AVG data-minimisation: one object = one retention clock = one delete; design D2). Declarative pipeline lifecycle `nieuw → screening → gesprek → aanbod → aangenomen/afgewezen` with `afwijzen` reachable from every active stage; `rejectedDate` and `retentionExpiryDate` are stamped on the carrying write of `afwijzen`. `talentPoolOptIn` (boolean, default false) records explicit candidate consent for extended retention.
- **Two new machine-checkable AVG-retention rules** in a new corpus file `lib/Standards/rules/privacy.json` + a new check provider `lib/Standards/Checks/NlAtsChecks.php`: `nl-ats-retentie-derivatie` (`retentionExpiryDate` = `rejectedDate` + 4 weeks, or + 1 year with opt-in — Autoriteit Persoonsgegevens sollicitatie-richtlijn) and `nl-ats-retentie-verlopen` (an Application past its `retentionExpiryDate` that still exists un-anonymised is a mandatory violation). Violations surface via the existing `occ hrmq:rules:audit`.
- **ATS pages** — `Vacancies` (index), `VacancyDetail` (data + lifecycleActions Publiceren/Sluiten + related applications), `Applications` (index, pre-filterable by pipeline status — the kanban board is explicitly a follow-up, design D7), `ApplicationDetail` (data + privacy/retention widget + lifecycleActions + CV files widget + related), plus deepLinks for `Vacancy` and `Application`.
- **Seed data** — 1 published Vacancy + 3 Applications (one `nieuw`, one `afgewezen` past its retention date → exercises the mandatory violation, one `aangenomen`), placeholder PII only (Jan Voorbeeld, `voorbeeld@example.org`, nil UUIDs).

### Non-goals

- **No werk.nl/LinkedIn multiposting** — external channel publication is an OpenConnector integration follow-up; the MVP `publiceren` transition marks the vacancy published inside hrmq only.
- **No public career page** — an external-audience surface for people without Nextcloud accounts belongs to portaliq per ADR-046 (the exact precedent of the shipped `portal-contribution` capability); noted as a portal-contribution follow-up. Applications are entered by HR in the MVP.
- **No kanban pipeline board** — a drag-and-drop board is a shared nc-vue widget follow-up (Vue logic lives in nc-vue); the MVP is the status-filterable `Applications` index.
- **No calendar integration** — interview scheduling with Nextcloud Calendar events/iCal is a follow-up; no Interview entity in the MVP.
- **No offer generation / e-signature** — PDF offers and digital signing are a docudesk/decidesk leaf-app follow-up.
- **No automatic Employee creation on hire** — the onboarding hand-off is documented as a manual action on the `aannemen` transition in the MVP (cross-object write hooks are the same follow-up class as leave-balance auto-posting).
- **No automatic deletion/anonymisation job** — retention is enforced as an audit violation (`nl-ats-retentie-verlopen`), consistent with the corpus' audit-only posture; guard/job wiring is owned by `hrmq-rule-compliance-enforcement` and a retention-job follow-up.

## Capabilities

### New Capabilities

- `recruiting-vacancies`: the `Onboarding & ATS` menu group (frozen ADR-001 menu 6, coordinated with the parallel `onboarding-wizard-mvp`), the `Vacancy` schema with its declarative concept→gepubliceerd→gesloten lifecycle, the `Vacancies`/`VacancyDetail` pages, and the seeded published vacancy.
- `recruiting-applications`: the `Application` schema (candidate PII inside the application, no Candidate entity) with the declarative pipeline lifecycle, the two machine-checkable AVG-retention rules with the `NlAtsChecks` provider, the `Applications`/`ApplicationDetail` pages, and the three seeded applications.

### Modified Capabilities

<!-- none — existing specs (leave-management, verzuim-wvp, hrmq-expenses, hrmq-timesheet-approval, portal-*, payroll specs) are untouched; this change only ADDS a new fragment, corpus file, provider, and pages. -->

## Impact

- `lib/Settings/register.d/hr-ats.json` — **new fragment**, `Vacancy` + `Application` schemas (both 0.1.0) with `x-openregister-lifecycle`.
- `lib/Settings/hrmq_register.json` — `info.version` 0.3.0 → 0.4.0 (version-gated re-import).
- `lib/Standards/rules/privacy.json` — **new corpus file** (AVG/privacy sub-domain; labour.json is labour law, payroll.json is tax/reporting), 2 rules; `RuleCatalogue::VERSION` 2026-07 → 2026-07.1.
- `lib/Standards/Checks/NlAtsChecks.php` — new check provider (auto-discovered by `RuleEngine::providers()`).
- `src/manifest.json` — `OnboardingAtsGroup` menu group; pages `Vacancies`, `VacancyDetail`, `Applications`, `ApplicationDetail`; deepLinks for `Vacancy` and `Application`.
- `src/icons.js` — register `AccountPlus` (group; the parallel change registers the same line — clean union), `BriefcaseSearchOutline`, `FileAccountOutline`.
- `lib/Settings/register.d/hr-seed.json` — seed Vacancy + Applications.
- `lib/Repair/InitializeRegister.php` — no change (fragment import picks up the new fragment + version bumps).
- Related: `onboarding-wizard-mvp` (parallel change; shares the menu group — design D6), `hrmq-ia-navigation-alignment` owns final IA ordering, `hrmq-rule-compliance-enforcement` owns guard wiring (rule checks stay audit-only here, per the loonaangifte and leave-verzuim precedents), `portal-contribution` is the ADR-046 home for a future public career surface.
