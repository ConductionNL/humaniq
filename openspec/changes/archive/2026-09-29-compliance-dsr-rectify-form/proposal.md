---
kind: code
---

# The Rectify button collects the correction it applies

## Why

When an employee asks HR to correct their data under the AVG (right to rectification,
art. 16), humaniq's `DsrRequestDetail` offers a "Rectify" button. It cannot work: the
button sends only the employee and the request id, while `POST /api/dsr/rectify` requires the
fields to change and answers 400 without them. The manifest's own note says the action type
has no way to ask for the changes. Today the correction only succeeds through
`occ humaniq:avg:rectify --changes <json>`, which an HR adviser does not run.

Correcting data on request and recording that it was done is what AFAS and Personio offer
with their change logs; humaniq has the recording half (the outcome lands on the request),
not a working way to start it.

### Matrix rows (humaniq `openspec/parity/capabilities.json`)

| row | capability | humaniq today |
|---|---|---|
| `cmp-dsr-rectify` | Correct an employee's data on request and record that you did. | `partial`: backend and occ work; the page button always gets 400 |

### Competitors rated yes

- `cmp-dsr-rectify`, AFAS Profit: "changes to chosen tables and fields are logged in the
  mutation logbook, so a correction and who made it are recorded"
  (https://help.afas.nl/help/NL/SE/App_Logging.htm).
- `cmp-dsr-rectify`, Personio: "HR edits employee attributes, and the Audit Log tracks changes
  to employee data showing who did what and when, with entries that cannot be changed"
  (https://support.personio.de/hc/en-us/articles/18906413010589-Use-the-Personio-Audit-Log).

## What Changes

- **The request holds the correction.** `DsrRequest` gains `requestedChanges`: a list of
  field and new value pairs, limited to the `Employee` fields a data subject may have
  corrected (name, date of birth, address, bank account). HR fills it on the request's edit
  form, where it is reviewed before anything is applied.
- **The button sends it.** The `dsr-rectify` action passes `@object.requestedChanges`, and
  the controller turns the list into the change map `rectifySubjectObject()` already takes.
- **The outcome stays recorded.** The existing `recordRectifyOutcome()` keeps writing what was
  changed onto the request, and OpenRegister's audit trail records the write to the employee.

## Capabilities

### Modified Capabilities

- `avg-dsr`: the rectification started from the page carries the requested changes.

## Impact

- `lib/Settings/register.d/hr-dsr.json`: `DsrRequest.requestedChanges`.
- `lib/Controller/AvgDsrController.php`: `validateRectifyInput()` accepts the list form beside
  the map the occ command sends.
- `src/manifest.d/hr-dsr.json`: the `dsr-rectify` params and the stale note.

## Out of scope

- Changes to fields outside the allowed list (salary, contract terms). Those go through
  `people-record-change-approval`.
