# Design: leave against a department schedule

## D1. Why a `not` candidate is being built at all

The lane author rated `C-parties-and-contacts-11` `not` while reading dossiq. That
rating is correct: a zaaksysteem should not administer verlof. The note beside it
says where the capability does belong, and it says humaniq.

Decision D17 makes that a build instruction rather than a footnote. The rating
travels with the reader, not with the capability, and a `not` for a case system is
ordinary for an HR product. Decision D21 requires the admission to be visible, so the
spec below carries the rating and the reason on its face. Nobody reading it later
should have to work out why a `not` candidate turned into a requirement.

## D2. The type is an object, because the list is a customer's

`LeaveRequest.leaveType` is an enum today. Every employer has a different list:
wettelijk, bovenwettelijk, zorgverlof, calamiteitenverlof, ouderschapsverlof,
onbetaald, and a few that only one CAO has heard of.

An enum makes each of those a schema change, and a schema change is a release. A
`LeaveType` object makes it an administration act. Huly reaches the same shape with
`RequestType` beside `Request`.

The type carries behaviour, not just a label:

| Field | What it decides |
|---|---|
| `drawsFromBalance` | whether approval posts to `LeaveBalance` |
| `requiresReason` | whether the request may be submitted without one |
| `requiresDocument` | whether a file must be attached |
| `maxNoticeDays` | how far ahead it may be requested |
| `active` | whether it is offered on new requests |

Existing requests keep their enum value as the new type's code, so the migration is a
lookup and no request loses its meaning.

## D3. The schedule is a view, not a new object

The department schedule answers: for this org unit, between these dates, who has
asked for leave and who has been given it. Every fact in it already exists on
`LeaveRequest`.

Storing a schedule would mean keeping a second copy in step with approvals,
withdrawals and rejections, and the AVG boundary would need re-enforcing on the copy.
So it composes on read, the same decision `agenda-rostering-and-resource-booking`
makes for the agenda, and for the same reason.

## D4. Coverage warns, it does not refuse

Three options for what happens when approving would empty an afdeling.

1. **Refuse.** Wrong. A manager who has arranged cover elsewhere, or who knows the
   balie is closed that week, is blocked by a rule that knows less than they do.
2. **Say nothing.** The situation today, and the reason a gemeente discovers the
   first week of August in the first week of August.
3. **Warn, with the facts, and let the human decide.**

Option 3. The warning names the date, the number present after approval, the
administered minimum and who else is away. It is recorded on the request when the
approver proceeds anyway, so the decision has a trail. That trail is the difference
between a warning and a nag.

The minimum is per org unit and per weekday, so a team that needs two people on
Monday and one on Friday can say so.

## D5. Approval from the schedule drives the existing edges

`leave-management` specifies exactly three transitions: `submit`, `approve`, `reject`,
guarded by `NoSelfApprovalGuard`. The schedule offers approve and reject and calls
those, unchanged.

The requirement in `leave-management` that the manifest's transitions match the
declared lifecycle action-for-action still holds, and this change must not add an
edge to satisfy a screen. A second surface onto one lifecycle is the whole design;
a second lifecycle would be a bug.

## D6. What the schedule shows to whom

A reader who may see a `LeaveRequest` sees it in full. A reader who may not sees that
the person is unavailable and nothing else, matching the boundary
`leave-calendar-nc` already draws for the Nextcloud calendar.

The team-scope rules in `mss-team-scope` decide which requests a manager may see.
This change reuses them and does not open a wider window: a schedule that quietly
showed a manager another department's leave would be a disclosure, not a feature.
