---
kind: code
---

# The wage tax return message, rendered from the payroll run

## Why

humaniq takes a monthly wage tax return (loonaangifte) through its lifecycle: concept, ready,
confirmed, sent, with the tijdvakcode and the statutory deadline checked by corpus rules. What
it does not do is produce the return itself. The message the Belastingdienst receives, the
collective part and one nominative line per income relationship, laid down in the yearly
Gegevensspecificaties, has to be made somewhere else and uploaded by hand, which for an
employer running payroll in humaniq means retyping what humaniq just calculated.

The loonaangifte change named this a follow-up in plain words: "message generation is a
follow-up spec". Sending the message over Digipoort stays decided no (`fil-digipoort`); this
change produces the file the employer uploads or hands to its gateway.

### Matrix rows (humaniq `openspec/parity/capabilities.json`)

| row | capability | humaniq today |
|---|---|---|
| `fil-wage-tax-return` | Prepare the monthly wage tax return for the tax authority. | `partial`: the filing lifecycle and rules are built; no message is produced |

The follow-up is named in
`openspec/changes/archive/2026-07-12-loonaangifte-filing-lifecycle/proposal.md` (Non-goals,
"message generation is a follow-up spec").

### Competitors rated yes

- `fil-wage-tax-return`, AFAS Profit: "after approving salaries Profit sends the wage tax
  returns to the Belastingdienst" (https://help.afas.nl/help/NL/SE/Pay_Taxes.htm), with a check
  before sending (https://help.afas.nl/help/NL/SE/Pay_Taxes_Month_Check.htm).
- `fil-wage-tax-return`, Visma Raet Youforce: release notes add a signal for the Loonaangifte and
  fix PAWW in the aangifte
  (https://community.visma.com/t5/Releases-YouServe/tkb-p/nl_ys_vismayouserve_releases/page/3).
- `fil-wage-tax-return`, HR2day: "automatic wage tax remittance and data exchange with the tax
  authority" (https://www.hr2day.com/features/salarisverwerking/).
- `fil-wage-tax-return`, Loket.nl: "payroll runs, wage tax returns and pension returns run
  automatically" (https://loket.nl/functionaliteiten/automatisch-verwerken/).

## What Changes

- **A message per filing.** When a filing is made ready (`klaarzetten`), humaniq renders the
  loonaangifte message for its administration and period from the approved payroll run: the
  collective part (totals per premium and tax) and a nominative part per income relationship
  (employee, employment, wages, withheld tax, premiums, the WW high or low indicator).
- **Validated before it is offered.** The message is checked against the year's XML schema
  shipped with the tax tables. A message that does not validate stops the filing in concept
  with the list of what is missing, per employee.
- **Stored on the filing.** The file is attached to the `LoonaangifteFiling` and downloadable
  from `LoonaangifteFilingDetail`; the collective totals are stored on the filing so the rules
  can compare them with the run.
- **Transport stays outside.** `verzenden` still records that the return left humaniq; the
  employer uploads the file or passes it to its gateway.

## Capabilities

### New Capabilities

- `loonaangifte-message`: rendering and validating the wage tax return message from an
  approved payroll run, attached to its filing.

## Impact

- `lib/Payroll/Loonaangifte/LoonaangifteMessageBuilder.php` (new),
  `lib/Service/LoonaangifteMessageService.php` (new).
- `lib/Standards/tables/nl-2026.json` or a sibling file: the message version and the XSD
  location; `lib/Standards/loonaangifte/2026/` (new): the Belastingdienst XSD.
- `lib/Settings/register.d/hr-objects.json`: `LoonaangifteFiling` gains `messageFileId`,
  `messageVersion`, `collectiveTotals`, `messageFindings`.
- `lib/Lifecycle/LoonaangifteMessageGuard.php` (new) on `klaarzetten`.
- `src/manifest.d/hr-objects.json`: the file and findings on `LoonaangifteFilingDetail`.

## Out of scope

- Digipoort transport (decided no, `fil-digipoort`).
- Correction messages (`fil-correction`, building elsewhere).
- Certification of humaniq as payroll software by the Belastingdienst.
