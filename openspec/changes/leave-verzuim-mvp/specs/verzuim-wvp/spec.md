# Spec: verzuim-wvp

**Status:** in-progress
**Scope:** hrmq
**Kind:** config (declarative schema + manifest + corpus data; check methods are the app's established rule-corpus exception)

**OpenSpec changes**
- `leave-verzuim-mvp` (2026-07-12)

## Purpose

Give hrmq the Dutch-law sickness depth no Nextcloud app has: an administrative (never medical) `SickLeaveCase` with a declarative gemeld⇄hersteld lifecycle, the Wet-verbetering-poortwachter milestone clock (probleemanalyse week 6, plan van aanpak week 8, UWV 42-wekenmelding, eerstejaarsevaluatie week 52) as stored-but-rule-checked dates, statutory 70% loondoorbetaling tracking (BW art. 7:629), three machine-checkable verzuim rules with a `NlVerzuimChecks` provider, and the verzuim pages under the `Verlof & verzuim` menu group.

## Requirements

### REQ-VWP-001: A new `SickLeaveCase` schema SHALL model the administrative sickness case with a gemeld⇄hersteld lifecycle

A new fragment `lib/Settings/register.d/hr-verzuim.json` (`x-hrmq-fragment: hr-verzuim`) declares `SickLeaveCase` (version 0.1.0): `employeeId` (string, format uuid, `$ref` Employee, required), `firstSickDay` (date, required), `recoveredDate` (date, nullable), `status` (enum `gemeld`/`hersteld`, default `gemeld`, required), `wachtdag` (boolean, default false — description documents that the first sick day may be an unpaid waiting day where CAO/contract provides), `loondoorbetalingPercentage` (number, default 70 — description documents the BW art. 7:629 statutory minimum of 70% for max 104 weeks and the first-year floor at minimum wage), and the milestone pairs `probleemanalyseDue`/`probleemanalyseDone` (week 6, Regeling procesgang art. 2), `planVanAanpakDue`/`planVanAanpakDone` (week 8), `uwv42WeekMeldingDue`/`uwv42WeekMeldingDone` (week 42, ZW art. 38), `eerstejaarsevaluatieDue`/`eerstejaarsevaluatieDone` (week 52) — all date, nullable. The Due dates are derived from `firstSickDay` but **stored** (loonaangifte-deadline precedent: audit trail + editability when UWV grants deferral; correctness enforced by REQ-VWP-003, not recomputed away). `configuration` declares `x-openregister-lifecycle`: `field: status`, `initial: gemeld`, transitions `herstellen` (gemeld→hersteld; description documents that `recoveredDate` is stamped on the carrying write) and `heropenen` (hersteld→gemeld; description documents relapse within 4 weeks as samengesteld ziektegeval per BW 7:629 lid 10, continuing the same 104-week clock, with `recoveredDate` cleared on the carrying write). Every property carries title + description (gate-28).

#### Scenario: Case walks recovery and relapse
- **GIVEN** a SickLeaveCase created with `firstSickDay: 2026-05-04` (status defaults to `gemeld`)
- **WHEN** `herstellen` is executed via the OpenRegister lifecycle endpoint with the carrying write setting `recoveredDate`
- **THEN** the object's `status` is `hersteld`; **AND WHEN** `heropenen` is executed within 4 weeks **THEN** `status` returns to `gemeld` and `recoveredDate` is cleared

#### Scenario: Illegal transition rejected
- **GIVEN** a case in status `gemeld`
- **WHEN** the `heropenen` action is attempted
- **THEN** OpenRegister rejects the transition (`heropenen` is only declared from `hersteld`)

### REQ-VWP-002: The schema SHALL carry NO medical data — administrative case facts only

Per the AVG and the Autoriteit Persoonsgegevens beleidsregels "De zieke werknemer" (and UWV beleidsregels), an employer may record that and how long an employee is sick and the re-integration process facts — never the nature or cause of the illness. The `SickLeaveCase` schema description states this explicitly, and the schema declares no diagnosis, symptom, cause, medical-note, or free-text illness field of any kind. The detail page's files widget description repeats the warning (gespreksverslagen and plan-van-aanpak documents only).

#### Scenario: No diagnosis field exists
- **WHEN** the `SickLeaveCase` properties in `lib/Settings/register.d/hr-verzuim.json` are enumerated
- **THEN** they are exactly the administrative set of REQ-VWP-001 (employeeId, firstSickDay, recoveredDate, status, wachtdag, loondoorbetalingPercentage, and the eight milestone dates) — no property whose name or description denotes diagnosis, illness nature, symptoms, or medical findings, and no unconstrained free-text field for them

### REQ-VWP-003: The rule corpus SHALL gain three machine-checkable NL verzuim rules

`lib/Standards/rules/labour.json` gains `nl-wvp-milestone-derivation` (Due fields equal `firstSickDay` + 6/8/42/52 weeks; on an open case none may be null; carries `parameters.milestoneWeeks` so the offsets are rule data, not PHP constants), `nl-wvp-milestone-overdue` (an open case with a Due in the past and no matching Done is a mandatory violation; a Due within 14 days is advisory), and `nl-loondoorbetaling-minimum` (`loondoorbetalingPercentage ≥ 70` on open cases). All three: `domain: labour`, `jurisdiction: NL`, `machineCheckable: true`, `severity: mandatory`; the milestone rules use `framework: nl-poortwachter`, `source` citing Regeling procesgang eerste en tweede ziektejaar art. 2/4 and ZW art. 38 (42-wekenmelding, `https://wetten.overheid.nl/BWBR0002598`), `sourceUrl: https://wetten.overheid.nl/BWBR0013540`; the loondoorbetaling rule uses `framework: bw7-10`, `source: BW art. 7:629 lid 1`, `sourceUrl: https://wetten.overheid.nl/BWBR0005290`.

#### Scenario: Corpus stays loadable and versioned
- **WHEN** `occ hrmq:rules:audit` runs after the corpus edit
- **THEN** the RuleCatalogue loads without error and reports the three verzuim rules as enforced

### REQ-VWP-004: `NlVerzuimChecks` SHALL enforce derivation, overdue signalling, and the loondoorbetaling floor

New auto-discovered provider `lib/Standards/Checks/NlVerzuimChecks.php` (implements `CheckProvider`) registering, under object type `SickLeaveCase`:
1. **Milestone derivation** — each non-null Due must equal `firstSickDay` plus its rule-parameterised week offset (6/8/42/52); on a case with `status: gemeld` a null Due is itself a violation (the clock starts day one); on `hersteld` cases null Due fields pass.
2. **Milestone overdue** — evaluated against the audit run date (the `nl-loonaangifte-deadline-alert` pattern): an open case with any Due in the past and no matching Done is a mandatory violation; a Due within 14 days and not Done is advisory.
3. **Loondoorbetaling minimum** — an open case with `loondoorbetalingPercentage < 70` (or missing) is a violation.

Each predicate is side-effect free and keyed by its corpus rule id.

#### Scenario: Overdue probleemanalyse raises mandatory violation
- **GIVEN** the seed case `sickcase-devries-week7` (`firstSickDay: 2026-05-25`, `probleemanalyseDue: 2026-07-06`, no Done, status `gemeld`)
- **WHEN** `occ hrmq:rules:audit` runs on any date after 2026-07-06
- **THEN** a `nl-wvp-milestone-overdue` violation with mandatory severity is reported for that object

#### Scenario: Approaching 42-week melding raises advisory
- **GIVEN** the seed case `sickcase-bakker-longterm` (`uwv42WeekMeldingDue: 2026-07-20`, no Done, status `gemeld`)
- **WHEN** the audit runs on 2026-07-12
- **THEN** a `nl-wvp-milestone-overdue` advisory is reported (due within 14 days)

#### Scenario: Wrong derivation flagged
- **GIVEN** an open case with `firstSickDay: 2026-05-25` and `probleemanalyseDue: 2026-07-13` (a week late — 6 weeks is 2026-07-06)
- **WHEN** the audit runs
- **THEN** a `nl-wvp-milestone-derivation` violation is reported

#### Scenario: Recovered short case passes clean
- **GIVEN** the seed case `sickcase-jansen-flu` (`hersteld`, all milestone fields null)
- **WHEN** the audit runs
- **THEN** no verzuim-rule violation is reported for that object

### REQ-VWP-005: The verzuim pages SHALL surface the case, the milestone clock, and the lifecycle under `Verlof & verzuim`

`src/manifest.json` gains, under the `VerlofVerzuimGroup` menu group (REQ-LVM-001): `SickLeaveCases` (index over `SickLeaveCase`: columns `employeeId`, `firstSickDay`, `recoveredDate`, `status`; filters `status`; sort `firstSickDay` desc — no computed next-milestone column: index columns are plain schema fields and no stored field carries that aggregate, so it is omitted rather than faked) and `SickLeaveCaseDetail` (detail: "Case" data widget excluding `employeeId`, a "Poortwachter milestones" data widget with the eight Due/Done fields, related, files "Gespreksverslagen & plan van aanpak" with the no-medical-data warning in the widget description and page `_note`, `lifecycleActions` exposing exactly `herstellen` ("Herstellen") and `heropenen` ("Heropenen"), audit-history sidebar tab). A `SickLeaveCase` deepLink is registered. The manifest MUST validate (`npm run check:manifest`).

#### Scenario: Manifest stays valid
- **WHEN** `npm run check:manifest` runs
- **THEN** it exits 0

#### Scenario: Detail page drives recovery
@e2e exclude declarative widget wiring is covered by the shared CnPageRenderer library tests; app-level e2e suite does not exist yet (tracked by active change hrmq-test-coverage-baseline)
- **GIVEN** an open case on `SickLeaveCaseDetail`
- **WHEN** the user executes Herstellen
- **THEN** the page reflects status `hersteld` and offers Heropenen

### REQ-VWP-006: Seed data SHALL exercise recovery, an overdue milestone, and an approaching 42-week melding

`lib/Settings/register.d/hr-seed.json` gains the three SickLeaveCase objects from design.md: `sickcase-jansen-flu` (recovered 4-day case, wachtdag true, milestones null), `sickcase-devries-week7` (open, probleemanalyse overdue → mandatory; plan van aanpak approaching → advisory), `sickcase-bakker-longterm` (open ~week 41, probleemanalyse/plan van aanpak done on time, 42-wekenmelding approaching → advisory). All Due values are exact `firstSickDay + 6/8/42/52 weeks` derivations (zero derivation violations on the seeds) and all references stay obvious slug-style placeholders matching the existing seed employees.

#### Scenario: Idempotent seed
- **WHEN** the register Repair import runs twice
- **THEN** the three cases exist exactly once

#### Scenario: Seeded audit shows exactly the intended verzuim violations
- **GIVEN** a fresh import of the seed data
- **WHEN** `occ hrmq:rules:audit` runs on 2026-07-12
- **THEN** the verzuim violations are exactly: one mandatory + one advisory `nl-wvp-milestone-overdue` for `sickcase-devries-week7`, one advisory `nl-wvp-milestone-overdue` for `sickcase-bakker-longterm`, and no `nl-wvp-milestone-derivation` or `nl-loondoorbetaling-minimum` violations
