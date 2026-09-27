# Design: the pension return message, including the ABP delivery

## Context

Read at `development` af702f78.

- `PensionFiling` (`lib/Settings/register.d/hr-pension.json`): `payrollRunId`, `period`, `fund`
  (enum `abp`, `spw`, `bpf-bouw`, `schoonmaak`, `pfab`, `pwri`: APG-administered funds),
  `aanleverkenmerk`, `deadline`, `status`, `responseStatus`, `responseMessage`,
  `submittedDate`, `verzondenDoor`, `administrationId`. Lifecycle `controleren`, `bevestigen`,
  `verzenden`, `heropenen`, `corrigeren`; `controleren` is guarded by
  `lib/Lifecycle/PayrollRunApprovedGuard.php`. Page `PensionFilingDetail`
  (`src/manifest.d/hr-pension.json:37`).
- Rules: `lib/Standards/Checks/NlPensionFilingChecks.php` (completeness, deadline) and
  `NlAbpChecks.php` (`nl-abp-fund-required`); `hrAdministration.abpAansluitingsplichtig`.
- Pay data: `Payslip.grossPay`, `pensionContribution` (operator-entered per the engine's
  non-goal on pension premium), `engineInputSnapshot`; `EmploymentContract.hoursPerWeek`,
  `startDate`, `endDate`.
- The engine does not compute pension premiums (payroll-core-engine non-goal); this change
  computes the pension base the message reports, not the premium withheld on the payslip.

## Goals / Non-Goals

**Goals**

- A validated delivery file per pension filing, from the approved run and a sourced scheme.
- ABP handled as configuration of the same mechanism, not a second one.

**Non-Goals**

- Changing what the payslip withholds for pension. That is the premium computation named as a
  separate follow-up.

## Decisions

### D1. `PensionScheme`

`administrationId`, `fund` (the `PensionFiling.fund` enum), `schemeCode`, `messageFormat`
(`upa`, `apg`), `franchise`, `maxPensionableSalary`, `employerPercentage`,
`employeePercentage`, `validFrom`, `validUntil`, `source` and `verified` (the
`{value, source, verified}` discipline the tables keep). Seeded ABP figures carry
`verified: false` until checked against APG's published rates.

### D2. One service, two builders

`PensionMessageService::render(filing)` reads the approved run's payslips for the fund's
administration, derives per employee the pensionable wage (gross from the snapshot), part-time
factor (`hoursPerWeek / fullTimeHoursWeek`), pension base `max(0, min(wage, max) - franchise
* partTimeFactor)` and contributions, and hands them to `UpaMessageBuilder` or
`ApgMessageBuilder` by `messageFormat`. Both are pure classes with golden-file tests.

### D3. Guarded check

`PensionMessageGuard` on `controleren`, after `PayrollRunApprovedGuard`, renders and
validates, stores the file and format, or refuses with `messageFindings`.

## Declarative-vs-imperative decision (ADR-031)

| behaviour | path | why |
|---|---|---|
| scheme parameters | declarative schema, sourced data | configuration |
| pension base and message | imperative service and builders | document generation over computed figures |
| refusing the check | lifecycle guard | cross-object precondition |

## Seed data

- A `PensionScheme` for fund `pfab` (UPA) and one for `abp` (APG format) on the seed
  administration, figures marked unverified.
- The seeded approved 2026-06 run renders both deliveries.

## Risks / Trade-offs

- [Unverified fund figures] → the scheme carries `verified: false` and the filing page shows it;
  the corpus rule family already treats unverified figures as advisory.
- [APG's format moves with the WTP transition] → the format and schema are per year, like the
  loonaangifte.

## Open Questions

- Which APG delivery version ABP expects from 2027 under the WTP; the design keeps the format
  per year so the answer is a data change.
