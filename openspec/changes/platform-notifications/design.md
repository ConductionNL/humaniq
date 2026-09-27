# Design: notifications for requests, decisions and payslips

## Context

Read at `development` af702f78.

- No humaniq schema declares `x-openregister-notifications`; `grep -rln
  'INotification|NotificationManager' lib/` finds nothing, so there is no imperative
  notifier to migrate either.
- Lifecycles and uid fields (`lib/Settings/register.d`):
  - `LeaveRequest` (`hr-leave.json`): actions `submit`, `approve`, `reject`; fields `userId`,
    `managerUserId`, `rejectionReason`, `startDate`, `endDate`.
  - `Timesheet` (`hr-timesheet.json`): `submit`, `approve`, `reject`, `reopen`; `userId`,
    `managerUserId`, `period`, `rejectionReason`.
  - `Expense` (`hr-expense.json`): `submit`, `approve`, `reject`, `reimburse`; `userId`,
    `managerUserId`, `title`, `amount`, `rejectionReason`.
  - `LeaveTransaction` (`hr-leave.json`): `submit`, `approve`, `reject`, `settle`; `userId`.
  - `PerformanceReview` (`hr-performance.json`): `indienen`, `bespreken`, `vaststellen`,
    `heropenen`; `userId`.
  - `Payslip` (`hr-objects.json`): no lifecycle; `userId`, `period`, `payrollRunId`.
- The canonical dialect (hydra ADR-031): `trigger` (`created`, `updated`, `transition` with
  `action`, `scheduled`, `threshold`, `calculatedChange`), `enabled`, `channels`
  (`nc-notification`, `email`, ...), `recipients` (`field` holding a Nextcloud uid,
  `groups`, `object-acl`, ...), per-locale `subject` and `message`, optional `actions`, and
  `originApp`. Users override `enabled` per rule through OpenRegister's
  `GET`/`PUT /api/notification-preferences`.
- `@conduction/nextcloud-vue` 2.40.0 `CnNotificationPreferences` reads and writes those
  preferences, scoped to the app whose settings are open (`cnAppId`), and mounts in
  `CnAppRoot`'s `#user-settings` slot.
- `userId` and `managerUserId` are copies of Nextcloud uids (`mss-team-scope`), which is what
  a `field` recipient needs; a record whose uid is empty simply notifies nobody.

## Goals / Non-Goals

**Goals**

- Every submit reaches the approver, every decision reaches the requester.
- Every rule is switchable by the employer (schema default) and by each user.

**Non-Goals**

- A humaniq notifier class. Gate 18 warns on one, ADR-031 forbids it.
- An admin page of its own for the schema defaults; OpenRegister's schema configuration holds
  them, and changing a default is a register configuration change.

## Decisions

### D1. Rules per schema

Rule keys follow `<schema>-<action>`: `leave-submitted`, `leave-approved`, `leave-rejected`,
`timesheet-submitted`, `timesheet-approved`, `timesheet-rejected`, `expense-submitted`,
`expense-approved`, `expense-rejected`, `expense-reimbursed`, `leave-transaction-approved`,
`leave-transaction-rejected`, `review-finalised`, `payslip-ready`. Each uses
`trigger: {type: "transition", action: "<action>"}` except `payslip-ready`
(`trigger: {type: "created"}`). `originApp: "humaniq"`. Recipients: `field` `managerUserId`
for submitted rules, `field` `userId` otherwise.

### D2. Channels and defaults

Submitted rules: `channels: ["nc-notification", "email"]`, `enabled: true`. Decision and
payslip rules: `["nc-notification"]`, `enabled: true`. Subjects in `nl` and `en`, sentence
case, no internal ids; for example `leave-rejected` has subject nl "Je verlofaanvraag is
afgewezen", en "Your leave request was rejected" and message with `{{rejectionReason}}`.

### D3. Preferences in the settings dialog

humaniq mounts `CnNotificationPreferences` in the `CnAppRoot` user-settings slot (its default
fallback), so a user sees exactly humaniq's rules and can switch each. No humaniq endpoint.

### D4. Payslip timing

A payslip object is created when a draft run is calculated, which is too early to tell the
employee. The `payslip-ready` rule therefore ships `enabled: false` by default with a note:
switch it on for administrations that create payslips only at approval, or wait for the
`payroll-run-as-a-flow` adoption to add an approve-time rule on the run. This keeps the rule
honest instead of notifying about a draft.

## Declarative-vs-imperative decision (ADR-031)

| behaviour | path | why |
|---|---|---|
| every notification | declarative `x-openregister-notifications` | the canonical engine |
| per-user switch | OpenRegister notification preferences, library component | no app code |

## Seed data

No new objects. The seeded leave request of an employee with a `managerUserId` produces a
`leave-submitted` notification when it is submitted on a dev instance, which is the live check.

## Risks / Trade-offs

- [Empty uid fields notify nobody] → `mss-team-scope` already audits `managerUserId`
  consistency (`nl-mss-manager-consistency`); the same gap now also means a silent
  notification, which the rule's note says.
- [Email volume for busy approvers] → users switch the email-bearing rule off for themselves.

## Open Questions

- Should `web-push` be added to the decision rules? It needs the engine's VAPID setup on the
  instance; left out until an administration asks.
