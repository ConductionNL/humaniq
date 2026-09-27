# Design: the wage tax return message, rendered from the payroll run

## Context

Read at `development` af702f78.

- `LoonaangifteFiling` (`lib/Settings/register.d/hr-objects.json`): `period`, `jurisdiction`,
  `filingType`, `tijdvak`, `tijdvakcode`, `deadline`, `submittedDate`, `aangiftenummer`,
  `betalingskenmerk`, `responseStatus`, `responseMessage`, `verzondenDoor`, `retainedUntil`,
  `status`, `administrationId`. Lifecycle `klaarzetten`, `bevestigen`, `verzenden`,
  `heropenen`, `corrigeren`. Page `LoonaangifteFilingDetail` (`src/manifest.d/hr-objects.json:1054`).
- Rules: `lib/Standards/Checks/NlWageTaxFilingChecks.php` (tijdvakcode, deadline).
- Run data: `PayrollRun` (`totalGross`, `totalLoonheffing`, `totalEmployerCharges`, `status`);
  `Payslip` (`grossPay`, `loonheffing`, `volksverzekeringen`, `werknemersverzekeringen`, `zvw`,
  `zvwMode`, `appliedTaxRate`, `anoniementariefApplied`, `vakantiegeldReserved`,
  `engineInputSnapshot`, `payrollRunId`). The pack `lib/Standards/packs/nl-2026.pack.json` and
  tables `lib/Standards/tables/nl-2026.json` hold the year's parameters.
- The WW premium (Awf) high or low indicator is already applied per contract:
  `EmploymentContract.awfTariff` (`hr-objects.json:83`) resolved by
  `PayrollRunService::awfTariffFor()` (`lib/Service/PayrollRunService.php:1611`), checked by
  `nl-awf-laag-hoog-tarief`, and carried in each payslip's `engineInputSnapshot`.
- Guards live in `lib/Lifecycle` and register in `lib/AppInfo/Application.php`.
- Non-goals on record: Digipoort transport (`fil-digipoort`, decided no), correction messages
  (`fil-correction`).

## Goals / Non-Goals

**Goals**

- A valid loonaangifte message per filing, made from what humaniq calculated.
- A clear refusal, per employee and field, when the data cannot make a valid message.

**Non-Goals**

- Sending. Uploading or a gateway is the employer's.

## Decisions

### D1. Build from the approved run, never from a draft

`LoonaangifteMessageService::render(filing)` finds the administration's approved (or posted
or paid) `PayrollRun` for the filing's period, reads its payslips with their
`engineInputSnapshot`, and hands both to `LoonaangifteMessageBuilder`, a pure class producing
the XML. A draft run refuses. Alternative considered: rendering from employee records at
render time. Rejected: the snapshot is what was calculated and taxed.

### D2. Versioned by year

The message version and XSD path sit beside the tax tables, per year, so a new year is a data
change like the tables. The builder keys its element map on the version.

### D3. Guarded readiness

`LoonaangifteMessageGuard` on `klaarzetten` renders and validates; on success the file id,
version and collective totals are stored and the transition proceeds; on failure the
transition is refused and `messageFindings` lists `{employeeId, element, problem}`.

### D4. Rules compare totals

A new rule in `NlWageTaxFilingChecks` compares `collectiveTotals` with the run's totals, so a
file that drifted from the run (a re-calculated run after rendering) is flagged.

## Declarative-vs-imperative decision (ADR-031)

| behaviour | path | why |
|---|---|---|
| rendering the message | imperative builder and service | document generation, the ADR-031 exception |
| refusing readiness | lifecycle guard | a cross-object precondition |
| totals consistency | corpus rule | the existing filing rule family |

## Seed data

- The seeded approved run for 2026-06 renders a message for the seed administration; one seed
  employee without a BSN makes a second administration's filing refuse with a named finding.

## Risks / Trade-offs

- [The Gegevensspecificaties change every year] → version per year, XSD shipped with the tables,
  a golden file test per version.
- [Fields the engine does not compute yet (for example anoniementarief cases)] → the builder
  refuses with a finding rather than filling a default.

## Open Questions

- Which year's specification to start with: the design assumes 2026, matching the shipped
  tables.
