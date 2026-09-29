# employer-hourly-cost-rate

## ADDED Requirements

### Requirement: A project manager gets the derived rate of the people on the project, never their salary (REQ-ECR-PM)

A caller who owns a planninq project on which the employee works (the employee's user is a
member of it, or one of the employee's time entries names it) SHALL receive the employee's
derived cost per hour even when the salary is hidden from that caller. That answer SHALL
carry `totalCentsPerHour`, `wageCostCents`, the additions and `access: "project-manager"`,
and SHALL NOT carry the salary, the wage basis or any contract field. Any other caller who
cannot read the employee SHALL still get 404, and one who cannot read the salary SHALL
still get 409.

#### Scenario: A project manager costs a team member
- **GIVEN** a planninq project owned by `pm` with the employee's user as a member
- **AND** the employee's salary hidden from `pm`
- **WHEN** `pm` requests the employee's cost rate
- **THEN** the answer is 200 with `totalCentsPerHour` and `wageCostCents` and `access: "project-manager"`
- **AND** it carries no `grossMonthlySalary`, no `wageBasis` and no contract
- @e2e exclude backend authorization, asserted by EmployerCostRateControllerTest and ProjectManagerAccessTest

#### Scenario: A manager of another project learns nothing
- **GIVEN** a caller who owns no project the employee works on
- **WHEN** the caller requests the employee's cost rate
- **THEN** the answer is the same 404 or 409 as before
- @e2e exclude backend authorization, asserted by EmployerCostRateControllerTest and ProjectManagerAccessTest
