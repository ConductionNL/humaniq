# payroll-mutation-lineage

## ADDED Requirements

### Requirement: Each calculated payslip SHALL carry one line per component with its source (REQ-PML-001)

When humaniq calculates a payslip it SHALL store one line per component (salary or hourly
pay, bijtelling, sick-pay adjustment, each retro correction, each leave transaction, each wage
garnishment, and each further fold that names a source), each with its amount, the source
object, and the audit entry of that object's last change before the calculation. A
recalculated draft payslip SHALL keep only its current lines. Summed figures on the payslip
SHALL stay unchanged.

Rows: `dm-mutation-lineage` (humaniq matrix), changelog https://loket.nl/roadmap/.

#### Scenario: Two corrections are two lines
- **GIVEN** two applied retro corrections of 100.00 and 50.00 settling in 2026-06 for one
  employee
- **WHEN** a payroll officer calculates the 2026-06 run and opens the employee's payslip
- **THEN** the payslip shows a retro total of 150.00 and two retro lines, each linking to its
  correction

#### Scenario: Recalculation replaces lines
- **GIVEN** a draft run whose payslip has a bijtelling line
- **WHEN** the vehicle assignment is ended and the run is recalculated
- **THEN** the payslip has no bijtelling line any more

### Requirement: The salary line SHALL name the raise or the edit that set the salary (REQ-PML-002)

The salary line SHALL name the latest applied raise whose proposed salary equals the salary the
engine read, and otherwise the audit entry of the last edit to the employee's salary, with who
made it and when. When the audit trail cannot be read, the line SHALL still name the employee
record and SHALL leave the audit entry empty.

Rows: `dm-mutation-lineage` (humaniq matrix), changelog https://loket.nl/roadmap/.

#### Scenario: A raise explains the salary
- **GIVEN** a raise from 3800.00 to 3876.00 applied on 1 July 2026
- **WHEN** the 2026-07 run is calculated
- **THEN** the salary line of 3876.00 links to that raise

#### Scenario: A direct edit explains the salary
- **GIVEN** an HR adviser who edited an employee's salary directly on 3 July 2026
- **WHEN** the 2026-07 run is calculated
- **THEN** the salary line names the employee record, the audit entry of that edit, the HR
  adviser and the time of the edit

### Requirement: Corrections and mutation reports SHALL point at payslip lines (REQ-PML-003)

A retro correction SHALL be able to name the payslip line it corrects, which SHALL belong to
the correction's original payslip. The mutation report SHALL list, for each changed employee,
the lines that are new, gone or changed in amount between the two runs; an employee whose
payslips have no lines SHALL show that the causes are unknown rather than empty.

Rows: `dm-mutation-lineage` (humaniq matrix), changelog https://loket.nl/roadmap/.

#### Scenario: The mutation report names the cause
- **GIVEN** a 2026-06 and a 2026-07 run where one employee's only difference is the applied
  raise
- **WHEN** an HR adviser generates the mutation report for 2026-07
- **THEN** that employee's line lists the salary line with the raise as its cause

#### Scenario: A correction must name a line of its own payslip
- **GIVEN** a retro correction on the 2026-05 payslip of one employee
- **WHEN** an HR adviser sets it to correct a line of another employee's payslip
- **THEN** the save is refused
