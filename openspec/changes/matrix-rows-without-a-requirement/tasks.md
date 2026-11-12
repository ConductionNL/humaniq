## 1. Build

- [ ] 1.1 Probation end signal in `lib/Standards/Checks/NlSignalChecks.php` and the corpus (REQ-SIG-007).
- [ ] 1.2 `budgetAmount` on `CompReviewCycle` in `lib/Settings/register.d/hr-comp.json`, with the used and left figures on `CompReviewCycleDetail` (REQ-COMP-009).
- [ ] 1.3 Pay the open leave balance on the final payslip in `lib/Service/PayrollRunService.php` (REQ-OFC-005).
- [ ] 1.4 Tests for `TimesheetNotEmptyGuard` and a guard on the `reopen` edge in `lib/Lifecycle/` (timesheet requirements).
- [ ] 1.5 `hourlyRate` on `TimeEntry` in `lib/Settings/register.d/hr-timesheet.json` (REQ-TEC-009).
- [ ] 1.6 Today view on the attendance page in `src/manifest.d/hr-attendance.json` (REQ-TA-007).
- [ ] 1.7 Employee sign-off transition in `lib/Settings/register.d/hr-performance.json` (REQ-PRV-007).

## 2. Hand-over

- [ ] 2.1 `@spec` tags and an e2e test or a reason-bearing `@e2e exclude` per scenario.
