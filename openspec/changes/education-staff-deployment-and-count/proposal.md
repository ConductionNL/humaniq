---
kind: code
---

# A teacher's yearly deployment plan and the staff count export

## Why

A school leader plans each teacher's year in hours: how many hours of teaching, how many of
lesson-related tasks, school tasks, professional development and sustainable employability,
against the annual norm the collective agreement sets for that teacher's contract. In primary
education that norm is the normjaartaak (1,659 hours a year for a full-time teacher in CAO PO,
recorded in humaniq as unverified data). humaniq holds the contracts and the norm, and does
nothing with the norm: `lib/Standards/CaoRegistry.php` reads no `workingTime` value, and
`ForwardCapacityService` compares rostered and booked hours against the contract over a
forward window, not a school year's task load. The school leader plans in a spreadsheet
beside the payroll system that knows the contracts.

In November the education ministry (OCW) asks every secondary school for a file for its
yearly staff count, the Integrale Personeelstelling Onderwijs (IPTO). Timetabling tools
produce it from their data. humaniq, which holds the people and their deployment, produces
nothing.

This change adds a deployment plan per teacher per school year, measured against the norm
for their contract, and an IPTO export built from those plans.

Both rows come from planninq's capability matrix (the timetabling area) and are owned by
humaniq, not planninq: the matrix notes "Sibling-owed: humaniq owns this capability, not
planninq". planninq holds no timetabling object today.

### Matrix rows (planninq `openspec/parity/capabilities.json`, owned by humaniq)

| row | capability | humaniq today |
|---|---|---|
| `tt-staff-deployment` | Plan each teacher's teaching load for the year against their contract hours. | `no`: no yearly task-load plan; the CAO PO normjaartaak is stored as display-only data |
| `tt-staff-count-export` | Export the timetable in the format the education ministry asks for its yearly staff count. | `no`: no IPTO export |

### Demand

- `tt-staff-deployment`, tender: https://www.tenderned.nl/aankondigingen/overzicht/271977
  (Graafschap College, "Planning docentinzet, in relatie tot de roostering").
- `tt-staff-deployment`, tender: https://www.tenderned.nl/aankondigingen/overzicht/414807
  (Firda, "personeelsplanning").

### Competitors rated yes

- `tt-staff-deployment`, Xedule: "per teacher plan of deployment with planned and realised
  hours" and a work allocation engine that "probeert de contactRE's van de medewerkers
  eerlijk te verdelen"
  (https://support.xedule.nl/hc/nl/articles/33452101172114-Jaarplanning-Analyses-Medewerker-Plan-van-Inzet,
  https://support.xedule.nl/hc/nl/articles/33446842118930-Jaarplanning-Uitvoeren-Werkverdelen).
- `tt-staff-count-export`, Zermelo: "In november vraagt het minsterie van OCW de scholen naar
  een exportbestand ten behoeve van de Integrale PersoneelsTellingen Onderwijs: de IPTO"
  (https://support.zermelo.nl/guides/applicatiebeheerder/ipto-exportbestand).
- `tt-staff-count-export`, Xedule: "IPTO Week Rapportage Integrale Personeels Tellingen
  Onderwijs t.b.v. het ministerie van onderwijs, alleen van toepassing voor Voortgezet
  Onderwijs scholen"
  (https://support.xedule.nl/hc/nl/articles/36238182237330-Export-Rapportages).

### Recorded follow-ups this change picks up

- `2026-07-14-cao-library` records `workingTime` as display-only, read by no resolver
  (`lib/Standards/cao/SCHEMA.md:50`). This change adds the first reader, under the same rule
  the other resolvers keep: an unverified value is not used as if it were verified.
- No recorded non-goal names teacher deployment or the staff count.

## What Changes

- **A deployment plan per teacher per school year.** A new `DeploymentPlan` holds one
  teacher's school year (1 August to 31 July), their norm hours, and lines of planned hours
  by category: teaching, lesson-related tasks, school tasks, professional development,
  sustainable employability. Teaching lines name a subject and an education level and give
  weekly hours over a number of weeks.
- **Measured against the norm for the contract.** The norm is the CAO's annual norm times the
  teacher's contract share of a full-time week, over the contracts active in the school year.
  When the CAO's figure is unverified or missing, HR enters the norm on the plan and the plan
  says it was entered by hand. Each plan shows planned, norm and the difference; an overload
  is flagged.
- **One view per school.** A `Taakbeleid` page per org unit lists every teacher's plan with
  planned against norm, so the school leader sees who is over and who has room.
- **The IPTO export.** `Export IPTO` on an administration produces the staff count file for a
  reference week from the teaching lines of the plans, per the file specification OCW
  publishes. It is written as a document on the administration for HR to submit.

## Capabilities

### New Capabilities

- `education-staff-deployment`: the yearly deployment plan per teacher against the CAO norm,
  the per-school view, and the IPTO export.

## Impact

- `lib/Settings/register.d/hr-deployment.json` (new fragment): `DeploymentPlan` with its
  lines, and an `x-openregister-calculations` total.
- `lib/Standards/CaoRegistry.php`: `annualNormHours(string $caoId): ?float`, null when the
  value is unverified or a placeholder.
- `lib/Service/DeploymentPlanService.php`, `lib/Service/IptoExportService.php` (new).
- `lib/Controller/DeploymentController.php` (new) and `appinfo/routes.php`:
  `GET /api/deployment/org-units/{id}`, `POST /api/deployment/ipto-export`.
- `src/manifest.d/hr-deployment.json` (new): `DeploymentPlans`, `DeploymentPlanDetail`,
  `Taakbeleid`, a menu entry.

## Out of scope

- Building a timetable, or deriving teaching hours from one. When planninq or another tool
  holds a timetable, feeding its hours into the plan is a later change.
- Automatic fair distribution of tasks across teachers.
- The staff count for primary schools, which OCW draws from other sources.
