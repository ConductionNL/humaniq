---
kind: code
depends_on: [platform-notifications]
---

# A complete personnel file and a real right-to-work check

## Why

An HR adviser in a care or youth organisation has to prove, for every employee, that the
file holds a valid ID, a signed contract, a recent certificate of conduct (VOG) and, for
some roles, a current BIG or SKJ registration. humaniq cannot tell them which files are
incomplete today. `EmployeeCompetence.validUntil` holds a registration's expiry, but it is
read only when a roster is checked; nothing lists which documents a person must have, and
nothing says one is missing or about to lapse. The adviser keeps a spreadsheet next to the
app, and finds the expired VOG at the inspection.

The start of employment has the same weakness. Before a new hire's first day the employer
must have seen their ID and, for someone from outside the EU, their right to work. humaniq
records this as a checkbox, `widCheckDone`, that HR ticks after looking at a passport. A
residence permit that does not allow work, or one that expired last month, passes just as
easily as a valid Dutch passport.

This change adds a per-employee list of mandatory documents with a signal when one is
missing, expired or about to expire, and a right-to-work check that reads the document,
decides by a stated rule, and stops the onboarding when it fails.

### Matrix rows (humaniq `openspec/parity/capabilities.json`)

| row | capability | humaniq today |
|---|---|---|
| `td-dossier-validation` | Get a signal when a mandatory document such as an ID, certificate of conduct or professional registration is missing or expired in a personnel file. | `partial`, built: competence expiry is checked only when rostering; no mandatory-document list |
| `dm-right-to-work-check` | Check a new hire's identity document and right to work during onboarding, with a hard stop when the check fails. | `partial`, built: `widCheckDone` is a tick HR sets after looking |

### Demand

- `td-dossier-validation`, tender: https://www.tenderned.nl/aankondigingen/overzicht/415705
  (Delft Support eis 1.2 E14: identiteitsbewijs, arbeidsovereenkomst, VOG, SKJ, BIG).
- `dm-right-to-work-check`, changelog: https://klant.afas.nl/update/profit-8/hrm

### Competitors rated yes

- `td-dossier-validation`, AFAS Profit: "personal documents such as ID, residence document
  and VOG are recorded with an expiry date, and a signal tells the employee when the end date
  approaches or has passed" (https://help.afas.nl/help/NL/SE/135092.htm).
- `td-dossier-validation`, HR2day: "expiry dates on documents with notifications before they
  expire" (https://www.hr2day.com/features/digitaal-dossier/).
- `dm-right-to-work-check`, AFAS Profit: "validates ID documents at onboarding, employee
  creation and document upload, runs an RTW check ... with hard and soft stops"
  (https://klant.afas.nl/update/profit-8/hrm, https://help.afas.nl/help/NL/SE/140314.htm).

### Recorded follow-ups this change picks up

- `2026-07-13-onboarding-wizard-mvp` proposal, Non-goals: "No new lifecycle guard classes.
  Checklist gates are documented on the transitions and enforced by audit rules; write-time
  guard wiring is owned by the active `hrmq-rule-compliance-enforcement` change." That change
  (archived 2026-09-07) stayed audit-only and left write-time blocking open; humaniq has since
  shipped guards on other transitions (`LeaveTypeConditionGuard`, `CompEffectiveDateGuard`).
  The hard stop here is one such guard.
- Same list: "No WID evidence vault (document hashing, retention timers, restricted ACLs)."
  This change adds no vault: files stay where they are, and it records the check, not a copy.

## What Changes

- **Mandatory documents per group.** A new `DossierRequirement` schema lists what a file
  must hold (for example ID for everyone, VOG for care roles, BIG for nurses), for whom
  (everyone, a contract type, a normfunctie, an org unit), whether it expires, how many days
  ahead to warn, and the maximum age at the start date (a VOG older than six months at hire
  does not count).
- **Documents as records.** A new `PersonnelDocument` schema holds one supplied document for
  one employee with issue date, expiry date, the file and who verified it. A requirement can
  also be met by what humaniq already has: a generated `arbeidsovereenkomst`, or a current
  `EmployeeCompetence` for a registration such as BIG.
- **A signal on the page and in one list.** `EmployeeDetail` shows each requirement as
  present, missing, expired or expiring, and a `Onvolledige dossiers` page lists every
  employee with a gap. HR is notified before a document expires, through the notification
  dialect `platform-notifications` specifies.
- **A right-to-work check with a hard stop.** A new `RightToWorkCheck` records the document
  type, nationality, expiry, the residence endorsement for a non-EU national, how it was read
  and the result. The result follows a stated rule: an EU, EEA or Swiss document that has not
  expired passes; any other nationality passes only with a residence document that allows
  work, or with a work permit (TWV) that is current. When filinq reads the machine-readable
  zone, humaniq verifies its check digits. A new guard stops the onboarding case at
  `gereed_melden` and `starten` without a passing check dated on or before the start date.

## Capabilities

### New Capabilities

- `dossier-completeness`: mandatory documents per group with a completeness signal, and a
  right-to-work check that blocks the onboarding when it fails.

## Impact

- `lib/Settings/register.d/hr-dossier.json` (new fragment): `DossierRequirement`,
  `PersonnelDocument`, `RightToWorkCheck`, with an expiry notification rule on
  `PersonnelDocument`.
- `lib/Service/DossierCompletenessService.php`, `lib/Service/RightToWorkService.php` (new);
  `lib/Standards/tables/eea-nationalities.json` (new).
- `lib/Lifecycle/RightToWorkGuard.php` (new), declared as `requires` on the `gereed_melden`
  and `starten` transitions in `lib/Settings/register.d/hr-onboarding.json`.
- `lib/Controller/DossierController.php` (new) and `appinfo/routes.php`:
  `GET /api/employees/{id}/dossier-status`, `GET /api/dossier/incomplete`,
  `POST /api/onboarding/{id}/right-to-work`.
- `src/manifest.d/hr-objects.json`, `src/manifest.d/hr-onboarding.json`, a new
  `src/manifest.d/hr-dossier.json`: the status section, the index pages, the check action.

## Cross-app dependencies

- **filinq**: reading an identity or residence document into document type, nationality,
  expiry date, endorsement text and the machine-readable zone lines, the duck-typed
  extraction path receipt OCR uses. Without it HR enters those values by hand and the check
  still decides.
- **openregister**: the canonical notification dialect, specified app-wide in
  `platform-notifications`.

## Out of scope

- Authenticity checks beyond the machine-readable zone (chip reading, sanction lists, PEP).
- Requesting a VOG from Justis. HR requests it; humaniq records it.
- Blocking payroll for an incomplete file. The signal informs; only the right-to-work check
  stops anything.
