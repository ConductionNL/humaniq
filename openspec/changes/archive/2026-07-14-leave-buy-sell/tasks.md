# Tasks — leave-buy-sell

> Verify against HEAD, not this brief — `leave-verzuim-mvp` (LeaveRequest/LeaveBalance/
> NoSelfApprovalGuard) and `payroll-core-engine`/`retro-adjustments` (PayrollRunService fold
> pattern) must already be merged/archived (they are, at HEAD 2026-07-15).

- [x] 1. Schema: `LeaveTransaction` appended to `lib/Settings/register.d/hr-leave.json`
  (`employeeId`, `transactionType`, `year`, `leaveType`, `hours`, `hourlyRate`, `settledAmount`,
  `settlementPeriod`, `settlementPayrollRunId`, `status`, timestamp/actor fields, `userId`) with
  `x-openregister-lifecycle` (`draft→submitted→approved/rejected→settled`, `terminal: [settled]`)
  per REQ-BUYSELL-001; register version bump
- [x] 2. Guard: `lib/Lifecycle/LeaveBuySellApprovalGuard.php` on `approve` — delegates to
  `NoSelfApprovalGuard` first, then for `transactionType: sell` resolves the `LeaveBalance` and
  denies when unresolvable or `bovenwettelijkHours < hours` per REQ-BUYSELL-001/-002
- [x] 3. Guard: `lib/Lifecycle/LeaveSettlementPeriodGuard.php` on `settle` — fail-closed unless
  `settlementPeriod` present and `YYYY-MM`-shaped, stateless (`CompEffectiveDateGuard` shape) per
  REQ-BUYSELL-004
- [x] 4. Rule: `nl-verlof-bovenwettelijk-niet-negatief` in `lib/Standards/rules/labour.json`
  (`LeaveBalance.bovenwettelijkHours >= 0`, mandatory) + `RuleCatalogue::VERSION` bump per
  REQ-BUYSELL-003
- [x] 5. Check: new predicate in the existing `lib/Standards/Checks/NlLeaveChecks.php` implementing
  the D3 rule, vacuous-scope-free (LeaveBalance is always in scope) per REQ-BUYSELL-003
- [x] 6. Service: `lib/Service/LeaveBuySellSettlementService.php::settle()` — idempotent
  (already-settled no-op), refuses not-approved/no-settlement-period/balance-unresolvable/
  insufficient-bovenwettelijk (sell), computes `settledAmount`, writes ONLY
  `LeaveBalance.bovenwettelijkHours` + the transaction's settle stamp, per REQ-BUYSELL-002/-004
- [x] 7. Service: `lib/Service/PayrollRunService.php` gains
  `settledLeaveTransactionsByEmployeeId(period)` (sums signed settled amounts, cents-exact, keyed
  by employeeId, degrades to empty map if schema absent) + `leaveBuySellFields()` folded into
  `generate()` alongside the existing sick-pay/retro-adjustment folds, `totals['net']` updated per
  REQ-BUYSELL-005
- [x] 8. Schema: nullable `leaveBuySell` on `Payslip` (`lib/Settings/register.d/hr-objects.json`,
  mirrors `retroAdjustment`) + version bump per REQ-BUYSELL-005
- [x] 9. Command: `lib/Command/LeaveBuySellSettleCommand.php` (`hrmq:leave:settle --id
  <transactionId>`) + register in `appinfo/info.xml` per REQ-BUYSELL-004
- [x] 10. Controller: `settle` action (`#[NoAdminRequired]`, RBAC-resolve-first → 404,
  non-approved/no-settlement-period → 400, delegate to the service) + route in
  `appinfo/routes.php` BEFORE the SPA catch-all per REQ-BUYSELL-004
- [x] 11. Manifest: `EmployeeDetail` gains `emp-leave-transactions` FK-scoped object-list row
  (after `emp-comp-adjustments`, before Files, which shifts down) per REQ-BUYSELL-006
- [x] 12. Manifest: new `LeaveTransactionDetail` page — data widget (exclude
  `employeeId`/`settlementPayrollRunId`), related widget, `lifecycleActions` exposing EXACTLY
  `submit`/`approve`/`reject` (no `settle` button), the guarded "Verrekenen" `api-call` action
  (`POST /api/leave/settle`), audit sidebar tab per REQ-BUYSELL-004/-006
- [x] 13. Manifest: new self-service index `MijnVerlofKopenVerkopen` (`filter: {userId: "@me"}`)
  added as a child of the EXISTING `MijnHrGroup` menu (no new top-level menu/group); deepLink +
  `src/icons.js` icon registration per REQ-BUYSELL-006; `npm run check:manifest` passes
- [x] 14. Tests: `LeaveBuySellApprovalGuardTest` (self-approval denial via delegation, sell
  sufficiency allow/deny, buy always allows, unresolvable balance denies) per REQ-BUYSELL-001/-002
- [x] 15. Tests: `LeaveSettlementPeriodGuardTest` (missing/malformed/valid `settlementPeriod`) per
  REQ-BUYSELL-004
- [x] 16. Tests: `LeaveBuySellSettlementServiceTest` (mocked ObjectService: idempotent re-settle,
  every refusal branch, correct signed `bovenwettelijkHours` write for buy vs sell, `settledAmount`
  computation) per REQ-BUYSELL-002/-004
- [x] 17. Tests: `PayrollRunServiceTest` extension (settled transaction folds into `leaveBuySell` +
  `nettoPay`; unsettled/wrong-period transaction does not fold; zero settled ⇒ payslip
  byte-identical to before) + `NlLeaveChecksTest` extension (new predicate) per REQ-BUYSELL-003/-005
- [x] 18. Quality gates: `composer lint` + full PHPUnit suite green, `npm run check:manifest`
  PASS, `npm run build` green; SPDX + `@spec` tags on every new/changed PHP method (gate-16); i18n
  keys ENGLISH (ADR-007), Dutch strings only in manifest labels/messages/guard deny reasons per
  existing convention

Acceptance criteria (plain reminders, not tasks):
- `grep`-verifiable: no code path in this change ever writes `LeaveBalance.entitledHours` or
  `LeaveBalance.usedHours` — only `bovenwettelijkHours`
- `settle` is never exposed as a bare `lifecycleActions` button anywhere in the manifest — only the
  guarded `api-call`
- `PayrollRunService`'s existing sick-pay/retro-adjustment behaviour and the `remainingHours`
  calculation are byte-identical to before this change when no `LeaveTransaction` is settled
- endpoint params come from the manifest action exactly as `{transactionId: "@objectId"}` — keep
  names in sync
