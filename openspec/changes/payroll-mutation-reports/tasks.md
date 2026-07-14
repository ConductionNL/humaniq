# Tasks — payroll-mutation-reports

> Depends on `payroll-core-engine` — do not start until it is merged (computed PayrollRuns,
> Payslip component cents fields and the `payrollRunId` `$ref` must exist at HEAD). Verify against
> HEAD, not this brief.

- [ ] 1. Schema: add `PayrollMutationReport` to `lib/Settings/register.d/hr-objects.json`
  (slug `PayrollMutationReport`, the design.md field table: fromRunId/toRunId/fromPeriod/toPeriod/
  administrationId/generatedAt + the five run-level deltas + four counts + `lines` array;
  required toRunId/toPeriod/administrationId/generatedAt) per REQ-MUT-005
- [ ] 2. Service: `lib/Service/PayrollMutationService.php` — resolve `from`/`to` runs via
  ObjectService (`runsById` idiom), load each run's `payrollRunId`-scoped Payslips
  (`auditPayrollRunScope` precedent), key both by `employeeId`, pure PHP, no calculator per REQ-MUT-001
- [ ] 3. Service: per-employee classification entered/left/changed/unchanged (set membership +
  headline-component equality) per REQ-MUT-001
- [ ] 4. Service: per-component integer-cents deltas (grossPay/nettoPay/loonheffing/
  employerCost=werknemersverzekeringen+zvw) for shared employees, `before/after/delta` per line
  per REQ-MUT-002
- [ ] 5. Service: run-level roll-ups over the union — grossDelta/netDelta/loonheffingDelta/
  employerCostDelta, `totalWageCostDelta = grossDelta + employerCostDelta`, and the four counts +
  `changedEmployeeCount` per REQ-MUT-003
- [ ] 6. Service: prior-run auto-resolution (`--to` alone → closest earlier period, same
  administration) + same-administration guard (refuse cross-administration diff) per REQ-MUT-006
- [ ] 7. Service: first-run path — no prior run → every `to` employee `entered`, before=0,
  deltas equal the `to` totals, `fromRunId` null, still produced/persistable per REQ-MUT-007
- [ ] 8. Service: idempotent persist — probe `PayrollMutationReport` by `(fromRunId, toRunId)`,
  upsert in place vs create, stamp `generatedAt`, serialise `lines` per REQ-MUT-005
- [ ] 9. Command: `lib/Command/PayrollMutationsCommand.php`
  (`hrmq:payroll:mutations --from <runId> --to <runId> [--persist]`, `--to` alone auto-resolves
  prior), print the entered/left/changed table + run-level deltas, register in `appinfo/info.xml`
  per REQ-MUT-004
- [ ] 10. Controller: `PayrollController::mutations` — `#[NoAdminRequired]` + explicit admin/HR
  authorization check (403 for non-admin), RBAC-resolve the run(s) first (unknown/unauthorized →
  404), cross-administration → 400, delegate to the service, return the report id;
  route in `appinfo/routes.php` BEFORE the SPA catch-all per REQ-MUT-008
- [ ] 11. Manifest: `PayrollMutations` report page (list persisted reports) +
  `PayrollMutationReportDetail` (run-level deltas as stat KPIs + per-employee mutation table),
  admin-scoped under the Payroll nav group per REQ-MUT-008
- [ ] 12. Manifest: `PayrollRunDetail` "Mutatieoverzicht" `api-call` action
  (`params: {toRunId: "@objectId"}`, onSuccessRoute `PayrollMutationReportDetail`);
  `npm run check:manifest` passes per REQ-MUT-008
- [ ] 13. Tests: `tests/Unit/Service/PayrollMutationServiceTest.php` (mocked ObjectService) —
  the design.md worked example (A changed / B left / C entered → the exact run-level deltas),
  per-component cents deltas, first-run-all-entered, idempotent upsert, same-administration guard
  per REQ-MUT-009
- [ ] 14. Quality gates: `composer lint` green, PHPUnit suite green (php:8.3-cli),
  `npm run check:manifest` PASS, `npm run build` green; SPDX + `@spec` tags on every new/changed
  PHP method (gate-16); i18n keys ENGLISH, Dutch only in manifest labels/messages

Acceptance criteria (plain reminders, not tasks):
- the diff is pure subtraction of persisted payslip cents — the service NEVER constructs
  `PayrollCalculator`, reads tables, or writes any PayrollRun/Payslip (reads only; writes only
  `PayrollMutationReport`)
- `lib/Service/` uses ObjectService only, no HTTP; never touches a run's `status`
- endpoint params match the manifest action exactly (`{toRunId: "@objectId"}`) — keep names in sync
- a first run is a valid input, not an error; a cross-administration pair is refused, not silently
  diffed
