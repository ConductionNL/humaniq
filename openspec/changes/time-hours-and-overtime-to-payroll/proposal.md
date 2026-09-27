---
kind: code
---

# Pay approved hours and overtime through the payroll run

## Why

A payroll officer with hourly-paid staff runs the monthly payroll in humaniq and finds those
people in the "skipped" list, every month, with the reason "no-monthly-salary (hourly path:
fast-follow)". Their manager approved the timesheet; the hours are in humaniq; the contract
carries the hourly wage. Nothing connects the three, so the officer pays these people outside
humaniq.

Overtime has the same gap, one step earlier. An employee cannot mark hours as overtime, a
manager cannot approve them as overtime, and nothing pays them with the surcharge or books them
as time off in lieu. The surcharges themselves are already in humaniq: the CAO corpus carries
overtime percentages, a contract can override them with a reason, and
`EmploymentTermsResolver::overtimeAdditionFor()` resolves the one that applies. It has no
caller outside its own tests.

This change pays approved hours for hourly-paid employees, lets employees register overtime on
their timesheet, and settles approved overtime in money or in time.

### Matrix rows (humaniq `openspec/parity/capabilities.json`)

| row | capability | humaniq today |
|---|---|---|
| `tim-hours-to-payroll` | Have approved hours flow into payroll. | `no`, built.state `none`: `PayrollRunService` skips employees without `grossMonthlySalary` ("the hourly x Timesheet path is a named fast-follow") and never reads `Timesheet` or `TimeEntry` |
| `tim-overtime` | Register overtime and have it compensated in time or pay. | `no`, built.state `none`: no overtime registration or compensation; the surcharge terms resolve but nothing uses them |

### Competitors rated yes

- `tim-hours-to-payroll`, AFAS Profit: "the integration of nacalculatie and salary processing
  turns bookings into wage mutations through linked wage components"
  (https://help.afas.nl/help/NL/SE/Pro_Costng_Inv_Employ.htm).
- `tim-hours-to-payroll`, Visma Raet Youforce: "the planning system exports worked hours or
  other variables so they get paid by the Visma payroll system"
  (https://vr-api-integration.github.io/youforce-api-documentation/wfm_api_intro.html).
- `tim-hours-to-payroll`, HR2day: "wages are calculated automatically from the time
  registration" (https://www.hr2day.com/hospitality/).
- `tim-hours-to-payroll`, Loket.nl: "declared worked hours are linked automatically to the
  right wage components" (https://loket.nl/functionaliteiten/declareren/).
- `tim-overtime`, AFAS Profit: "the Urenontrafelaar books overtime per employment on contract
  hours" (https://klant.afas.nl/update/profit-8/hrm).
- `tim-overtime`, Visma Raet Youforce: "extra hours registered as compensatie uren are approved
  and added to the leave balance"
  (https://www.ssc-ons.nl/content/uploads/2024/07/Handleiding-Mijn-Youforce-1.pdf).
- `tim-overtime`, Loket.nl: "an employee enters overtime in the app and after manager approval
  it goes into payroll" (https://loket.nl/hrm-systeem/).
- `tim-overtime`, Personio: "review overtime balances and compensate overtime as a payout or
  as time off"
  (https://support.personio.de/hc/en-us/articles/37338523068701-Manage-overtime-new-experience).

### Recorded non-goals this change picks up

- payroll-core-engine (`openspec/changes/archive/2026-07-14-payroll-core-engine/proposal.md`):
  "Hourly path (hourlyWage x approved Timesheet hours when salary is absent): named
  fast-follow; the MVP computes fixed monthly salaries only and reports skipped employees."
  This change is that fast-follow.
- time-attendance-mvp: "No CAO overtime/toeslag calculation: CAO premium matrices are
  rulesets/configuration (ADR-001 rule 1)." The surcharges stay configuration: this change
  reads them through the existing CAO corpus and contract override, and adds no CAO logic.
- time-attendance-mvp: "No attendance to Timesheet aggregation job." Unchanged. Payroll reads
  approved timesheets, never clock records.

## What Changes

- **Approved hours become pay.** For an employee without a monthly salary whose contract
  carries an hourly wage, the run pays the approved timesheet hours times that wage as the
  period's gross. The payslip records the hours paid and the timesheets they came from.
- **Late hours are paid once, in the next run.** A timesheet approved after its period's run
  was approved is paid in the next draft run. Each paid timesheet carries the run that paid
  it, so no timesheet is paid twice.
- **Overtime on the timesheet.** A time entry can be marked as overtime, with a choice to be
  paid or to take the time off. The default choice comes from the CAO's
  `compensationPreference`. The timesheet shows its overtime hours next to its total, and the
  manager approves both in the approval that exists.
- **Overtime in money.** Approved overtime to be paid adds hours times the hourly rate times
  (100 + surcharge) / 100 to the period's gross. The surcharge is the one
  `EmploymentTermsResolver` resolves for the day's category (weekday, Saturday, Sunday or
  public holiday). A salaried employee's hourly rate is the monthly salary divided by the
  contracted monthly hours, the same basis the payslip's `hoursWorked` already uses.
- **Overtime in time.** Approved overtime to be taken off is credited, at the same factor, to
  a compensation leave balance the employee can then request leave against.
- **A missing surcharge is visible, not guessed.** When the CAO's overtime article is not
  confirmed and the contract has no override, the hours are paid or credited at the base rate
  and the payslip says the surcharge was not resolved.

## Capabilities

### New Capabilities

- `time-hours-and-overtime-to-payroll`: approved hours and overtime flow into the payroll run
  as pay, or into a compensation balance as time off.

## Impact

- `lib/Settings/register.d/hr-timesheet.json`: `TimeEntry` gains `overtime` and
  `overtimeCompensation`; `Timesheet` gains `overtimeHours`, `payrollRunId`, `paidInPeriod`.
- `lib/Settings/register.d/hr-objects.json`: `Payslip` gains `hoursPaid`, `hourlyPay`,
  `overtimeHours`, `overtimePay`, `overtimeSurchargeUnresolved`, `timesheetIds`.
- `lib/Settings/register.d/hr-leave.json` and `hr-leave-types.json`: a `compensation` leave
  type for time off in lieu.
- `lib/Service/TimesheetAggregationService.php`: aggregates overtime hours.
- `lib/Service/HoursPayService.php` (new): selects the unpaid approved timesheets and computes
  hourly and overtime gross, calling `EmploymentTermsResolver` and `WorkingCalendarReader`.
- `lib/Service/PayrollRunService.php`: a pre-calculation gross fold for hours and overtime,
  stamps on the payslip and on each paid timesheet.
- `lib/Service/OvertimeCreditService.php` (new): credits approved time-off overtime to the
  compensation balance once.
- `lib/Listener/PayrollRunApprovedListener.php` (new): calls the credit when a run's status
  becomes `approved`, whether by the Loonrun flow or by a direct edit.
- `src/manifest.d/hr-timesheet.json`, `src/manifest.d/hr-objects.json`: the new fields on
  timesheet and payslip pages.
- `lib/Settings/register.d/hr-seed.json`: an approved timesheet for the hourly seed employee
  and one overtime entry.

## Out of scope

- Deriving overtime automatically from clock records or the working pattern. Overtime is
  registered and approved.
- Shift and irregular-hours premiums (ORT) and other CAO components: `payroll-cao-components`.
- Weekly or four-weekly pay periods: `payroll-period-edge-cases`.
- Billing hours to clients: shillinq's, through the existing typed timesheet event.
