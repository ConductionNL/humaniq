# time-entries-summary Specification

## Purpose
Another app can show a user the hours they booked on a day, without reading anyone else's.

## Requirements

### Requirement: A user reads their own hours of a day

`GET /api/time-entries/summary` SHALL answer `{date, hours, entries}` for the calling user's time entries of `date`
(`YYYY-MM-DD`, today when left out): `hours` the sum of their `hours`, rounded to two decimals, `entries` how many
counted. It SHALL resolve the user from the session only and SHALL NOT count an entry of another user. An entry without
numeric hours SHALL add nothing. Without a session it SHALL answer 401, on a date that is not a day 400, and when the
entries cannot be read 500 without `hours`.

#### Scenario: Three bookings and a running timer

- **GIVEN** alice booked 1.5, 0.75 and 0.5 hours on 2026-10-07 and has a timer running
- **WHEN** alice asks the summary of 2026-10-07
- **THEN** the answer is `{date: "2026-10-07", hours: 2.75, entries: 3}`

#### Scenario: Someone else's row

- **GIVEN** the store hands back an entry of bob beside alice's
- **THEN** alice's summary does not count bob's hours
