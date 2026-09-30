---
kind: code
depends_on: [time-hours-and-overtime-to-payroll]
---

# Pay the allowances and premiums a collective agreement prescribes

## Why

A care organisation runs payroll in humaniq for nurses who work nights and weekends. Their
collective agreement pays an irregular-hours premium (ORT) per hour worked in the evening, at
night and at weekends. A metal company's agreement pays a shift allowance as a percentage of
wage. humaniq's CAO library knows about these: the corpus files carry `ort`, `ploegentoeslag`
and `toeslagOnregelmatigheid` entries. The payroll run never reads them. The CAO library checks
two floors (minimum scale wage and minimum leave) and shows the rest as reference; nothing a
collective agreement prescribes ever reaches a payslip.

The CAO library change said so itself: it audits the CAO, "it does not yet compute CAO
allowances into net pay". Every competitor in the matrix does.

This change gives CAO components a shape the run can compute, resolves them per contract, and
pays them in the run: a percentage of wage, a fixed monthly amount, or a premium per hour worked
inside a time window.

### Matrix rows (humaniq `openspec/parity/capabilities.json`)

| row | capability | humaniq today |
|---|---|---|
| `pay-cao-components` | Pay allowances, shift premiums and other components a collective agreement prescribes. | `no`, built.state `built`: `NlCaoChecks` runs two floor checks; `Caos` and `CaoDetail` are read-only; no step in the run adds a CAO component |

### Competitors rated yes

- `pay-cao-components`, AFAS Profit: "Profit CAO updates maintain cao wage components such as
  EHBO and BHV allowances and ORT or overtime premiums" (https://help.afas.nl/cao/cao_34663129).
- `pay-cao-components`, Visma Raet Youforce: "supports your cao and bedrijfsspecifieke
  regelingen without custom work" (https://youforce.nl/product/hr-core-salaris).
- `pay-cao-components`, HR2day: "automatic application of collective agreement provisions,
  bonuses and allowances" (https://www.hr2day.com/features/salarisverwerking/).
- `pay-cao-components`, Loket.nl: "overtime and irregular hours (ORT) schemes managed at cao,
  wage model and administration level" (https://loket.nl/roadmap/).

### Recorded non-goals this change picks up

- cao-library (`openspec/changes/archive/2026-07-14-cao-library/proposal.md`, named fast-follows):
  "CAO-driven computation (ploegentoeslag/overwerk actually added to gross by the calculator,
  bijzonder-tarief vakantiegeld payout, CAO pension premie): the MVP audits the CAO; it does not
  yet compute CAO allowances into net pay. The calculator's table-driven shape is the extension
  point." This change computes the allowances and premiums. Overtime is
  `time-hours-and-overtime-to-payroll`; the payout at the special rate is
  `payroll-reservation-payouts`; the pension premium stays out.
- payroll-core-schema: "CAO-specific rules: a later payroll-cao-mvp owns CAO logic; the engine
  exposes extension points, it does not hardcode any CAO." This change is that later change. It
  hardcodes no agreement: every figure stays in the corpus.
- cao-library: "A CAO import/authoring UI: the corpus is maintained in code." Unchanged. The
  corpus gains a machine-readable component shape; it is still edited in code.
- time-attendance-mvp: "CAO premium matrices are rulesets/configuration (ADR-001 rule 1)." The
  premiums stay configuration in the corpus and on the contract.

## What Changes

- **A component shape in the corpus.** The CAO `allowances` leaf gets a documented,
  machine-readable form per component: `percentage-of-wage`, `fixed-monthly` or
  `hourly-surcharge` with time windows (days, from, to) and a percentage per window, plus a
  public-holiday percentage. The existing entries are rewritten in that form, keeping their
  `verified` and `placeholder` flags.
- **Which components apply to a contract.** A contract lists the components of its agreement
  that apply to it (a shift worker gets the shift allowance, an office worker does not), and
  may override a figure with a reason, never below the agreement, the same rule
  `EmploymentTermsResolver` applies to overtime.
- **Components in the run.** Each period the run adds the applicable components to the gross
  before the calculator: a percentage of the regular wage, a fixed amount, or the premium for
  every approved hour whose start and end fall in a window, at the hourly rate.
- **A placeholder pays nothing and says so.** A component whose agreement figure is unverified,
  and not overridden on the contract, is not paid. The payslip lists it as unresolved, and the
  run check shows it.
- **Visible on the payslip.** The payslip lists each component with its basis (percentage,
  amount or hours in which window) and its source (agreement or contract override).

## Capabilities

### New Capabilities

- `payroll-cao-components`: collective-agreement allowances and irregular-hours premiums,
  resolved per contract from the corpus and paid in the run.

## Impact

- `lib/Standards/cao/SCHEMA.md` and every `lib/Standards/cao/*.json` with allowances: the
  component shape; `CaoRegistry::VERSION` bumped.
- `lib/Standards/CaoRegistry.php`: `components(caoId)` resolving the new shape, null when
  unverified.
- `lib/Service/EmploymentTermsResolver.php`: `resolveComponents(contract)`.
- `lib/Service/CaoComponentCalculator.php` (new, pure) and `lib/Service/PayrollRunService.php`:
  the pre-calculation fold.
- `lib/Settings/register.d/hr-objects.json`: `EmploymentContract` gains `caoComponents`,
  `caoComponentOverrides`, `caoComponentOverrideReason`; `Payslip` gains `caoComponentLines`,
  `caoComponentsTotal`, `caoComponentsUnresolved`.
- `lib/Standards/Checks/NlCaoChecks.php`: a check that flags a contract naming a component its
  agreement does not have.
- `src/manifest.d/hr-objects.json`, `hr-cao.json`: components on the contract, payslip and CAO
  pages.

## Out of scope

- Overtime surcharges: `time-hours-and-overtime-to-payroll`.
- Payouts at the special rate: `payroll-reservation-payouts`.
- CAO pension premiums (payroll-core-engine non-goal "pension premie calculation").
- Premiums on planned roster shifts that were not worked. Only approved hours earn a premium.
