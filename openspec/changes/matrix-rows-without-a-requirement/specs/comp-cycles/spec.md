# comp-cycles

## ADDED Requirements

### Requirement: A review cycle SHALL carry a raise budget and show what is left of it (REQ-COMP-009)

`CompReviewCycle` SHALL carry an optional `budgetAmount` in integer cents. The cycle page SHALL
show the sum of the proposed and approved raises in the cycle against that budget, and what is
left. A proposal that takes the cycle over budget SHALL be flagged on the cycle page, not
refused.

Rows: `tal-comp-cycle` (humaniq matrix).

#### Scenario: The budget is partly used
- **GIVEN** a cycle with a budget of 10,000 euros and two proposals adding 2,500 and 3,000 euros
- **WHEN** HR opens the cycle page
- **THEN** it shows 5,500 euros used and 4,500 euros left

#### Scenario: A proposal goes over budget
- **GIVEN** a cycle with 1,000 euros left
- **WHEN** a manager proposes a raise costing 1,500 euros
- **THEN** the proposal is saved and the cycle page flags the cycle as 500 euros over budget
