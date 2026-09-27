---
kind: code
---

# Leave costs what the person would have worked

## Why

A part-timer who works eight hours on Monday, Tuesday and Wednesday takes Monday and Tuesday
off. humaniq charges them 9.6 hours, not 16. `LeaveHoursCalculator` counts the Monday to
Friday days in the range and multiplies by contract hours divided by five, a flat 4.8 hours
a day for a 24-hour week. The same person asking for Thursday and Friday, days they never
work, is charged 9.6 hours as well. A week with Easter Monday in it costs the same as a week
without, and the class says so: "Public holidays are NOT subtracted, so a range covering one
overstates usage by a day."

humaniq already knows better. `WorkingHoursService` resolves a person's contracted hours per
day from their `WorkingPattern`, subtracts their `NonWorkingTime`, and zeroes the days
openregister's working calendar marks as feestdagen. The absence rate and the capacity
figures use it. Leave does not.

This change makes a leave request cost the hours the person would have worked on those days,
from the same resolution point, including the public holidays in openregister's calendar.
humaniq still defines no feestdag of its own: decision D19 in `a-working-calendar-per-person`
puts the national holidays in openregister's working calendar, and leave reads them there.

### Matrix rows (humaniq `openspec/parity/capabilities.json`)

| row | capability | humaniq today |
|---|---|---|
| `lve-hours-from-pattern` | Calculate the leave hours a request costs from the employee's working pattern and public holidays. | `partial`, built: hours are working days times contract hours divided by five |
| `lve-public-holidays` | Keep a list of public holidays and non-working days that leave calculations respect. | `no`, built.state `built`: `NonWorkingTime` is per person; leave math subtracts no holiday |

### Competitors rated yes

- `lve-hours-from-pattern`, AFAS Profit: "leave booking follows the specified roster
  including break duration" and it "integrates public holidays and closing days with leave
  automatically" (https://help.afas.nl/help/NL/SE/Hrm_Config_Leave_Sttngs.htm,
  https://help.afas.nl/help/NL/SE/110894.htm).
- `lve-hours-from-pattern`, Visma Raet Youforce: "hours for the requested days are
  prefilled from your werkpatroon and public holidays in the period are indicated"
  (https://www.ssc-ons.nl/content/uploads/2024/07/Handleiding-Mijn-Youforce-1.pdf).
- `lve-hours-from-pattern`, Loket.nl: "proposed leave hours are calculated from the working
  hours or work pattern, and the leave policy sets useHolidaysInCalculation"
  (https://developer.loket.nl/ApiDocs#tag/Leave/operation/GetProposedLeaveHoursByEmploymentId).
- `lve-hours-from-pattern`, Personio: "the system uses the work schedule assigned to
  employees when calculating time off"
  (https://support.personio.de/hc/en-us/articles/35372841985565-Create-work-schedules).
- `lve-hours-from-pattern`, OrangeHRM: "leave length uses the employee's work shift hours,
  the work week and half-day holidays" (orangehrm v5.9
  `src/plugins/orangehrmLeavePlugin/WorkSchedule/BasicWorkSchedule.php:79`).
- `lve-public-holidays`, AFAS Profit: "the closing days table holds national public holidays
  and company closing days" (https://help.afas.nl/help/NL/SE/App_GenSet_FctInt_DayOff.htm).
- `lve-public-holidays`, Visma Raet Youforce: "public holidays are shown in the leave
  calendar and flagged when inside a request"
  (https://www.ssc-ons.nl/content/uploads/2024/07/Handleiding-Mijn-Youforce-1.pdf).
- `lve-public-holidays`, Loket.nl: "national holidays per employer and custom holidays that
  can be added" (https://developer.loket.nl/ApiDocs#tag/National-holiday).
- `lve-public-holidays`, Personio: "custom bank holiday calendars"
  (https://support.personio.de/hc/en-us/articles/22919070507037-Create-and-manage-custom-bank-holiday-calendars).
- `lve-public-holidays`, OrangeHRM: "holidays API with full or half day and recurring flag"
  (orangehrm v5.9 `src/plugins/orangehrmLeavePlugin/config/routes.yaml:9`).

### Recorded follow-ups and decisions this change follows

- `a-working-calendar-per-person` (open change), decision D19: openregister owns which days
  are feestdagen; humaniq owns the person's pattern and non-working times, "never writes to
  it and never defines a feestdag". This change reads that calendar and adds no holiday list.
- `LeaveHoursCalculator::workingDaysBetween()` docblock names the holiday overstatement and
  the explicit `hours` value as the manual correction. This change removes the need for it.

## What Changes

- **Hours from the pattern.** For each day of a request, the cost is the person's contracted
  hours that day from `WorkingHoursService::contractedHoursOn()`: the `WorkingPattern` in
  force, minus their `NonWorkingTime`, zero on a day openregister's working calendar marks
  non-working.
- **Public holidays from openregister, read not owned.** The calendar's non-working dates
  come through the existing `WorkingCalendarReader`. When it cannot be read, the cost is
  computed from the pattern alone and says so; humaniq never substitutes a list of its own.
- **A stated basis on every cost.** Each computed cost carries its basis: `explicit` (the
  request's own `hours`), `pattern`, `pattern-only` (calendar unread) or `contract-average`
  (no pattern on file, the current formula, now also skipping calendar holidays).
- **The cost is visible before approval.** `LeaveRequestDetail` shows the hours the request
  costs per year with a day-by-day breakdown naming each feestdag and free day.

## Capabilities

### New Capabilities

- `leave-hours-from-pattern`: leave cost computed per day from the working pattern,
  non-working times and openregister's working calendar, with a stated basis.

## Impact

- `lib/Service/LeaveHoursCalculator.php`: `requestHours()` takes the employee's patterns,
  non-working times and the calendar's non-working dates, and returns the basis.
- `lib/Service/LeaveBalanceProjectionService.php`: loads `WorkingPattern`, `NonWorkingTime`
  and the calendar dates once per projection.
- `lib/Controller/LeaveController.php` and `appinfo/routes.php`:
  `GET /api/leave/requests/{id}/cost`.
- `src/manifest.d/hr-leave.json` and `src/registry.js`: a cost section on
  `LeaveRequestDetail`.
- No schema change. No holiday schema, now or later.

## Cross-app dependencies

- **openregister**: the working calendar that marks national holidays non-working, published
  through `WorkingCalendarService::nonWorkingDates()` as `a-working-calendar-per-person`
  already reads it. Without it every cost is marked `pattern-only`.

## Out of scope

- Company closing days that are not public holidays. They belong in openregister's calendar
  too, under D19, or as a person's non-working time.
- Allocating the hours across balances. That is `leave-expiry-and-carry-over`.
- Half-day holidays. The calendar answers per date; a half day is recorded as a partial
  non-working time.
