---
kind: code
---

# The pension return message, including the ABP delivery

## Why

humaniq records a pension filing per fund and period (`PensionFiling`) with a guarded
lifecycle: it cannot be checked until the payroll run behind it is approved, and the rules
flag a period without a filing and an ABP-obliged administration without its ABP delivery.
It does not produce the delivery itself. The Uniforme Pensioenaangifte (UPA, the SIVI message
funds take) and APG's delivery for ABP are still made elsewhere and uploaded by hand.

Both archived changes named this a follow-up: `pension-filing-upa-mvp` ("UPA XML
generation, APG transport and auto-dispatch are follow-up specs") and `abp-aansluiting`
("ABP-specific UPA fields ... named fast-follows and exclusions").

### Matrix rows (humaniq `openspec/parity/capabilities.json`)

| row | capability | humaniq today |
|---|---|---|
| `fil-pension` | Deliver pension data to the pension fund in the UPA format. | `partial`: filing lifecycle and rules built; no UPA message |
| `fil-abp` | Connect to ABP for public sector pension administration. | `partial`: an obligation check; no ABP delivery, fund recorded by hand |

### Competitors rated yes

- `fil-pension`, AFAS Profit: "Profit manages the Uniforme Pensioenaangifte (UPA) with the
  pension providers" (https://help.afas.nl/help/NL/SE/Pay_Config_Pnsion_Upa.htm).
- `fil-pension`, Visma Raet Youforce: 2026-08 release notes "cover UPA aangifte to a private
  pension provider and APG and PMT pension premiums"
  (https://community.visma.com/t5/Releases-YouServe/tkb-p/nl_ys_vismayouserve_releases).
- `fil-pension`, HR2day: "HR2day certified for the UPA pension return"
  (https://www.hr2day.com/nieuws/hr2day-gecertificeerd-uniforme-pensioenaangifte-upa/).
- `fil-pension`, Loket.nl: "initiate UPA pension declarations per administration and external
  party, also for previous years" (https://developer.loket.nl/ApiDocs#tag/Upa-pension-declaration).
- `fil-abp`, AFAS Profit: "Levering Pensioen Gegevens (LPG) is delivered digitally to APG, the
  pension administrator for ABP" (https://help.afas.nl/help/NL/SE/Pay_Config_Pnsion_APG.htm).
- `fil-abp`, Visma Raet Youforce: 2026-08 notes "fix APG productloon in the collective pension
  return (APG administers ABP)"
  (https://community.visma.com/t5/Releases-YouServe/tkb-p/nl_ys_vismayouserve_releases).
- `fil-abp`, HR2day: "supports the ABP calculation method for annual income"
  (https://www.hr2day.com/nieuws/hr2day-lion/).
- `fil-abp`, Loket.nl: "activate ABP funds per employment" and "initiate APG pension
  declarations" (https://developer.loket.nl/ApiDocs#tag/Abp-funds).

## What Changes

- **A pension scheme per fund.** A new `PensionScheme` records, per administration and fund,
  the scheme code, the delivery format (`upa` or `apg`), the franchise, the maximum pensionable
  salary and the employer and employee contribution percentages, sourced and dated like the
  tax tables.
- **A message per filing.** When a pension filing is checked (`controleren`), humaniq renders
  the delivery from the approved run: per employee the pensionable wage, the pension base
  (wage minus franchise, capped), the part-time factor, the contributions and the employment
  dates, in the UPA message or, for a fund on the APG format such as ABP, in APG's delivery.
- **Validated and attached.** The message is validated against the format's schema and
  attached to the `PensionFiling`; a message that cannot be made refuses the check with the
  findings per employee.
- **ABP by configuration.** An administration marked ABP-obliged gets an `abp` scheme on the APG
  format, so the existing `nl-abp-fund-required` rule and the delivery meet.

## Capabilities

### New Capabilities

- `pension-upa-message`: pension schemes per fund and the UPA or APG delivery rendered from an
  approved payroll run.

## Impact

- `lib/Settings/register.d/hr-pension.json`: `PensionScheme` (new schema); `PensionFiling`
  gains `messageFileId`, `messageFormat`, `messageFindings`.
- `lib/Payroll/Pension/UpaMessageBuilder.php`, `ApgMessageBuilder.php`,
  `lib/Service/PensionMessageService.php` (new).
- `lib/Lifecycle/PayrollRunApprovedGuard.php` stays; a `PensionMessageGuard` (new) joins it on
  `controleren`.
- `lib/Standards/pension/` (new): the format schemas per year.
- `src/manifest.d/hr-pension.json`: `PensionSchemes` pages; file and findings on
  `PensionFilingDetail`.

## Out of scope

- Wire transport to APG or other administrators and retour-bericht ingestion
  (`pension-filing-upa-mvp` non-goals; the `upa-pensioen` connection in
  `platform-integrations-catalogue` stays not available until a transport change lands).
- The employer's own rules for pension bases per CAO (`dm-own-pension-rules`, deferred).
- VPL, Keuzepensioen and Adieu messages (`abp-aansluiting` exclusions).
