# Design: changes to an employee record wait for the right approver

## Context

Read at `development` af702f78.

- `Employee` (`lib/Settings/register.d/hr-objects.json`) carries identity, pay and
  compliance fields (`iban`, `tenaamstelling`, `grossMonthlySalary`, `dateOfBirth`, `bsn`,
  `nextcloudUserId` and more). It has no `x-openregister-lifecycle` and no address fields.
  `iban` carries a shape pattern only.
- `EmployeeDetail` (`src/manifest.d/hr-objects.json:4`) edits the record directly. Mijn HR
  pages filter on `userId: "@me"` (`src/manifest.d/personal-dashboard.json`); `Employee`
  carries `nextcloudUserId`, which is the field a self page filters on.
- Roles per administration exist: `AdministrationAccess.role` with `accountant`, `hr`,
  `employee` (`lib/Settings/register.d/hr-administratie.json`). The manager relation is the
  denormalised `managerUserId` from `mss-team-scope`, and `OrgResolutionService` resolves an
  employee's unit and its manager.
- Guards: `lib/Lifecycle/NoSelfApprovalGuard.php` refuses an approval by the subject;
  guards are registered in `lib/AppInfo/Application.php:125`.
- Pre-save refusal: `lib/Listener/ResourceBookingOverlapListener.php` listens on
  OpenRegister's `ObjectCreatingEvent` and `ObjectUpdatingEvent` and refuses a write, fail
  closed. `lib/Service/InternalWriteMarker.php` marks humaniq's own writes so such a
  listener can exempt them (`runInternal()`).
- Side effects on a transition: `lib/Listener/LeaveApprovalListener.php` reacts to a saved
  `LeaveRequest` and hands it to a service; the same shape fits applying a request.

## Goals / Non-Goals

**Goals**

- A change of a guarded kind takes effect only after the configured role approves it, no
  matter which page or API the change came from.
- An employee can see their own record and ask for an address or bank account change.
- The rule per kind of change is data an administrator edits, not code.

**Non-Goals**

- A general workflow designer. One approver role per kind of change.
- Guarding schemas other than `Employee`.

## Decisions

### D1. A request object with a declarative lifecycle

`EmployeeChangeRequest`: `employeeId` ($ref Employee), `changeKind` (enum `adres`,
`bankrekening`, `persoonsgegevens`, `salaris`, `contractvoorwaarden`), `changes` (object of
field name to proposed value), `previousValues` (object, stamped at submission),
`requestedBy`, `approverRole`, `status`, `decidedBy`, `decidedAt`, `rejectionReason`,
`userId`, `managerUserId`, `administrationId`. Lifecycle on `status`: `indienen`
(to `ingediend`), `goedkeuren` (`ingediend` to `goedgekeurd`), `afwijzen` (`ingediend` to
`afgewezen`, requires `rejectionReason`). Alternative considered: a lifecycle on `Employee`
itself. Rejected: it would freeze the whole record while one field waits.

### D2. The rule decides the approver, a guard enforces it

`ChangeApprovalRule`: `changeKind`, `fields` (array of `Employee` property names),
`approverRole` (enum `hr`, `accountant`, `manager`, `none`), `administrationId`. Seeded
defaults: `adres` none, `bankrekening` hr, `persoonsgegevens` hr, `salaris` accountant,
`contractvoorwaarden` hr. `ChangeApproverRoleGuard` on `goedkeuren` and `afwijzen` allows
the transition only for a user holding `approverRole` in the request's administration
(`AdministrationAccess`) or, for `manager`, the request's `managerUserId`.
`NoSelfApprovalGuard` is added to both transitions too.

### D3. Guarded fields are refused on direct save

`EmployeeGuardedFieldListener` on `ObjectUpdatingEvent` for `Employee` compares the incoming
values with the stored ones; when a changed field is covered by a rule whose `approverRole`
is not `none`, it refuses the write with a message naming the change kind. It is exempt
when `InternalWriteMarker` is set, which is how the apply step writes. Fail closed: a rule
table that cannot be read refuses the write. Create is not guarded (a new employee is
entered by HR in one go).

### D4. Applying is one internal write

`ChangeRequestService::apply()` runs when a request enters `goedgekeurd` (listener on the
saved request, the `LeaveApprovalListener` shape). It re-reads the employee, checks the
current values still equal `previousValues` (otherwise it refuses and marks the request as
stale for HR to redo), and writes `changes` inside `InternalWriteMarker::runInternal()`. A
kind with approver `none` is applied at submission by the same service.

### D5. Address fields

`Employee` gains `straat`, `huisnummer`, `postcode` (pattern `^[1-9][0-9]{3} ?[A-Z]{2}$`
for NL, free for other countries), `woonplaats`, `land` (ISO 3166 alpha-2, default `NL`).
All nullable; titles and descriptions follow gate 28.

## Build-time notes (2026-09-29)

- The request starts in `ingediend` (a `concept` state exists for a saved draft, `indienen`
  moves it on). `ChangeRequestListener` hands the object events to `ChangeRequestService`:
  on create it fills `employeeId` from the requester's account when empty, merges the flat
  form fields (`straat`, `huisnummer`, `postcode`, `woonplaats`, `land`, `iban`,
  `tenaamstelling`) into `changes`, refuses a field the kind's rule does not cover, stamps
  `previousValues`, `approverRole`, `requestedBy`, `userId`, `managerUserId`,
  `administrationId`; a kind with approver `none` is approved at once only on the
  employee's own record (someone else's waits for `hr`). A rejection without
  `rejectionReason` is refused. The apply step records `appliedAt`, or `applyError` when
  stale.
- `ChangeApproverRoleGuard` includes the no-self rule itself (subject `userId` and
  `requestedBy`), so the transition needs one `requires`.
- `AvgDsrService::rectifySubjectObject()` now writes under `InternalWriteMarker`: an AVG
  rectification is an administrator's decided procedure and must not be sent to a request.
- `MijnGegevens` is the Employee index with base filter `nextcloudUserId: @me`;
  `MijnWijzigingsverzoeken` the request index with `userId: @me`, whose create form is the
  self-service request.

## Declarative-vs-imperative decision (ADR-031)

| behaviour | path | why |
|---|---|---|
| request states and transitions | declarative `x-openregister-lifecycle` on `EmployeeChangeRequest` | a plain state machine |
| who may approve | lifecycle guard `ChangeApproverRoleGuard` | a cross-actor rule the state machine cannot express (ADR-031 guard exception) |
| refusing a direct edit of a guarded field | imperative pre-save listener | a write-time refusal across every caller, the `ResourceBookingOverlapListener` precedent |
| applying approved values | imperative `ChangeRequestService` | a cross-object write with a staleness check |
| approval queue and self page | declarative manifest pages | index and detail pages suffice |

## Seed data

- `ChangeApprovalRule` rows for the five kinds with the defaults in D2, for the seed
  administration.
- Two `EmployeeChangeRequest` rows: an address change by employee "Fatima Yilmaz" already
  applied (approver none), and a bank account change by "Jan de Vries" waiting in
  `ingediend` for an HR user, IBAN a safe placeholder (`NL00BANK0123456789`).
- One seed employee gains an address (Stationsplein 1, 1234 AB Utrecht, NL).

## Risks / Trade-offs

- [Existing integrations write guarded fields directly] → they are refused after this
  lands. The refusal message names the change kind; an integration that must write
  directly runs as an approver-less kind or goes through the request API.
- [Stale requests] → the staleness check in D4 refuses rather than overwrites a value
  changed after the request was filed.

## Open Questions

- Should an HR adviser's own edit of a guarded field create the request automatically
  instead of being refused? This design refuses and offers the request, which is simpler
  to audit.
