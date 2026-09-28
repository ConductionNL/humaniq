---
kind: code
---

# Collective raises and yearly step increases from the compensation pages

## Why

A payroll officer who has to apply a collective raise, say a new CAO scale or 3% for everyone
from 1 July, finds one path in humaniq today: a `CompAdjustment` per employee, proposed,
approved and then effectuated one at a time with the "Effectuate" action on
`CompAdjustmentDetail`. The batch that effectuates a whole cycle already exists
(`CompAdjustmentService::effectuateCycle()`, with a dry run), but only
`occ humaniq:comp:effectuate` reaches it. For two hundred employees that is six hundred clicks
or a shell session.

The yearly step in a pay scale (the periodiek) is missing altogether. Nothing records which
step of the scale an employee is on, nothing proposes the next step when it falls due, and a
manager who refuses a step has nowhere to write why. The employee never hears the outcome. The
Delft tender asks for exactly this: approve or refuse periodic increases, with the reasoning
(E5).

This change lets HR raise many salaries in one cycle, lets the step increase propose itself,
and sends the employee the decision and its reason.

### Matrix rows (humaniq `openspec/parity/capabilities.json`)

| row | capability | humaniq today |
|---|---|---|
| `ppl-bulk-mutations` | Apply a change such as a collective pay rise to many employees at once. | `partial`, built.state `built`: one `CompAdjustment` at a time from `CompAdjustmentDetail`; the cycle-wide batch is `occ humaniq:comp:effectuate` only |
| `td-step-increase` | Approve or refuse an employee's yearly step in their pay scale, with the reason sent to them. | `partial`, built.state `built`: raises are proposed and approved per employee; no step is generated and no refusal reason reaches the employee |

### Demand

- `td-step-increase`, tender: https://www.tenderned.nl/aankondigingen/overzicht/415705
  (Delft Support E5, periodieke stijgingen goed- en afkeuren met onderbouwing).

### Competitors rated yes

- `ppl-bulk-mutations`, AFAS Profit: "collective salary increase for employees at once
  (collectieve salarisverhoging)" (https://help.afas.nl/help/NL/SE/Hrm_Scorfi_Wages_Coll.htm).
- `ppl-bulk-mutations`, Visma Raet Youforce: "Upload extern mutatiebestand to import
  spreadsheets of mutations for many employees"
  (https://community.visma.com/t5/Releases-YouServe/tkb-p/nl_ys_vismayouserve_releases).
- `ppl-bulk-mutations`, HR2day: "salary and scale changes and step increases run for
  selections of employees from the Payroll Console"
  (https://www.hr2day.com/nieuws/hr2day-penguin-releasenotes/).
- `ppl-bulk-mutations`, Loket.nl: "create wages for multiple employments"
  (https://developer.loket.nl/ApiDocs#tag/Collective-wage).
- `ppl-bulk-mutations`, Personio: "select several employees in the People list, Actions,
  Edit profile to set one attribute value for all of them"
  (https://support.personio.de/hc/en-us/articles/213331029-Update-employee-data).
- `td-step-increase`: no competitor is rated yes; HR2day and Loket.nl are rated partial.

### Recorded non-goals this change picks up

- comp-cycles (`openspec/changes/archive/2026-07-14-comp-cycles/proposal.md`): "Bulk/matrix
  proposal UI, merit-budget modelling, calibration/9-box, letter generation: the advanced
  comp-planning surface is out of this MVP; the per-adjustment lifecycle is its extension
  point." This change adds only the bulk proposal, on that same lifecycle. Merit budgets,
  calibration and letters stay out.
- comp-cycles, the same list: "Hourly-wage effective-dating onto
  `EmploymentContract.hourlyWage`: fast-follow." A collective percentage now also raises the
  contract's hourly wage.
- cao-library (`openspec/changes/archive/2026-07-14-cao-library/proposal.md`): "Per-trede
  (step) progression, age tables, part-time proration nuance." This change keeps the CAO
  corpus untouched and puts the steps on the employer's own `SalaryBand`.

## What Changes

- **A collective cycle.** `CompReviewCycle` gains a `kind` (`individual`, `collective`,
  `step-increase`) and, for a collective cycle, a raise (`raisePercentage` or
  `raiseAmountCents`) and a scope (administration, CAO, org unit). A "Propose for everyone in
  scope" action on `CompReviewCycleDetail` first shows how many employees it will touch, then
  creates one proposed `CompAdjustment` per employee. Running it twice creates nothing new.
- **A hand-picked selection.** The `Employees` index gains a bulk action "Propose a raise"
  that opens a small form for the selected employees and an open cycle.
- **Approve in one go, still four eyes.** An "Approve all proposed" action approves every
  proposed adjustment in the cycle that the caller did not propose. Each approval is its own
  guarded write, so `NoSelfApprovalGuard` still runs per adjustment; the ones it refuses are
  listed back.
- **Effectuate from the page.** An "Effectuate due adjustments" action runs the existing
  `effectuateCycle()` after a dry-run preview of what it will write.
- **Steps in the scale.** `SalaryBand` gains `steps` (step number and monthly salary per step).
  `EmploymentContract` gains `salaryBandId`, `salaryStep` and `stepDate`. A step-increase
  cycle proposes the next step for every contract whose step date falls in the cycle period
  and whose step is below the top of its band.
- **Refuse with a reason.** `CompAdjustment` gains a `refuse` transition to a terminal
  `refused` state that requires `decisionReason`. The existing `reject` (back to draft for
  rework) stays.
- **The employee hears it.** A declarative notification tells the employee when their
  adjustment is approved or refused, with the reason.

## Capabilities

### New Capabilities

- `comp-collective-raise-and-step-increase`: collective and step-increase compensation cycles,
  bulk proposal, approval and effectuation from the cycle page, and a reasoned decision the
  employee receives.

## Impact

- `lib/Settings/register.d/hr-comp.json`: `CompReviewCycle` gains `kind`, `raisePercentage`,
  `raiseAmountCents`, `scope`; `SalaryBand` gains `steps`; `CompAdjustment` gains
  `adjustmentKind`, `fromStep`, `toStep`, `proposedHourlyWage`, `decisionReason`,
  `employeeUserId`, the `refused` status, the `refuse` transition and an
  `x-openregister-notifications` block.
- `lib/Settings/register.d/hr-objects.json`: `EmploymentContract` gains `salaryBandId`,
  `salaryStep`, `stepDate`.
- `lib/Service/CompCollectiveService.php` (new): propose for a cycle, approve a cycle.
- `lib/Service/CompAdjustmentService.php`: effectuation also writes the contract's hourly wage
  and advances the step.
- `lib/Lifecycle/DecisionReasonGuard.php` (new).
- `lib/Controller/CompController.php` and `appinfo/routes.php`: three cycle endpoints.
- `src/manifest.d/hr-comp.json`, `src/manifest.d/hr-objects.json` (`Employees` bulk action),
  `src/registry.js` and one host modal.
- `lib/Settings/register.d/hr-seed.json`: a step-increase cycle and a band with steps.

## Out of scope

- Per-step tables inside the CAO corpus (cao-library non-goal). Steps live on the employer's
  band.
- Merit budgets, calibration, 9-box and compensation letters (comp-cycles non-goals).
- Retroactive effective dates that reopen a posted payroll run: `retro-adjustments` owns those.
- Importing mutations from a spreadsheet. The selection comes from the scope or the index.
