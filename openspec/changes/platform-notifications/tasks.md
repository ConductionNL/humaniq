## 1. Rules

- [ ] 1.1 Declare the leave, timesheet and expense rules of design D1 and D2 on
      `LeaveRequest`, `Timesheet` and `Expense`, bump the schema versions. Verify: hydra gate
      18 passes and `occ maintenance:repair` imports the fragments.
- [ ] 1.2 Declare the `LeaveTransaction`, `PerformanceReview` and `Payslip` rules (payslip
      disabled by default). Verify: gate 18 passes.
- [ ] 1.3 Check the rules land in OpenRegister. Verify: `GET /apps/openregister/api/notification-preferences`
      as a seed employee lists the humaniq rules with their defaults.

## 2. Preferences

- [ ] 2.1 Mount `CnNotificationPreferences` in the humaniq user settings. Verify: `npm run lint`
      exits 0; the settings dialog lists only humaniq rules.

## 3. Verification

- [ ] 3.1 e2e or reason-bearing `@e2e exclude` per scenario (gate 19), and a live round trip:
      submit leave as an employee, see the manager's notification, reject it, see the
      employee's notification with the reason, switch the rule off, and see no notification
      on the next rejection.
