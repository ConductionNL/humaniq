## 0. The candidate that is already met

- [x] 0.1 Confirm `hours-leaf` already specifies the running timer, its survival
      across navigation and the one-running-entry rule, so C-reporting-12 needs no
      build. Recorded in the proposal, no code.

## 1. The estimate object

- [x] 1.1 Add the `TimeEstimate` schema in `lib/Settings/register.d/`:
      `domainObjectType`, `domainObjectRef`, `estimatedHours`, optional `role`,
      `enforced` defaulting to false.
- [x] 1.2 Refuse a second estimate for the same object and role.

## 2. The derived remainder

- [x] 2.1 Derive remaining as estimated minus booked, on read, per role and in total.
- [x] 2.2 Report an overrun as a negative remainder rather than clipping it to zero.
- [x] 2.3 Store no remaining figure anywhere.

## 3. The leaf

- [x] 3.1 Add estimated and remaining to the KPI tile, beside the existing total.
- [x] 3.2 Add the no-estimate state: spent, a statement that no estimate is set, no
      remaining figure.
- [x] 3.3 Leave the running-timer presentation untouched.
- [x] 3.4 Keep both halves of the leaf in agreement, per the existing requirement.

## 4. The ceiling

- [x] 4.1 Refuse a new entry past an `enforced` estimate in the write path, naming
      the estimate, the ceiling and the hours left.
- [x] 4.2 Scope the refusal to the matching role.
- [x] 4.3 Let a timer stop land past the ceiling and record the overrun.

## 5. Verification

- [x] 5.1 Unit tests for the derivation, the negative remainder, the role-scoped
      refusal and the timer-stop exception.
- [x] 5.2 e2e coverage or a reason-bearing exclusion per scenario, per gate 19.
- [x] 5.3 `npm run check:manifest` exits 0.
