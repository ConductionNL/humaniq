# Design: employee relations cases

## Context

Read at `development` af702f78, plus the change it depends on.

- No humaniq schema declares `authorization` today (the manifest-fragment change counted 0
  of 55). OpenRegister enforces schema-level and property-level rules through
  `AuthorizationService` and `PropertyRbacHandler`; a property rule reads
  `authorization: {read: [{group, match}], update: [...]}`
  (`openregister/lib/Service/PropertyRbacHandler.php`). ADR-001 forbids custom permission
  checks in an app; `aor-ambtenarenrecht` F-009 records that confidentiality tiering is
  OpenRegister's to enforce.
- `compliance-roles-and-field-access` (this pass) introduces humaniq's HR, payroll and
  manager Nextcloud groups and the first `authorization` blocks; this change uses its HR
  group.
- Retention: `document-dossier-avg` added `nl-bewaartermijn-verstreken`, a recommended flag
  for a record present past its own `retainedUntil`; `AvgDsrService` protects records inside
  a retention window from erasure.
- `EmployeeDetail` (`src/manifest.d/hr-objects.json:4`) lists related records per schema.
- `lib/Lifecycle/NoSelfApprovalGuard.php` exists for cross-actor rules.

## Goals / Non-Goals

**Goals**

- A case record whose content only HR can read, enforced by OpenRegister, not by a page.
- The employee can see measures taken against them once closed.

**Non-Goals**

- A procedure engine with hearing terms and appeals.
- Medical information. A case never records a diagnosis (REQ-VWP-002 posture).

## Decisions

### D1. Schema

`EmployeeRelationsCase`: `employeeId` ($ref), `kind` (`klacht`, `conflict`,
`schriftelijke-waarschuwing`, `disciplinaire-maatregel`, `anders`), `openedOn`, `facts`
(text), `measure` (text, nullable), `measureFrom`, `measureUntil`, `outcome` (text),
`closedOn`, `retainedUntil`, `documents` (files), `status`, `userId` (the employee's
Nextcloud id), `managerUserId`, `administrationId`. Lifecycle: `in-behandeling-nemen`
(`geopend` to `in-behandeling`), `afsluiten` (`in-behandeling` to `afgesloten`, requires
`outcome` and `closedOn`), `heropenen` (`afgesloten` to `in-behandeling`).

### D2. Authorization

Schema-level: read, create, update and delete for the HR group; read for the subject
(`match: {userId: "$userId"}`) only when `status` is `afgesloten` and `kind` is
`schriftelijke-waarschuwing` or `disciplinaire-maatregel`. Property-level: `facts`,
`measure`, `outcome` and `documents` readable by the HR group and by the subject under the
same condition; the manager reads `kind`, `status` and `openedOn` only (a
`managerUserId: "$userId"` match), so the list on `EmployeeDetail` shows that a case exists.
Alternative considered: a humaniq controller filtering fields. Rejected by ADR-001 and by
the F-009 decision.

### D3. Retention

On `afsluiten`, `retainedUntil` is set to `closedOn` plus two years unless HR sets it
otherwise (a derived value on the transition through `x-openregister-calculations` where
the lifecycle can set it; otherwise the listener shape of `LeaveApprovalListener`). The case
joins the schemas `nl-bewaartermijn-verstreken` reads.

## Declarative-vs-imperative decision (ADR-031)

| behaviour | path | why |
|---|---|---|
| case states | declarative `x-openregister-lifecycle` | a state machine |
| who reads what | declarative `authorization` blocks | OpenRegister enforces it (ADR-001, F-009) |
| default retention date | declarative calculation, listener fallback | derived from `closedOn` |
| retention flag | existing corpus rule | reuse |

## Seed data

- A closed written warning for "Mark Visser" (late arrival, three times in a month) with a
  placeholder letter file, `retainedUntil` two years after closing.
- An open grievance by "Lisa Smit" about workload, status `in-behandeling`.

## Risks / Trade-offs

- [First authorization blocks in humaniq] → the dependency change lands the HR group and
  the pattern first; this change only applies it to one schema, and a test reads the case
  as a manager and as another employee to prove the refusal.
- [Two-year default is a policy choice] → it is an editable field; the default and its
  source (Autoriteit Persoonsgegevens guidance on personnel files) are named on the schema.

## Open Questions

- Should a grievance be visible to the employee who filed it while open? This design shows
  only closed warnings and measures to the subject.

## Changes during the build (2026-09-29)

- **Closing is declarative.** `afsluiten` declares `inputs` `outcome` and `closedOn` as
  required; OpenRegister's TransitionEngine refuses the transition without them. No guard class.
- **Retention by listener.** `RelationsCaseListener` sets `retainedUntil` to `closedOn` plus two
  years when a case is saved as `afgesloten` without one; a date HR set is kept. A calculation
  would overwrite HR's date, so the listener shape was used.
- **Accounts are stamped, not typed.** The authorization matches on `userId` and
  `managerUserId`, so the listener derives both from the employee (`nextcloudUserId`, and the
  org chain through `HoursRegisterGateway::uniqueManagerUserIdFor()`) on every create and
  update and puts a hand-set value back. Otherwise anyone with update rights could make a case
  readable to another account.
- **Schema-level read for the manager.** D2 gave the manager property-level reads; the manager
  also needs a schema-level read (`managerUserId: $userId`) to see the case at all. The subject's
  schema-level read carries the same condition as their property reads (`status` afgesloten,
  `kind` in written warning or disciplinary measure).
- **Letters as references, not object files.** OpenRegister serves an object's files to everyone
  who may read the object, and the manager may read the case, so a letter attached as an object
  file would reach the manager. `documents` holds references (paths in HR's files) under the
  property rules instead. A Files widget on the detail page is deliberately absent.
- **MijnMaatregelen is a menu preset**, My HR > My warnings and measures on `RelationsCases`
  with `userId = @me`, the side activities precedent; the authorization does the filtering.
- **Seeds use existing employees.** A closed warning for Noa Visser (closed 2023-03-20, kept
  until 2025-03-20, so the rules audit flags it) and an open grievance for Sanne de Vries.
