---
kind: config
---

# Notifications for requests, decisions and payslips

## Why

An employee who submits leave, hours or an expense claim in humaniq hears nothing when their
manager decides: they have to open Mijn HR again to find out. A manager hears nothing when a
request lands in their queue. humaniq declares no notification at all. Every earlier change
that touched it (leave-verzuim, mijn-hr, hr-signals, performance reviews, bhv) named
notifications a deliberate app-wide deferral "until hrmq adopts the dialect". This change is
that adoption.

The same change gives the employer the per-step control the buyer expects: each notification
is a declared rule that can be switched on or off, and each user can turn off the ones they do
not want, through OpenRegister's notification preferences.

### Matrix rows (humaniq `openspec/parity/capabilities.json`)

| row | capability | humaniq today |
|---|---|---|
| `ess-notifications` | Get notified when something you submitted is approved or rejected. | `no`, none: no `x-openregister-notifications`, no notifier in `lib/` |
| `plt-configurable-notifications` | Choose per workflow step whether a notification is sent. | `no`, none: no rule exists to switch; named an app-wide deferral in `2026-07-13-performance-reviews-mvp/design.md` |

### Competitors rated yes

- `ess-notifications`, AFAS Profit: "Profit sends push notifications to Pocket users based on
  signals and workflows, and when a new payslip or annual statement is ready"
  (https://help.afas.nl/help/NL/SE/135800.htm).
- `ess-notifications`, Visma Raet Youforce: "when a claim is rejected the employee gets a
  notification in the app and in the Mijn Youforce inbox"
  (https://www.ssc-ons.nl/content/uploads/2021/05/Handleiding-Youforce-Controleren-declaraties-leidinggevenden.pdf).
- `ess-notifications`, HR2day: "push notifications for approvals and changes"
  (https://www.hr2day.com/features/complete-app/).
- `ess-notifications`, Personio: "as soon as the request is reviewed, you receive a
  notification via email" (https://support.personio.de/hc/en-us/articles/360006849378-Request-time-off).
- `plt-configurable-notifications`, AFAS Profit: "a message template can be linked to a task
  or action in a workflow so an e-mail is sent when the item reaches it"
  (https://help.afas.nl/help/NL/SE/Ins_Wrkflw_Task_Massge_set.htm).
- `plt-configurable-notifications`, HR2day: "automatic emails and reminders with flexible
  settings" (https://www.hr2day.com/features/slimme-workflows/).
- `plt-configurable-notifications`, Loket.nl: "decide yourself how processes run, from
  notifications to approvals, everyone gets only notifications relevant to their role"
  (https://loket.nl/functionaliteiten/workflows/).
- `plt-configurable-notifications`, Personio: "workflow actions include Assign a task, Send a
  notification, Send an email and Add a participant, added per step"
  (https://support.personio.de/hc/en-us/articles/16599105162909-Summary-of-workflow-components).

## What Changes

- **humaniq adopts the canonical dialect.** `x-openregister-notifications` rules are declared on
  the schemas that carry a request and a decision:
  - `LeaveRequest`, `Timesheet`, `Expense`: on `submit` the manager in `managerUserId` is told a
    request waits; on `approve` and `reject` the employee in `userId` is told the outcome, with
    the rejection reason in the body.
  - `Expense` on `reimburse`: the employee is told the claim is paid.
  - `LeaveTransaction` on `approve` and `reject`, `PerformanceReview` on `vaststellen`: the
    employee is told.
  - `Payslip` on create: the employee is told a payslip is ready.
- **Every rule can be switched per step.** Each rule carries `enabled`; the schema default is
  the employer's setting, and a user switches a rule off or on for themselves in the humaniq
  settings dialog, which mounts the library's notification preferences for humaniq's rules.
- **One channel set.** Rules send an in-app Nextcloud notification; the request-waiting rules also
  send email so a manager who rarely opens Nextcloud still sees the queue.

## Capabilities

### New Capabilities

- `humaniq-notifications`: declared notification rules for submissions, decisions and new
  payslips, switchable per rule by the employer and per user.

## Impact

- `lib/Settings/register.d/hr-leave.json`, `hr-timesheet.json`, `hr-expense.json`,
  `hr-performance.json`, `hr-objects.json` (Payslip): `x-openregister-notifications` blocks,
  schema version bumps.
- `src/main.js` or the `CnAppRoot` user-settings slot: the library's
  `CnNotificationPreferences` scoped to humaniq.
- hydra gate 18 (`notification-dialect`) must pass on the register fragments.

## Out of scope

- Reminders before a deadline (Poortwachter, contract expiry). `absence-deadlines-and-signals`
  declares those on top of this adoption.
- Notifications for changes specified in this pass that add new schemas; each declares its own
  rules when it is built.
- Mobile push beyond what the engine's `web-push` channel gives a browser.
