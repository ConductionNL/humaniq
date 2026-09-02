## 1. Correct the misleading docblock (code)

- [ ] 1.1 In `lib/Standards/RuleEngine.php`, remove the sentence claiming "the lifecycle wiring +
      object loading live in `OCA\Humaniq\Lifecycle\RuleComplianceGuard`" (no such class exists).
- [ ] 1.2 Replace it with an accurate statement: the engine is advisory/reporting-only today,
      consumed by `occ humaniq:rules:audit`; write-time enforcement is tracked as a design decision
      (link `design.md` of this change) rather than implemented.

## 2. Make the audit actionable (code)

- [ ] 2.1 In `lib/Command/RulesAuditCommand.php::execute()`, after printing the report, return a
      non-zero exit code (e.g. `1`) when
      `$report['violationsBySeverity']['mandatory'] > 0`; return `0` otherwise (matching current
      behaviour when there are no mandatory violations).
- [ ] 2.2 Add a one-line note to the command's `setDescription()` / help output documenting the
      non-zero-on-mandatory-violations exit-code contract, so CI/ops scripts wiring it in know what
      to expect.
- [ ] 2.3 `php -l` + `composer check:strict` on the changed files.

## 3. Verify

- [ ] 3.1 Run `occ humaniq:rules:audit` against a register with zero mandatory violations — confirm
      exit code `0`.
- [ ] 3.2 Run it again against register data seeded/edited to trigger at least one mandatory
      violation (e.g. via `occ humaniq:rules:seed-testdata` on a fresh, not-yet-backfilled register,
      or by manually clearing a mandatory field) — confirm exit code non-zero.
- [ ] 3.3 Confirm `occ humaniq:rules:seed-testdata` (a separate command, unaffected by this change)
      still runs and exits `0` as before.

## 4. Follow-up (tracked, not built here)

- [ ] 4.1 File a cross-cutting note (fleet-level, in the parent review's report — not a hydra ADR
      authored by this change) proposing OpenRegister investigate a schema-declarative "pre-save
      validation" **hook point** (`design.md` Option C). The evaluation substrate itself now
      exists — OpenRegister's shared decision-table capability (openregister#3329), consumed by
      humaniq via the `ProvidesTables` seam from `rules-onto-or-decision-tables` — so the ask is
      only the hook, never a second evaluator.
- [ ] 4.2 Any enforcement implementation that follows honours the standing constraint recorded in
      `design.md`: build on `RuleEngine` (which already delegates tabular matching to
      OpenRegister's `Dmn\DecisionTableEvaluator`) or on `openregister.decision-table` flow
      steps; author new tabular rules as decision tables via `ProvidesTables`; introduce no new
      in-app matching, hit-policy or cell-grammar machinery.
