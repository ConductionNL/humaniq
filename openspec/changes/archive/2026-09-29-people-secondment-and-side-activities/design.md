# Design: secondments and side activities on the personnel file

## Context

Read at `development` af702f78.

- `Employee` (`lib/Settings/register.d/hr-objects.json`) carries `publicSectorRegime`
  (`genormaliseerd`, `ambtenarenwet`, or null) and `nevenwerkzaamhedenGemeld` (boolean).
  Rule `nl-ambtenaar-nevenwerkzaamheden-melding` (`lib/Standards/rules/labour.json:451`,
  Ambtenarenwet 2017 art. 9) is presence-only on that boolean and vacuous for private-sector
  staff.
- `lib/Settings/register.d/hr-cost-rate.json:20` notes that the cost-rate derivation cannot
  tell a seconded employee apart; the override reason is the only trace today.
- `lib/Service/AgendaComposer.php` composes agenda entries of kinds `shift` (:184),
  `leave` (:229), `absent` (:279) and `interview` (:326);
  `AvailabilityService::committedHours()` (:203) counts `shift`, `leave`, `absent`,
  `booking`, `busy` and `interview` as committed.
- Self-service pages filter on `userId: "@me"`; `managerUserId` carries the approver scope
  (`mss-team-scope`). `NoSelfApprovalGuard` refuses an approval by the subject.

## Goals / Non-Goals

**Goals**

- Record a secondment with enough detail to plan around it and to settle it later.
- Replace the yes or no box by a register the employee fills and the employer decides on.

**Non-Goals**

- Judging conflicts automatically. The decision is human; humaniq records it.
- Any change to payroll. A seconded employee is paid as before.

## Decisions

### D1. `Secondment`

`employeeId`, `receivingOrganisation`, `receivingKvkNumber` (pattern `^[0-9]{8}$`,
nullable), `startDate`, `endDate`, `hoursPerWeek`, `agreedHourlyRate` (nullable),
`agreementFile` (file), `status` with lifecycle `activeren` (`concept` to `actief`),
`beeindigen` (`actief` to `beeindigd`), `administrationId`. Alternative considered: a
contract type `detachering`. Rejected: the employment contract does not change during a
secondment; the secondment is an arrangement on top of it.

### D2. Secondment in the agenda

`AgendaComposer` gains a `secondment` source producing one entry per working day of an
`actief` secondment, spread over the contracted days in proportion to `hoursPerWeek`.
`AvailabilityService::committedHours()` adds `secondment` to its committed kinds, so
`ForwardCapacityService` inherits it.

### D3. `SideActivity`

`employeeId`, `description`, `organisation`, `paid` (boolean), `hoursPerWeek`,
`startDate`, `endDate`, `noneToReport` (boolean, for an explicit nil report), `decision`,
`conflictReason`, `publish` (boolean), `userId`, `managerUserId`, `administrationId`.
Lifecycle on `status`: `melden` (to `gemeld`), `akkoord` (`gemeld` to `akkoord`),
`afwijzen` (`gemeld` to `afgewezen`, requires `conflictReason`), `beeindigen` (to
`beeindigd`). `NoSelfApprovalGuard` on `akkoord` and `afwijzen`.

### D4. The attestation is derived

`SideActivityAttestationListener` sets `Employee.nevenwerkzaamhedenGemeld` to true when the
employee has at least one `SideActivity` in `gemeld`, `akkoord` or `afgewezen`, or a nil
report, and false otherwise, writing through `InternalWriteMarker::runInternal()`. The
existing rule then measures a real report. Alternative considered: retiring the boolean.
Rejected: the rule, its seed and its audit history read it.

## Declarative-vs-imperative decision (ADR-031)

| behaviour | path | why |
|---|---|---|
| secondment and side activity states | declarative `x-openregister-lifecycle` | plain state machines |
| no self-approval | existing `NoSelfApprovalGuard` | cross-actor rule |
| secondment hours as committed time | imperative, `AgendaComposer` and `AvailabilityService` | the existing composition |
| attestation flag | imperative listener | a derived value across schemas that the rule reads |

## Seed data

- "Pieter Jansen" seconded to "Veiligheidsregio Fryslan" (KvK `00000000`, placeholder)
  16 hours a week from 2026-09-01 to 2027-02-28, status `actief`.
- "Ingrid de Boer" (publicSectorRegime `ambtenarenwet`) with a paid side activity
  "Bestuurslid woningcorporatie", status `gemeld`, awaiting her manager.

## Risks / Trade-offs

- [Existing true attestations without a register entry] → the listener only sets the flag
  on a register change; a migration note tells HR the flag becomes register-driven, and
  the first rules audit after upgrade still reads the old value until the employee files.

## Open Questions

- Which functions must have their side activities published is employer policy; this design
  records a per-activity `publish` flag rather than a function list.

## Build-time changes (2026-09-29)

Read against `development` bbe065fe while building.

- **Secondment share.** `AvailabilityService` commits `hoursPerWeek` over the employee's
  contracted week (the pattern hours of the seven days from the window start) as a share
  of each working day: 16 hours in a 36-hour week costs 16/36 of each day. The agenda entry
  spans the secondment's days in the window and carries `hoursPerWeek`. `ForwardCapacityService`
  inherits it. No `agreementFile` property: the signed agreement goes in the detail page's
  files widget, like every other humaniq document.
- **One listener for the register.** `SideActivityListener` (not `SideActivityAttestationListener`)
  places a new report on its employee (from the reporter's account when `employeeId` is left
  empty; only HR may report for someone else; a report needs a description or `noneToReport`),
  keeps `nevenwerkzaamhedenGemeld` in step after every create, update or delete, and puts
  the register's value back when someone changes that flag by hand on the employee.
  `SideActivityRegister` holds the rules.
- **The conflict reason is `decisionReason`**, so the existing `DecisionReasonGuard` refuses
  a refusal without one and, through `NoSelfApprovalGuard`, a decision by the employee.
- **Existing attestations stay** until the flag is edited or a report changes: the three
  seeded civil servants keep their `true`.
- **Seeds** use the seed's own people: Sam Jansen seconded 16 hours a week to
  Veiligheidsregio Fryslân (KvK left empty rather than invented), and Ingrid Doornbos
  (Ambtenarenwet) with a paid board seat awaiting a decision.
- **My side activities** is a menu preset on `SideActivities` (`userId = @me`), not a second
  index page (gate 68).
