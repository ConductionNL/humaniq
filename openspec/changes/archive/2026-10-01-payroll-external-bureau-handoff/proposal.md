---
kind: code
---

# Hand payroll to an outside bureau and record what comes back

## Why

humaniq is a payroll engine, and most employers who use it will run payroll in it. Some will
not. A municipality may keep HR in humaniq while its payroll stays with a bureau for years, or
a customer may move HR first and payroll a year later. Today such an employer re-enters every
payroll mutation in the bureau's system by hand: the new hire, the leaver, the raise, the
approved hours, the claims. When the bureau is done, the payslips and totals never come back
into humaniq, so the employee's page, the self-service payslips and the annual statement stay
empty.

Visma sells exactly this service, and Personio runs Dutch payroll through Loket, with its own HR
workflows in front. humaniq has the HR side and the integration layer (integriq); what is
missing is the handoff itself.

This change lets an administration mark its payroll as run by an outside bureau, compiles each
period's mutations for the bureau, hands them over through integriq, and records the bureau's
payslips and totals when they come back.

### Matrix rows (humaniq `openspec/parity/capabilities.json`)

| row | capability | humaniq today |
|---|---|---|
| `pay-outsourced` | Hand payroll processing to the vendor's own payroll service. | `no`, built.state `none`: nothing hands payroll to a bureau; humaniq's README describes it as the engine itself |

### Competitors rated yes

- `pay-outsourced`, Visma Raet Youforce: "Youforce offers uitbesteding salarisadministratie,
  taking the payroll processing over" (https://youforce.nl/uitbesteding-salarisadministratie).
- `pay-outsourced`, Personio: "Personio Payroll, powered by Loket. Managed payroll delivery for
  the Netherlands, combining Personio's HR workflows with compliant, local payroll expertise"
  (https://www.personio.com/whats-new-q2-26/).

### How this fits humaniq's scope

The matrix notes that humaniq "is built to be the alternative to outsourcing, not a broker for
it". That stays true: this change adds no bureau of its own and no second engine. It lets an
employer who keeps HR in humaniq and payroll elsewhere avoid typing everything twice. No
recorded non-goal of a humaniq change refuses it.

## What Changes

- **Payroll processing per administration.** `hrAdministration` gains `payrollProcessing`
  (`engine`, the default, or `external-bureau`) and the bureau's name. The engine refuses to
  calculate a run for an administration processed externally, and says why.
- **A handoff per period.** A `PayrollHandoff` for an administration and period compiles the
  period's mutations: starters and leavers, contract changes, salary changes, approved hours,
  payroll-route claims, allowances, leave sold or bought, sickness starting or ending, wage
  garnishments and bank-account changes. Each becomes a `PayrollHandoffMutation` with the
  employee, the kind, the effective date, the changed fields, and the object it came from.
- **Only what changed.** The compilation compares each employee's payroll fields with what the
  previous handoff sent, so the bureau receives changes, not the whole file, and a change that
  was reverted before the handoff is not sent at all.
- **Reviewed, then sent through integriq.** HR reviews the mutations and sets the handoff
  ready. integriq picks up ready handoffs, delivers them to the bureau in the bureau's format
  and marks them sent with a delivery reference. humaniq holds no bureau credentials.
- **Results recorded.** The bureau's payslips come back as `Payslip` objects linked to the
  handoff, with gross, net, wage tax and the PDF when the bureau sends one, stamped with the
  employee's account so they appear in self-service and on the annual statement. humaniq checks
  that every handed-over employee has a payslip and that the returned totals add up, and lists
  what does not.

## Capabilities

### New Capabilities

- `payroll-external-bureau-handoff`: per-period mutation handoff to an outside payroll bureau
  through integriq, and intake of the bureau's payslips and totals.

## Impact

- `lib/Settings/register.d/hr-administratie.json`: `payrollProcessing`, `payrollBureauName`.
- `lib/Settings/register.d/hr-handoff.json` (new fragment): schemas `PayrollHandoff` (with a
  lifecycle) and `PayrollHandoffMutation`.
- `lib/Settings/register.d/hr-objects.json`: `Payslip` gains `payrollHandoffId`,
  `externalSource`.
- `lib/Service/PayrollHandoffService.php` (new): compile mutations and check the intake.
- `lib/Service/PayrollRunService.php`, `lib/Flow/PayrollCalculateNode.php`: refuse externally
  processed administrations.
- `lib/Controller/PayrollHandoffController.php` (new) and `appinfo/routes.php`:
  `POST /api/payroll/handoffs/compile` and `POST /api/payroll/handoffs/check-intake`.
- `src/manifest.d/hr-handoff.json` (new), `05-menu.json`: handoff pages under Payroll.

## Out of scope

- The bureau's own formats, protocols and credentials: integriq's.
- Journal posting and net-pay payment for externally processed payroll. The bureau usually does
  both; if it does not, that is a later change.
- Running humaniq's engine side by side with the bureau for comparison.

## Cross-app dependencies

- integriq: a synchronisation whose source is humaniq's `PayrollHandoff` objects in status
  `klaargezet`, a target per bureau (for example the Loket.nl API, a Nmbrs API or an SFTP file
  drop) mapping `PayrollHandoffMutation` to that bureau's mutation format, and the credentials
  for it. On delivery it sets the handoff to `verzonden` with a delivery reference. On the way
  back it writes the bureau's payslips into humaniq's register as `Payslip` objects with
  `payrollHandoffId` set, and sets the handoff to `ontvangen`.
