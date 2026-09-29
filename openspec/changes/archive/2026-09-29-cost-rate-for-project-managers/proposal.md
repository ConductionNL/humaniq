---
kind: code
---

# A project manager sees the cost rate of the people on the project

## Why

Since compliance-roles-and-field-access (#552) the salary on `Employee` and
`EmploymentContract` is readable only by HR, payroll and the employee. The cost-rate
endpoint reads both under the caller's own access, so a project manager who needs to
budget or cost the hours of the people on the project gets no rate at all: the salary
is stripped, the wage base is empty and the answer is 409.

Ruben decided on 29 Sep 2026 (build-all DECISIONS row 8): a project manager of a project
the employee works on gets the DERIVED PERSONAL cost rate; the salary itself stays hidden.

Building it also showed that the endpoint never found an active contract for anyone:
`activeContract()` called `ObjectService::findAll()` with named `register`, `schema` and
`filters` arguments that method does not have (its signature is
`findAll(array $config, bool $_rbac, bool $_multitenancy)`), the error was caught, and
every contract-derived rate answered 409. The test double accepted any arguments, so it
could not see it.

## What changes

- A caller who owns a planninq `project` (the `owner` field is the project manager) on
  which the employee works (the employee's user is a project member, or one of the
  employee's time entries names that project) gets the rate for that employee even when
  the salary is hidden from them.
- That answer carries the hourly figures only: `totalCentsPerHour`, `wageCostCents`, the
  additions, and `access: "project-manager"`. It carries no salary, no wage basis text and
  no contract field.
- Any other caller keeps today's behaviour: an employee they cannot read is a 404, a
  hidden salary is a 409.
- The active contract is looked up with the method's real signature, so contract-derived
  rates work again.

## Rows

No matrix row: this is a decision on an existing capability (employer-hourly-cost-rate).
