---
kind: config
---

# Leave against a department schedule

## Why

Cluster 70 of the round 4 competitor sweep is called "the not bucket": twenty
candidates a lane author rated `not`, meaning a municipal case system does not need
them. The sweep's own note on one of them reads: **"this is humaniq's job, not the
case system's"**.

That is the whole argument. `C-parties-and-contacts-11` is `not` for dossiq and it
is not `not` for humaniq. **Decision D17** settles it: the product serves a broad
market including MKB, and a `not` rating is not a disqualification. Rated for
humaniq's own market it is a `could`, and a small employer meets it on day one.
**Decision D21** admits it and requires it to be labelled, which the spec below does.

humaniq already has most of it. `leave-management` ships `LeaveRequest` with a
`submit`, `approve`, `reject` lifecycle, a `LeaveBalance` with statutory expiry, and
a `NoSelfApprovalGuard`. Two things are missing, and both are in the candidate's own
wording: leave is requested **against a type**, and it **lands on a department
schedule and is approved there**.

Today an approver sees one request at a time. Nothing tells them that approving it
leaves the afdeling with one person on the counter in the first week of August.

## The candidate, with its lane citation

| id | capability | relevance | driven passers | lane |
|---|---|---|---|---|
| C-parties-and-contacts-11 | Leave is requested against a type, lands on a department schedule and is approved there. | not, for dossiq | huly | `parties-and-contacts.tsv:15` |

Huly's evidence: `plugins/hr`, `RequestType` and `Request`. One driven passer, and
the lane author's note reads "Lane author rates relevance not; HR rather than
parties", which is the sentence that routes it here.

## What humaniq builds

- **A leave type that is administered, not enumerated.** `LeaveType` becomes an
  object: a code, a label, whether it draws from a balance, whether it needs a
  reason, whether it needs a document, and how far ahead it may be requested. Today
  the type is a plain enum on the request, so adding "zorgverlof" means editing a
  schema.
- **The approver's screen becomes a schedule.** A department view over a period
  showing every request, approved and pending, for one org unit at once, so the
  decision is made against the month rather than against a single row.
- **A coverage warning, not a refusal.** When approving would take an org unit below
  an administered minimum present on a date, the approver is told, with the names and
  the date, and may approve anyway. A hard refusal here would be wrong: a manager who
  knows the counter is closed that week needs the approval to land.
- **Approval from the schedule.** Approve and reject are available on the schedule
  view itself, driving the transitions `leave-management` already declares. No new
  edge is invented.

## How dossiq consumes it

It does not. This is the one change in humaniq's wave 3 with no dossiq consumer, and
that is the correct outcome of reading a `not` candidate under D17: it is built for
humaniq's own market, not for the parity ledger.

The one indirect link is real though. `agenda-rostering-and-resource-booking` answers
who is available, and approved leave is subtracted from that answer. A gemeente
planning a hoorzitting gets a better answer because this exists, without dossiq
knowing it does.

## The existing spec this extends

`leave-management`. The `LeaveRequest` lifecycle, the `NoSelfApprovalGuard` and the
`LeaveBalance` expiry rules are untouched. The `LeaveApproval` page specified there
keeps working: this change adds a second way to reach the same transitions, it does
not replace the queue.

`leave-calendar-nc` is unchanged and its AVG boundary holds. The department schedule
shows that a colleague is away, never the type of leave, unless the reader may
already see the request itself.

## Size and dependencies

**Size: S.** One schema, one migration of an enum to a reference, one view, one
warning.

**Depends on:** nothing. The coverage minimum is per org unit and does not need
`a-working-calendar-per-person`, though a later change could sharpen it by counting
contracted hours instead of heads.

## What this change does not do

It does not automate accrual, and it does not add CAO bovenwettelijk rules.
`leave-management` named both as explicit non-goals and `leave-accrual-job` owns the
first. Nothing here changes how a balance is filled.
