# Design — recruiting-ats-basic

## Context

hrmq has no recruiting surface: no vacancy or application object anywhere in the register, and ADR-001's frozen menu 6 (`Onboarding & ATS`, icon `account-plus` — "vacatures, kandidaten, offers, onboardings") is still empty. A round-1 draft of this change exists on `origin/spec/recruiting-ats-basic`, written before the declarative platform landed: it plans six bespoke database tables (`vacancies`, `applications`, `application_events`, `interviews`, `offer_letters`, `offer_letter_templates`), seven PHP service classes and a REST controller layer — exactly the architecture ADR-022/ADR-031 and this repo's `openspec/config.yaml` rules forbid (hrmq owns no tables; objects live in the OpenRegister `hrmq` register; workflow is `x-openregister-lifecycle`). This change re-scopes that draft onto the platform, and cuts it to an MVP.

Market grounding (Spectr `hrmq-insight-ranked-buildlist`, rank 6; round-1 competitor research 2026-07-12): Personio and BambooHR ship an ATS as a core SMB-suite module; Krip — the only generic Nextcloud HR app — has none. Legal grounding: the Autoriteit Persoonsgegevens sollicitatie-richtlijn (AVG art. 5 lid 1 sub e, opslagbeperking) — a rejected candidate's data is deleted at the latest **4 weeks** after the end of the sollicitatieprocedure; with explicit consent retention may be extended to at most **1 year** (e.g. a talent pool). Source URL verified live 2026-07-12: `https://autoriteitpersoonsgegevens.nl/themas/werk-en-uitkering/sollicitaties` (page carries the 4-weken and 1-jaar guidance verbatim); secondary anchor UAVG `https://wetten.overheid.nl/BWBR0040940`.

Verified at HEAD before designing:
- Fragment format: `lib/Settings/register.d/*.json` with `x-hrmq-fragment` + `components.schemas`; lifecycle lives in each schema's `configuration` under `x-openregister-lifecycle` (`field`/`initial`/`terminal`/`transitions`), see `hr-verzuim.json` `SickLeaveCase`.
- Carrying-write convention: `SickLeaveCase.recoveredDate` and `Timesheet.approvedAt/approvedBy` are stamped/cleared by the write that executes the transition — the pattern reused here for `publishedDate`, `rejectedDate`, `retentionExpiryDate`.
- `RuleCatalogue` glob-loads `lib/Standards/rules/*.json` (no registration needed); `RuleCatalogue::VERSION` is `2026-07` and must bump on any corpus change; `RuleEngine` predicates are strictly single-object (`fn(array $object, array $context): bool`, `$context = {jurisdiction}` only), and date-relative checks evaluate against the audit run date (`nl-loonaangifte-deadline-alert`, `nl-wvp-milestone-overdue` precedents).
- Register `lib/Settings/hrmq_register.json` `info.version` is `0.3.0`; manifest menu orders in use: 10/20/90/100/105/110/120.

## Goals / Non-Goals

**Goals:** ship the ADR-001 menu-6 surface with working vacancy + application pages on declarative lifecycles; make the AVG sollicitatie-bewaartermijn a stored, machine-checkable fact with two versioned corpus rules and a check provider; seed data that exercises the mandatory retention violation.

**Non-Goals:** werk.nl/LinkedIn multiposting (OpenConnector follow-up), public career page (portaliq per ADR-046 — portal-contribution follow-up), kanban board (nc-vue widget follow-up), calendar-integrated interviews, offer generation/e-signature (docudesk/decidesk leaf follow-up), automatic Employee creation on hire (manual hand-off in MVP), automatic deletion/anonymisation job (audit-only enforcement; job/guard wiring follows `hrmq-rule-compliance-enforcement`), interview/offer/event entities of the round-1 draft.

## Decisions

### D1 — OpenRegister schemas, not the draft's six tables

The round-1 draft's `vacancies`/`applications`/`application_events`/`interviews`/`offer_letters`/`offer_letter_templates` migrations and service/controller layer are dropped wholesale. `Vacancy` and `Application` are OpenRegister schemas in a new fragment `hr-ats.json`; the frontend drives them through the manifest renderer and the objects API (ADR-022 — no app-owned CRUD). The draft's `ApplicationEvent` append-only log is not re-modelled at all: OpenRegister's audit trail already records every write, surfaced by the standard audit-history sidebar tab. Interviews and offer letters are simply out of scope (non-goals) — not deferred entities with half-built schemas.

### D2 — Candidate PII stays inside `Application`; no `Candidate` entity in the MVP

The draft (and enterprise ATSes) split Candidate from Application. The MVP deliberately does not:

- **AVG data-minimisation**: one object carries exactly the PII needed for one procedure, with **one retention clock** (`retentionExpiryDate`) and **one deletion** (`DELETE` on the object removes name, email, phone, CV ref and motivation atomically). A shared Candidate entity would keep PII alive after an application is deleted and force cross-object retention reasoning the single-object RuleEngine cannot audit.
- **Fewer schemas**: no dedupe/merge UI, no orphan-candidate cleanup, no second detail page.
- **Talent pool = a flag, not a pool**: `talentPoolOptIn` (explicit consent) extends the retention derivation to 1 year; a searchable pool over consented applications is the status-filtered index. A first-class Candidate/pool entity is the natural follow-up if proactive-sourcing features land.

Trade-off: a repeat applicant duplicates name/email across Applications. Acceptable at MKB volume (5–50 vacancies/year); dedupe belongs to the future talent-pool follow-up.

### D3 — Both lifecycles are declarative; date stamps ride the carrying write

`Vacancy`: `x-openregister-lifecycle` on `status`, initial `concept`, transitions `publiceren` (concept→gepubliceerd; `publishedDate` stamped on the carrying write) and `sluiten` (gepubliceerd→gesloten), terminal `gesloten`. No re-open and no publish-from-closed edge — the manifest must never claim transitions the schema does not declare (`PayrollRunDetail` `_note` precedent); a closed vacancy is re-created, not resurrected.

`Application`: initial `nieuw`, transitions `screenen` (nieuw→screening), `uitnodigen` (screening→gesprek), `aanbieden` (gesprek→aanbod), `aannemen` (aanbod→aangenomen), `afwijzen` (nieuw|screening|gesprek|aanbod→afgewezen); terminal `aangenomen`, `afgewezen`. `afwijzen`'s carrying write stamps `rejectedDate` and `retentionExpiryDate` (per D4). `aannemen`'s transition description documents the **manual** onboarding hand-off (create the Employee from the application data by hand) — no cross-object write hook in the MVP, the same deferral class as leave-balance auto-posting. No withdraw-by-candidate edge: without a public career page there is no candidate actor in the MVP; HR records a withdrawal as `afwijzen`.

### D4 — `retentionExpiryDate` is derived, stored, and rule-checked (loonaangifte/WVP precedent)

The retention deadline is a pure function of `rejectedDate` + `talentPoolOptIn`, yet it is a stored field — the exact decision `loonaangifte-filing-lifecycle` made for `deadline` and `leave-verzuim-mvp` made for the WVP milestone Due dates, for the same two reasons: (a) **audit trail** — the dossier must show which clock HR was actually working against; (b) **editability** — the AP norm anchors on the *end of the sollicitatieprocedure*, which for a candidate rejected early can legitimately be later than their `rejectedDate` (the procedure continued for others); the stored date may then diverge, and a deviation is a *violation to be explained* under `nl-ats-retentie-derivatie`, not silently recomputed away. Derivation: `rejectedDate` + 4 weeks (28 days) without opt-in; `rejectedDate` + 1 year (365 days) with `talentPoolOptIn: true`. On non-`afgewezen` applications both fields stay null (nothing to check — hired employees' data moves to the personnel dossier under its own retention regime, out of scope here). `aangenomen` is not given a retention clock in the MVP.

### D5 — New corpus file `privacy.json`; rules `domain: gdpr-recruitment` (ADR-031)

`lib/Standards/rules/` holds `payroll.json` (tax/reporting/ledger-integrity) and `labour.json` (labour law). Sollicitatie-bewaartermijnen are privacy law, not labour law — SCHEMA.md prescribes one file per sub-domain, so the two rules go in a **new** `lib/Standards/rules/privacy.json` (`{"domain": "privacy", "version": "2026-07", "rules": [...]}`). Both rules: `domain: gdpr-recruitment` (following SCHEMA.md's own `gdpr-employee` domain example), `jurisdiction: NL` (the concrete 4-weken/1-jaar termijnen are the NL AP norm), `framework: gdpr` (SCHEMA.md's listed framework slug), `severity: mandatory`, `machineCheckable: true`, `effectiveDate: 2018-05-25` (AVG applicability). `RuleCatalogue` picks the file up via glob; `RuleCatalogue::VERSION` bumps `2026-07` → `2026-07.1` (second corpus change within the month; the constant is an opaque bump marker, not a period).

| behaviour | path | rationale |
|---|---|---|
| Vacancy publish workflow (concept→gepubliceerd→gesloten) | **declarative** `x-openregister-lifecycle` | ADR-031 default; `publishedDate` stamped on the carrying write |
| Application pipeline (nieuw→…→aangenomen/afgewezen) | **declarative** `x-openregister-lifecycle` | ADR-031 default; `rejectedDate`/`retentionExpiryDate` stamped on the carrying write of `afwijzen` |
| Retention derivation + expiry detection | imperative **CheckProvider** predicates (`NlAtsChecks`) | domain-rule evaluation over the versioned corpus — the established ADR-031 exception used by all existing payroll/labour rules; violations surface via `occ hrmq:rules:audit` |
| Automatic deletion/anonymisation of expired applications | **neither** — deliberately deferred | a destructive background job needs its own spec + guard posture; the audit is the MVP enforcement surface, consistent with the corpus' audit-only precedent |
| Status-change notifications to candidates | **neither** — deliberately deferred | x-openregister-notifications adoption is app-wide work (ADR-031/gate-18), same deferral as loonaangifte and leave-verzuim |
| Guard wiring of rule predicates into transitions | out of scope | owned by the active `hrmq-rule-compliance-enforcement` change |

### D6 — Menu group is the frozen ADR-001 menu 6, declared identically to the parallel `onboarding-wizard-mvp`

`Onboarding & ATS` is ADR-001 menu 6 — a frozen top-level entry, so adding it needs no ADR amendment. The **parallel** change `onboarding-wizard-mvp` (being authored concurrently) also places pages under this group. Both changes therefore declare the group with the **identical tuple** so a build-time merge of the two manifest deltas is a clean union of children under one group object:

```json
{ "id": "OnboardingAtsGroup", "label": "Onboarding & ATS", "icon": "AccountPlus", "order": 106, "children": [ ... ] }
```

(`AccountPlus` = ADR-001's `account-plus` in the manifest's PascalCase icon convention; order 106 = the menu-6 provisional slot directly after Verlof & verzuim's 105 and before Onkosten's 110 — final ordering of all ADR-001 menus is owned by the active `hrmq-ia-navigation-alignment` change and deliberately not solved here.) Whichever change merges second unions its children into the existing group and MUST NOT re-declare the group with a different label/icon/order; the merged `src/manifest.json` must be re-validated after the union (`npm run check:manifest`), per the union-merge/re-validate convention. This change contributes children `Vacancies` and `Applications`; `onboarding-wizard-mvp` contributes its own.

### D7 — Kanban is out; the `Applications` index is the pipeline surface

The draft's kanban board needs a drag-and-drop board widget that does not exist in `@conduction/nextcloud-vue`; per the fleet convention Vue logic lives in nc-vue, so building it as a one-off hrmq page is wrong twice. The MVP pipeline surface is the `Applications` index with a `status` filter (each pipeline stage is one filter click) plus the lifecycle actions on `ApplicationDetail`. A `CnKanbanBoard` widget is noted as the nc-vue follow-up; when it lands, the manifest page swaps type without schema changes.

### D8 — Applications have no stored `appliedDate`; submission time is object metadata

The MVP field list deliberately omits an application-date property: OpenRegister stamps creation time on every object (`@self` metadata + audit trail), and in the MVP applications are entered by HR at receipt time. The index therefore sorts on `candidateName` (deterministic, no fake recency column), and the audit-history tab shows the true timeline. If the portaliq career page lands later and applications arrive asynchronously, an explicit `appliedDate` becomes a schema addition of that change.

## Schema deltas

**`Vacancy` (new fragment `hr-ats.json`, version 0.1.0, icon `BriefcaseSearchOutline`):** `title` (string, required — job title), `description` (string, nullable — vacancy text), `department` (string, nullable), `status` (enum `concept`/`gepubliceerd`/`gesloten`, default `concept`, required — governed by the lifecycle), `publishedDate` (string, format date, nullable — stamped on the carrying write of `publiceren`), `closingDate` (string, format date, nullable — application deadline, informational in the MVP). Required: `title`, `status`. Lifecycle per D3. Gate-28: title + description on every property.

**`Application` (same fragment, version 0.1.0, icon `FileAccountOutline`):** `vacancyId` (string, format uuid, `$ref` Vacancy, required), `candidateName` (string, required), `email` (string, format email, required), `phone` (string, nullable), `cvFile` (string, nullable — reference (Nextcloud Files path or OpenRegister file id) to the uploaded CV, the `Expense.receiptFile` pattern), `motivation` (string, nullable — free-text motivation), `status` (enum `nieuw`/`screening`/`gesprek`/`aanbod`/`aangenomen`/`afgewezen`, default `nieuw`, required), `rejectedDate` (string, format date, nullable — stamped on the carrying write of `afwijzen`), `talentPoolOptIn` (boolean, default false — explicit candidate consent to extended talent-pool retention; description cites the AP 1-year maximum), `retentionExpiryDate` (string, format date, nullable — stored-but-rule-checked per D4; description cites the AP 4-weken richtlijn). Required: `vacancyId`, `candidateName`, `email`, `status`. Lifecycle per D3. The schema description states the D2 decision (PII lives here; deleting the application deletes the candidate's data). No Candidate schema anywhere. Register `info.version` 0.3.0 → 0.4.0.

## New corpus rules (privacy.json)

| id | framework | source | statement (short) | severity | machineCheckable |
|---|---|---|---|---|---|
| `nl-ats-retentie-derivatie` | `gdpr` | AVG art. 5 lid 1 sub e (opslagbeperking); Autoriteit Persoonsgegevens richtlijn sollicitatiegegevens | A rejected Application carries `rejectedDate` and `retentionExpiryDate = rejectedDate + 4 weken` (zonder talent-pool-toestemming) or `+ 1 jaar` (met `talentPoolOptIn`); on `afgewezen` neither field may be null | mandatory | true |
| `nl-ats-retentie-verlopen` | `gdpr` | AVG art. 5 lid 1 sub e; Autoriteit Persoonsgegevens richtlijn sollicitatiegegevens | An Application whose `retentionExpiryDate` lies in the past must no longer exist un-anonymised in the register; one still present at audit time is in violation | mandatory | true |

Both: `domain: gdpr-recruitment`, `jurisdiction: NL`, `effectiveDate: 2018-05-25`, `sourceUrl: https://autoriteitpersoonsgegevens.nl/themas/werk-en-uitkering/sollicitaties` (verified live 2026-07-12; carries the 4-weken/1-jaar guidance), UAVG secondary anchor `https://wetten.overheid.nl/BWBR0040940` cited in the `source` string. The 28-day and 365-day offsets live in the derivation rule's `parameters` (`retentionDays: 28`, `optInRetentionDays: 365`) and the check reads them from the rule data, not from PHP constants — the same data-over-code convention as the tijdvakcode tables and the WVP `milestoneWeeks`.

Checks: `NlAtsChecks` registers both `Application` predicates. Derivation is evaluated only on `status: afgewezen` (other statuses pass vacuously — nothing to derive); expiry is evaluated against the audit run date (the `nl-loonaangifte-deadline-alert` pattern) on any Application carrying a non-null `retentionExpiryDate`.

## Manifest delta

- **deepLinks**: `Vacancy` → `/apps/hrmq/vacancies/{uuid}`, `Application` → `/apps/hrmq/applications/{uuid}`.
- **Menu group** `OnboardingAtsGroup` ("Onboarding & ATS", `AccountPlus`, order 106 — D6) with children: `Vacancies` ("Vacatures", `BriefcaseSearchOutline`), `Applications` ("Sollicitaties", `FileAccountOutline`).
- **`Vacancies`** (index, `/vacancies`): columns `title`, `department`, `status`, `publishedDate`, `closingDate`; filters `status`, `department`; sort `publishedDate` desc.
- **`VacancyDetail`** (detail, `/vacancies/:id`): data widget "Vacancy" (all fields), related widget "Sollicitaties" (the incoming `Application.vacancyId` references resolve here — the candidate list per vacancy), lifecycleActions exposing exactly `publiceren` ("Publiceren") and `sluiten` ("Sluiten"), audit-history sidebar tab.
- **`Applications`** (index, `/applications`): columns `candidateName`, `vacancyId`, `status`, `talentPoolOptIn`, `retentionExpiryDate`; filters `status` (the pipeline surface per D7 — each stage is one filter value); sort `candidateName` asc (D8).
- **`ApplicationDetail`** (detail, `/applications/:id`): data widget "Application" (exclude `vacancyId` — Related resolves the Vacancy — and the three privacy fields), data widget "Privacy & retention" (`talentPoolOptIn`, `rejectedDate`, `retentionExpiryDate`), related widget, files widget "CV & motivatiebrief" (`cvFile` + uploaded documents), lifecycleActions exposing exactly `screenen`/`uitnodigen`/`aanbieden`/`aannemen`/`afwijzen` with Dutch labels, audit-history sidebar tab. A page `_note` documents the D2 PII decision and the manual onboarding hand-off on `aannemen`.
- **Icons**: `src/icons.js` registers `AccountPlus`, `BriefcaseSearchOutline`, `FileAccountOutline` (verified present in `vue-material-design-icons` at HEAD; unregistered names silently fall back to a help-circle). `onboarding-wizard-mvp` registers `AccountPlus` too — the import/export lines are identical, so the union is clean like the menu group's.
- All four pages validate against app-manifest-v2 (`npm run check:manifest`).

## Seed Data (ADR-001)

Extend `lib/Settings/register.d/hr-seed.json` (placeholders only, obvious slugs; candidate PII is explicitly fictional — Voorbeeld names, `example.org` addresses, nil-UUID file refs):

**Vacancy (1):**
1. `vacancy-vue-developer` — title "Medior Vue-developer", description placeholder vacancy text, department "Engineering", status `gepubliceerd`, publishedDate `2026-06-15`, closingDate `2026-08-31`.

**Application (3, all `vacancyId: vacancy-vue-developer`):**
1. `application-voorbeeld-nieuw` — Jan Voorbeeld, `voorbeeld@example.org`, phone `+31 6 00000000`, cvFile `00000000-0000-0000-0000-000000000000` (nil UUID), motivation placeholder, status `nieuw`, talentPoolOptIn false (clean — no retention fields yet).
2. `application-voorbeeld-afgewezen` — Piet Voorbeeld, `voorbeeld+afgewezen@example.org`, status `afgewezen`, rejectedDate `2026-06-01`, talentPoolOptIn false, retentionExpiryDate `2026-06-29` (exact +28 days derivation → `nl-ats-retentie-derivatie` passes; date is past the 2026-07-12 reference audit date → **exercises the mandatory `nl-ats-retentie-verlopen` violation**).
3. `application-voorbeeld-aangenomen` — Anna Voorbeeld, `voorbeeld+aangenomen@example.org`, status `aangenomen`, talentPoolOptIn false, no rejectedDate/retentionExpiryDate (clean).

The seeded audit must show exactly one ATS violation: `nl-ats-retentie-verlopen` on `application-voorbeeld-afgewezen`.

## Risks / Trade-offs

- **The expired seed IS a standing violation** — intentional (it proves the check), but a demo instance will always show one mandatory AVG violation until the object is deleted. Mitigation: the violation message names the object and the required action (delete/anonymise); this is the feature.
- **Seed violation dates age**: like the WVP seeds, the story is told relative to 2026-07-12; later audits show the same violation, never fewer. Acceptable for placeholder data.
- **Menu-group merge race with `onboarding-wizard-mvp`**: if the parallel change deviates from the D6 tuple, the union produces two groups or a label conflict. Mitigation: the tuple is spelled out identically in both changes' designs; the post-merge re-validation task catches a double declaration.
- **Repeat-applicant PII duplication** (D2) — accepted at MKB volume; dedupe rides the future talent-pool/Candidate follow-up.
- **Fragment objects go LIVE on import** (portal-schemas precedent): the seeds are deliberately obvious placeholders (Voorbeeld names, example.org, nil UUIDs), consistent with hr-seed.json's existing content.
- **`retentionExpiryDate` on non-rejected terminal states**: `aangenomen` applications get no retention clock in the MVP (their data transitions to the personnel dossier regime). If withdrawn-by-candidate ever becomes a distinct status, it needs the same retention treatment as `afgewezen`.

## Open Questions

- None blocking. Multiposting (OpenConnector), the portaliq career page (ADR-046), the nc-vue kanban widget, interviews/offers, the retention-deletion job and automatic Employee creation on hire are the declared follow-ups.
