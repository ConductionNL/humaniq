# Design: training on the personnel file, and people in the learning app

## Context

Read at `development` af702f78.

- No training schema or page exists: `grep -rliF opleiding lib/Settings/register.d
  src/manifest.d` finds only `hr-stagiair.json` (a school field on an intern) and
  `hr-bhv.json` (safety certificates).
- `lib/Support/FleetAppId.php:77` maps `learniq` to `['learniq', 'scholiq']`; no humaniq code
  outside that file calls it.
- `EmployeeCompetence` (`lib/Settings/register.d/hr-agenda.json:21`): `employeeId`,
  `competenceCode`, `label`, `issuedOn`, `validUntil`, read by
  `CompetenceCheckService::holds()` (line 149).
- `Employee` (`hr-objects.json:5`): `firstName`, `lastName`, `nextcloudUserId`, `startDate`,
  `endDate`, `administrationId`. `OrgAssignment` (`hr-org.json`): `employeeId`,
  `orgUnitId`, `role`, `startDate`, `endDate`. `OrgUnit`: `name`, `type`, `parentUnitId`,
  `managerId`.
- `lib/Service/OrgResolutionService.php` resolves an employee's current placement and
  managers (`isActiveOn()` line 71, `resolveManagerUserIds()` line 110).
- `lib/Service/RbacObjectReader.php` filters composed reads by the caller's OpenRegister
  RBAC (the `LeaveScheduleController` precedent).
- learniq (read at its `development`) holds `LearnerProfile` (`ncUserId`, `managerId`,
  `department`), `Enrolment` (`learnerId`, `courseId`, `dueDate`), and `Credential`
  (`learnerId`, `courseId`, `issuedAt`, `expiresAt`, `competencyIds`), "issued on enrolment
  completion".

## Goals / Non-Goals

**Goals**

- HR plans a training for employees and registers who attended, with or without learniq.
- The personnel file shows every training and its certificate, and qualifications feed the
  competences rostering reads.
- A learning platform learns who works where from one feed with minimal data.

**Non-Goals**

- Running courses. learniq does.
- Sending data to an external platform. integriq fetches it.

## Decisions

### D1. `TrainingRecord` is the HR fact, not the course

`TrainingRecord`: `employeeId` (`$ref` `Employee`), `title`, `provider`, `plannedOn`,
`status` (`gepland`, `gevolgd`, `niet-gevolgd`, with an `x-openregister-lifecycle`:
`registreren-gevolgd`, `registreren-niet-gevolgd`), `completedOn`, `validUntil`,
`competenceCode`, `costEur`, `studiekostenbeding` (boolean), `terugbetalingsregeling`
(text), `source` (`hr`, `learniq`, `lms`), `sourceRef`, `administrationId`. Added at build
time: `validityMonths`, because a group course is planned knowing its validity in months, not
its end date; `TrainingRecordListener` fills `completedOn` (the planned day, or today) and
`validUntil` (`completedOn` plus `validityMonths`) on the write that makes a record
`gevolgd`, and the administration from the employee. One record per employee per
training; a group course is planned as one record per participant from the `Trainingen`
index with the library's mass actions.

Alternative considered: a `Course` and `Session` in humaniq. Rejected: learniq owns course
administration, and humaniq's job is what the personnel file must show.

### D2. A followed training with a code writes the competence

`TrainingCompetenceWriter` runs (from `TrainingRecordListener` on OpenRegister's created and
updated events) after a `TrainingRecord` reaches `gevolgd` with a
`competenceCode`: it creates an `EmployeeCompetence` with `issuedOn = completedOn` and
`validUntil`, or extends the existing one for the same code when the new validity is later.
It never shortens or deletes a competence.

### D3. The people feed is a composed, minimal read

`GET /api/learning/people?modifiedSince=` (`#[NoAdminRequired]`) returns, per active
employee: `id`, `firstName`, `lastName`, `nextcloudUserId`, `orgUnit` (`{id, name}` of the
current placement), `role` (`OrgAssignment.role`), `managerUserIds`, `startDate`,
`endDate`, and `modified`. It reads through `RbacObjectReader`, so an integration account
sees only what its own Nextcloud account may read, and `hris-api-public`'s
`IntegrationAccount` records who was granted it. `modifiedSince` compares the newest
`modified` of the employee, their placement and their unit.

Alternative considered: letting each platform read `Employee`, `OrgAssignment` and `OrgUnit`
through the objects API. Rejected: three reads and a join per platform, and each would see
every field of `Employee`, including BSN and salary.

### D4. Credentials come back through a listener on learniq's object events

`LearniqCredentialListener` handles OpenRegister's `ObjectCreatedEvent` when the object's
register is learniq's and its schema is `credential`. Checked at build time against learniq's
`development` (806b755): learniq emits no credential event class of its own;
`CredentialIssuanceHandler` saves the `credential` object through `ObjectService`, so
OpenRegister's `ObjectCreatedEvent` is the signal. The subscription is scoped to the register
slugs `FleetAppId` lists for learniq (`learniq`, `scholiq`), and the listener checks the
register id again through `RegisterMapper::findIdsBySlugs`. `Credential.learnerId` is a
`LearnerProfile` id, so the listener reads that profile's `ncUserId` and the `Course.name`
for the title from learniq's register (read only). It resolves the learner's `ncUserId` to
the `Employee` with that `nextcloudUserId`, and writes
a `TrainingRecord` with `status` `gevolgd`, `completedOn = issuedAt`,
`validUntil = expiresAt`, `source` `learniq`, `sourceRef` the credential id. A second event
for the same credential finds the existing record and does nothing. Without learniq the
listener never matches.

Alternative considered: learniq writing humaniq's records. Rejected: a leaf app writing
another app's register is the cross-app write the fleet avoids; humaniq reads what learniq
publishes.

## Declarative-vs-imperative decision (ADR-031)

| behaviour | path | why |
|---|---|---|
| training status | declarative `x-openregister-lifecycle` | a plain state machine |
| competence on completion | imperative post-save writer | a cross-schema upsert with a later-date rule |
| people feed | imperative composed read | a projection over three schemas with RBAC |
| credentials from learniq | imperative listener on another app's object events | cross-app, duck-typed |
| pages | declarative manifest | existing widgets and mass actions |

## Seed data

- A `TrainingRecord` for a seed employee: BHV refresher `gevolgd` with competence code
  `bhv` valid for a year, and a planned privacy training.
- One record with `studiekostenbeding` true, a cost of 2,400 euros and repayment terms.

## Risks / Trade-offs

- [The feed exposes people to an integration] → minimal fields, RBAC per caller, and the
  catalogue of integrations from `hris-api-public`.
- [Two records of one course, in learniq and humaniq] → the humaniq record carries
  `sourceRef`, so it points at learniq's credential instead of copying the course.

## Open Questions

- Should learniq's enrolments (planned, not yet completed) appear as `gepland` records too,
  or only on a cross-app widget on `EmployeeDetail`?
