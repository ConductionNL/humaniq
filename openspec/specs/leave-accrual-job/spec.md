---
capability: leave-accrual-job
status: done
built_by: openspec/changes/archive/2026-07-14-leave-accrual-job
---

# leave-accrual-job Specification

**Status**: done
**Scope**: humaniq
**OpenSpec changes**:
- [leave-accrual-job](../../changes/archive/2026-07-14-leave-accrual-job/) _(archived 2026-07-14)_ — `LeaveAccrualJob`, a monthly `OCP\BackgroundJob\TimedJob` that provisions each active employee's current-year holiday `LeaveBalance` with the full statutory (BW 7:634) entitlement and accrues the bovenwettelijk opbouw slice, idempotently per `(employee, year, month)` via the new additive nullable `lastAccruedPeriod` marker, gated by a `leave_accrual_enabled` toggle, written through OpenRegister's ObjectService (kind: code)

## Purpose

The leave-verzuim MVP shipped the `LeaveBalance` ledger — per-employee,
per-year, per-leave-type entitlement/usage with a declarative
`remainingHours` calculation and three machine-checkable rules
(`nl-verlof-wettelijk-minimum`, `nl-verlof-saldo-niet-negatief`,
`nl-verlof-vervaltermijn`) — but nothing produced those balances: every
balance was hand-typed test data. This capability delivers the automatic
build-up (opbouw) of the entitlement itself: statutory holiday leave
(`4 × contractual weekly working time` per BW art. 7:634) granted in full up
front so the mandatory minimum never regresses mid-year, and bovenwettelijk
(above-statutory) hours accrued 1/12 per month worked — a small, focused
background job that keeps every existing mandatory leave rule green.

## Requirements

### Requirement: A monthly TimedJob SHALL accrue holiday leave onto every active employee's LeaveBalance (REQ-ACCR-001)

`lib/BackgroundJob/LeaveAccrualJob.php` SHALL be an `OCP\BackgroundJob\TimedJob` registered via a
background-jobs block in `appinfo/info.xml`. Each run SHALL enumerate
active employees — `loadAll('Employee')` filtered by `coversPeriod(startDate, endDate,
currentPeriod)` (the `PayrollRunService` idiom) — resolve each one's covering `EmploymentContract`
and read its `hoursPerWeek`, and write the current-year `holiday` `LeaveBalance` through
OpenRegister's ObjectService (container-resolved `OCA\OpenRegister\Service\ObjectService`, register
`humaniq`, `saveObject`), degrading soft with a logged warning rather than throwing. Correctness SHALL
NOT depend on the job's fire interval — the per-balance period guard (REQ-ACCR-004) defines the
"once a month" semantics.

#### Scenario: The registered job accrues each active employee once when it runs

- **GIVEN** the app is enabled (so `OCA\Humaniq\BackgroundJob\LeaveAccrualJob` is enrolled in the job
  list) and one active employee with a covering contract of 40 hours/week
- **WHEN** the TimedJob's `run()` executes for period 2026-07
- **THEN** the employee's `(2026, holiday)` `LeaveBalance` is written through ObjectService and the
  run's log summary counts exactly one processed employee

#### Scenario: An employee inactive in the period is not accrued

- **GIVEN** an employee whose `endDate` is before the current period's first day
- **WHEN** the job runs
- **THEN** no `LeaveBalance` is created or changed for that employee

### Requirement: Statutory entitlement SHALL be provisioned in full so the mandatory minimum stays green (REQ-ACCR-002)

On the first accrual of a calendar year for an employee the job SHALL provision
`entitledHours = 4 × hoursPerWeek` (the full BW art. 7:634 annual statutory figure — the increment
computed from contractual hours), snapshot `contractHoursPerWeek = hoursPerWeek`, and set
`expiryDate` to 1 July of the following year (2027-07-01 for year 2026), so that `nl-verlof-wettelijk-minimum`
(`entitledHours >= 4 × contractHoursPerWeek`) and `nl-verlof-vervaltermijn` both hold from the
first write. The job MUST NOT build `entitledHours` up in monthly slices (which would violate the
mandatory minimum from January through November), and MUST NOT lower an already-granted year's
`entitledHours`.

#### Scenario: A 40-hour employee is provisioned at the statutory minimum

- **GIVEN** an active employee with a covering contract of 40 hours/week and no existing 2026
  holiday balance
- **WHEN** the job runs for period 2026-07
- **THEN** the created `LeaveBalance` carries `entitledHours` 160, `contractHoursPerWeek` 40 and
  `expiryDate` 2027-07-01

#### Scenario: A job-provisioned balance passes the mandatory verlof rules

- **GIVEN** the balance provisioned in the previous scenario
- **WHEN** `occ humaniq:rules:audit` runs the `labour.json` corpus over it
- **THEN** `nl-verlof-wettelijk-minimum` and `nl-verlof-vervaltermijn` report zero violations for it

### Requirement: Bovenwettelijk hours SHALL accrue as a monthly opbouw slice (REQ-ACCR-003)

Each accrued month the job SHALL add `round1(annualBovenwettelijk / 12)` to `bovenwettelijkHours`,
where `annualBovenwettelijk` is `SettingsService::getLeaveBovenwettelijkAnnualHours()` (config
`leave_bovenwettelijk_annual_hours`, default `0` — statutory-only until an employer configures it).
Because accrual only ever grows `entitledHours + bovenwettelijkHours` and never touches `usedHours`,
`nl-verlof-saldo-niet-negatief` (`usedHours <= entitledHours + bovenwettelijkHours`) SHALL never be
pushed negative by the job.

#### Scenario: A configured annual bovenwettelijk accrues one twelfth per month

- **GIVEN** `leave_bovenwettelijk_annual_hours` is 48 and an employee whose 2026 holiday balance was
  provisioned in a prior month with `bovenwettelijkHours` 4
- **WHEN** the job accrues that balance for the next period
- **THEN** `bovenwettelijkHours` becomes 8 (one further `round1(48/12) = 4` slice)

#### Scenario: Default zero bovenwettelijk leaves the balance statutory-only

- **GIVEN** `leave_bovenwettelijk_annual_hours` is at its default 0
- **WHEN** the job provisions and later accrues a balance
- **THEN** `bovenwettelijkHours` stays 0 and only the statutory `entitledHours` is granted

### Requirement: Accrual SHALL be idempotent, keyed by (employee, year, month) via lastAccruedPeriod (REQ-ACCR-004)

`LeaveBalance` SHALL gain one additive **nullable** `lastAccruedPeriod` (`YYYY-MM`) property (the
only schema change; the `remainingHours` calculation and all other fields are untouched, and every
pre-existing row keeps `lastAccruedPeriod` null). The job SHALL record the accrued month in
`lastAccruedPeriod`, and SHALL treat a balance whose `lastAccruedPeriod` already equals the current
period as a no-op — no write. A given `(employee, year, month)` therefore accrues at most once
regardless of how often the TimedJob fires.

#### Scenario: A second run in the same month writes nothing

- **GIVEN** a 2026 holiday balance already accrued for period 2026-07 (`lastAccruedPeriod` 2026-07)
- **WHEN** the job runs again in 2026-07
- **THEN** no ObjectService write occurs for that balance and every figure is unchanged

#### Scenario: The next month accrues exactly one slice

- **GIVEN** the balance from the previous scenario (`lastAccruedPeriod` 2026-07)
- **WHEN** the job runs for period 2026-08
- **THEN** one bovenwettelijk slice is added and `lastAccruedPeriod` becomes 2026-08

### Requirement: The job SHALL be operator-toggleable and SHALL skip employees it cannot resolve (REQ-ACCR-005)

`run()` SHALL read `SettingsService::isLeaveAccrualEnabled()` (config `leave_accrual_enabled`,
default `true`) first and return immediately with zero writes when disabled. Employees the job
cannot resolve honestly — no covering `EmploymentContract`, no `hoursPerWeek`, or no resolvable
employee identity — SHALL be skipped with a counted reason in the run's log summary, never computed
wrong and never silently dropped (the `PayrollRunService` skip-reporting precedent).

#### Scenario: Disabled config no-ops the whole run

- **GIVEN** `leave_accrual_enabled` is false
- **WHEN** the TimedJob's `run()` executes
- **THEN** it returns without enumerating employees and no `LeaveBalance` is created or changed

#### Scenario: An employee without a covering contract is skipped with a reason

- **GIVEN** an active employee with no `EmploymentContract` covering the current period
- **WHEN** the job runs
- **THEN** no `LeaveBalance` is written for that employee and the log summary counts one skip with a
  `no-covering-contract` reason
