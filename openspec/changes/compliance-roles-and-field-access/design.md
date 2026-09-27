# Design: HR, payroll, manager and employee roles, down to the field

## Context

Read at `development` af702f78.

- Admin checks: `PayrollController::isAdminOrHr()` (`lib/Controller/PayrollController.php:263`)
  returns `IGroupManager::isAdmin()`; the same pattern sits in `AvgDsrController.php:274`,
  `OfferController.php:135`, `JurisdictionPackController.php:110` and `:148`,
  `ExpenseController.php:178`, `InterviewController.php:126`, `LoonbeslagController.php:261`.
- Scoping fields: `userId` and `managerUserId` on the request schemas (`mss-team-scope`,
  `mijn-hr-self-service`); `Employee.nextcloudUserId`. `AdministrationAccess.role`
  (`accountant`, `hr`, `employee`) governs which administration a user may switch into
  (`multi-administratie`).
- No humaniq schema declares `authorization`. OpenRegister enforces a property rule of the
  form `authorization: {read: [{group, match}], update: [...]}`
  (`openregister/lib/Service/PropertyRbacHandler.php`), both when rendering data out and when
  validating data in. hydra ADR-001 forbids custom permission checks in apps; the
  `aor-ambtenarenrecht` F-009 decision records that confidentiality tiering is OpenRegister's.
- `PerformanceReview` (`hr-performance.json`): `reviewerId` is a `$ref` to `Employee`, not a
  uid; `userId` is the reviewed employee's uid; `vastgesteldDoor` a uid.
- Repair steps live in `lib/Repair` and are listed in `appinfo/info.xml`.

## Goals / Non-Goals

**Goals**

- Salary, identity and bank data readable only by HR, payroll and the subject, on every
  page and API, because OpenRegister enforces it.
- One place in humaniq that answers who is HR or payroll.

**Non-Goals**

- An app-level role editor. Group membership is managed in Nextcloud's user settings.
- Row-level restriction of every schema. This change covers the named sensitive fields and
  review content; rows stay scoped as today.

## Decisions

### D1. Groups, created once, configurable

`EnsureRoleGroups` creates `humaniq-hr` and `humaniq-payroll` when absent and stores their ids
in `SettingsService`; an administrator may point the settings at existing groups instead.
Alternative considered: reusing `AdministrationAccess.role`. Rejected for enforcement:
OpenRegister's `authorization` blocks match Nextcloud groups, not objects in a register.

### D2. `HumaniqRoles`

`isHr(uid)`, `isPayroll(uid)`, `isHrOrPayroll(uid)`, each true for an administrator too. The
controller checks call it; behaviour for an administrator is unchanged, and a member of the
HR group gains the HR actions (offers, loonbeslag, interviews, expense admin), a member of the
payroll group the payroll actions (runs, packs). The data subject request actions in
`AvgDsrController` stay administrator-only: `hr-dsr.json` records that OpenRegister's
`DsarService::assertPrivileged()` hard-requires a Nextcloud administrator, so widening them
in humaniq would only move the refusal.

### D3. Property authorization

On each sensitive property: `read` for `humaniq-hr`, `humaniq-payroll`, and the subject
(`{group: "public", match: {nextcloudUserId: "$userId"}}` on `Employee`, `userId` on
`Payslip`); `update` for `humaniq-hr` (and `humaniq-payroll` on pay fields). A manager or a
colleague reading the object gets it without those fields. The admin group passes through
OpenRegister's own admin bypass.

### D4. Review content

`PerformanceReview` gains `reviewerUserId` (uid of the reviewer, stamped from
`reviewerId`'s `Employee.nextcloudUserId` by a pre-save listener, the `TimeEntryStampListener`
precedent). Content properties read and update: `{match: {userId: "$userId"}}` and
`{match: {reviewerUserId: "$userId"}}`; HR has no read on them. Status, `besprokenOp`,
`vastgesteldDoor` stay readable by HR, so `ReviewCycleDetail` shows progress.

## Declarative-vs-imperative decision (ADR-031)

| behaviour | path | why |
|---|---|---|
| who reads and updates which field | declarative property `authorization` | OpenRegister enforces it |
| humaniq's own endpoints | one imperative `HumaniqRoles` check | controller guards, the existing pattern consolidated |
| reviewer uid | pre-save stamp listener | a denormalised uid the authorization matches on |

## Seed data

- Seed users `hr-demo` in `humaniq-hr`, `payroll-demo` in `humaniq-payroll`, a manager and an
  employee, on the existing seed employees, so the four views can be checked.

## Risks / Trade-offs

- [Existing HR users lose rights they had as admins] → administrators keep every right; the
  upgrade note tells an administrator to add HR staff to `humaniq-hr`.
- [A page that shows a field the reader cannot see] → the data widget renders what
  OpenRegister returns; a hidden field simply does not appear.

## Open Questions

- Should managers read the salary of their own team? This design says no, the Personio and
  OrangeHRM default; an employer can widen the rule in the register.
