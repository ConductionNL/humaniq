---
kind: code
---

# Leave accrual job — automatic periodic (monthly) verlofopbouw onto every active employee's LeaveBalance

## Why

The leave-verzuim MVP (PR #22) shipped the `LeaveBalance` ledger — per-employee, per-year,
per-leave-type entitlement/usage with a declarative `remainingHours` calculation and three
machine-checkable rules (`nl-verlof-wettelijk-minimum`, `nl-verlof-saldo-niet-negatief`,
`nl-verlof-vervaltermijn` in `lib/Standards/rules/labour.json`). But nothing **produces** those
balances: every seeded `LeaveBalance` row is hand-typed (`hr-seed.json`), and the schema's own
description names the gap — "`usedHours` is not yet auto-posted … that cross-object write hook is a
declared follow-up." This change delivers the *granting* half of that follow-up: the automatic
build-up (opbouw) of the entitlement itself.

Statutory holiday leave is `4 × contractual weekly working time` per BW art. 7:634 (the exact
figure `nl-verlof-wettelijk-minimum` audits); bovenwettelijk (above-statutory) hours accrue per
period worked under most CAOs. A payroll product must grant these automatically as employees are
hired and time passes, not rely on an administrator hand-creating a row per person per year. This
change is a small, focused background job that does exactly that — nothing more.

## What Changes

- **NEW `lib/BackgroundJob/LeaveAccrualJob.php`** — an `OCP\BackgroundJob\TimedJob` (the app has
  no `lib/BackgroundJob/` today). Each run enumerates active employees (the
  `PayrollRunService::generate` idiom: `loadAll('Employee')` + `coversPeriod(startDate, endDate,
  currentPeriod)`), resolves each one's covering `EmploymentContract` (`coveringContract`, whose
  `hoursPerWeek` is the accrual driver), and accrues onto the current-year **holiday**
  `LeaveBalance` — creating the row when absent. The job self-guards on the accrual period so
  re-runs never double-accrue (see idempotency below); registered via a new `<background-jobs>`
  block in `appinfo/info.xml`.
- **Statutory provisioning (mandatory-rule-safe).** On the year's first accrual for an employee
  the job provisions `entitledHours = 4 × hoursPerWeek` (the full BW 7:634 annual figure) so the
  **mandatory** `nl-verlof-wettelijk-minimum` invariant holds from the first write — never
  under-granted mid-year. It also snapshots `contractHoursPerWeek` (drives that same check),
  seeds `bovenwettelijkHours = 0`/`usedHours = 0`, and sets `expiryDate = <year+1>-07-01` so
  `nl-verlof-vervaltermijn` also holds. Design D2/D3.
- **Bovenwettelijk monthly opbouw.** Each accrued month adds `round1(annualBovenwettelijk / 12)`
  to `bovenwettelijkHours` (the genuinely periodic part). `annualBovenwettelijk` is a new
  `SettingsService` getter `leave_bovenwettelijk_annual_hours` (default `0` — statutory-only until
  an employer configures it; CAO-derived bovenwettelijk is a named fast-follow). Design D4.
- **Idempotency — key by (employee, year, period).** A new nullable `lastAccruedPeriod` (`YYYY-MM`)
  property on `LeaveBalance` (the ONE small schema delta this change needs) records the last month
  accrued; the job skips any balance whose `lastAccruedPeriod` already equals the current period.
  So the TimedJob may fire many times a month with no drift, and each `(employee, year, month)`
  accrues exactly once. Design D5.
- **Config `leave_accrual_enabled`** (`SettingsService`, default `true`) — an off-switch; the job
  no-ops early when disabled. Employees it cannot resolve (no covering contract, no `hoursPerWeek`,
  no `nextcloudUserId`/employee identity) are skipped and counted in the job's log summary, never
  computed wrong and never silently dropped (the `PayrollRunService` skip-reporting precedent).
- **Tests** — `LeaveAccrualJobTest` (mocked ObjectService): first-run provisioning writes the
  anchor figures (40h/week → `entitledHours 160`, `expiryDate 2027-07-01`, `contractHoursPerWeek
  40`), same-period re-run is a no-op, next-month run adds one bovenwettelijk slice, disabled
  config no-ops, unresolvable employee is skipped. A rule-corpus assertion pins that a
  job-provisioned balance passes `nl-verlof-wettelijk-minimum` and `nl-verlof-vervaltermijn`.

### Non-goals (named fast-follows and exclusions)

- **Auto-posting `usedHours` from approved LeaveRequests** — the *other* half of the schema's
  declared follow-up; separate change (this one grants entitlement, it does not consume it).
- **Pro-rata statutory for mid-year entry/exit** — statutory is provisioned at the full annual
  figure so the mandatory minimum stays green; date-proportional statutory would require the
  minimum rule to become entry-date-aware (owned by `hrmq-rule-compliance-enforcement`).
- **CAO-derived bovenwettelijk** — `annualBovenwettelijk` is a single config default here; reading
  the employee's CAO leave article (`lib/Standards/cao/`, `nl-cao-verlof-minimum`) is a fast-follow.
- **Non-holiday leave types, non-NL jurisdictions, per-day granularity** — the job accrues the
  `holiday` (vakantie) balance for NL employees monthly; other types/jurisdictions are excluded.

## Capabilities

### New Capabilities

- `leave-accrual-job`: a monthly `TimedJob` that provisions each active employee's current-year
  holiday `LeaveBalance` with the full statutory (BW 7:634) entitlement and accrues the
  bovenwettelijk opbouw slice, idempotently per `(employee, year, month)` via `lastAccruedPeriod`,
  written through OpenRegister's ObjectService — the automatic producer for the leave-verzuim
  ledger, keeping every existing mandatory leave rule green.

### Modified Capabilities

<!-- none — the leave-verzuim LeaveBalance schema and rules are consumed; the only schema change is
     the additive nullable lastAccruedPeriod idempotency marker described above -->

## Impact

- `lib/BackgroundJob/LeaveAccrualJob.php` — NEW (`OCP\BackgroundJob\TimedJob`; ObjectService write
  idiom + active-employee/covering-contract selection per `PayrollRunService`).
- `lib/Service/SettingsService.php` — two config getters: `leave_accrual_enabled` (bool, default
  `true`) and `leave_bovenwettelijk_annual_hours` (number, default `0`).
- `lib/Settings/register.d/hr-leave.json` — `LeaveBalance` gains the additive nullable
  `lastAccruedPeriod` (`YYYY-MM`) property; no other field changes, `remainingHours` calc untouched.
- `appinfo/info.xml` — NEW `<background-jobs>` block registering the job (the app has none today).
- `tests/Unit/BackgroundJob/LeaveAccrualJobTest.php` — NEW.
- Consumes: the leave-verzuim `LeaveBalance` schema + `labour.json` verlof rules (must exist at
  HEAD — they do, shipped by PR #22). No dependency on any unmerged change.
