# Design: one approvals inbox, and a deputy while the manager is away

## Context

Read at `development` af702f78.

- Team queues: `TeamUrengoedkeuring`, `TeamVerlofgoedkeuring`, `TeamDeclaratiegoedkeuring`
  (`src/manifest.d/05-menu.json:242`, `:287`, `:369`), each filtering its schema on
  `managerUserId: "@me"` and `status: submitted`. `MijnHr` has a te-beoordelen widget
  (`src/manifest.d/personal-dashboard.json:71`).
- `managerUserId` is a denormalised uid on `LeaveRequest`, `Timesheet`, `Expense`
  (`mss-team-scope`); `TimesheetProcessStampListener` stamps it for timesheets through
  `OrgResolutionService::resolveManagerUserIds()` (`lib/Service/OrgResolutionService.php:110`).
  mss-team-scope's non-goals: no org-derived approval authorization; `NoSelfApprovalGuard`
  untouched.
- Lifecycles: `submit`, `approve`, `reject` on all three plus `LeaveTransaction`;
  `submittedAt`, `approvedAt`, `approvedBy`, `rejectionReason` fields on most.
- OpenRegister's notification dialect allows `recipients: [{kind: "expression", resolver:
  "<FQCN>"}]` implementing `RecipientResolverInterface` (hydra ADR-031).
- `RbacObjectReader` reads under the caller's own rights.

## Goals / Non-Goals

**Goals**

- A manager and a deputy see every waiting request in one place and act on it.
- A deputy period starts and ends by itself.

**Non-Goals**

- A new approval engine; the lifecycles stay the ones each schema declares.

## Decisions

### D1. Compose on read

`ApprovalsInboxService::open(uid, today)` reads `submitted` rows of the approvable schemas whose
`managerUserId` is the caller or a manager the caller deputises for today, through
`RbacObjectReader`, and maps them to `{schema, id, employee, kind, from, to, submittedAt,
waitingDays, route}`. `decided(uid)` reads rows the caller decided (`approvedBy` or the audit
trail's actor on `reject`) in the last 90 days, with their timeline from OpenRegister's audit
entries. Alternative considered: a stored task object per request. Rejected: it would drift from
the requests it mirrors.

### D2. `ManagerDeputy`

`managerUserId`, `deputyUserId`, `from`, `until`, `administrationId`, `note`. A pre-save listener
refuses a deputy who is the manager, and an `until` before `from`. Records are created by the
manager on `MijnVervangers` or by HR.

### D3. Notifications reach the deputy

The submit rules of `platform-notifications` gain a second recipient
`{kind: "expression", resolver: "OCA\\Humaniq\\Notification\\ManagerOrDeputyRecipientResolver"}`
returning the active deputies of the request's `managerUserId`.

### D4. Acting from the inbox

Approve and reject in the inbox call the same OpenRegister lifecycle transitions as the team
pages, so `NoSelfApprovalGuard` and every other guard still applies.

## Declarative-vs-imperative decision (ADR-031)

| behaviour | path | why |
|---|---|---|
| deputy record | declarative schema | data |
| inbox composition | imperative service | a read across schemas with deputy resolution |
| deputy recipients | declared notification rule with an expression resolver | the engine's extension point |
| approve and reject | existing declarative lifecycles | reuse |

## Seed data

- A `ManagerDeputy` making the seed HR user deputy of the Burgerzaken manager for the current
  month.

## Risks / Trade-offs

- [A deputy without read rights on the team] → the inbox reads through the deputy's own rights,
  so a missing right shows as a missing row, not a leak; the deputy page warns when the deputy
  cannot read the manager's team.

## Open Questions

- Should HR be able to set a deputy for a manager who is off sick? Yes by default in this design,
  since HR may create the record.

## Build-time changes (2026-09-29)

Read against `development` 82dd772d plus humaniq#560 while building.

- **Decisions are stamped, not read from the audit trail.** No listener stamped `approvedBy`
  or `approvedAt` on `LeaveRequest`, `Expense` or `LeaveTransaction`, so the decided view had
  nothing to read. `ApprovalDecisionStampListener` stamps `submittedAt` on a submit (and
  clears an earlier verdict), and `approvedBy` and `approvedAt` on an approve or reject, on
  those three. `Timesheet` keeps its own `TimesheetProcessStampListener`. A decision made
  before this change carries no stamp and does not show in the decided view.
- **The submit rules are declared here.** `platform-notifications` is not built, so this
  change declares the four submitted rules itself, with that change's keys and channels:
  `leave-submitted`, `timesheet-submitted`, `expense-submitted` and
  `leave-transaction-submitted`, each `{type: transition, action: submit}` on
  `nc-notification` and `email`. Each has one recipient,
  `ManagerOrDeputyRecipientResolver`, which returns the manager and the active deputies.
  One recipient rather than a field recipient plus the resolver, so a manager is not told
  twice. `platform-notifications` keeps the decision and payslip rules.
- **A request without a stamped manager.** `LeaveTransaction` has no `managerUserId`, and
  older claims may lack one. `ManagerDeputies::managersOf()` then takes the employee's unique
  manager from the org chart on the day of the read. The requester's own account is never
  an approver.
- **Who may write a deputy record.** `ManagerDeputyListener` also refuses a writer who is
  neither the record's manager nor HR, and fills in the writer as manager when the field is
  empty. Without that check, anyone could make themselves a manager's deputy and receive the
  submit notifications.
- **The inbox is a dashboard with a host widget, not a custom page.** Gate 69 forbids a new
  `type: custom` page. The library's `object-table` resolves to `CnObjectListWidget`, which
  ignores `endpointSource` in nextcloud-vue 2.57.1, has one static `rowRoute`, and its
  `api-call` row actions carry no row token. So `MijnGoedkeuringen` is a dashboard with two
  `approvals-inbox` widgets (`state: open`, `state: decided`), registered with a
  `@custom-widget-ratchet exclude` reason. The widget posts the OpenRegister transition.
- **My deputies** is a menu preset on the `ManagerDeputies` index (`managerUserId = @me`),
  not a second index page. The warning for a deputy who cannot read the manager's team is
  not built: a row the deputy may not read is simply absent.
- **Seed.** A fixed period, 14 July to 1 August 2026, with `hr-demo` standing in for `admin`,
  rather than the current month, because a seed cannot move with the calendar.
