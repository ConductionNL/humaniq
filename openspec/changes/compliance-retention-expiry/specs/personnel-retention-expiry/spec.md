# personnel-retention-expiry

## ADDED Requirements

### Requirement: Retained personnel records SHALL carry a retention end date (REQ-RET-001)

`Payslip`, `PayrollRun`, `LoonaangifteFiling`, `PensionFiling`, `GeneratedDocument` and
`Employee` SHALL declare an OpenRegister `archive` configuration so every saved object carries
an `archiefactiedatum` no earlier than its statutory retention end.

Rows: `cmp-retention` (humaniq matrix).

#### Scenario: A payslip knows when it may go
- **GIVEN** a payslip for March 2026
- **WHEN** it is saved
- **THEN** its retention block carries an action date no earlier than 31 December 2033

### Requirement: Expired records SHALL reach OpenRegister's destruction list (REQ-RET-002)

When the admin has switched retention release on, a daily job SHALL release every legal hold
humaniq placed for a statutory floor whose date has passed, and SHALL leave every other hold
in place, so OpenRegister's destruction check can list the record for an archivist to
approve. While the switch is off the job SHALL release nothing.

Rows: `cmp-retention` (humaniq matrix).

#### Scenario: An eight year old payslip is listed for destruction
- **GIVEN** retention release is switched on and a payslip whose floor hold ran out last year
- **WHEN** the daily jobs have run
- **THEN** the hold is released and the payslip is on a destruction list awaiting approval

#### Scenario: A hold for a dispute stays
- **GIVEN** a payslip past its floor with a second hold an HR officer placed for a dispute
- **WHEN** the daily job runs
- **THEN** only the humaniq floor hold is released and the payslip is not deleted

#### Scenario: Nothing happens until an admin says so
- **GIVEN** retention release is switched off
- **WHEN** the daily job runs
- **THEN** no hold is released and the log names the holds it would have released
