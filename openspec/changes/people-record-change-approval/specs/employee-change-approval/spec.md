# employee-change-approval

## ADDED Requirements

### Requirement: A guarded change SHALL take effect only after its approver approves it (REQ-ECR-001)

humaniq SHALL hold an administered `ChangeApprovalRule` per kind of change naming the
`Employee` fields it covers and the role that approves it (`hr`, `accountant`, the
employee's manager, or none). A change to a field covered by a rule with an approver SHALL
be made through an `EmployeeChangeRequest` and SHALL be applied to the employee only when a
user holding that role approves the request. The subject of the request SHALL NOT approve
it.

Rows: `td-mutation-approval` (humaniq matrix), tender
https://www.tenderned.nl/aankondigingen/overzicht/415705.

#### Scenario: A bank account change waits for HR
- **GIVEN** the rule for `bankrekening` names approver `hr`
- **WHEN** an employee's new IBAN is submitted as a change request
- **THEN** `EmployeeDetail` still shows the old IBAN, and the request appears on
  `ChangeRequests` for an HR user with the old and new value side by side

#### Scenario: The wrong role cannot approve
- **GIVEN** a salary change request under a rule naming approver `accountant`
- **WHEN** a user who holds only the `hr` role tries `goedkeuren`
- **THEN** the transition is refused and the salary is unchanged

#### Scenario: An approved request is applied once
- **GIVEN** a bank account request in `ingediend`
- **WHEN** an HR user approves it
- **THEN** the employee's IBAN equals the requested value and the request shows who
  approved it and when

### Requirement: A guarded field SHALL NOT be saved around the request (REQ-ECR-002)

A direct update of `Employee` that changes a field covered by a rule with an approver SHALL
be refused for every caller, on every page and API, with a message naming the kind of
change. humaniq's own apply step SHALL be the only exempt write. When the rules cannot be
read, the update SHALL be refused.

Rows: `td-mutation-approval` (humaniq matrix).

#### Scenario: Editing the salary on the employee page is refused
- **GIVEN** the rule for `salaris` names approver `accountant`
- **WHEN** an HR adviser edits `grossMonthlySalary` on `EmployeeDetail` and saves
- **THEN** the save is refused with a message that a salary change needs a change request,
  and the stored salary is unchanged

#### Scenario: An unguarded field saves directly
- **GIVEN** no rule covers `a1CertificateNumber`
- **WHEN** an HR adviser edits it on `EmployeeDetail`
- **THEN** the change is saved at once

### Requirement: An employee SHALL see their own record and ask for a change (REQ-ECR-003)

humaniq SHALL offer a `MijnGegevens` page in Mijn HR showing the signed-in employee their
own `Employee` record, including an address, and SHALL let them request a change of
address or bank account. The request SHALL follow the rule for its kind: applied at once
when the kind has no approver, waiting otherwise.

Rows: `ess-edit-details` (humaniq matrix).

#### Scenario: An address change applies at once
- **GIVEN** the rule for `adres` names no approver
- **WHEN** an employee enters a new street, postcode and city on `MijnGegevens`
- **THEN** their record shows the new address immediately, and the applied request is on
  record with the old address

#### Scenario: An employee sees only themselves
- **GIVEN** an employee signed in to Nextcloud
- **WHEN** they open `MijnGegevens`
- **THEN** only the `Employee` whose `nextcloudUserId` is theirs is shown
