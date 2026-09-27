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
