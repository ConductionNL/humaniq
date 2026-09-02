## 1. The seam (code)

- [x] 1.1 Add `lib/Standards/Checks/ProvidesTables.php` — capability interface
      (`tables(): array<objectType, array<ruleId, {derive: callable, table: array}>>`), mirroring
      the `SeedsObjects`/`UpsertsObjects` capability pattern.
- [x] 1.2 Add `lib/Standards/TableCheckEvaluator.php` — memoised `class_exists()` +
      direct-construction resolution of `OCA\OpenRegister\Service\Dmn\DecisionTableEvaluator`,
      `satisfied(table, inputs): bool` mapping `outputs['satisfied'] === true`, `RuntimeException`
      when OpenRegister is absent (fail closed), `reset()` test hook. No fallback matcher.
- [x] 1.3 In `RuleEngine::checks()`, merge table-declared checks from `ProvidesTables` providers
      into the registry, wrapped as predicates (`derive` null => vacuous pass; otherwise delegate).

## 2. The proof provider (code)

- [x] 2.1 Convert `NlLeaveChecks` to `ProvidesTables`: the four LeaveBalance rules as
      derivation + table per design.md's mapping table; `checks()` returns empty.
- [x] 2.2 Keep the legacy predicates as `legacyChecks()` — the parity oracle, documented as
      unregistered and scheduled for staged retirement (REQ-RULE-009).

## 3. Test substrate

- [x] 3.1 Vendor OR's pure Dmn classes verbatim (openregister@d1594ccd) into
      `tests/stubs/OpenRegisterDmn/` (`DecisionEvaluationException`, `UnaryTestEvaluator`,
      `DecisionTableEvaluator`), rewriting only the OR-repo `@spec` anchors to provenance notes.
- [x] 3.2 Load them from `tests/bootstrap.php` behind `class_exists()` so the real classes always
      win on a live instance.

## 4. Tests

- [x] 4.1 `tests/Unit/Standards/TableCheckEvaluatorTest.php` — satisfied mapping, non-`true`
      output is a violation, evaluator errors propagate, memoisation/reset.
- [x] 4.2 `tests/Unit/Standards/NlLeaveTableParityTest.php` — the pinned fixture matrix through
      BOTH the table path (real evaluator semantics via the vendored classes) and
      `legacyChecks()`; verdicts identical per rule per fixture (REQ-RULE-009).
- [x] 4.3 Update `tests/Unit/Standards/Checks/NlLeaveChecksTest.php` to drive all four rules
      through `RuleEngine::evaluate('LeaveBalance', ...)` (registry path end-to-end) instead of
      raw closures.
- [x] 4.4 Full unit suite green; analyzers (phpcs, psalm, phpstan, phpmd per subdir) individually
      green on the diff.

## 5. Re-scope humaniq-rule-compliance-enforcement (docs only)

- [x] 5.1 Amend its `design.md` Option C: the OR-side substrate now partially exists
      (`openregister.decision-table` + shared Dmn evaluator, openregister#3329); name the two
      build-on paths (flow with inline table; a future guard calling the already-delegating
      `RuleEngine`).
- [x] 5.2 Add the standing constraint to its design and tasks: enforcement work builds on the
      shared evaluator through `ProvidesTables`; no new in-app matching/hit-policy/cell-grammar
      machinery; new tabular rules are authored as decision tables.

## 6. Staged retirement (deliberately unchecked — proof before deletion)

- [ ] 6.1 On an OpenRegister-backed environment, run `occ humaniq:rules:audit` before and after
      this change over seeded data covering the four converted rules; verdicts must match.
- [ ] 6.2 After 6.1 is proven, retire `NlLeaveChecks::legacyChecks()` and rewrite the parity test
      into a table-behaviour pin (fixtures + expected verdicts, no oracle).
- [ ] 6.3 Next conversion wave: sweep the remaining 36 providers for tabular shapes (thresholds,
      enumerations, boolean gates over derived values) and convert them provider-by-provider with
      the same parity discipline; leave genuinely non-tabular predicates as closures.
