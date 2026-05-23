---
kind: code
depends_on: [employee-management, leave-administration, expense-management, performance-reviews, org-chart-basic]
---

# Proposal: Manager Self-Service Portal

## Why

Line-managers today face a false choice between data privacy and operational efficiency. They either receive full HR-admin rights (exposing them to GDPR Art. 5(1)(c) minimization violations and NEN 7510 segregation-of-duties violations) or receive nothing and must email HR for every leave approval, creating a bottleneck that blocks their team's ability to plan.

The current state leaves four critical manager workflows unaddressed:
1. **Leave approvals** — managers cannot approve/reject team leave requests; employees wait for HR
2. **Expense approvals** — managers cannot pre-check team expense claims; all go to finance
3. **Team visibility** — managers have no scoped view of their team's status (leave balance, sickness rate, contract end dates)
4. **Performance initiation** — managers cannot trigger performance review cycles; HR must manage the schedule

This spec defines a scoped manager role with org-chart-derived access control, surfaced as dashboard widgets + scoped actions in the relevant app modules (not a separate portal app — per ADR-001 Rule 2).

## What Changes

**New system role**: `manager` with transitive team-scope derived from OrgChart reporting structure.

**Extended entities**:
- `ApprovalRequest` — added manager decision fields (manager_decision, manager_comment, manager_decided_at, escalation_target)
- `ManagerDashboardConfig` — per-user widget preferences and alert thresholds

**New surfaces**:
- Dashboard widgets (leave pending, expense pending, team overview, verzuim, cost-center budget)
- Scoped actions in Verlof, Salarissen, Declaraties modules for manager-role users
- Manager detail view on employee records (read-only)

**New workflows**:
- Leave approval with inline comment and escalation
- Expense claim review with policy-flag warnings
- Performance review initiation with async-completion
- Verzuim monitoring with Wet Verbetering Poortwachter milestone tracking
- Cost-center budget forecasting and variance alerts

**Privacy fence**: Managers cannot query/access data outside their team scope; all out-of-scope requests return 403 with audit logging.

## Capabilities

### New Capabilities
- **manager-leave-approvals**: Line-managers approve/reject team leave requests with comments; leave-ledger updates on approval
- **manager-expense-review**: Managers review expense claims with policy-flag checks and approve/reject
- **team-overview**: Managers view team roster with status (active/on-leave/sick/off-boarding), contract end-date, leave balance, YTD verzuim%
- **verzuim-monitoring**: Managers monitor team sick-days, verzuim-percentage, meldingsfrequentie, and Wet Verbetering Poortwachter milestones; alerts on Verbaan-norm threshold
- **performance-review-initiation**: Managers initiate performance reviews for direct reports; drafts save asynchronously before HR submission
- **cost-center-budget-overview**: Cost-center owners view budget, YTD spent, commitments, variance%, and 3-month forecast
- **manager-scope-derivation**: System derives manager team-scope from OrgChart transitive reports_to relationship (configurable depth; default 1 level)
- **privacy-fence**: 403 on out-of-scope access; audit logging of access violations

### Modified Capabilities
- **leave-management-mvp**: Extended to support manager approval workflow before HR sign-off
- **dashboard**: Widget layout includes manager-role defaults (leave pending, team status, verzuim summary, budget alerts)
- **org-chart-basic**: Managers query their team via reporting_to relationship (read-only access control)

## Impact

- **Apps affected**: hrmq (Dashboard, Verlof & verzuim, Salarissen, Declaraties) + openregister
- **New entities**: ManagerDashboardConfig
- **Extended entities**: ApprovalRequest (manager_decision, manager_comment, manager_decided_at, manager_decided_by_user_id, escalation_target)
- **Breaking changes**: None (additive only)
- **Migration needed**: No; role is new and opt-in
- **Compliance**: Implements AVG Art. 5(1)(c) data minimization (manager scope fence) and NEN 7510 segregation of duties (manager role separate from admin)
- **Depends on**: employee-management, leave-administration, expense-management, performance-reviews, org-chart-basic must be implemented first

## Rollout Notes

Manager role is hidden until org-chart-basic is in place (to derive team scope). On first login, a manager gets the default dashboard layout (widgets ordered: leave pending, team status, verzuim, budget). Managers can customize widget order and alert thresholds in ManagerDashboardConfig. All manager actions are audit-logged.
