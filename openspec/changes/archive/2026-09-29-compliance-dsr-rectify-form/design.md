# Design: the Rectify button collects the correction it applies

## Context

Read at `development` af702f78.

- `DsrRequest` (`lib/Settings/register.d/hr-dsr.json`): `employeeId`, `right`, `status`,
  `receivedDate`, `deadlineDate`, `handledBy`, `completedDate`, `outcomeSummary`,
  `retainedObjectRefs`, `rejectionReason`. No field holds the requested correction.
- `src/manifest.d/hr-dsr.json:93-106`: header action `dsr-rectify`, `type: api-call`,
  `POST /api/dsr/rectify` with params `employeeId: @object.employeeId`,
  `dsrRequestId: @objectId`, no `changes`. The page note (`:41`) names the missing prompt a
  fast-follow.
- `lib/Controller/AvgDsrController.php:206` `rectify()` calls
  `validateRectifyInput()` (`:248`), which refuses unless `changes` is a non-empty array, then
  `guardAdminAndEmployee()` and `AvgDsrService::rectifySubjectObject()` (`:332`), which records
  the outcome through `recordRectifyOutcome()`.
- `lib/Command/AvgDsrRectifyCommand.php` sends a JSON map and works.
- The library's api-call action resolves `@object.<field>` tokens from the loaded object.

## Goals / Non-Goals

**Goals**

- The page button succeeds with a correction HR entered and reviewed on the request.

**Non-Goals**

- A generic prompt in the action type. That belongs in nextcloud-vue if ever needed.

## Decisions

### D1. The correction is data on the request

`requestedChanges`: array of `{field, value}`, where `field` is an enum of the allowed
`Employee` properties (`firstName`, `lastName`, `dateOfBirth`, `iban`, `tenaamstelling`,
and the address fields when `people-record-change-approval` adds them). Alternative
considered: an `open-form` action. Rejected: `open-form` creates an object, it does not
collect parameters for an endpoint.

### D2. Two input shapes, one map

`validateRectifyInput()` accepts a map (occ) or a list of pairs (page); the list becomes a map
before the service is called. A field outside the allowed list is refused with 400.

## Declarative-vs-imperative decision (ADR-031)

| behaviour | path | why |
|---|---|---|
| holding the correction | declarative schema property | data |
| applying it | existing imperative `AvgDsrService` | unchanged, guarded path |

## Seed data

- One `DsrRequest` with `right: rectificatie` and `requestedChanges`
  `[{field: "lastName", value: "de Vries-Jansen"}]` on a seed employee.

## Risks / Trade-offs

- [A wrong value typed on the request] → nothing is applied until HR presses Rectify with the
  existing confirmation, and the audit trail keeps the previous value.

## Open Questions

- None.

## Build-time changes (2026-09-29)

Read against `development` 82dd772d plus humaniq#560 to #562 while building.

- **The allowed list.** `AvgDsrController::RECTIFIABLE_FIELDS` and the schema enum hold the
  same ten fields: `firstName`, `lastName`, `dateOfBirth`, `iban`, `tenaamstelling` and the
  address fields `straat`, `huisnummer`, `postcode`, `woonplaats`, `land`, which Employee
  already carries. A declaration test keeps the two lists equal.
- **Both shapes are checked.** The allowed list applies to a map from an API caller as well
  as to the page's list of pairs; a pair without a field is refused too. The occ command
  calls `AvgDsrService` directly and is unchanged.
- **The surface stays admin-only.** The scenario's "HR administrator" is an administrator:
  the endpoint's guard (`DsarService::assertPrivileged()`) is unchanged.
- **Seed.** `dsr-devries-rectify-surname` sits in `hr-dsr.json` next to the other DSR seeds,
  on Sanne de Vries (`employee-devries`).
- **Not checked here:** whether the library's edit form renders an array of pairs well. The
  list is shown and edited through the `data` widget on `DsrRequestDetail`; the live check
  in the PR says what to look at.
