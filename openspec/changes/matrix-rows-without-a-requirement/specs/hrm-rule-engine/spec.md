# hrm-rule-engine

## ADDED Requirements

### Requirement: HR SHALL see the audit's violations and coverage on a page (REQ-RULE-010)

HR SHALL run the compliance audit from a `Compliance check` page and read its result there,
without `occ`. The page SHALL list every violation with its rule, its severity and the record it
is about, link each violation to that record, and show the corpus coverage as rules enforced
against rules catalogued. Only users who may read the audited records SHALL see their
violations.

Rows: `cmp-rule-engine` (humaniq matrix).

#### Scenario: HR finds a contract below the CAO minimum
- **GIVEN** a contract paid below the minimum of its CAO scale
- **WHEN** an HR adviser runs the check on `Compliance check`
- **THEN** the page lists a mandatory `nl-cao-minimumloon-schaal` violation that opens the contract

#### Scenario: Coverage is shown beside the findings
- **WHEN** the check has run
- **THEN** the page shows how many catalogued rules are enforced and how many are not

#### Scenario: A manager sees only their own people
- **GIVEN** a manager of one team
- **WHEN** they open `Compliance check`
- **THEN** no violation about an employee outside their team is listed
