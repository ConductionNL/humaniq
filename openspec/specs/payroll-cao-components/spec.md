# payroll-cao-components Specification

## Purpose
The allowances and premiums a collective labour agreement prescribes are paid in the payroll run: the CAO corpus describes each as a percentage of wage, a fixed monthly amount or an hourly surcharge with time windows, a contract names the components that apply to it and may improve them with a reason, and the run adds them to the gross, listing what it could not pay. Built by payroll-cao-components (archived 2026-09-30).

## Requirements

### Requirement: The CAO corpus SHALL describe components in one computable shape (REQ-CCP-001)

Every allowance in the CAO corpus SHALL be one of three kinds: a percentage of wage, a fixed
monthly amount, or an hourly surcharge with time windows and a public-holiday percentage.
humaniq SHALL resolve an agreement's components only when its allowances leaf is verified and
not a placeholder.

Rows: `pay-cao-components` (humaniq matrix).

#### Scenario: A placeholder agreement resolves nothing
- **GIVEN** an agreement whose allowances leaf is a placeholder
- **WHEN** the components of that agreement are resolved
- **THEN** the result is empty and marked unresolved, never a guessed percentage

@e2e exclude pure resolution; covered by EmploymentTermsResolverComponentsTest::testAPlaceholderResolvesNothingUnlessOverridden and CaoComponentsCorpusTest::testComponentsResolveOnlyFromAConfirmedLeaf

### Requirement: A contract SHALL name the components that apply and MAY improve them with a reason (REQ-CCP-002)

A contract SHALL list which of its agreement's components apply to it. A contract override of
a component's figure SHALL carry a reason and SHALL NOT be below the agreement's figure. A
contract naming a component its agreement does not have SHALL be flagged by the rule audit.

Rows: `pay-cao-components` (humaniq matrix).

#### Scenario: An override below the agreement is refused
- **GIVEN** an agreement with a 13.3% shift allowance
- **WHEN** an HR adviser saves a contract override of 10% for that allowance
- **THEN** the override is refused because it is below the agreement

@e2e exclude the refusal is a server-side listener; covered by CaoComponentOverrideListenerTest::testAWrongOverrideIsRefused and EmploymentTermsResolverComponentsTest::testAnOverrideBelowTheAgreementIsRefused

#### Scenario: An override above the agreement needs a reason
- **GIVEN** the same agreement
- **WHEN** an HR adviser saves an override of 15% without a reason
- **THEN** the override is refused until a reason is given

@e2e exclude the refusal is a server-side listener; covered by CaoComponentOverrideListenerTest::testAWrongOverrideIsRefused and EmploymentTermsResolverComponentsTest::testAnOverrideWithoutAReasonIsRefused

### Requirement: The run SHALL pay the applicable components as wage (REQ-CCP-003)

For each payslip the run SHALL add to the gross, before the calculation: each applicable
percentage-of-wage component on the regular wage, each fixed monthly component, and for each
hourly surcharge the premium on the hourly rate for every approved hour worked inside one of
its windows, using the holiday percentage on public holidays. The payslip SHALL list every
component with its basis and source. An approved entry without a start and end time SHALL earn
no premium and SHALL be listed. A named component that does not resolve SHALL NOT be paid and
SHALL be listed as unresolved.

Rows: `pay-cao-components` (humaniq matrix).

#### Scenario: A shift allowance on the payslip
- **GIVEN** a contract naming a verified 13.3% shift allowance and a regular wage of 3000.00
- **WHEN** a payroll officer calculates the month's run
- **THEN** the payslip lists the shift allowance at 399.00 and the gross includes it

@e2e exclude the run is server-side; covered by CaoComponentCalculatorTest::testAShiftAllowanceIsAPercentageOfWage and PayrollRunServiceTest::testCaoComponentsArePaidAsWage

#### Scenario: Night hours earn the night premium
- **GIVEN** a verified hourly surcharge of 40% for 00:00 to 06:00 on weekdays, an hourly rate of
  20.00, and an approved entry on a Tuesday from 22:00 to 06:00
- **WHEN** the run is calculated
- **THEN** the payslip lists 6 premium hours at 40%, an amount of 48.00, and the 2 hours before
  midnight earn no premium from that window

@e2e exclude pure calculation; covered by CaoComponentCalculatorTest::testANightSpanCrossingMidnight and CaoComponentPayServiceTest::testANightWorkerIsPaidBothComponents

#### Scenario: An unconfirmed agreement is listed, not paid
- **GIVEN** a contract naming the irregular-hours premium of an agreement whose leaf is a
  placeholder, without an override
- **WHEN** the run is calculated
- **THEN** no premium is paid and the payslip lists the premium as unresolved

@e2e exclude the run is server-side; covered by CaoComponentPayServiceTest::testAnUnconfirmedAgreementIsListedNotPaid and PayrollRunCheckServiceTest::testAnUnresolvedCaoComponentIsAWarning
