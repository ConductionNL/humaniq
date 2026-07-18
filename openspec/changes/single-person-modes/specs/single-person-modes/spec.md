# Delta — single-person-modes

An `Administration.mode` toggle (ADR-001 Rule 4), the `manifest.runtime.user` wiring that lets nc-vue's
`visibleIf` primitive act on it (closing `multi-administratie` REQ-MULTI-006), `visibleIf`-gated menu surfaces for
the DGA-single-person and eenmanszaak-no-payroll flavours, a soft headcount-drift check, and a self-service
surface for the existing gebruikelijkloon compliance verdict.

## ADDED Requirements

### Requirement: An Administration.mode enum SHALL model the single-person mode-switch (REQ-SPM-001)

`lib/Settings/register.d/hr-administratie.json` SHALL define `Administration.mode` as an enum
`standard`/`dga_single_person`/`eenmanszaak_no_payroll`, defaulting to `standard`. No new schema, table, or
top-level menu SHALL be introduced to represent the mode — it SHALL live as a field on the existing
`Administration` schema, under the existing `Configuratie › Administraties` surface.

#### Scenario: The default mode is standard for every existing administratie
- **GIVEN** an `Administration` record created before this change (no `mode` value ever set)
- **WHEN** it is read after this change ships
- **THEN** `mode` resolves to `standard`

#### Scenario: An administratie can be switched to dga_single_person
- **GIVEN** an `Administration` record
- **WHEN** an admin sets `mode: dga_single_person` and saves
- **THEN** the record validates against the schema and `mode` reads back as `dga_single_person`

#### Scenario: The mode is reversible with no data loss
- **GIVEN** an `Administration` in `dga_single_person` mode with a seeded DGA `Employee` and payroll history
- **WHEN** an admin switches `mode` back to `standard`
- **THEN** no `Employee`, `EmploymentContract`, or `Payslip` record is deleted or modified, and the administratie
  behaves exactly as any other `standard` administratie

### Requirement: The active administratie's mode SHALL be available to the manifest renderer as runtime.user context (REQ-SPM-002)

`PageController::index()` SHALL stamp the caller's active administratie's `mode` as initial state
(`activeAdministrationMode`) before the template renders, using the same `IInitialState` mechanism REQ-MULTI-004
established for `activeAdministrationId`, defaulting to `standard` when no active administratie is resolved.
`AdministrationController::context()` SHALL include `mode` on every administratie entry in its response. The
resolved mode SHALL be exposed to `visibleIf` predicates as `manifest.runtime.user.administrationMode`.

#### Scenario: A caller with no active administratie sees the standard default
- **GIVEN** a caller who has never selected an active administratie
- **WHEN** the SPA page loads
- **THEN** `activeAdministrationMode` initial state is `standard` (or absent, resolving to the same default), and
  no `visibleIf` predicate keyed on `administrationMode` hides a menu entry for this caller

#### Scenario: Switching administratie updates the stamped mode on next load
- **GIVEN** a caller whose active administratie is a `standard` one
- **WHEN** they switch their active administratie to one with `mode: dga_single_person` and reload
- **THEN** `activeAdministrationMode` initial state is `dga_single_person` on the next page load

#### Scenario: The context endpoint reports mode per administratie
- **GIVEN** a caller with `AdministrationAccess` to two administraties, one `standard` and one
  `eenmanszaak_no_payroll`
- **WHEN** they call `GET /api/administration/context`
- **THEN** the response's `administrations` array carries the correct `mode` value for each entry

### Requirement: Multi-person-only menu surfaces SHALL hide when the active administratie's mode is not standard (REQ-SPM-003)

`src/manifest.json` SHALL carry a `visibleIf` condition hiding `OrgUnits`, `OrgAssignments`,
`TimesheetApproval`, `TeamUrengoedkeuring`, `LeaveApproval`, `TeamVerlofgoedkeuring`, `ExpenseApproval`,
`TeamDeclaratiegoedkeuring`, and the `PlanningGroup` (`Rosters`/`RosterAssignments`/`Shifts`) menu entries
whenever `manifest.runtime.user.administrationMode` is not `standard`. `Medewerkers` (`Employees`,
`EmploymentContracts`) and `MijnHrGroup` entries SHALL NOT be hidden by this requirement.

#### Scenario: A dga_single_person caller does not see org-chart or team-approval menus
- **GIVEN** a caller whose active administratie has `mode: dga_single_person`
- **WHEN** the app navigation renders
- **THEN** `OrgUnits`, `OrgAssignments`, `TimesheetApproval`, `TeamUrengoedkeuring`, `LeaveApproval`,
  `TeamVerlofgoedkeuring`, `ExpenseApproval`, `TeamDeclaratiegoedkeuring`, and the `PlanningGroup` entries do not
  appear, while `Employees` and `EmploymentContracts` do

#### Scenario: A standard caller sees every existing menu entry unchanged
- **GIVEN** a caller whose active administratie has `mode: standard` (or no `mode` set)
- **WHEN** the app navigation renders
- **THEN** every menu entry present before this change still renders — no regression for the existing
  multi-employee fixtures

### Requirement: Payroll-run generation surfaces SHALL additionally hide under eenmanszaak_no_payroll mode only (REQ-SPM-004)

`src/manifest.json` SHALL carry a `visibleIf` condition hiding the whole `PayrollGroup` and
`ProformaPayslipMenu` entries whenever `manifest.runtime.user.administrationMode` equals
`eenmanszaak_no_payroll`. This condition SHALL NOT apply when the mode is `dga_single_person` — a DGA still
receives one monthly `loon` payment through the existing payroll engine.

#### Scenario: An eenmanszaak_no_payroll caller sees no payroll menus
- **GIVEN** a caller whose active administratie has `mode: eenmanszaak_no_payroll`
- **WHEN** the app navigation renders
- **THEN** no `PayrollGroup` child entry and no `ProformaPayslipMenu` entry appears

#### Scenario: A dga_single_person caller still sees Salarissen
- **GIVEN** a caller whose active administratie has `mode: dga_single_person`
- **WHEN** the app navigation renders
- **THEN** the `PayrollGroup` entries (including `Payslips`, `PayrollRuns`) and `ProformaPayslipMenu` remain
  visible

### Requirement: A recommended-severity check SHALL flag employee-count drift on a dga_single_person administratie, never block it (REQ-SPM-005)

`lib/Standards/Checks/NlSinglePersonChecks.php` SHALL implement `CheckProvider`, auto-discovered by
`RuleEngine::providers()`, contributing `nl-single-person-mode-employee-count` (recommended severity) on
`Administration`. The predicate SHALL be vacuous unless `mode` is `dga_single_person`; else it SHALL count active
`Employee` records whose `administrationId` equals the `Administration`'s own `administrationId`, and SHALL be
satisfied only when that count is exactly 1 and the matching `Employee` has `isDga: true`. This check SHALL NOT
block any write — it SHALL surface only through the existing `occ hrmq:rules:audit` report.

#### Scenario: Exactly one DGA employee passes
- **GIVEN** an `Administration` with `mode: dga_single_person` and exactly one active `Employee` with matching
  `administrationId` and `isDga: true`
- **WHEN** the RuleEngine audits the corpus
- **THEN** no `nl-single-person-mode-employee-count` violation is reported for that administratie

#### Scenario: A second employee is flagged, not blocked
- **GIVEN** the same administratie now has a second active `Employee` with matching `administrationId`
- **WHEN** an admin saves that second `Employee` record
- **THEN** the save succeeds (no write-time block), and the next `occ hrmq:rules:audit` run reports an
  `nl-single-person-mode-employee-count` violation for the administratie

#### Scenario: A standard-mode administratie is never evaluated
- **GIVEN** an `Administration` with `mode: standard` and five active employees
- **WHEN** the RuleEngine audits the corpus
- **THEN** no `nl-single-person-mode-employee-count` violation is reported (vacuous — out of scope for this rule)

### Requirement: A self-service endpoint SHALL surface the existing gebruikelijkloon compliance verdict for the caller's own DGA record (REQ-SPM-006)

`PayrollController::dgaStatus()` (`#[NoAdminRequired]`, `GET /api/payroll/dga-status`) SHALL resolve the caller's
own `Employee` via `nextcloudUserId`, respond **404** when no such `Employee` exists or the resolved `Employee`
has `isDga` not `true`, and otherwise SHALL evaluate the existing `NlDgaChecks` `nl-gebruikelijkloon-norm`
predicate against that one record — computed fresh on every call, persisting nothing — and return
`{isDga: true, grossAnnualSalaryCents, jaarnormCents, met: bool, justification: string|null}`. `src/manifest.json`
SHALL expose a `MijnGebruikelijkLoon` self-service page under `MijnHrGroup`, `visibleIf`-gated on
`administrationMode: dga_single_person`, rendering the verdict without requiring `occ hrmq:rules:audit`.

#### Scenario: A below-norm DGA sees a warning without running occ
- **GIVEN** a caller whose own `Employee` record has `isDga: true`, `grossMonthlySalary: 3500.00` (annualised
  €42.000, below the €58.000 norm), and no `gebruikelijkloonJustification`
- **WHEN** they call `GET /api/payroll/dga-status`
- **THEN** the response is `{isDga: true, grossAnnualSalaryCents: 4200000, jaarnormCents: 5800000, met: false,
  justification: null}`, and the `MijnGebruikelijkLoon` page's banner renders the warning variant

#### Scenario: A justified below-norm DGA is reported as met
- **GIVEN** the same below-norm caller now has a non-empty `gebruikelijkloonJustification`
- **WHEN** they call `GET /api/payroll/dga-status`
- **THEN** `met` is `true` (the existing `NlDgaChecks` vacuous-when-justified rule applies unchanged)

#### Scenario: A non-DGA caller and a caller with no Employee record both receive 404
- **GIVEN** two callers — one with no `Employee` record linked via `nextcloudUserId`, one with an `Employee`
  record where `isDga` is `false`
- **WHEN** either calls `GET /api/payroll/dga-status`
- **THEN** both receive HTTP 404 with no way to distinguish which case occurred from the response alone

#### Scenario: The status is computed fresh, never persisted
- **GIVEN** a below-norm DGA caller
- **WHEN** `GET /api/payroll/dga-status` is called twice, with `Employee.grossMonthlySalary` raised above the
  norm threshold between calls
- **THEN** the second call's `met` is `true` and no register write occurred from either call
