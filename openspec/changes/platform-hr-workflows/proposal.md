---
kind: config
depends_on: [platform-notifications]
---

# Shipped HR workflows beyond payroll

## Why

humaniq ships one workflow: the "Loonrun" flow on OpenRegister's flow engine, which runs a
payroll period from calculation through review to posting and payment. Everything else an HR
department automates in the suites the buyer compares humaniq with (the steps around a new
hire, the steps around a leaver, a leave request nobody decides) is still a checklist a person
remembers. The engine, its `Flows` page and its nodes (object triggers, user tasks, waits,
notifications, field writes) are all there; humaniq simply declares no HR flow for them.

This change ships three adoptable HR flows, disabled by default like the Loonrun flow, so an
employer switches on the ones it wants and adjusts them on the canvas.

### Matrix rows (humaniq `openspec/parity/capabilities.json`)

| row | capability | humaniq today |
|---|---|---|
| `plt-workflows` | Automate HR processes with configurable workflows and approvals. | `partial`: only the payroll flow is shipped; OpenRegister's flow pages exist |

### Competitors rated yes

- `plt-workflows`, AFAS Profit: "standard workflows are delivered and you can build your own to
  automate processes such as leave requests" (https://help.afas.nl/help/NL/SE/Ins_Wrkflw.htm).
- `plt-workflows`, Visma Raet Youforce: "flexible workflows with best practices for claims,
  sick reports with case management, document screening and hiring"
  (https://youforce.nl/youforce-modules).
- `plt-workflows`, HR2day: "standard workflows adjustable per organisation or department,
  multiple approvers, triggers such as a new employee"
  (https://www.hr2day.com/features/slimme-workflows/).
- `plt-workflows`, Loket.nl: "fully custom workflows, you decide how processes run from
  notifications to approvals" (https://loket.nl/functionaliteiten/workflows/).
- `plt-workflows`, Personio: "pre-built templates and a no-code workflow builder in the
  Automations area for lifecycle, onboarding and approval workflows"
  (https://support.personio.de/hc/en-us/articles/16452157755421-Create-workflows).

## What Changes

- **"Indiensttreding" (onboarding).** Starts when an `Onboarding` case is created: a task for
  the IT contact group to provide the account, a task for the manager to prepare the workplace,
  and a notification to the manager; completing a task ticks the matching checklist field on
  the case (`itProvisioned`).
- **"Uitdiensttreding" (offboarding).** Starts when an `Offboarding` case is created: tasks
  for asset return, access revocation and the exit interview, each filling its field
  (`assetsIngeleverd`, `toegangIngetrokken`, and the date in `exitGesprekDone`) when completed.
- **"Verlofaanvraag blijft liggen" (leave escalation).** Starts when a leave request is
  submitted, waits a set number of days, and if the request is still undecided notifies the
  HR group and gives it a task to follow up.
- **All three arrive disabled.** Each description says how to adopt it (enable, set the
  candidate groups), the same contract the Loonrun flow follows.

## Capabilities

### New Capabilities

- `hr-workflows`: shipped, adoptable onboarding, offboarding and leave escalation flows on the
  OpenRegister flow engine.

## Impact

- `lib/Settings/register.d/hr-onboarding.json` (`Onboarding`, `Offboarding`) and
  `lib/Settings/register.d/hr-leave.json` (`LeaveRequest`): `x-openregister-flows` blocks.
- `lib/Repair/RepublishLoonrunFlow.php`: generalised to republish every humaniq flow, so a
  shipped flow reaches instances that installed before it.
- No new node, no humaniq PHP service.

## Out of scope

- A humaniq flow editor. OpenRegister's `Flows` and `FlowDetail` pages already are one.
- Revoking the Nextcloud account itself (`hiring-offboarding-completion` specifies that); the
  flow asks a person to do it and records it.
