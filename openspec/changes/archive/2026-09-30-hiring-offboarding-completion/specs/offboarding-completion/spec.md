# offboarding-completion

## ADDED Requirements

### Requirement: An exit interview SHALL be recorded as structured data (REQ-OFC-001)

humaniq SHALL provide an `ExitInterview` schema linked to one `Offboarding` case, holding
when and by whom it was held, the leaver's main reason from a fixed list, a 0 to 10 "would
recommend" score, whether they would return, and two free-text answers. Recording one SHALL
set `Offboarding.exitGesprekDone` to its date. The main reason SHALL be counted per value
over the last twelve months on the `ExitInterviews` page.

Rows: `hir-exit-interview` (humaniq matrix).

#### Scenario: An HR adviser records why someone left
- **GIVEN** an offboarding case in `afronding_gepland` with no exit interview date
- **WHEN** an HR adviser records an exit interview held today with main reason
  `leidinggevende` and score 4
- **THEN** `OffboardingDetail` shows the interview and `exitGesprekDone` reads today's date

#### Scenario: A pattern shows across leavers
- **GIVEN** six exit interviews this year, four with main reason `leidinggevende`
- **WHEN** an HR adviser opens `ExitInterviews`
- **THEN** the reason count shows 4 for `leidinggevende`

### Requirement: An exit interview SHALL lose its person link after 90 days (REQ-OFC-002)

A shipped flow SHALL clear `employeeId`, `offboardingId`, `conductedBy` and both free-text
answers of every exit interview held more than 90 days ago and SHALL stamp `anonymisedAt`,
keeping `mainReason`, `wouldRecommend`, `wouldReturn`, `orgUnitId` and `heldOn`.

Rows: `hir-exit-interview` (humaniq matrix).

#### Scenario: Last spring's interview is anonymised
- **GIVEN** an adopted anonymisation flow and an exit interview held 120 days ago
- **WHEN** the flow runs
- **THEN** the record has no employee, no case and no free text, and still counts toward its
  reason on `ExitInterviews`

### Requirement: Revoking access SHALL disable the leaver's Nextcloud account (REQ-OFC-003)

A `Revoke access` action on `OffboardingDetail` SHALL disable the Nextcloud account named in
the leaver's `Employee.nextcloudUserId` through Nextcloud's user manager and SHALL then set
`toegangIngetrokken` with who revoked it and when. It SHALL refuse an account in the
`admin` group and the acting user's own account. It SHALL be available to admins and HR
only, and SHALL answer 404 for a case the caller may not read. A shipped flow SHALL do the
same for open cases on the day after `lastWorkingDay`.

Rows: `hir-access-revocation` (humaniq matrix).

#### Scenario: A leaver cannot sign in the day after
- **GIVEN** an offboarding case for an employee with Nextcloud account `j.jansen` and
  `lastWorkingDay` yesterday
- **WHEN** an HR adviser presses `Revoke access` on `OffboardingDetail`
- **THEN** `j.jansen` is disabled in Nextcloud and the checklist shows access revoked by the
  adviser today

#### Scenario: An administrator account is never disabled this way
- **GIVEN** an offboarding case whose employee's account is in the `admin` group
- **WHEN** an HR adviser presses `Revoke access`
- **THEN** the account stays enabled, `toegangIngetrokken` stays false and the answer names
  the refusal

### Requirement: The transition payment SHALL be calculated from the statute's inputs (REQ-OFC-004)

A `Calculate transition payment` action on `OffboardingDetail` SHALL compute the statutory
transition payment for a dismissal-initiated reason as one third of the monthly wage per
year of service, pro rata for the remainder, from the start of the unbroken contract chain,
capped at the cap in the `nl-offboarding-transitievergoeding` rule parameters or one gross
annual salary when higher. It SHALL write the amount to `transitievergoedingBedrag` and the
breakdown to `transitievergoedingBerekening`. For any other reason it SHALL answer zero and
say why. HR SHALL be able to overwrite the amount afterwards.

Rows: `ppl-transition-payment` (humaniq matrix).

#### Scenario: Seven years of service
- **GIVEN** a case with reason `opzegging-werkgever` for an employee whose contract chain
  started seven years before `lastWorkingDay`, with a monthly wage including holiday
  allowance of 4,320 euros
- **WHEN** a payroll officer presses `Calculate transition payment`
- **THEN** `transitievergoedingBedrag` reads 10,080 euros and the breakdown shows seven
  service years and the monthly wage parts

#### Scenario: A gap longer than six months restarts the count
- **GIVEN** an employee with a first contract that ended eight months before the current
  contract started
- **WHEN** the transition payment is calculated
- **THEN** service counts from the current contract's start only

#### Scenario: A voluntary leaver gets nothing
- **GIVEN** a case with reason `opzegging-werknemer`
- **WHEN** the transition payment is calculated
- **THEN** the answer is zero with the reason that the departure was not employer-initiated
