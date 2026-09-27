---
kind: code
depends_on: [payroll-reservation-payouts]
---

# Pay early-retirement benefits and political office holders

## Why

Two groups of people a municipality pays fall outside humaniq today.

**Early retirement (RVU).** An employee in a heavy job may stop working up to three years before
the state pension age and receive an early-retirement benefit from the employer, monthly or as
one sum. The benefit is wage from a former employment: it is taxed with the green table, no
employee insurance premiums apply, and above a monthly threshold the employer owes a final levy.
In humaniq the employee leaves, the run stops selecting them, and there is nothing that can pay
a benefit. `grep -rniE 'rvu|vervroegd' lib src` finds nothing. Visma and AFAS both ship it.

**Political office holders.** A mayor, aldermen and council members are paid by the
municipality, but they are office holders, not employees: no unemployment or disability
insurance, their own legal position, and after leaving office a post-office allowance
(wachtgeld) under the Appa for a number of years. humaniq's `publicSectorRegime` covers civil
servants only. Sudwest-Fryslan asks for office holders and their wachtgeld explicitly (E5.39,
W0.1).

Both need two engine abilities humaniq lacks: a payslip for a benefit rather than for a job,
and the withheld Zvw contribution (Zvw-inhouding) that benefits carry instead of the employer's
levy. The engine design named the Zvw-inhouding mode its fast-follow.

This change adds benefit entitlements that produce their own payslips, the Zvw withholding mode,
the RVU final levy, and an office-holder regime for the people who hold a political office.

### Matrix rows (humaniq `openspec/parity/capabilities.json`)

| row | capability | humaniq today |
|---|---|---|
| `dm-rvu` | Pay an early retirement benefit to an employee close to state pension age, in one sum or instalments, with the tax handled automatically. | `no`, built.state `none`: no RVU anywhere in `lib` or `src` |
| `td-political-office-holders` | Administer and pay political office holders such as a mayor and aldermen, including their post-office allowance. | `no`, built.state `none`: `Employee.publicSectorRegime` covers civil servants, not office holders |

### Demand

- `dm-rvu`, changelog:
  https://community.visma.com/t5/Releases-YouServe/tkb-p/nl_ys_vismayouserve_releases/page/3
  (Visma Raet YouServe release notes 2025-11, workflow Uitdienst met RVU).
- `td-political-office-holders`, tender: https://www.tenderned.nl/aankondigingen/overzicht/415227
  (Sudwest-Fryslan E5.39 and W0.1, politieke ambtsdragers, wachtgeld of pensioen).

### Competitors rated yes

- `dm-rvu`, AFAS Profit: "standard wage components for a periodic RVU benefit with the
  threshold exemption and final levy calculated, plus audits for wrongly applied exemption"
  (https://help.afas.nl/cao/cao_32338608).
- `dm-rvu`, Visma Raet Youforce: "the workflow Uitdienst met RVU, which creates a second income
  relationship, pays the RVU in one or more terms and determines wage tax and any final levy"
  (https://community.visma.com/t5/Releases-YouServe/tkb-p/nl_ys_vismayouserve_releases/page/3).
- `td-political-office-holders`: no competitor is rated yes; AFAS Profit is rated partial.

### Recorded non-goals this change picks up

- payroll-core-engine (`openspec/changes/archive/2026-07-14-payroll-core-engine/proposal.md`,
  named fast-follows): "CAO rules, bijzonder tarief (vakantiegeld payout), 30%-ruling
  netto-operation, pension premie calculation, Zvw-inhouding mode ...: the calculator's
  table-driven, pure-function shape is the extension point." This change adds the Zvw-inhouding
  mode. The special rate for a lump sum comes from `payroll-reservation-payouts`.
- payroll-core-schema: "Groene tabel completeness beyond structure: pension/benefit payroll runs
  are not an MVP flow." This change makes benefit payslips a flow; the green table's structure
  is what it already computes.
- jurisdiction-packs, binding: "The five app-level folds stay app-level." Benefits are a second
  pass in the run service, not an object-aware step in the pack.

## What Changes

- **A benefit entitlement.** A new `BenefitEntitlement` records a benefit decision for a person:
  kind (`rvu` or `wachtgeld`), the decision reference, start and end, periodic or lump sum, and
  the amount, either fixed per month or as a percentage schedule of a reference salary. HR
  activates it with separation of duties. For wachtgeld, HR records the income to offset each
  month from the Appa decision; humaniq does not compute the offset.
- **A payslip per benefit.** Each period the run makes one benefit payslip per active
  entitlement, next to any salary payslip the person has, marked as a separate income
  relationship. A person who has left employment still gets it.
- **Regimes as sourced data.** The tables gain a `specialRegimes` group: per regime (RVU benefit,
  wachtgeld, political office) the table colour, whether employee insurances apply, the Zvw
  mode, and for RVU the monthly threshold and final-levy rate. Each leaf is sourced; an
  unverified leaf stops the payslip with a reason.
- **Zvw withholding in the pack.** The pack gains a Zvw mode input: the employer's levy as today,
  or the withheld contribution at the tables' existing `zvw.inhouding` rate, which reduces net.
- **The RVU final levy.** The pack computes the employer's final levy on the part of a monthly
  RVU benefit above the threshold, as an employer cost. A lump sum is paid as a special payment
  at the special rate, with the levy on the part above the threshold times the months it covers.
- **Office holders.** A contract type `political-office` with the office (mayor, alderman,
  council member and the like) and its term. The run pays them under the political-office
  regime: no employee insurance premiums, taxed as the regime's data says. When the term ends,
  HR records the wachtgeld entitlement from the Appa decision.

## Capabilities

### New Capabilities

- `payroll-special-regimes`: benefit payslips for early-retirement benefits and post-office
  allowances, the Zvw withholding mode, the RVU final levy, and an office-holder regime.

## Impact

- `lib/Settings/register.d/hr-objects.json`: new schema `BenefitEntitlement` with a lifecycle;
  `EmploymentContract.type` gains `political-office` with `officeRole`, `termStart`,
  `termEnd`; `Payslip` gains `benefitEntitlementId`, `incomeRelationship`, `regime`,
  `rvuFinalLevy`.
- `lib/Standards/tables/nl-2026.json` and `SCHEMA.md`: the `specialRegimes` group.
- `lib/Standards/packs/nl-2026.pack.json`: inputs `zvwMode` and `rvuThreshold`; a gated
  withheld-Zvw step and the RVU final-levy step; new golden vectors.
- `lib/Payroll/CalculationInput.php`, `CalculationResult.php`, `PayrollCalculator.php`: the
  Zvw mode is read from the pack instead of fixed.
- `lib/Service/PayrollRunService.php`: the benefit pass, the regime inputs, and the payslip key
  extended with `benefitEntitlementId`.
- `src/manifest.d/hr-objects.json`, `05-menu.json`: entitlement pages; office fields on the
  contract.
- `lib/Settings/register.d/hr-seed.json`: one monthly RVU entitlement and one alderman.

## Out of scope

- Computing wachtgeld duration, percentages or income offset from the Appa. HR records the
  decision; humaniq pays it.
- The office holders' pension: it is accrued with ABP, which `abp-aansluiting` covers.
- The wage tax return's separate income relationship: the filing change reads
  `incomeRelationship` from the payslip.
- Checking that an employee qualifies for the RVU (heavy job, age window).
