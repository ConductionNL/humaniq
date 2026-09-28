# loonaangifte-correction

## ADDED Requirements

### Requirement: A sent wage tax return SHALL be corrected by a linked new filing (REQ-LHC-001)

The `corrigeren` action on a `LoonaangifteFiling` in status `verzonden` SHALL create a new
filing for the same period with `filingType` `correctie` and `corrects` set to the sent
filing, and SHALL leave the sent filing in status `verzonden` with its fields unchanged.

Rows: `fil-correction` (humaniq matrix).

#### Scenario: A payroll officer corrects March after it was sent
- **GIVEN** the March filing of an administration in status `verzonden`
- **WHEN** a payroll officer chooses Correct on that filing's detail page
- **THEN** a new filing for March in status `concept` exists that names the March filing as
  the one it corrects
- **AND** the March filing is still `verzonden` with its sent date and file unchanged

### Requirement: A correction SHALL carry only what changed, routed by tax year (REQ-LHC-002)

When a correction is made ready, humaniq SHALL compare the approved payslips of the period
with the snapshot the sent return was built from and store one correction line per employee
whose amounts differ. A correction for a period in the current tax year SHALL be routed to
the next regular return; one for a closed year SHALL be rendered as its own correction
message. A correction with no difference SHALL be refused.

Rows: `fil-correction` (humaniq matrix).

#### Scenario: One employee's retro pay rise is corrected
- **GIVEN** a sent March return for two employees and an approved retro rise for one of them
- **WHEN** the payroll officer makes the March correction ready
- **THEN** the correction holds one line, for that employee, with the changed amounts

#### Scenario: Nothing changed
- **GIVEN** a sent return and no change to the period's payslips since
- **WHEN** the payroll officer makes a correction ready
- **THEN** the action is refused with the message that there is nothing to correct
