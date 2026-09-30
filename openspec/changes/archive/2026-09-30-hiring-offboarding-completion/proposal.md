---
kind: code
---

# Finish the offboarding: exit interview, account, transition payment

## Why

An HR adviser who closes an offboarding case in humaniq today ticks three boxes that each
stand for work done somewhere else.

- **The exit interview** is a date, `exitGesprekDone`. What the leaver said, why they really
  left and whether they would come back ends up in the free `notes` field or nowhere, so
  nobody can see after a year that four of six leavers in one team named their manager.
- **Access** is a checkbox, `toegangIngetrokken`. Nothing in humaniq disables the leaver's
  Nextcloud account; the adviser asks IT, trusts it happened and ticks the box. The rules
  engine does not even read the field.
- **The transition payment** is a number the adviser types, `transitievergoedingBedrag`. The
  statutory formula (BW 7:673: one third of a monthly wage per year of service, capped) is
  stored as rule parameters, but nothing computes the amount from it, so the adviser works it
  out in a spreadsheet.

This change turns all three into work humaniq does: a structured exit interview record, a
real account disable on the leaver's Nextcloud account, and a calculated transition payment
with its breakdown.

### Matrix rows (humaniq `openspec/parity/capabilities.json`)

| row | capability | humaniq today |
|---|---|---|
| `hir-exit-interview` | Record an exit interview and the reason for leaving. | `partial`, built: `Offboarding.reason` is a real field; the interview is a date and free notes |
| `hir-access-revocation` | Have accounts and access revoked when an employee leaves. | `partial`, built: `toegangIngetrokken` is a manual tick; no code disables an account |
| `ppl-transition-payment` | Calculate the statutory transition payment when a contract ends. | `partial`, built: HR types the amount; the check only asserts it is present |

### Competitors rated yes

- `hir-exit-interview`, AFAS Profit: "the workflow Exitgesprek records the exit interview
  data including the reason for leaving" (https://help.afas.nl/content/NL/SE/97599.htm).
- `hir-exit-interview`, Visma Raet Youforce: "offboarding collects feedback from departing
  colleagues" (https://youforce.nl/product/onboarding).
- `hir-exit-interview`, HR2day: "exit conversations and handovers are planned" and "surveys
  usable for exit interviews" (https://www.hr2day.com/features/on-offboarding/,
  https://www.hr2day.com/features/surveys/).
- `hir-exit-interview`, Personio: "adds leavers automatically to an offboarding survey to
  gather feedback when they leave"
  (https://support.personio.de/hc/en-us/articles/23682604998813-Automate-onboarding-and-offboarding-surveys).
- `hir-access-revocation`, AFAS Profit: "application access, user e-mail and blocking of
  employee and user are updated automatically on the leaving date"
  (https://help.afas.nl/help/NL/SE/133370.htm).
- `hir-access-revocation`, Visma Raet Youforce: "the IAM API feeds identity systems to
  automate onboarding, offboarding and revoking application or building access"
  (https://vr-api-integration.github.io/youforce-api-documentation/iam_api_intro.html).
- `hir-access-revocation`, HR2day: "automatic deregistration at IT with access rights
  revoked" (https://www.hr2day.com/features/on-offboarding/).
- `hir-access-revocation`, Personio: "after termination the status changes to Inactive and
  employees cannot log in"
  (https://support.personio.de/hc/en-us/articles/4405573920285-Terminate-an-employment).
- `ppl-transition-payment`, AFAS Profit: "the process Transitievergoeding calculates and
  processes the statutory transition payment on leaving"
  (https://help.afas.nl/help/NL/SE/143364.htm).
- `ppl-transition-payment`, Visma Raet Youforce: "the workflow Uitstroom > Uit dienst met
  transitievergoeding"
  (https://community.visma.com/t5/Releases-YouServe/tkb-p/nl_ys_vismayouserve_releases).
- `ppl-transition-payment`, HR2day: "automatic calculation of the transitievergoeding from
  contract duration and fixed and variable pay"
  (https://data.maglr.com/1697/issues/64244/760003/index.html).
- `ppl-transition-payment`, Loket.nl: "operations get default input parameters and
  calculate transition compensation"
  (https://developer.loket.nl/ApiDocs#tag/Transition-compensation).

### Recorded follow-ups this change picks up

- `2026-07-13-offboarding-wizard-mvp` proposal, Non-goals: "No ExitInterview entity.
  `exitGesprekDone` (date) records that the exit interview happened; structured feedback
  capture and 90-day anonymisation are follow-up."
- Same list: "No IT deprovisioning automation or data-export provisioning.
  `toegangIngetrokken` records the outcome; OCS automation is follow-up."
- Same list: "No eindafrekening computation engine. The draft's severance math (...
  transitievergoeding formula ...) is follow-up; the MVP records `transitievergoedingBedrag`
  as auditable input and versions the formula constants as rule `parameters` data so the
  rule and a later computation service share one source of truth."
- `2026-08-20-aor-ambtenarenrecht` F-002 left the calculation out only because "BW 7:673 is
  identical for every BW7 employee; not ambtenaar-specific". It does not refuse it.
- `2026-09-07-hris-api-public` refuses SCIM provisioning as Nextcloud's identity layer. This
  change provisions nothing: it asks Nextcloud's own user manager to disable one account.

## What Changes

- **An exit interview record.** A new `ExitInterview` schema, one per offboarding case, holds
  when it was held and by whom, the leaver's own main reason from a fixed list, a 0 to 10
  "would recommend" score, whether they would come back, and two free-text answers.
  Recording it stamps `Offboarding.exitGesprekDone` with the same date. After 90 days a
  shipped flow clears the person link and the free text, and keeps the reason, score and
  unit for reporting.
- **Leavers' reasons at a glance.** An `ExitInterviews` page lists the records with a count
  per reason over the last twelve months.
- **The account is disabled, not ticked.** A `Revoke access` action on `OffboardingDetail`
  disables the Nextcloud account in `Employee.nextcloudUserId` through Nextcloud's user
  manager and then sets `toegangIngetrokken`, with who did it and when. A shipped flow does
  the same on the day after `lastWorkingDay` for cases still open. An admin account and the
  acting user's own account are refused.
- **The transition payment is calculated.** A `Calculate transition payment` action on
  `OffboardingDetail` computes the amount from the employment start of the unbroken contract
  chain, the monthly wage and the cap in the rule's `parameters`, writes it to
  `transitievergoedingBedrag` and keeps the breakdown beside it. HR can still overwrite the
  amount; the breakdown shows what the calculation said.

## Capabilities

### New Capabilities

- `offboarding-completion`: the structured exit interview, the account disable on leaving
  and the calculated transition payment.

## Impact

- `lib/Settings/register.d/hr-onboarding.json`: new `ExitInterview` schema with an
  anonymisation flow; `Offboarding` gains `toegangIngetrokkenDoor`, `toegangIngetrokkenOp`
  and `transitievergoedingBerekening` (0.2.0).
- `lib/Service/AccessRevocationService.php` (new), `lib/Service/TransitionPaymentCalculator.php`
  (new).
- `lib/Controller/OffboardingController.php` (new) and `appinfo/routes.php`:
  `POST /api/offboarding/{id}/revoke-access`, `POST /api/offboarding/{id}/transition-payment`.
- `lib/Flow/RevokeAccessNode.php` (new) registered in `lib/Flow/HumaniqFlowNodeListener.php`.
- `lib/Standards/rules/labour.json`: the 2026 cap replaces the 2025 figure marked TODO.
- `src/manifest.d/hr-onboarding.json`: two actions on `OffboardingDetail`, an exit interview
  section, an `ExitInterviews` index page and menu entry.

## Out of scope

- Revoking access in systems outside Nextcloud. Other systems learn of the leaver through
  `platform-hr-lifecycle-events`.
- The rest of the final settlement (leave payout, holiday allowance, thirteenth month). They
  stay recorded amounts.
- An exit survey sent to the leaver. `talent-engagement-surveys` can carry one.
