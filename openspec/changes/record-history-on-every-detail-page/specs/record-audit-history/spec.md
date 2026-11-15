# record-audit-history

## ADDED Requirements

### Requirement: Every read and every change of a humaniq record SHALL be recorded in OpenRegister's audit trail (REQ-RAH-001)

Every change to a humaniq record SHALL leave an audit entry with who, when and what changed,
and every read a person makes of a humaniq record SHALL leave a `read` entry, both written by
OpenRegister while the instance keeps audit trails. humaniq SHALL write no audit store of its
own. Reads humaniq's own services make inside one operation, such as a payroll calculation,
SHALL NOT be recorded as reads.

Rows: `cmp-audit-trail` (humaniq matrix).

#### Scenario: Opening an employee leaves a read entry
- **GIVEN** an instance with audit trails on
- **WHEN** an HR adviser opens the page of employee Noor Bakker
- **THEN** the audit trail of that record has a `read` entry naming the adviser and the time

#### Scenario: A payroll calculation leaves no read entries
- **GIVEN** a draft payroll run for 20 employees
- **WHEN** a payroll officer calculates it
- **THEN** the audit trail gains no `read` entry for those employees from the calculation

### Requirement: Every humaniq detail page SHALL show the record's history (REQ-RAH-002)

Every humaniq detail page SHALL show the record's audit history in its sidebar: the changes to
everyone who may read the record, and the read entries to those OpenRegister lets see reads
(administrators and the groups an administrator names). humaniq SHALL add no rule of its own on
top of OpenRegister's.

Rows: `cmp-audit-trail` (humaniq matrix). Board: `humaniq/HuMedewerker`.

#### Scenario: A changed IBAN shows in the history
- **GIVEN** an HR adviser changed the IBAN of employee Johan Mulder
- **WHEN** another HR adviser opens his page and the history tab
- **THEN** the history shows the change with who made it and when

#### Scenario: A detail page without history fails the manifest check
- **GIVEN** a `type: "detail"` page in the manifest without `sidebar.showMetadata: true`
- **WHEN** the manifest check runs
- **THEN** it exits with an error naming the page

### Requirement: The admin settings SHALL show an Audittrail section across the humaniq register (REQ-RAH-003)

The humaniq admin settings SHALL show an Audittrail section with the ten newest audit entries of
humaniq records the administrator may read, each as one sentence with who, what and when. The
section SHALL say entries cannot be changed or removed, SHALL link to the full audit trail in
OpenRegister filtered to the humaniq register, and SHALL offer a download of that trail.

Rows: `cmp-audit-trail` (humaniq matrix). Board: `humaniq/HuInstellingen`, tab Audittrail.

#### Scenario: The newest entries read as sentences
- **GIVEN** Ruud Visser opened the salary data of Femke van Dijk at 10:12
- **WHEN** an administrator opens the Audittrail section
- **THEN** the first entry reads that Ruud Visser viewed the salary data of Femke van Dijk at 10:12

#### Scenario: The full trail opens filtered
- **GIVEN** the Audittrail section
- **WHEN** the administrator chooses "Volledige audittrail"
- **THEN** OpenRegister's audit trail opens filtered to the humaniq register
