# payroll-cost-allocation

## ADDED Requirements

### Requirement: Each payslip's cost SHALL be allocated to cost centres and projects (REQ-PCA-001)

For every calculated payslip humaniq SHALL allocate the gross and the employer charges to cost
centres and projects: by the employee's fixed allocation covering the period when one exists,
otherwise by the approved hours booked per cost centre and project when the allocation basis is
hours, otherwise to the cost centre of the unit the employee is placed in, split equally over
several placements. When nothing resolves, the cost SHALL be allocated as unallocated. The
allocation lines SHALL add up to the payslip to the cent. Fixed splits SHALL add up to 100
percent.

Rows: `pay-cost-allocation` (humaniq matrix).

#### Scenario: A fixed split over two departments
- **GIVEN** an employee allocated 60% to CC-100 and 40% to CC-200, with a gross of 2912.00
- **WHEN** a payroll officer calculates the run
- **THEN** the payslip shows 1747.20 of gross on CC-100 and 1164.80 on CC-200, with the
  employer charges split the same way

#### Scenario: No allocation falls back to the department
- **GIVEN** an employee without an allocation, placed in a unit with cost centre CC-100
- **WHEN** the run is calculated
- **THEN** the whole cost is allocated to CC-100 with the source "placement"

#### Scenario: A split that does not add up is refused
- **GIVEN** an HR adviser entering splits of 50% and 40%
- **WHEN** they save the allocation
- **THEN** the save is refused because the splits add up to 90%

### Requirement: The payroll journal SHALL book wage costs per cost centre and project (REQ-PCA-002)

The payroll journal SHALL carry its gross-wage and employer-charge debit lines per cost centre
and project, each with the cost-centre and project codes, and SHALL keep its liability lines as
totals. The journal SHALL balance. A run without allocation lines SHALL produce the same
journal as before this change.

Rows: `pay-cost-allocation` (humaniq matrix).

#### Scenario: The journal carries two cost centres
- **GIVEN** an approved run whose allocations cover CC-100 and CC-200
- **WHEN** the journal is posted to shillinq
- **THEN** it has a gross debit line and an employer-charges debit line for each cost centre,
  each with its code, and debits equal credits

### Requirement: Wage costs per cost centre SHALL be readable per period (REQ-PCA-003)

humaniq SHALL show the allocated total wage cost per cost centre and period from the stored
allocation lines.

Rows: `pay-cost-allocation` (humaniq matrix).

#### Scenario: A controller reads May's wage costs
- **GIVEN** an approved 2026-05 run with allocations on CC-100 and CC-200
- **WHEN** a controller opens the wage costs page and filters on 2026-05
- **THEN** the page shows one total per cost centre, and together they equal the run's gross
  plus employer charges
