## 1. Rules

- [x] 1.1 Declare the leave, timesheet and expense rules of design D1 and D2 on
      `LeaveRequest`, `Timesheet` and `Expense`, bump the schema versions. Verify: hydra gate
      18 passes and `occ maintenance:repair` imports the fragments.
- [x] 1.2 Declare the `LeaveTransaction`, `PerformanceReview` and `Payslip` rules (payslip
      disabled by default). Verify: gate 18 passes.
- [x] 1.3 Check the rules land in OpenRegister. Verify: `GET /apps/openregister/api/notification-preferences`
      as a seed employee lists the humaniq rules with their defaults.

## 2. Preferences

- [x] 2.1 Mount `CnNotificationPreferences` in the humaniq user settings. Verify: `npm run lint`
      exits 0; the settings dialog lists only humaniq rules.

## 3. Verification

- [x] 3.1 e2e or reason-bearing `@e2e exclude` per scenario (gate 19), and a live round trip:
      submit leave as an employee, see the manager's notification, reject it, see the
      employee's notification with the reason, switch the rule off, and see no notification
      on the next rejection.

Verified 2026-09-29: red first, DecisionNotificationRulesTest (13 failures); green after. Every rule
validated with OpenRegister's NotificationAnnotationValidator (0 errors; a bogus control rule is
rejected). The import (1.1), the preferences listing (1.3) and the live round trip (3.1) are the
recipe in the PR body, not run in this lane.
