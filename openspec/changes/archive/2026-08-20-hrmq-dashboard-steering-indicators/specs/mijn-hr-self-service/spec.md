## REMOVED Requirements

### Requirement: The Dashboard page SHALL surface self-service and approver KPIs from the built-in widget set

**Reason**: The Dashboard's widget set is fully superseded by `hrmq-dashboard-steering-indicators`
(REQ-DSI-001), which replaces every `stat`/`object-table` queue-depth widget — including the six
this requirement described — with six trend/obligation indicators. The self/approver KPI shape
this requirement pinned (queue depth, no trend) is exactly the pattern that change's own governing
rule removes.

**Migration**: The four self-service index pages this requirement's KPIs deep-linked to
(`MijnUren`, `MijnDeclaraties`, `MijnVerlof`, and the approval queues `TimesheetApproval` /
`ExpenseApproval`) are unchanged and remain reachable from the `Mijn HR` menu group and the
existing approval-queue menu entries — only their Dashboard mirror is removed. No data, page, or
route this requirement referenced is deleted; only the Dashboard's copy of the same counts is.
