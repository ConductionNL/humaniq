# Design: leave costs what the person would have worked

## Context

Read at `development` af702f78.

- `lib/Service/LeaveHoursCalculator.php` is pure and static. `workingDaysBetween()` (line 74)
  counts Monday to Friday and its docblock (lines 58-72) says public holidays are not
  subtracted. `requestHours()` (line 156) returns the explicit `hours` in the start year, or
  working days times `contractHoursPerWeek / 5`, with a `derivable` flag.
  `usedHoursFor()` (line 199) sums approved requests per balance.
- `lib/Service/LeaveBalanceProjectionService.php:96` loads every `LeaveRequest` and
  `LeaveBalance` and calls the calculator with the balance's `contractHoursPerWeek` snapshot.
  It is the only caller of `LeaveHoursCalculator` (`grep -rl LeaveHoursCalculator lib/`).
- `lib/Service/WorkingHoursService.php` is the single resolution point for contracted hours
  (REQ-WHP-003). `contractedHoursOn()` (line 117) takes the employee's `WorkingPattern`
  rows, `NonWorkingTime` rows and the calendar's non-working dates, and answers
  `{hours, patternOnly, patternFound, calendarApplied}`; `contractedHoursOver()` (line 183)
  sums a range. It is dependency-free: callers pass the rows in.
- `lib/Service/WorkingCalendarReader.php:111` `nonWorkingDates($from, $to)` resolves
  openregister's `WorkingCalendarService` by name and answers `{dates, resolved, reason}`,
  with `dates` null when unread, "so a caller cannot mistake an unread calendar for an empty
  one".
- `AbsenceRateService` (line 293) and `AvailabilityService` (line 106) already combine the
  two. `lib/Settings/register.d/hr-working-calendar.json` holds `WorkingPattern` and
  `NonWorkingTime` and no holiday.
- `LeaveRequestDetail` (`src/manifest.d/hr-leave.json:4`) shows the request data, related
  records and files; `lib/Controller/LeaveController.php` carries `settle` today
  (`appinfo/routes.php:82`).

## Goals / Non-Goals

**Goals**

- A leave day costs what the person was contracted to work that day.
- Feestdagen come from openregister's working calendar and nowhere else.
- Every cost says how it was worked out.

**Non-Goals**

- A humaniq holiday list or holiday schema (D19).
- Changing how an explicit `hours` value is treated.

## Decisions

### D1. One resolution point, called per day

`LeaveHoursCalculator::requestHours()` gains three inputs: the employee's patterns, their
non-working times and the calendar's non-working dates (or null). For a request without
explicit `hours`, it walks the days of the range inside the balance year and sums
`WorkingHoursService::contractedHoursOn()`. The calculator stays pure: the projection
service fetches the rows once and passes them in.

Alternative considered: a leave-specific holiday subtraction in the calculator. Rejected:
it would be the second derivation of contracted hours that `WorkingHoursService` exists to
prevent, and the absence rate and the leave cost could disagree about the same day.

### D2. The basis is always stated

`requestHours()` returns `basis`:

| basis | when |
|---|---|
| `explicit` | the request carries `hours` |
| `pattern` | a pattern is in force and the calendar was read |
| `pattern-only` | a pattern is in force and the calendar could not be read |
| `contract-average` | no pattern on file: working days times hours divided by five, skipping calendar non-working dates when the calendar was read |

`contract-average` keeps every employee without a pattern working as today, with the one
fix that a read calendar's holidays no longer cost leave.

### D3. The calendar is read once per projection

`LeaveBalanceProjectionService` asks `WorkingCalendarReader::nonWorkingDates()` for the
span of the requests it recomputes, once, and loads `WorkingPattern` and `NonWorkingTime`
filtered on the employee. An unread calendar is logged once with the reader's `reason`.

### D4. The cost is shown on the request

`GET /api/leave/requests/{id}/cost` (`#[NoAdminRequired]`, resolved through
`RbacObjectReader`, 404 when unreadable) answers per year the hours and the basis, and per
day `{date, hours, reason}` where `reason` is `pattern`, `feestdag`, `vrije-dag` or
`weekend`. A host section on `LeaveRequestDetail` hands the days to the library's
`CnDataTable`.

## Declarative-vs-imperative decision (ADR-031)

| behaviour | path | why |
|---|---|---|
| hours per day | imperative, `WorkingHoursService` via `LeaveHoursCalculator` | the existing single resolution point |
| public holidays | read from openregister's working calendar | D19: openregister owns the calendar |
| cost section on the request | declarative `bodyWidgets` with a host section | the library table exists |

## Seed data

No schema change. One seed employee with a Monday to Wednesday pattern gains an approved
two-day request over a Monday and Tuesday, and one request for a week holding a date the
seeded working calendar marks non-working, so both corrections show on the balance.

## Risks / Trade-offs

- [Balances move when this lands] → the first projection after release recomputes used hours
  from the pattern; the release note names it, and a request with explicit `hours` keeps its
  number.
- [No pattern for most employees on day one] → `contract-average` keeps today's figure for
  them, minus holidays when the calendar is read.

## Open Questions

- Should the cost be stamped on the request at approval, so the approver's figure stays on
  record even if the pattern later changes?
