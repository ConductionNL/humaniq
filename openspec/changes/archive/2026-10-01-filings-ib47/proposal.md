---
kind: code
---

# Payments to third parties reported to the tax authority (UBD, formerly IB47)

## Why

An employer who pays people who are neither employees nor entrepreneurs (a guest lecturer, a
volunteer paid above the tax-free amount, a council committee member, a freelancer without a
VAT number) must report those payments to the Belastingdienst once a year, by 31 January: the
opgaaf uitbetaalde bedragen aan derden (UBD), formerly the IB47. humaniq does not know these
payments exist, so a municipality or a school keeps the list in a spreadsheet beside the
payroll it runs in humaniq.

### Matrix rows (humaniq `openspec/parity/capabilities.json`)

| row | capability | humaniq today |
|---|---|---|
| `fil-ib47` | Report payments to third parties who are not employees. | `no`, none: no reference anywhere |

### Competitors rated yes

- `fil-ib47`, AFAS Profit: "payments to third parties who are not employees and not
  entrepreneurs (UBD, formerly IB47) are registered and reported to the Belastingdienst"
  (https://help.afas.nl/help/NL/SE/120274.htm).
- `fil-ib47`, HR2day: "supports the IB47 return for payments to third parties"
  (https://www.hr2day.com/nieuws/hr2day-jaguar/), and a "Jaaropgave UBD (uitbetaalde bedragen
  aan derden)" (https://data.maglr.com/1697/issues/68666/808497/index.html).

## What Changes

- **A third party and their payments.** A new `ThirdPartyPayee` holds the person's name, BSN,
  date of birth and address, and a new `ThirdPartyPayment` each payment: payee, date, gross
  amount, expense allowance paid on top, and the administration.
- **A yearly report.** For an administration and a year humaniq assembles the UBD report: one
  line per payee with the summed amounts, in the Belastingdienst's delivery format, validated,
  and stored as a `ThirdPartyReport` with a lifecycle `concept`, `klaargezet`, `verzonden`,
  like the other filings.
- **A deadline.** A corpus rule flags a year with payments and no report sent by 31 January of
  the next year, and a payee missing a BSN or date of birth.
- **A statement for the payee.** Each payee can be given a yearly statement of what was paid,
  generated through the existing document path.

## Capabilities

### New Capabilities

- `third-party-payments`: registering payments to non-employee third parties and the yearly UBD
  report with its deadline.

## Impact

- `lib/Settings/register.d/hr-third-party.json` (new): `ThirdPartyPayee`, `ThirdPartyPayment`,
  `ThirdPartyReport`.
- `lib/Service/ThirdPartyReportService.php` (new), `lib/Standards/rules/payroll.json` and a
  check provider `lib/Standards/Checks/NlThirdPartyChecks.php` (new).
- `src/manifest.d/hr-third-party.json` (new): payees, payments, reports under Loonadministratie.
- `lib/Service/HrDocumentService.php`: a `ubd-jaaropgaaf` document type.

## Cross-app dependencies

- filinq: a template for the payee statement in its template store, as for the other HR
  documents; humaniq supplies the variables.

## Out of scope

- Paying the third parties. Payment runs through accounts payable (shillinq); humaniq records
  what was paid.
- Transport to the Belastingdienst; the file is uploaded as for the other returns.
