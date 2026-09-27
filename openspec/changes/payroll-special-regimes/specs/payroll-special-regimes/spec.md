# payroll-special-regimes

## ADDED Requirements

### Requirement: An active benefit entitlement SHALL produce its own payslip (REQ-PSR-001)

humaniq SHALL record benefit entitlements (early retirement and post-office allowance) per
person, activated by someone other than the person who drafted them. For every period an active
entitlement covers, the run SHALL compute one benefit payslip marked as a separate income
relationship, whether or not the person is still employed, and SHALL keep any salary payslip of
the same person in the same run.

Rows: `dm-rvu`, `td-political-office-holders` (humaniq matrix), tender
https://www.tenderned.nl/aankondigingen/overzicht/415227.

#### Scenario: A former employee receives the monthly RVU
- **GIVEN** an employee who left on 30 June 2026 with an active monthly RVU entitlement of
  2000.00 from July
- **WHEN** a payroll officer calculates the 2026-07 run
- **THEN** a benefit payslip of 2000.00 gross exists for that person, marked as a separate
  income relationship

#### Scenario: Salary and benefit in one month
- **GIVEN** a person with a covering contract and an active wachtgeld entitlement in 2026-07
- **WHEN** the run is calculated
- **THEN** the person has one salary payslip and one benefit payslip in that run

### Requirement: Each regime SHALL be computed from sourced table data or not at all (REQ-PSR-002)

The tax table colour, whether employee insurances apply, the Zvw mode and, for early retirement,
the monthly threshold and final-levy rate SHALL come from the regime's leaves in the tables
corpus. When a leaf the regime needs is unverified or a placeholder, the run SHALL NOT compute
that payslip and SHALL list the person with the reason.

Rows: `dm-rvu`, `td-political-office-holders` (humaniq matrix).

#### Scenario: An unverified regime pays nothing
- **GIVEN** the wachtgeld regime's Zvw leaf marked as a placeholder
- **WHEN** the run reaches an active wachtgeld entitlement
- **THEN** no payslip is written for it and the run lists the person as `regime-unverified`

### Requirement: The engine SHALL withhold Zvw when the regime says so and SHALL compute the RVU final levy (REQ-PSR-003)

The payroll pack SHALL compute the Zvw either as the employer's levy or as a contribution
withheld from net pay at the tables' withholding rate, per the input mode, and a payslip SHALL
record the mode used. For an early-retirement benefit the pack SHALL compute the employer's
final levy on the part of the benefit above the monthly threshold as an employer cost. With the
default mode and no threshold every figure SHALL equal the pack's result before this change.

Rows: `dm-rvu` (humaniq matrix), changelog
https://community.visma.com/t5/Releases-YouServe/tkb-p/nl_ys_vismayouserve_releases/page/3.

#### Scenario: A benefit with withheld Zvw
- **GIVEN** a benefit payslip under a regime whose Zvw mode is withholding
- **WHEN** it is calculated
- **THEN** the payslip shows Zvw withheld at 4.85% of the capped base, net pay lower by that
  amount, and `zvwMode` `inhouding`

#### Scenario: The final levy on the excess
- **GIVEN** a monthly RVU benefit above the regime's monthly threshold
- **WHEN** it is calculated
- **THEN** the payslip shows a final levy equal to the rate times the excess, as an employer
  cost that does not change net pay

#### Scenario: A lump sum at the special rate
- **GIVEN** an RVU entitlement paid as one sum covering 24 months, settling in 2026-09
- **WHEN** the 2026-09 run is calculated
- **THEN** the sum is taxed at the special rate and the final levy uses 24 times the monthly
  threshold

### Requirement: Political office holders SHALL be paid under their own regime (REQ-PSR-004)

A contract SHALL be able to be a political office with its role and term. The run SHALL pay it
under the political-office regime, without employee insurance premiums, and the contract page
SHALL link to the post-office allowance entitlement recorded when the term ends.

Rows: `td-political-office-holders` (humaniq matrix), tender
https://www.tenderned.nl/aankondigingen/overzicht/415227.

#### Scenario: An alderman's payslip
- **GIVEN** a contract of type political office with role alderman, covering 2026-05
- **WHEN** a payroll officer calculates the 2026-05 run
- **THEN** the alderman's payslip shows no unemployment, disability or sickness insurance
  premiums and names the political-office regime

#### Scenario: Wachtgeld after the term
- **GIVEN** an alderman whose term ended on 31 March 2026
- **WHEN** an HR adviser records the wachtgeld entitlement from the Appa decision and another
  HR adviser activates it
- **THEN** the April run produces a benefit payslip for the former alderman
