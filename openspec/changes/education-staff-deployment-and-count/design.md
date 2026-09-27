# Design: a teacher's yearly deployment plan and the staff count export

## Context

Read at `development` af702f78.

- `lib/Standards/cao/cao-onderwijs-po.json:58` holds `workingTime.value` with
  `normjaartaakUrenPerJaar` 1659, `lesgevendeTaakMaxUren` 940,
  `lesgebondenTakenOpslagfactorPct` [35, 45], `professionaliseringUren` 83,
  `duurzameInzetbaarheidUren` 40, marked `verified: false`, `placeholder: true`, "Display-only
  - not read by any CaoRegistry resolver". `cao-onderwijs-vo.json` holds only
  `fulltimeHoursPerWeek` 40, also a placeholder.
- `lib/Standards/CaoRegistry.php` resolvers (`minMaandloonCents()` line 140,
  `minLeaveHours()` line 180, `overtimeToeslagPercentages()` line 223) return null for a leaf
  that is `verified: false` or `placeholder: true` (the cao-library design D5). None reads
  `workingTime` (`lib/Standards/cao/SCHEMA.md:50`).
- `EmploymentContract` (`hr-objects.json`): `employeeId`, `startDate`, `endDate`,
  `hoursPerWeek`, `cao`, `caoSchaal`. `AbsenceRateService::DEFAULT_FULL_TIME_HOURS_PER_WEEK`
  is 40.0 (line 100).
- `lib/Service/ForwardCapacityService.php` measures committed shift, leave, absence,
  booking and interview hours against contracted hours over a forward window
  (REQ-ROST-C03). It has no school year and no task categories.
- `OrgUnit` and `OrgAssignment` (`hr-org.json`) place teachers in schools.
  `hrAdministration` (`hr-administratie.json`) holds `name`, `kvkNumber`,
  `loonheffingennummer`; no school registration number (BRIN).
- `HrGeneratedDocument` and `HrDocumentService` file generated documents; the IPTO file is a
  data export, not a rendered template.

## Goals / Non-Goals

**Goals**

- A school leader plans every teacher's year in categories against a norm that follows the
  contract.
- Overload and room are visible per teacher and per school.
- The November IPTO file comes out of the plans.

**Non-Goals**

- A timetable, and task distribution by an algorithm.
- Using an unverified CAO figure as if it were verified.

## Decisions

### D1. One plan per teacher per school year

`DeploymentPlan`: `employeeId` (`$ref` `Employee`), `orgUnitId` (`$ref` `OrgUnit`),
`schoolYear` (`2026-2027`, 1 August to 31 July), `normHours`, `normSource`
(`cao` or `handmatig`), `lines` (array of `{category, subject, educationLevel,
hoursPerWeek, weeks, hours}` with category `lesgevend`, `lesgebonden`, `schooltaak`,
`professionalisering`, `duurzame-inzetbaarheid`), `status` (`concept`, `vastgesteld`, with
an `x-openregister-lifecycle`, `vaststellen` guarded by `NoSelfApprovalGuard` so a teacher
cannot confirm their own plan). `plannedHours` is an `x-openregister-calculations` sum of
`lines.hours`; `differenceHours` is `plannedHours - normHours`.

Alternative considered: stretching `ForwardCapacityService` to a school year. Rejected: it
counts booked calendar entries, and a task load is a plan of categories that never becomes
calendar entries.

### D2. The norm follows the contract, and says where it came from

`DeploymentPlanService::normFor(string $employeeId, string $schoolYear): array` sums over the
contracts active in the school year: `CaoRegistry::annualNormHours($contract.cao)` times
`hoursPerWeek / 40` times the share of the school year the contract covers. When
`annualNormHours()` returns null (unknown CAO, or the value is unverified or a placeholder,
which today is every value), it returns null and the plan requires a `normHours` typed by HR
with `normSource: handmatig`. Verifying the CAO PO figure against the official text and
flipping `verified` is a task here; until then plans show the hand-entered norm.

### D3. The school view is composed on read

`GET /api/deployment/org-units/{id}?schoolYear=` returns, for every teacher placed in the unit
during the year, their plan's planned, norm and difference, and teachers without a plan.
It reads through `RbacObjectReader`. The `Taakbeleid` page shows it with the library's
`CnDataTable`, overloads first.

### D4. The IPTO export follows OCW's published specification, which is an input

`IptoExportService::export(string $administrationId, string $referenceWeek): array` takes the
teaching lines (`lesgevend`) of the `vastgesteld` plans active in the reference week, one row
per teacher, subject and education level with the weekly hours, and writes the file in the
layout OCW publishes for the IPTO. That layout is not in this repository and is not
invented here: task 3.1 obtains the current specification from OCW and DUO and records its
version in the service. `hrAdministration` gains `brinNummer`, which the export needs to
identify the school. The file is stored on the administration and returned for download.

Alternative considered: exporting only a generic CSV. Rejected: the row asks for "the format
the education ministry asks for", and a CSV the school must reshape is the manual step this
removes.

## Declarative-vs-imperative decision (ADR-031)

| behaviour | path | why |
|---|---|---|
| plan status and confirmation | declarative `x-openregister-lifecycle` with `NoSelfApprovalGuard` | existing guard |
| planned total and difference | declarative `x-openregister-calculations` | sums over one record |
| norm per contract | imperative `DeploymentPlanService` via `CaoRegistry` | CAO data plus contract proration |
| school view | imperative composed read | many teachers, one unit, RBAC |
| IPTO file | imperative `IptoExportService` | an external file format |

## Seed data

- One seed administration gains a `brinNummer` placeholder `00XX` and one school org unit.
- Two seed teachers: one on a full-time CAO PO contract with a `vastgesteld` plan totalling
  1,700 hours against a hand-entered norm of 1,659 (overload 41), one on a 0.6 contract with a
  `concept` plan under the norm.

## Risks / Trade-offs

- [The CAO figure is unverified] → it is not used until verified; the hand-entered norm is
  labelled as such on every plan.
- [OCW changes the IPTO layout] → the specification version is recorded in the service and
  the export states which version it wrote.

## Open Questions

- Should the plan also cover secondary education's own annual norm once the CAO VO data file
  carries one?
