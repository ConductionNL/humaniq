# Delta — document-dossier-avg

A personnel-dossier view tying an employee's generated documents together, the loonbelastingverklaring retention
sibling to the shipped identity-document rule, a `GeneratedDocument.retainedUntil` field that closes a real gap
in the shipped AVG-DSR retention guard, and a new storage-limitation-ceiling check.

## ADDED Requirements

### Requirement: An employee's personnel dossier SHALL be visible as one FK-scoped list on their own detail page (REQ-DOSS-001)

`src/manifest.json`'s `EmployeeDetail` page SHALL carry an `object-list` widget listing every `GeneratedDocument`
whose `employeeId` matches the viewed employee (`filter: {employeeId: "@objectId"}`), sorted by `generatedAt`
descending, with a `rowRoute` to `GeneratedDocumentDetail` — the same FK-scoped-list pattern already used for
`EmploymentContract`/`Payslip`/`Expense` and every other child record on that page.

#### Scenario: An HR admin sees every generated document for one employee without leaving the record
- **GIVEN** an `Employee` with three `GeneratedDocument` records (one arbeidsovereenkomst, two loonstroken)
- **WHEN** an HR admin opens that employee's detail page
- **THEN** all three documents appear in the dossier list, sorted newest-first, and clicking one navigates to its
  `GeneratedDocumentDetail` page

#### Scenario: A document for a different employee never appears
- **GIVEN** two employees, each with their own `GeneratedDocument` records
- **WHEN** an HR admin opens the first employee's detail page
- **THEN** only that employee's documents appear in the dossier list; the second employee's documents do not

### Requirement: A loonbelastingverklaring's retention SHALL be tracked and machine-checked, mirroring the shipped identity-document rule (REQ-DOSS-002)

`lib/Settings/register.d/hr-objects.json` SHALL add `Employee.loonheffingenVerklaringRetainedUntil` (nullable
date). `lib/Standards/Checks/NlPayrollChecks.php` SHALL add `nl-loonbelastingverklaring-bewaarplicht-5jaar`
(severity `recommended`, source Uitvoeringsregeling loonbelasting 2011 art. 12.1 lid 5): vacuous unless
`loonheffingenVerklaringOnFile` is `true`; else satisfied only when `loonheffingenVerklaringRetainedUntil` is
populated and dated at least 5 full calendar years after the year `Employee.endDate` falls in (vacuously
satisfied while `endDate` is unset — still employed, the retention clock has not started), using the existing
`retainedAtLeastYearsAfterEnd()` helper unchanged.

#### Scenario: A compliant retained loonbelastingverklaring passes
- **GIVEN** an `Employee` with `loonheffingenVerklaringOnFile: true`, `endDate: "2026-06-30"`, and
  `loonheffingenVerklaringRetainedUntil: "2031-12-31"` (5 full calendar years after 2026)
- **WHEN** `occ hrmq:rules:audit` runs
- **THEN** no `nl-loonbelastingverklaring-bewaarplicht-5jaar` violation is reported for that employee

#### Scenario: An on-file statement with no retention date is flagged
- **GIVEN** an `Employee` with `loonheffingenVerklaringOnFile: true`, `endDate: "2026-06-30"`, and
  `loonheffingenVerklaringRetainedUntil` unpopulated
- **WHEN** `occ hrmq:rules:audit` runs
- **THEN** an `nl-loonbelastingverklaring-bewaarplicht-5jaar` violation is reported for that employee

#### Scenario: An employee with no statement on file is out of scope
- **GIVEN** an `Employee` with `loonheffingenVerklaringOnFile: false` (or absent)
- **WHEN** `occ hrmq:rules:audit` runs
- **THEN** no `nl-loonbelastingverklaring-bewaarplicht-5jaar` violation is reported for that employee (vacuous)

### Requirement: A generated loonstrook or jaaropgaaf SHALL carry a retention deadline, so the shipped AVG-DSR retention guard protects it (REQ-DOSS-003)

`lib/Settings/register.d/hr-documents.json` SHALL add `GeneratedDocument.retainedUntil` (nullable date).
`HrDocumentService::generateLoonstrook()` SHALL populate it from the resolved `Payslip.retainedUntil` when
populated, else derive it as 31 December of (the `Payslip.period` year + 7) — the identical AWR art. 52 lid 4
formula already applied elsewhere in this codebase. `HrDocumentService::generateJaaropgaaf()` SHALL populate it
as 31 December of (the aggregate's `year` + 7) directly. The four letter-type documents
(`arbeidsovereenkomst`/`aanbiedingsbrief`/`werkgeversverklaring`/`getuigschrift`) SHALL NOT have `retainedUntil`
populated by this change. `AvgDsrRetentionClassifier` SHALL NOT be modified — it already reads any schema's
populated `retainedUntil` field.

#### Scenario: A loonstrook inherits its Payslip's populated retention date
- **GIVEN** a `Payslip` with `retainedUntil: "2033-12-31"`
- **WHEN** `HrDocumentService::generateLoonstrook()` renders its loonstrook
- **THEN** the resulting `GeneratedDocument.retainedUntil` is `"2033-12-31"`

#### Scenario: A loonstrook derives its retention date when the Payslip has none
- **GIVEN** a `Payslip` with `period: "2026-05"` and `retainedUntil` unpopulated
- **WHEN** `HrDocumentService::generateLoonstrook()` renders its loonstrook
- **THEN** the resulting `GeneratedDocument.retainedUntil` is `"2033-12-31"` (31 December of 2026 + 7)

#### Scenario: A jaaropgaaf derives its retention date from its year
- **GIVEN** `HrDocumentService::generateJaaropgaaf(employeeId, 2026)` renders a jaaropgaaf
- **WHEN** the `GeneratedDocument` is created
- **THEN** its `retainedUntil` is `"2033-12-31"` (31 December of 2026 + 7)

#### Scenario: A retention-locked loonstrook is now excluded from a DSR erase
- **GIVEN** an employee with a `Payslip` inside its 7-year AWR window and its generated loonstrook
  `GeneratedDocument` (this change ensures `retainedUntil` is populated on it)
- **WHEN** `AvgDsrService::previewErasure()` runs for that employee (avg-dsr, unmodified by this change)
- **THEN** the loonstrook `GeneratedDocument` appears in the `retained` list, not `wouldErase` — it is never
  passed into `eraseObjectsForSubject()`

#### Scenario: A letter-type document carries no retention signal
- **GIVEN** an `arbeidsovereenkomst` `GeneratedDocument` generated by this change's version of
  `HrDocumentService`
- **WHEN** it is inspected
- **THEN** `retainedUntil` is `null`

### Requirement: A record still present past its own populated retention deadline SHALL be flagged (REQ-DOSS-004)

`lib/Standards/Checks/NlDossierRetentionChecks.php` SHALL implement `CheckProvider`, auto-discovered, contributing
`nl-bewaartermijn-verstreken` (severity `recommended`) on `Employee` (checking
`identityDocumentRetainedUntil` and `loonheffingenVerklaringRetainedUntil`) and `GeneratedDocument` (checking
`retainedUntil`). The predicate SHALL be vacuous when the relevant field is unpopulated, and SHALL violate when a
populated field's date is before today.

#### Scenario: A past identity-document retention date is flagged
- **GIVEN** an `Employee` with `identityDocumentRetainedUntil` dated in the past
- **WHEN** `occ hrmq:rules:audit` runs
- **THEN** an `nl-bewaartermijn-verstreken` violation is reported for that employee

#### Scenario: A past GeneratedDocument retention date is flagged
- **GIVEN** a `GeneratedDocument` with `retainedUntil` dated in the past and `status: generated` (still present)
- **WHEN** `occ hrmq:rules:audit` runs
- **THEN** an `nl-bewaartermijn-verstreken` violation is reported for that document

#### Scenario: A future retention date is not flagged
- **GIVEN** a `GeneratedDocument` with `retainedUntil` dated in the future
- **WHEN** `occ hrmq:rules:audit` runs
- **THEN** no `nl-bewaartermijn-verstreken` violation is reported for it

#### Scenario: An unpopulated retention field is vacuous, not flagged
- **GIVEN** a `GeneratedDocument` with `retainedUntil` unpopulated (a letter-type document, per REQ-DOSS-003)
- **WHEN** `occ hrmq:rules:audit` runs
- **THEN** no `nl-bewaartermijn-verstreken` violation is reported for it

#### Scenario: The check never deletes, anonymises, or destroys anything
- **GIVEN** any record flagged by `nl-bewaartermijn-verstreken`
- **WHEN** `occ hrmq:rules:audit` runs
- **THEN** the flagged record's data is unchanged — the check reports only; no destruction job exists in this
  change
