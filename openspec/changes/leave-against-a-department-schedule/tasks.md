## 1. The leave type

- [ ] 1.1 Add the `LeaveType` schema in `lib/Settings/register.d/`: `code`, label,
      `drawsFromBalance`, `requiresReason`, `requiresDocument`, `maxNoticeDays`,
      `active`.
- [ ] 1.2 Seed one type per value of the current `leaveType` enum, so existing
      requests resolve by code.
- [ ] 1.3 Move `LeaveRequest.leaveType` from an enum to a reference; keep the stored
      code readable on requests that already exist.
- [ ] 1.4 Manifest page for leave types, under the existing Verlof en verzuim group.

## 2. The type's conditions

- [ ] 2.1 Refuse `submit` when a required reason or document is missing, or the
      start is further ahead than `maxNoticeDays`, naming the failed condition.
- [ ] 2.2 Confirm a draft is saved without the checks.
- [ ] 2.3 Skip the balance post when `drawsFromBalance` is false.

## 3. The department schedule

- [ ] 3.1 Compose the schedule on read from `LeaveRequest`, for one org unit and a
      date range. Store nothing.
- [ ] 3.2 Apply the existing team-scope rules for who may see which request; show
      unavailability only to a reader who may not see the request.
- [ ] 3.3 Manifest page for the schedule.

## 4. Coverage

- [ ] 4.1 Add the administered minimum present per org unit per weekday.
- [ ] 4.2 Warn before approval with the dates, the count, the minimum and the names.
- [ ] 4.3 Record the accepted warning on the request when the approver proceeds.
- [ ] 4.4 Confirm approval is never refused on coverage grounds.

## 5. Approving from the schedule

- [ ] 5.1 Wire approve and reject on the schedule to the declared transitions.
- [ ] 5.2 Confirm the manifest transitions still match the lifecycle
      action-for-action.
- [ ] 5.3 Confirm `NoSelfApprovalGuard` refuses from the new surface too.

## 6. Verification

- [ ] 6.1 Unit tests for the type conditions, the coverage count and the scope
      filter.
- [ ] 6.2 e2e coverage or a reason-bearing exclusion per scenario, per gate 19.
- [ ] 6.3 `npm run check:manifest` exits 0.
