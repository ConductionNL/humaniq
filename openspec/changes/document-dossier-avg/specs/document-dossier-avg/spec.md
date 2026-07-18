# Delta — document-dossier-avg

A personnel-dossier view tying an employee's generated documents together, the loonbelastingverklaring retention
sibling to the shipped identity-document rule, and an `Employee`-scoped storage-limitation-ceiling check. **Revised
2026-07-18 (hrmq#99 consume-not-rebuild correction)**: the original REQ-DOSS-003 (a `GeneratedDocument.retainedUntil`
field) is REMOVED — superseded by hrmq#99's OpenRegister legal-hold inheritance
(`PayrollRetentionGuardService::inheritLegalHold()`); REQ-DOSS-004 is narrowed to the `Employee`-scoped entry only,
extending hrmq#99's already-shipped `NlDossierRetentionChecks.php` rather than duplicating its
`GeneratedDocument`/payroll-family coverage. See `openspec/specs/avg-dsr/spec.md` REQ-DSR-005 for what hrmq#99
shipped.

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

> **REQ-DOSS-003 REMOVED 2026-07-18 (hrmq#99 consume-not-rebuild correction).** The original requirement here
> ("A generated loonstrook or jaaropgaaf SHALL carry a `GeneratedDocument.retainedUntil` retention deadline")
> is superseded: hrmq#99 deleted `AvgDsrRetentionClassifier` (which would have read that field) and switched
> `AvgDsrService` to OpenRegister's guarded `Gdpr\DataSubjectRequestService::erase()`, which checks a legal hold /
> immutable archival status directly, never a `retainedUntil` field. The gap this requirement named (a generated
> PDF evading the guard its retained source carries) is now closed by `PayrollRetentionGuardService
> ::inheritLegalHold()`, consumed by `HrDocumentService::generateLoonstrook()`/`generateJaaropgaaf()` — see
> `openspec/specs/avg-dsr/spec.md` REQ-DSR-005 for the shipped requirement and scenarios. `GeneratedDocument`
> gains NO new schema field from this change or from hrmq#99.

### Requirement: An Employee record still present past its own populated retention deadline SHALL be flagged (REQ-DOSS-004)

`lib/Standards/Checks/NlDossierRetentionChecks.php` SHALL gain an `Employee` entry for `nl-bewaartermijn-verstreken` (severity `recommended`, checking `identityDocumentRetainedUntil` and `loonheffingenVerklaringRetainedUntil`) on the SAME already-implemented `CheckProvider` hrmq#99 shipped (auto-discovered) — not a new file. The predicate SHALL be vacuous when the relevant field is unpopulated, and SHALL violate when a populated field's date is before today.

_(Narrowed 2026-07-18, hrmq#99: the `GeneratedDocument`/payroll-family portion of this requirement, as originally
proposed, is already shipped by hrmq#99's `NlDossierRetentionChecks.php`, reading OpenRegister's own
`retention.archiefactiedatum` — see `openspec/specs/avg-dsr/spec.md` REQ-DSR-005. This requirement now covers
only the `Employee`-scoped entry this change adds to that SAME, already-shipped provider.)_

#### Scenario: A past identity-document retention date is flagged
- **GIVEN** an `Employee` with `identityDocumentRetainedUntil` dated in the past
- **WHEN** `occ hrmq:rules:audit` runs
- **THEN** an `nl-bewaartermijn-verstreken` violation is reported for that employee

#### Scenario: A future retention date is not flagged
- **GIVEN** an `Employee` with `loonheffingenVerklaringRetainedUntil` dated in the future
- **WHEN** `occ hrmq:rules:audit` runs
- **THEN** no `nl-bewaartermijn-verstreken` violation is reported for it

#### Scenario: An unpopulated retention field is vacuous, not flagged
- **GIVEN** an `Employee` with `identityDocumentRetainedUntil` and `loonheffingenVerklaringRetainedUntil` both
  unpopulated
- **WHEN** `occ hrmq:rules:audit` runs
- **THEN** no `nl-bewaartermijn-verstreken` violation is reported for that employee

#### Scenario: The check never deletes, anonymises, or destroys anything
- **GIVEN** any `Employee` flagged by `nl-bewaartermijn-verstreken`
- **WHEN** `occ hrmq:rules:audit` runs
- **THEN** the flagged record's data is unchanged — the check reports only; no destruction job exists in this
  change
