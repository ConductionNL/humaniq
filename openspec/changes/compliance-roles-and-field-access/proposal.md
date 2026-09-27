---
kind: code
---

# HR, payroll, manager and employee roles, down to the field

## Why

humaniq knows two kinds of user: a Nextcloud administrator, who may do everything sensitive,
and everyone else. Seven controllers each carry their own copy of an admin check, and the one
called `isAdminOrHr()` is, by its own caveat, just `isAdmin()`. Managers and employees are kept
to their own rows by page filters (`managerUserId`, `userId`), which are scoping, not
permission. No schema declares an OpenRegister `authorization` block, so a salary, a BSN or a
bank account is readable by anyone who can open the record at all.

Every suite the buyer compares humaniq with separates HR, payroll, managers and employees, and
three of them hide salary and personal data per field from roles that do not need it. A
municipal tender also asks that HR can follow whether appraisal conversations took place
without reading what was said.

### Matrix rows (humaniq `openspec/parity/capabilities.json`)

| row | capability | humaniq today |
|---|---|---|
| `cmp-roles` | Give HR, payroll, managers and employees different permissions. | `partial`: an admin or non-admin split plus page-level scoping |
| `cmp-field-rbac` | Hide sensitive fields such as salary or BSN from roles that do not need them. | `no`, none: no property-level authorization anywhere in the register |
| `td-review-progress-private` | Let HR track whether appraisal conversations took place without reading what was said. | `partial`: HR sees review status and content alike |

A dedicated HR group is the named fast-follow of `2026-07-15-avg-dsr` and
`2026-07-14-loonbeslag` ("a dedicated Nextcloud HR group (vs. reusing the admin group)").

### Demand

- `td-review-progress-private`, tender: https://www.tenderned.nl/aankondigingen/overzicht/415705
  (Delft Support E33, voortgang beoordelingsgesprekken bewaken zonder de inhoud te zien).

### Competitors rated yes

- `cmp-roles`, AFAS Profit: "access is set per user group in the autorisatie tool, with filter
  authorisation so managers see only their own employees"
  (https://help.afas.nl/help/NL/SE/Hrm_Conf_Privacy_Law_WBP.htm).
- `cmp-roles`, Visma Raet Youforce: "managers, employees, HR staff and administrators have
  different rights" (https://youforce.nl/security).
- `cmp-roles`, HR2day: "scopes on roles give users access to specific actions such as entering
  absence or approving processes" (https://www.hr2day.com/nieuws/nieuwe-release-kiwi/).
- `cmp-roles`, Loket.nl: "authorization groups with authorizations, employers and users"
  (https://developer.loket.nl/ApiDocs#tag/Authorization-group).
- `cmp-roles`, Personio: "employee roles with distinct permissions for admins, supervisors,
  payroll and employees"
  (https://support.personio.de/hc/en-us/articles/360000040389-Set-up-permissions-and-employee-roles).
- `cmp-field-rbac`, HR2day: "Field Level Security section in Organisatie en Systeem"
  (https://data.maglr.com/1697/issues/66612/785296/index.html).
- `cmp-field-rbac`, Personio: "permissions per profile section and data group, with salary
  information and salary band as separate permissions that can be withheld from a role"
  (https://support.personio.de/hc/en-us/articles/36459341835037-Summary-of-permissions-New-experience).
- `cmp-field-rbac`, OrangeHRM: "the salary data group is unreadable for Supervisor, and
  sensitive personal details sit in their own data group"
  (orangehrm@v5.9 installer/Migration/V3_3_3/dbscript-2.sql:1780).

## What Changes

- **Two Nextcloud groups.** humaniq creates and names an HR group and a payroll group
  (defaults `humaniq-hr` and `humaniq-payroll`, configurable in the admin settings). Managers
  stay defined by the org chart (`managerUserId`), employees by their own account (`userId`,
  `nextcloudUserId`).
- **One role check for humaniq's own endpoints.** A single `HumaniqRoles` service answers "is
  this user HR", "is this user payroll", and replaces the copied admin checks. A
  Nextcloud administrator keeps every right. Data subject requests stay administrator-only,
  because OpenRegister's `DsarService` requires an administrator.
- **OpenRegister enforces the fields.** Property-level `authorization` on the sensitive
  fields: `Employee.bsn`, `iban`, `tenaamstelling`, `grossMonthlySalary`, `dateOfBirth` and the
  identity document fields; `EmploymentContract.hourlyWage`; the amounts on `Payslip`. They are
  readable by HR, payroll and the employee the record is about, and not by a manager or a
  colleague.
- **Review content stays between the two people.** On `PerformanceReview` the content
  (`sterktes`, `ontwikkelpunten`, `afspraken`, `goals`, `rating`) is readable by the employee
  and the reviewer only; HR reads status, dates and who reviewed, so `ReviewCycleDetail` still
  shows progress.

## Capabilities

### New Capabilities

- `humaniq-roles-and-field-access`: HR and payroll groups, one role check, and field-level
  authorization on salary, identity and review content.

## Impact

- `lib/Repair/EnsureRoleGroups.php` (new, registered in `appinfo/info.xml`),
  `lib/Service/HumaniqRoles.php` (new), `lib/Service/SettingsService.php` (group ids).
- The controllers that copy `isAdmin()`: `OfferController`,
  `PayrollController`, `JurisdictionPackController`, `ExpenseController`,
  `InterviewController`, `LoonbeslagController`.
- `lib/Settings/register.d/hr-objects.json` (Employee, EmploymentContract, Payslip),
  `hr-performance.json` (PerformanceReview gains `reviewerUserId`): property `authorization`.
- `src/views` admin settings: the two group ids.

## Out of scope

- Per-administration roles beyond the existing `AdministrationAccess` membership
  (`multi-administratie` names them post-MVP).
- A permissions overview page (`dm-permission-overview`, deferred).
