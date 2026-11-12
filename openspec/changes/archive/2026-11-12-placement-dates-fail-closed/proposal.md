---
kind: code
---

# An unreadable placement date counts as not active

## Why

`OrgResolutionService::isActiveOn` read a placement date it could not parse as "no
bound", so a placement with the start date "sinds kort" or the end date "tot nader
order" counted as active for ever. Every manager stamp and approval queue is resolved
through it, so such a placement could route a timesheet, a leave request or an expense
claim to a manager it should not reach.

Ruben decided on 3 November 2026 that the shared "is manager" check goes strict
everywhere, approvals included.

## What changes

- A `startDate` or `endDate` that is set but cannot be read makes the placement inactive.
  A missing or empty date stays no bound.
- Every caller of `isActiveOn` follows, through `resolveManagerUserIds`,
  `resolveCostCenters` and the direct calls.

## Impact

A record whose only placement has an unreadable date gets no `managerUserId` stamp and no
cost centre: it lands in no team queue and waits for HR, as an employee without a manager
already does.
