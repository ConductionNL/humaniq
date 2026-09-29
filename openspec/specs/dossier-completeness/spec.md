# dossier-completeness Specification

## Purpose
A personnel file lists the documents its employee must hold and says per document whether it is present, missing, expired, expiring or too old at the start of employment; a new hire's identity document and right to work are checked by a stated rule, and the onboarding case cannot reach the first working day without a passing check. Built by people-dossier-completeness (archived 2026-09-29).

## Requirements

### Requirement: HR SHALL state which documents a group of employees must have (REQ-DCP-001)

humaniq SHALL provide a `DossierRequirement` schema naming a required document, the
employees it applies to (everyone, a contract type, a normfunctie or an org unit), whether it
expires, how many days ahead to warn, the maximum age at the start of employment, and what
counts as evidence: a supplied `PersonnelDocument`, a generated document of a given type, or
a current `EmployeeCompetence` with a given code.

Rows: `td-dossier-validation` (humaniq matrix), tender https://www.tenderned.nl/aankondigingen/overzicht/415705.

#### Scenario: A care organisation requires a VOG for care roles only
- **GIVEN** an HR adviser who adds a VOG requirement for the normfunctie "Begeleider" with a
  maximum age of 180 days at start
- **WHEN** they open an administrative employee's file and a Begeleider's file
- **THEN** only the Begeleider's file lists the VOG requirement

@e2e exclude the rule is decided server-side (a listener, a guard and an endpoint); covered by DossierCompletenessServiceTest::testARequirementAppliesOnlyToItsGroup

### Requirement: Every personnel file SHALL show which required documents are present, missing, expired or expiring (REQ-DCP-002)

For one employee and a date, humaniq SHALL answer per applicable requirement whether it is
present, missing, expired, expiring within the warning window, or too old at the start of
employment, with the evidence it found. `EmployeeDetail` SHALL show this, a page SHALL list
every employee with at least one gap, and the answer SHALL include only employees and
records the caller may read. HR SHALL be notified before a supplied document expires.

Rows: `td-dossier-validation` (humaniq matrix), tender https://www.tenderned.nl/aankondigingen/overzicht/415705.

#### Scenario: An expired VOG shows up before the inspection
- **GIVEN** a Begeleider whose only VOG expired last month
- **WHEN** an HR adviser opens `Onvolledige dossiers`
- **THEN** the Begeleider is listed with the VOG as `verlopen`

@e2e exclude the rule is decided server-side (a listener, a guard and an endpoint); covered by DossierControllerTest::testIncompleteListsOnlyReadableEmployeesWithAGap and DossierCompletenessServiceTest::testExpiredAndExpiring

#### Scenario: A BIG registration held as a competence counts
- **GIVEN** a nurse with a current `EmployeeCompetence` `big-verpleegkundige` valid for
  another year and a BIG requirement pointing at that code
- **WHEN** the nurse's dossier status is read
- **THEN** the BIG requirement is `aanwezig` and names the competence as evidence

@e2e exclude the rule is decided server-side (a listener, a guard and an endpoint); covered by DossierCompletenessServiceTest::testABigRegistrationHeldAsACompetenceCounts

#### Scenario: A manager sees only their own people
- **GIVEN** a manager who may read the employees of one unit
- **WHEN** they request `GET /api/dossier/incomplete`
- **THEN** no employee outside that unit is returned

@e2e exclude the rule is decided server-side (a listener, a guard and an endpoint); covered by DossierControllerTest::testIncompleteListsOnlyReadableEmployeesWithAGap and ::testAnUnreadableEmployeeIsNotFound

### Requirement: A right-to-work check SHALL decide by a stated rule (REQ-DCP-003)

humaniq SHALL record a `RightToWorkCheck` per new hire with document type, nationality,
document expiry, residence endorsement, work permit validity, the reading method and the
result. The result SHALL be `geslaagd` for an unexpired EU, EEA or Swiss document, for a
residence document whose endorsement allows work, or for a current work permit, and
`mislukt` otherwise. When the machine-readable zone is read, a wrong check digit SHALL
make the result `mislukt`. A passing check SHALL set `widCheckDone` and `widCheckDate` on
the onboarding case. No document number SHALL be stored.

Rows: `dm-right-to-work-check` (humaniq matrix), changelog https://klant.afas.nl/update/profit-8/hrm.

#### Scenario: A residence permit without work endorsement fails
- **GIVEN** a new hire from outside the EU with a residence document whose endorsement does
  not allow work and no work permit
- **WHEN** an HR adviser runs the right-to-work check on `OnboardingDetail`
- **THEN** the check reads `mislukt` with the reason "no permission to work", and
  `widCheckDone` stays false

@e2e exclude the rule is decided server-side (a listener, a guard and an endpoint); covered by RightToWorkCheckListenerTest::testAResidencePermitWithoutWorkEndorsementFails

#### Scenario: A Dutch passport passes
- **GIVEN** a new hire with a Dutch passport valid for five more years
- **WHEN** the check runs
- **THEN** it reads `geslaagd` and the onboarding checklist shows the WID check done today

@e2e exclude the rule is decided server-side (a listener, a guard and an endpoint); covered by RightToWorkCheckListenerTest::testADutchPassportPassesAndTicksTheWidCheck

### Requirement: An onboarding case SHALL NOT reach the first working day without a passing check (REQ-DCP-004)

The `gereed_melden` and `starten` transitions of `Onboarding` SHALL be refused unless the
case's employee has a `RightToWorkCheck` with result `geslaagd` dated on or before the
case's `startDate`. A failed check SHALL NOT be overridable; only a new passing check lifts
the stop.

Rows: `dm-right-to-work-check` (humaniq matrix).

#### Scenario: The hard stop holds
- **GIVEN** an onboarding case at `gegevens_gevalideerd` whose only check is `mislukt`
- **WHEN** an HR adviser presses `Mark ready` on `OnboardingDetail`
- **THEN** the transition is refused with the check's reason and the case stays at
  `gegevens_gevalideerd`

@e2e exclude the rule is decided server-side (a listener, a guard and an endpoint); covered by RightToWorkGuardTest::testAFailedCheckRefusesWithItsReason and ::testAHandSetPassThatTheRuleRefusesIsRefused
