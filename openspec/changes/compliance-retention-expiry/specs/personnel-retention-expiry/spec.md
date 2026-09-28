# personnel-retention-expiry

## ADDED Requirements

### Requirement: A record past its statutory floor SHALL be marked for destruction (REQ-RET-001)

When the admin has switched retention expiry on, a daily job SHALL mark every `Payslip`,
`PayrollRun`, `LoonaangifteFiling` and `PensionFiling` whose humaniq floor hold has lapsed,
and every `Employee` whose employment ended more than seven full years ago, with the appraisal
`vernietigen` and the floor date as `archiefactiedatum` in its retention block, unless the
record already carries an appraisal.

Rows: `cmp-retention` (humaniq matrix).

#### Scenario: An employee who left in 2017 is marked
- **GIVEN** retention expiry is switched on and an employee whose `endDate` is 2017-05-31
- **WHEN** the daily job runs in 2026
- **THEN** the employee's retention block carries the appraisal `vernietigen` and the action
  date 2024-12-31

### Requirement: Expired records SHALL reach OpenRegister's destruction list (REQ-RET-002)

When the admin has switched retention expiry on, a daily job SHALL release every legal hold
humaniq placed for a statutory floor whose date has passed, and SHALL leave every other hold
in place, so OpenRegister's destruction check can list the record for an archivist to
approve. While the switch is off the job SHALL release nothing.

Rows: `cmp-retention` (humaniq matrix).

#### Scenario: An eight year old payslip is listed for destruction
- **GIVEN** retention expiry is switched on and a payslip whose floor hold ran out last year
- **WHEN** the daily jobs have run
- **THEN** the hold is released and the payslip is on a destruction list awaiting approval

#### Scenario: A hold for a dispute stays
- **GIVEN** a payslip past its floor whose hold an HR officer replaced with one for a dispute
- **WHEN** the daily job runs
- **THEN** the hold stays active and the payslip is not marked for destruction

#### Scenario: Nothing happens until an admin says so
- **GIVEN** retention expiry is switched off
- **WHEN** the daily job runs
- **THEN** no hold is released and the log names the holds it would have released
