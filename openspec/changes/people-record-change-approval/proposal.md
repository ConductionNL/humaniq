---
kind: code
---

# Changes to an employee record wait for the right approver

## Why

In humaniq a change to an employee record lands the moment it is saved. Whoever may open
`EmployeeDetail` can change a bank account, a salary or a date of birth, and nobody else
looks at it first. An employee cannot change anything about themselves at all: there is no
page where they see their own details, and the record has no address fields for them to
correct.

Every Dutch HR suite the buyer compares humaniq with works the other way round. The
employee changes their own address or bank account in self-service, and a change of a kind
the employer marks as sensitive (a bank account, a salary, a contract term) waits for the
role set for that kind of change before it takes effect. A municipal tender asks for exactly
that, configurable per kind of change.

### Matrix rows (humaniq `openspec/parity/capabilities.json`)

| row | capability | humaniq today |
|---|---|---|
| `td-mutation-approval` | Have a change to an employee record take effect only after the role set for that kind of change approves it. | `no`, none: `Employee` has no lifecycle and no pending-change object; a change is saved at once |
| `ess-edit-details` | Let an employee update their own address or bank account, with approval where needed. | `no`, none: no self-service page for one's own record; `Employee` has no address fields |

### Demand

- `td-mutation-approval`, tender: https://www.tenderned.nl/aankondigingen/overzicht/415705
  (Delft Support HRM/PSA eis 1.2 E9: personeelsdossier, wijzigingen pas na goedkeuring, per
  type wijziging instelbaar).

### Competitors rated yes

- `td-mutation-approval`, AFAS Profit: "each kind of change (contract change, salary change,
  personal data) runs through its own workflow in which you set who checks it and in which
  order before it is recorded" (https://help.afas.nl/help/NL/SE/143405.htm).
- `td-mutation-approval`, Visma Raet Youforce: "Self Service forms move through statuses
  such as Goedkeuren MGR (manager) and Fiatteren SA (payroll administration) before
  processing in HR Core" (https://www.ssc-ons.nl/content/uploads/2024/07/Handleiding-Mijn-Youforce-1.pdf).
- `td-mutation-approval`, HR2day: "approval flows with multiple approvers chosen by role or
  department" (https://www.hr2day.com/features/slimme-workflows/).
- `td-mutation-approval`, Loket.nl: "every change starts with the employee and runs through
  smart workflows to the right person before it is processed" (https://loket.nl/mijnloket/).
- `td-mutation-approval`, Personio: "admins define which changes to employee information
  need manual approval based on the profile section changed, with approvers set per rule"
  (https://support.personio.de/hc/en-us/articles/21453114449053-Set-up-employee-data-change-approval-workflows).
- `ess-edit-details`, AFAS Profit: "employees change their own address or salary bank
  account in InSite or Pocket, which starts a workflow before it is recorded"
  (https://help.afas.nl/help/NL/SE/142135.htm).
- `ess-edit-details`, Visma Raet Youforce: "employees change address, bank account and civil
  status via Self Service forms that may pass approval"
  (https://www.ssc-ons.nl/content/uploads/2024/07/Handleiding-Mijn-Youforce-1.pdf).
- `ess-edit-details`, HR2day: "employees change addresses, bank details and other data
  online" (https://www.hr2day.com/features/personeelsbeheer/).
- `ess-edit-details`, Loket.nl: "employees change their own address, IBAN and other
  personal data" (https://loket.nl/oplossingen-voor/slim-geregeld/).
- `ess-edit-details`, Personio: "admins define which changes to employee information need
  manual approval, based on the profile section updated, and which are approved
  automatically" (https://support.personio.de/hc/en-us/articles/21453114449053-Set-up-employee-data-change-approval-workflows).

## What Changes

- **A change request object.** A new `EmployeeChangeRequest` carries the employee, the kind
  of change, the proposed field values, who asked and a lifecycle `ingediend` to
  `goedgekeurd` or `afgewezen`. Approving applies the values to `Employee`; rejecting
  applies nothing and records the reason.
- **An administered rule per kind of change.** A new `ChangeApprovalRule` names, per kind
  of change (address, bank account, personal data, salary, contract terms), the fields it
  covers and the role that approves it: `hr`, `accountant`, the employee's manager, or none.
  A kind with no approver applies at once.
- **Guarded fields cannot be saved around the request.** A pre-save listener on
  `Employee` refuses a direct edit of a field covered by a rule with an approver, for every
  caller, and tells the caller to raise a change request instead. humaniq's own apply step
  is exempt through the existing `InternalWriteMarker`.
- **Address fields on the employee.** `Employee` gains street, house number, postcode,
  city and country, so an address change has somewhere to land.
- **My details in Mijn HR.** A new `MijnGegevens` page shows the employee their own record
  and lets them request a change of address or bank account; the request then follows the
  rule for its kind.
- **An approval queue.** `ChangeRequests` and `ChangeRequestDetail` list open requests with
  the changed fields next to their current values, with approve and reject actions.

## Capabilities

### New Capabilities

- `employee-change-approval`: employee record changes routed through an administered
  approval rule per kind of change, including the employee's own requests.

## Impact

- `lib/Settings/register.d/hr-change-requests.json` (new): `EmployeeChangeRequest` with its
  lifecycle, `ChangeApprovalRule`.
- `lib/Settings/register.d/hr-objects.json`: `Employee` gains five address properties
  (schema version bump).
- `lib/Listener/EmployeeGuardedFieldListener.php` (new), `lib/Service/ChangeRequestService.php`
  (new), `lib/Lifecycle/ChangeApproverRoleGuard.php` (new), registration in
  `lib/AppInfo/Application.php`.
- `src/manifest.d/hr-change-requests.json` (new): `MijnGegevens`, `ChangeRequests`,
  `ChangeRequestDetail`, `ChangeApprovalRules`; menu entries under Mijn HR and Personeel.

## Out of scope

- Multi-step chains (manager then HR) for one kind of change. One approver role per kind;
  a second step is a follow-up once a kind needs it.
- Changes to contracts through the request. `EmploymentContract` keeps its own edit path;
  the contract-terms kind covers the contract fields that live on `Employee` today.
- Notifications about a decision. They arrive with the app-wide notification dialect in
  `platform-notifications`.
