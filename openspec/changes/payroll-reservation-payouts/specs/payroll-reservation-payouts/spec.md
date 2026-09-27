# payroll-reservation-payouts

## ADDED Requirements

### Requirement: The engine SHALL tax a special payment at the special rate (REQ-RSP-001)

The payroll pack SHALL accept a special payment and an annual wage reference, SHALL select the
special-rate percentage from the sourced table of special payments by the annual wage, table
colour, AOW status and heffingskorting column, and SHALL withhold wage tax on the special
payment at that percentage. The regular wage SHALL be taxed exactly as before. A payslip with no
special payment SHALL be identical to one calculated before this change. While the table is
unverified the special payment SHALL NOT be paid, and the run SHALL list the employee with the
reason.

Rows: `pay-holiday-allowance` (humaniq matrix).

#### Scenario: The May holiday allowance is taxed at the special rate
- **GIVEN** an employee paid 3800.00 a month for all of 2025 and a holiday allowance of
  3648.00 to pay in May 2026
- **WHEN** a payroll officer calculates the 2026-05 run
- **THEN** the payslip shows the regular wage tax on 3800.00 as before, a special payment of
  3648.00, the special-rate percentage for an annual wage of 45600.00, and the wage tax on the
  special payment at that percentage

#### Scenario: No special payment, no difference
- **GIVEN** a month without payouts
- **WHEN** the run is calculated
- **THEN** every payslip equals the one the previous pack version computes for the same input

### Requirement: Holiday allowance SHALL be paid out on the administration's schedule and on leaving (REQ-RSP-002)

humaniq SHALL pay the open holiday allowance reserve of the basis period in the
administration's payout month, or each period's reserve in that period when the administration
pays per period, SHALL record each payout, and SHALL pay every open reserve in a leaver's final
period. The open reserve SHALL always equal the reserved amounts on the payslips of the basis
period minus the recorded payouts for it.

Rows: `pay-holiday-allowance` (humaniq matrix).

#### Scenario: The yearly payout in May
- **GIVEN** an administration paying holiday allowance yearly in May and an employee with
  twelve payslips from June 2025 to May 2026 reserving 304.00 each
- **WHEN** the 2026-05 run is calculated
- **THEN** the payslip pays 3648.00 as a special payment and a payout record covers June 2025
  to May 2026

#### Scenario: A leaver takes the reserve with them
- **GIVEN** an employee whose employment ends on 30 September 2026 with four months of reserve
  since the May payout
- **WHEN** the 2026-09 run is calculated and approved
- **THEN** the final payslip pays those four months' reserve and the offboarding case shows the
  holiday allowance as settled

### Requirement: An end-of-year payment SHALL be reserved and paid when a rate applies (REQ-RSP-003)

A contract, or its confirmed collective agreement, SHALL be able to carry an end-of-year rate.
The pack SHALL reserve that rate of the regular wage on every payslip, and the schedule SHALL
pay the calendar year's reserve in the end-of-year month at the special rate. A contract under
an agreement whose figure is a placeholder, without its own rate, SHALL reserve nothing.

Rows: `pay-holiday-allowance` (humaniq matrix).

#### Scenario: A thirteenth month in December
- **GIVEN** a contract with an end-of-year rate of 8.33% and a regular wage of 3000.00 all year
- **WHEN** the 2026-12 run is calculated
- **THEN** the payslip pays the year's reserve of 2998.80 as a special payment

### Requirement: The individual choice budget SHALL accrue and be spent by approved request (REQ-RSP-004)

humaniq SHALL accrue an employee's individual choice budget per year at the contract's rate, or
the confirmed agreement's rate, times the regular gross of each approved payslip. An employee
SHALL be able to request a spend as a payout, as leave hours, or on a goal HR configured with
its tax treatment. A request SHALL be approved by someone other than the requester and SHALL
NOT exceed the balance. A payout SHALL be paid at the special rate, leave SHALL be credited as
hours without money, and an exempt goal SHALL be paid untaxed with a WKR row. When the
administration says so, the balance left in December SHALL be paid out.

Rows: `td-ikb` (humaniq matrix), tender
https://www.tenderned.nl/aankondigingen/overzicht/415227.

#### Scenario: An employee buys extra leave with the budget
- **GIVEN** an IKB balance of 1500.00 and a request for 16 hours of leave valued at 480.00
- **WHEN** the manager approves the request and it is settled
- **THEN** the employee's holiday balance gains 16 hours, the budget shows 1020.00 left, and
  no amount appears on the payslip

#### Scenario: A request above the balance is refused
- **GIVEN** an IKB balance of 300.00
- **WHEN** the employee submits a payout request of 500.00 and the manager tries to approve it
- **THEN** the approval is refused because it exceeds the balance

#### Scenario: A payout is taxed at the special rate
- **GIVEN** an approved payout request of 800.00 settling in 2026-06
- **WHEN** the 2026-06 run is calculated
- **THEN** the payslip shows a special payment of 800.00 taxed at the employee's special rate
