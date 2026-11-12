# humaniq-expenses

## ADDED Requirements

### Requirement: The shipped example claim SHALL be settled once on tenants that imported it as payable

A repair step SHALL run on upgrade and install, after the register import. It SHALL change
the Expense whose slug is `expense-devries-train` only when all of these hold:

- the claim still holds exactly what the old seed shipped: title, description, amount
  27.40, currency EUR, category travel, expense date 2026-05-20, status approved, submitted,
  approved by and approved at as shipped, and the payroll route;
- its employee is the Employee whose slug is `employee-devries`;
- it has no `payrollRunId`.

It SHALL then save the claim with status `reimbursed`, route `direct` and `reimbursedAt`
2026-05-26T10:00:00Z, as humaniq's own write, and log the claim's id. It SHALL NOT change any
other field or any other record. A run that finds nothing to change SHALL change nothing.

#### Scenario: The unchanged example claim is settled

@e2e exclude A repair step with no screen of its own; covered by SettleShippedExampleClaimTest::testTheUnchangedExampleClaimIsSettled
- **GIVEN** a tenant that imported the old seed and has not changed the example claim
- **WHEN** the repair step runs
- **THEN** the claim is reimbursed on the direct route, and payroll does not pay it
- **AND** a second run changes nothing

#### Scenario: A changed or paid example claim is left alone

@e2e exclude A repair step with no screen of its own; covered by SettleShippedExampleClaimTest::testAChangedOrPaidClaimIsLeftAlone
- **GIVEN** the example claim with another amount, another employee, or a payroll run
- **WHEN** the repair step runs
- **THEN** nothing is written
