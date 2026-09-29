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

### D1. Groups, created once, with fixed ids

`EnsureRoleGroups` (a repair step on install and on every upgrade) creates `humaniq-hr` and
`humaniq-payroll` when absent and leaves an existing group and its members alone.

**Decision (built 2026-09-29, reversible by Ruben): the group ids are fixed, not
configurable.** OpenRegister's property `authorization` blocks name groups literally in the
register, so a setting that pointed humaniq's own checks at another group would leave the
register guarding the old one: the two halves of the rule would disagree in silence. To make
them configurable, the register import would have to rewrite the group names in the
`authorization` blocks from the setting; that is a larger change and needs a product call.
There is therefore no group setting on the admin page (task 3.1 changed accordingly).
Alternative considered: reusing `AdministrationAccess.role`. Rejected for enforcement:
OpenRegister's `authorization` blocks match Nextcloud groups, not objects in a register.

### D2. `HumaniqRoles`

`isHr(uid)`, `isPayroll(uid)`, `isHrOrPayroll(uid)`, each true for an administrator too. The
controller checks call it; behaviour for an administrator is unchanged. HR actions, for the
HR group: offers (`OfferController`), wage garnishments (`LoonbeslagController`), interview
sync (`InterviewController`), receipt extraction for someone else's expense
(`ExpenseController`). Payroll actions, for the payroll group: calculating a run (a new check
on `PayrollController::calculate()`, which before only resolved the run under RBAC), the
mutation report and the WKR assessment. Two stay administrator-only on purpose: the data
subject request actions in `AvgDsrController`, because OpenRegister's
`DsarService::assertPrivileged()` requires an administrator, and the jurisdiction pack
upload, which is `#[AuthorizedAdminSetting]` because a pack carries executable payroll
arithmetic. Controllers that already accept the per-administration `AdministrationAccess`
role `hr` (travel, UWV notification, compensation cycles, analytics) keep that check; it
answers a different question (which administration), and merging the two is out of scope.

### D3. Property authorization

On each sensitive property: `read` for `humaniq-hr`, `humaniq-payroll`, and the subject
(`{group: "public", match: {nextcloudUserId: "$userId"}}` on `Employee`, `userId` on
`Payslip`); `update` for `humaniq-hr` (and `humaniq-payroll` on pay fields). A manager or a
colleague reading the object gets it without those fields. The admin group passes through
OpenRegister's own admin bypass.

Payslip: every number property and `engineInputSnapshot` (which holds the salary the run
was calculated from); read by HR, payroll and the subject (`userId`), update by payroll.
`EmploymentContract` gains `userId`, stamped from the employee (D5), so the subject rule has
something to match.

### D4. Review content

`PerformanceReview` gains `reviewerUserId` (uid of the reviewer, stamped from
`reviewerId`'s `Employee.nextcloudUserId` by a pre-save listener, the `TimeEntryStampListener`
precedent). Content properties read and update: `{match: {userId: "$userId"}}` and
`{match: {reviewerUserId: "$userId"}}`; HR has no read on them. Status, `besprokenOp`,
`vastgesteldDoor` stay readable by HR, so `ReviewCycleDetail` shows progress.

### D5. `FieldAccessListener`: stamp the uids, keep what a save would wipe

One pre-save listener on the four schemas (`ObjectCreatingEvent`, `ObjectUpdatingEvent`):

- It stamps `EmploymentContract.userId` from `employeeId` and
  `PerformanceReview.reviewerUserId` from `reviewerId`, each from the Employee's
  `nextcloudUserId`, read with `_rbac: false` through `HoursRegisterGateway`.
- It carries a protected value forward. OpenRegister strips a field the reader may not see,
  and a full save fills every absent property with null
  (`SaveObject::fillMissingSchemaPropertiesWithNull()`); its update check only looks at keys
  in the payload. A manager who opens an employee and saves it would so erase the BSN, bank
  account and salary. When an update empties a field that OpenRegister's own
  `PropertyRbacHandler::canReadProperty()` says the writer cannot read, the stored value is
  kept (`FieldReadAccess::carriedForward()`). When OpenRegister cannot be asked, every emptied
  value is kept: a lost BSN cannot be restored, a refused clear can be repeated. Filed
  upstream as ConductionNL/openregister#4170; when OpenRegister carries these values itself,
  the carry half of this listener can go.
- `EmployeeGuardedFieldListener` (people-record-change-approval) treats a carried value as no
  change, so a manager's save is not refused as an unapproved bank account change.

### D6. Aggregates and audits read past the field strip

Services that aggregate or audit read with `_rbac: false` (rows are unchanged, because no
humaniq schema declares object-level authorization): `AnalyticsService` (a team leader is
authorised for the unit's figures by `AnalyticsAccess` and sees aggregates with the
minimum-size suppression, which the payslip amounts must still feed), `RuleAuditService`
(also behind `PayrollRunApprovedGuard`), `PayrollAuditVerificationService` and
`PayrollReproduceService` (their `occ` commands have no user, so every protected field would
read as missing). Most register gateways in this app already read this way.

Consequence not changed here: `EmployerCostRateController` resolves the cost rate from the
employee as the caller reads it, so a caller outside HR, payroll and the employee now gets
no rate (the salary is hidden from them). Whether a project manager should get a cost rate
derived from a salary they may not read is a product call, listed for Ruben.

### Limitation: review content is written after the review exists

OpenRegister evaluates a `match` rule on create against the stored object, which is empty
(`PropertyRbacHandler::checkConditionalRule()`), so nobody but an administrator can create a
review with content in one save. The review is created with its employee, reviewer and
cycle, and the content is filled in on the review. Verified against the real class.

### Verification against OpenRegister

The declarations were evaluated with OpenRegister's own `PropertyRbacHandler`,
`ConditionMatcher` and `StateFieldRuleResolver` (development at the time of writing, 72
assertions): HR, payroll, the subject and an administrator read the governed employee,
contract and payslip fields and a manager, a colleague and a caller without a session do not;
payroll may update the salary but not the BSN; HR reads a review's status, date and
finaliser but not its content, the employee and the reviewer read and update the content.
`tests/Unit/Settings/FieldAuthorizationDeclarationTest.php` pins the declarations.

## Declarative-vs-imperative decision (ADR-031)

| behaviour | path | why |
|---|---|---|
| who reads and updates which field | declarative property `authorization` | OpenRegister enforces it |
| humaniq's own endpoints | one imperative `HumaniqRoles` check | controller guards, the existing pattern consolidated |
| reviewer and contract uid | pre-save stamp listener | a denormalised uid the authorization matches on |
| a protected value a full save would null | pre-save carry, asking OpenRegister's handler | OpenRegister does not carry it (#4170) |

## Seed data

No demo accounts are seeded. A repair step that creates Nextcloud users would ship accounts
with known passwords to every production instance. The seed's two Jansen contracts carry
`userId: admin` (Jansen's account); the live check creates `hr-demo`, `payroll-demo` and a
manager with `occ user:add` and `occ group:adduser`.

## Risks / Trade-offs

- [Existing HR users lose rights they had as admins] → administrators keep every right; the
  upgrade note tells an administrator to add HR staff to `humaniq-hr`.
- [A user with the `AdministrationAccess` role `hr` who is not in `humaniq-hr` no longer
  reads BSN, bank account or salary] → the upgrade note says so; the two notions of HR stay
  separate (D2).
- [An internal read under the caller's RBAC that uses a protected field] → the aggregating
  and auditing readers moved to `_rbac: false` (D6); humaniq's other gateways already read
  that way.
- [A page that shows a field the reader cannot see] → the data widget renders what
  OpenRegister returns; a hidden field simply does not appear.

## Open Questions

- Should managers read the salary of their own team? This design says no, the Personio and
  OrangeHRM default; an employer can widen the rule in the register.
