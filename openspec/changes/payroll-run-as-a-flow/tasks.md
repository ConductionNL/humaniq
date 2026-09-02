## 1. The nodes (code)

- [x] 1.1 Add `lib/Flow/PayrollFlowNodeBase.php` — shared IFlowNode metadata (icon, scope,
      validate-required-keys), the `{{ dotted.path }}` item renderer, lazy ObjectService
      resolution and run loading by id.
- [x] 1.2 Add `lib/Flow/PayrollCalculateNode.php` (`humaniq.payroll-calculate`) — period/
      administration/recalculate config, `PayrollRunService::runFor()`, outcome under
      `payroll`, throw on `failed`/`refused-not-draft`.
- [x] 1.3 Add `lib/Flow/PayrollApproveNode.php` (`humaniq.payroll-approve`) — draft-only guard,
      writes `status: approved` and nothing else.
- [x] 1.4 Add `lib/Flow/PayrollGlPostNode.php` + `lib/Flow/PayrollNetPayNode.php` — thin
      adapters over `postRun()` / `processRun()`, degradation outcomes pass through, `failed`
      throws.

## 2. Registration

- [x] 2.1 Add `lib/Flow/HumaniqFlowNodeListener.php` — the dossiq listener pattern (class-string
      list, container resolution, log-and-skip).
- [x] 2.2 Register it in `Application::boot()` behind `class_exists(RegisterFlowNodesEvent)`,
      matching the RegisterHoursLeafListener posture.

## 3. The shipped flow

- [x] 3.1 Add `configuration.x-openregister-flows` with the `Loonrun` flow to the `PayrollRun`
      schema in `lib/Settings/register.d/hr-objects.json` (design.md D4 graph, Dutch task
      copy, admin candidate group, no owner/enabled/schedule).

## 4. Test substrate

- [x] 4.1 Add `tests/stubs/OpenRegisterFlow/` — `IFlowNode` (verbatim tier) plus real-API
      mirrors for `FlowNodeRegistry`/`RegisterFlowNodesEvent`, provenance-tagged, loaded from
      `tests/bootstrap.php` behind `class_exists()`.
- [x] 4.2 Extend `psalm.xml` referencedClass suppressions and `phpstan.neon` excludePaths for
      the classes implementing the OR interface (the #289 lockstep rule).

## 5. Tests

- [x] 5.1 `tests/Unit/Flow/PayrollCalculateNodeTest.php` — outcome pass-through, default
      period, throw on `failed`/`refused-not-draft`, empty items short-circuit.
- [x] 5.2 `tests/Unit/Flow/PayrollApproveNodeTest.php` — draft flip, non-draft refusal,
      status-only write, missing run refusal.
- [x] 5.3 `tests/Unit/Flow/PayrollHandoffNodesTest.php` — glpost/netpay adapters: outcome
      routing incl. `skipped-no-shillinq`, throw on `failed`.
- [x] 5.4 `tests/Unit/Flow/HumaniqFlowNodeListenerTest.php` — all four register; one broken
      node is skipped, not fatal.
- [x] 5.5 `tests/Unit/Flow/PayrollFlowDeclarationTest.php` — the REQ-PRF-005 structural pins,
      including the no-self-scoping source scan (REQ-PRF-006).
- [x] 5.6 Full unit suite green; analyzers (phpcs, psalm, phpstan, phpmd per subdir)
      individually green on the diff.

## 6. Staged follow-ups (deliberately unchecked — design.md D7)

- [ ] 6.1 Pay-date wait: land a pay-date field on the administration surface, then an
      `openregister.wait` (or run-scoped FlowTimer) between approve and glpost.
- [ ] 6.2 Schedule adoption recipe on the admin/docs surface (`openregister.trigger-schedule`
      + explicit `runAs`), plus oversight registration if the scheduled flow qualifies.
- [ ] 6.3 Once adopted flows carry approval everywhere, guard the raw `status` edit so the
      recorded decision is the only approver.
