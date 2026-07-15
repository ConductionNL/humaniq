# Tasks — leave-accrual-job

> Consumes the leave-verzuim `LeaveBalance` schema + `labour.json` verlof rules (both at HEAD, from
> PR #22). Verify against HEAD, not this brief. Keep every mandatory verlof rule green.

- [x] 1. Schema delta: add the additive **nullable** `lastAccruedPeriod` (`YYYY-MM`, type string,
  `format`-free, nullable) property to `LeaveBalance` in `lib/Settings/register.d/hr-leave.json`
  with a description; leave `remainingHours` calc and all other fields untouched per REQ-ACCR-004
- [x] 2. Config getters in `lib/Service/SettingsService.php`: `isLeaveAccrualEnabled()`
  (`leave_accrual_enabled`, bool, default `true`) and `getLeaveBovenwettelijkAnnualHours()`
  (`leave_bovenwettelijk_annual_hours`, number, default `0`) per REQ-ACCR-003/-ACCR-005
- [x] 3. NEW `lib/BackgroundJob/LeaveAccrualJob.php` extending `OCP\BackgroundJob\TimedJob`:
  constructor `setInterval(~1 day)` + `setTimeSensitivity(TIME_INSENSITIVE)`; container-resolved
  ObjectService + SettingsService injected per REQ-ACCR-001
- [x] 4. `run()`: early-return no-op when `isLeaveAccrualEnabled()` is false per REQ-ACCR-005
- [x] 5. Active-employee enumeration: `loadAll('Employee')` filtered by
  `coversPeriod(startDate, endDate, currentPeriod)` (the PayrollRunService idiom) per REQ-ACCR-001
- [x] 6. Covering-contract resolution + `hoursPerWeek` read (`coveringContract`, EmploymentContract
  `hoursPerWeek`); skip + count employees with no contract / no `hoursPerWeek` / no identity per
  REQ-ACCR-005
- [x] 7. Balance probe: find the `(employeeId, year, leaveType: holiday)` LeaveBalance via
  ObjectService filter (probe-before-write) per REQ-ACCR-004
- [x] 8. First-accrual provisioning (absent balance): create with `entitledHours = 4 × hoursPerWeek`,
  `bovenwettelijkHours = round1(annualBovenwettelijk/12)`, `usedHours = 0`,
  `contractHoursPerWeek = hoursPerWeek`, `expiryDate = <year+1>-07-01`,
  `lastAccruedPeriod = currentPeriod` per REQ-ACCR-002
- [x] 9. Monthly opbouw (present balance, different period): `bovenwettelijkHours += round1(
  annualBovenwettelijk/12)`, raise `entitledHours`/`contractHoursPerWeek` only if
  `4 × hoursPerWeek` is higher (never lower), set `lastAccruedPeriod = currentPeriod` per
  REQ-ACCR-003
- [x] 10. Idempotency guard: present balance whose `lastAccruedPeriod === currentPeriod` → no-op
  (no write) per REQ-ACCR-004
- [x] 11. Persist via ObjectService `saveObject(object, register: hrmq, schema: 'LeaveBalance')`;
  never-throw degradation with logged warning per REQ-ACCR-001
- [x] 12. Log summary per run: counts of provisioned / accrued / skipped(+reason) employees per
  REQ-ACCR-005
- [x] 13. Register the job: NEW `<background-jobs><job>OCA\Hrmq\BackgroundJob\LeaveAccrualJob</job>
  </background-jobs>` block in `appinfo/info.xml` per REQ-ACCR-001
- [x] 14. Tests: `tests/Unit/BackgroundJob/LeaveAccrualJobTest.php` (mocked ObjectService) —
  first-run provisioning writes 40h→`entitledHours 160` / `expiryDate 2027-07-01` /
  `contractHoursPerWeek 40` / `lastAccruedPeriod` set per REQ-ACCR-002/-ACCR-004
- [x] 15. Tests: same-period re-run is a no-op; next-period run adds exactly one bovenwettelijk
  slice; disabled config no-ops; unresolvable employee is skipped per REQ-ACCR-003/-ACCR-004/-ACCR-005
- [x] 16. Test: a job-provisioned balance passes `nl-verlof-wettelijk-minimum` and
  `nl-verlof-vervaltermijn` through the real RuleEngine + `labour.json` corpus per REQ-ACCR-002
- [x] 17. Quality gates: `composer lint` green, full PHPUnit suite green, SPDX + `@spec` tags on
  every new method (gate-16), i18n keys ENGLISH; fix any pre-existing issue encountered

Acceptance criteria (plain reminders, not tasks):
- `lib/BackgroundJob/` has zero HTTP calls; ObjectService only; the `remainingHours` declarative
  calc is never touched and never shadowed
- statutory is provisioned in full (never a monthly slice) so `nl-verlof-wettelijk-minimum` stays
  green from the first write; only `bovenwettelijkHours` accrues per month
- correctness is independent of TimedJob fire cadence — the `lastAccruedPeriod` guard defines
  "once per employee-month", not the interval
- accrual only ever grows `entitledHours + bovenwettelijkHours`, never pushes a balance negative
