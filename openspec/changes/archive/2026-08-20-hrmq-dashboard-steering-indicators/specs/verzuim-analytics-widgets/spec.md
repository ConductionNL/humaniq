## REMOVED Requirements

### Requirement: The Dashboard SHALL gain four absence-analytics stat widgets using only verified aggregation shapes

**Reason**: All four widgets this requirement described (`dash-verzuim-open`,
`dash-verzuim-langdurig`, `dash-leave-pending`, `dash-leave-hours-month`) are queue depths or a
single-month sum — exactly the pattern `hrmq-dashboard-steering-indicators`'s governing rule
removes. The requirement's own text already named the reason a *trend* was not shipped instead:
whole-day absence counting could not support one. That reason no longer holds — `absence-rate`
(landed on this branch) supplies the FTE-weighted rate calculation, and
`hrmq-dashboard-steering-indicators` REQ-DSI-004 is its first consumer. This is the literal
resolution the capability's own narrative anticipated: *"the data-shape reason its trend charts
were declared non-goals no longer holds. This change does not add those charts — it removes the
blocker."* — `hrmq-dashboard-steering-indicators` is the change that adds them.

**Migration**: `dash-verzuim-open` and `dash-verzuim-langdurig`'s underlying data remains reachable
via the unchanged `VerzuimOverzicht` page (REQ-VZA-002, not modified by this change).
`dash-leave-pending`'s queue is reachable via the existing `LeaveApproval` page.
`dash-leave-hours-month`'s single-month sum has no direct replacement — the Absence-rate trend
widget (REQ-DSI-004) supersedes it as the dashboard's verzuim/leave indicator, and a caller wanting
this month's approved-leave-hours figure specifically uses the `LeaveRequests` index page's own
filters. No schema, page, or route is removed.
