# leave-accrual-job — delta for humaniq-personal-dashboard

## ADDED Requirements

### Requirement: The job SHALL stamp the denormalized `userId` on every LeaveBalance it writes (REQ-ACCR-006)

`LeaveAccrualJob` — the `LeaveBalance` schema's sole systematic writer — SHALL set `userId`
from the resolved Employee's `nextcloudUserId` on **both** its balance-create path and its
monthly balance-update path, so a pre-existing balance (created before the property existed)
self-heals on the next accrual run without a dedicated repair step. An employee with no linked
account yields `userId: null` (fail-closed for `@me` surfaces; the row is skipped-from-display,
never mis-attributed), following the job's existing counted-not-per-row-logged skip
conventions. `userId` remains a pure denormalization of the employee link (mijn-hr-self-service
REQ-MHS-002); nothing else about accrual math, idempotency (REQ-ACCR-004), or the operator
toggle (REQ-ACCR-005) changes. The buy/sell settlement path mutates `usedHours` on existing
rows and never creates balances; it needs no stamping logic.

#### Scenario: A newly created balance carries the account link

@e2e exclude BackgroundJob write path with no UI surface; verified by the LeaveAccrualJob unit tests (tasks.md 2.3)
- **GIVEN** an active employee whose `nextcloudUserId` is "admin" and no balance for the
  current year
- **WHEN** the accrual job runs
- **THEN** the created LeaveBalance carries `userId: "admin"`

#### Scenario: A pre-existing null userId self-heals on the next accrual

@e2e exclude BackgroundJob write path with no UI surface; verified by the LeaveAccrualJob unit tests (tasks.md 2.3)
- **GIVEN** an existing current-year balance with `userId: null` whose employee's
  `nextcloudUserId` is "admin"
- **WHEN** the accrual job processes its next monthly slice for that balance
- **THEN** the updated balance carries `userId: "admin"`

#### Scenario: An unlinked employee's balance stays null

@e2e exclude BackgroundJob write path with no UI surface; verified by the LeaveAccrualJob unit tests (tasks.md 2.3)
- **GIVEN** an active employee with no `nextcloudUserId`
- **WHEN** the accrual job creates or updates that employee's balance
- **THEN** `userId` is `null` — never guessed, never another account
