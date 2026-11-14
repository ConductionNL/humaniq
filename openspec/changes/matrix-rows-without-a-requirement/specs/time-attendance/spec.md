# time-attendance

## ADDED Requirements

### Requirement: A manager SHALL see who is present, absent or late today (REQ-TA-007)

The attendance page SHALL offer a today view for the manager's team. Each person SHALL read as
present (clocked in), late (clocked in after their rostered or pattern start), away (approved
leave or sick) or not clocked in. Only people the caller may read SHALL be listed.

Rows: `tim-attendance-overview` (humaniq matrix).

#### Scenario: A team at 09:30
- **GIVEN** a team of four: one clocked in at 08:00, one at 09:15 with a 09:00 shift, one on approved leave and one not clocked in
- **WHEN** their manager opens the today view at 09:30
- **THEN** they read as present, late, away and not clocked in

#### Scenario: Another team stays hidden
- **WHEN** a manager opens the today view
- **THEN** people outside the units they may read are not listed

### Requirement: An employee SHALL clock in and clock out from My attendance (REQ-TA-008)

An employee SHALL start their working day from `My attendance` with one action that records the
current time as `clockIn` on a new open `AttendanceRecord`. The server SHALL stamp the record with
the caller's own employee and user id, so the employee never picks themselves. Clocking out SHALL
close the record through the existing `sluiten` transition, record the current time as
`clockOut`, and compute `workedHours` on the server from the clock times and the break.
An employee SHALL have at most one open record at a time.

Rows: `tim-clock-in` (humaniq matrix).

#### Scenario: An employee clocks in at the start of the day
- **GIVEN** an employee with a linked Nextcloud account and no open attendance record
- **WHEN** they press Clock in on `My attendance` at 08:30
- **THEN** an open attendance record for today exists with `clockIn` 08:30, their employee and their user id
- **AND** the record is listed on their `My attendance` page

#### Scenario: An employee clocks out at the end of the day
- **GIVEN** the open record from 08:30 with a 30 minute break
- **WHEN** they press Clock out at 17:00
- **THEN** the record is `gesloten` with `clockOut` 17:00 and `workedHours` 8.0

#### Scenario: A second clock in on an open day is refused
- **GIVEN** an employee whose record for today is still open
- **WHEN** they press Clock in again
- **THEN** no second record is created and the answer says a day is already open
