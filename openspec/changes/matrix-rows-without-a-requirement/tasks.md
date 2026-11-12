## 1. Build

- [ ] 1.1 Probation end signal in `lib/Standards/Checks/NlSignalChecks.php` and the corpus (REQ-SIG-007).
- [ ] 1.2 `budgetAmount` on `CompReviewCycle` in `lib/Settings/register.d/hr-comp.json`, with the used and left figures on `CompReviewCycleDetail` (REQ-COMP-009).
- [ ] 1.3 Pay the open leave balance on the final payslip in `lib/Service/PayrollRunService.php` (REQ-OFC-005).
- [ ] 1.4 Tests for `TimesheetNotEmptyGuard` and a guard on the `reopen` edge in `lib/Lifecycle/` (timesheet requirements).
- [ ] 1.5 `hourlyRate` on `TimeEntry` in `lib/Settings/register.d/hr-timesheet.json` (REQ-TEC-009).
- [ ] 1.6 Today view on the attendance page in `src/manifest.d/hr-attendance.json` (REQ-TA-007).
- [ ] 1.7 Employee sign-off transition in `lib/Settings/register.d/hr-performance.json` (REQ-PRV-007).
- [ ] 1.8 Clock in and Clock out actions on `My attendance` in `src/manifest.d/hr-attendance.json`, with server-side stamping of employee, user and `workedHours` in a new `lib/Service/AttendanceClockService.php` (REQ-TA-008).
- [ ] 1.9 Integrator guide for the employee and contract calls in `docs/`, and an API test with an app password in `tests/Integration/` (REQ-HRIS-006).
- [ ] 1.10 VAT overview per period on the expenses pages in `src/manifest.d/hr-expense.json`, and the VAT line in `lib/Service/PayrollGLPostService.php` (REQ-RCPT-008).
- [ ] 1.11 `Compliance check` page in `src/manifest.d/` backed by a guarded audit endpoint over `lib/Service/RuleAuditService.php` (REQ-RULE-010).

## 2. Hand-over

- [ ] 2.1 `@spec` tags and an e2e test or a reason-bearing `@e2e exclude` per scenario.
