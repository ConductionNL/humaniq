---
kind: code
depends_on: [platform-notifications]
---

# One approvals inbox, and a deputy while the manager is away

## Why

A manager in humaniq approves hours on `TeamUrengoedkeuring`, leave on `TeamVerlofgoedkeuring`
and expense claims on `TeamDeclaratiegoedkeuring`: three pages, three queues, and no view of
what they already decided or where a request has got to. When the manager is on holiday nobody
else sees their queue, so requests wait until they return; a municipal tender asks that managers
can stand in for each other for a period.

### Matrix rows (humaniq `openspec/parity/capabilities.json`)

| row | capability | humaniq today |
|---|---|---|
| `dm-approvals-dashboard` | Track every approval request across processes in one list with its timeline and current status. | `partial`: three separate team approval pages and a count on `MijnHr` |
| `td-manager-deputy` | Let a manager hand approvals for their team to a deputy for a period. | `no`, none: `managerUserId` comes from the org chart with no temporary delegate |

### Demand

- `dm-approvals-dashboard`, changelog:
  https://support.personio.de/hc/en-us/articles/36278272594077-Approvals-dashboard-Beta-access-overview
- `td-manager-deputy`, tender: https://www.tenderned.nl/aankondigingen/overzicht/415705 (Delft
  Support MSS E2, leidinggevenden kunnen elkaar tijdelijk vervangen).

### Competitors rated yes

- `dm-approvals-dashboard`, HR2day: "Eén overzicht voor alle lopende workflows", a personal
  dashboard with active tasks and status updates, filterable by workflow type
  (https://www.hr2day.com/features/slimme-workflows/).
- `dm-approvals-dashboard`, Loket.nl: "Procesbeheer functie ... op één centrale plek een
  overzicht van de lopende en afgeronde statussen van de workflows" (https://loket.nl/roadmap/).
- `td-manager-deputy`, AFAS Profit: "hands tasks to a deputy when the user is on leave or sick"
  (https://help.afas.nl/help/NL/SE/Ins_Config_WF_InMtn_Replac.htm).
- `td-manager-deputy`, HR2day: "set a deputy (plaatsvervanger) when an approver is temporarily
  away" (https://www.hr2day.com/nieuws/nieuwe-release-kiwi/).

## What Changes

- **One inbox.** A new `MijnGoedkeuringen` page lists every request waiting for the caller,
  whatever its kind (leave, hours, expenses, leave buy and sell, and later change requests),
  with its employee, dates, waiting time and status, filterable by kind, with approve and reject
  in place. A second tab lists what the caller decided, with each request's timeline (submitted,
  decided, by whom).
- **A deputy for a period.** A manager records a `ManagerDeputy`: who stands in, from and until
  when. During that period the deputy's inbox also holds the manager's team requests, and the
  submit notifications of `platform-notifications` reach the deputy as well.
- **Nothing is re-stamped.** Requests keep their `managerUserId`; the inbox and the notification
  recipient resolve the active deputy at the moment they are read or sent.

## Capabilities

### New Capabilities

- `approvals-inbox`: one cross-process approvals inbox with request timelines, and temporary
  deputies for managers.

## Impact

- `lib/Settings/register.d/hr-deputy.json` (new): `ManagerDeputy`.
- `lib/Service/ApprovalsInboxService.php` (new), `lib/Controller/ApprovalsController.php` (new),
  `appinfo/routes.php`: `GET /api/approvals?state=open|decided`.
- `lib/Notification/ManagerOrDeputyRecipientResolver.php` (new): an OpenRegister
  `RecipientResolverInterface` used by the submit rules through `{kind: expression}`.
- `src/manifest.d/personal-dashboard.json` or a new fragment: `MijnGoedkeuringen` and
  `MijnVervangers`; `src/registry.js`: the inbox host view.

## Out of scope

- Approval authority. humaniq still scopes queues, it does not grant rights (`mss-team-scope`
  "scoping is not permission"); a deputy approves under the same OpenRegister rights as the
  manager.
- Retiring the three team pages. They stay until the inbox has been used for a while.
