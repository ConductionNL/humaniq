# Tasks — proforma-payslip

> Depends on `payroll-core-engine` (merged): reuse `PayrollCalculator`, `CalculationInput`,
> `CalculationResult` and `TaxTables` AS-IS — this change adds ZERO tax logic. Verify against HEAD,
> not this brief.

- [x] 1. Service: `lib/Service/ProformaPayslipService.php` — stateless `simulate()` that builds a
  `CalculationInput` from hypothetical inputs, loads the tax-year `TaxTables` (default the current
  year's `nl-<YYYY>`), calls the existing `PayrollCalculator::calculate()`, and maps the
  `CalculationResult` to a euro-decimal breakdown; NO ObjectService, writes nothing per REQ-PRO-001
- [x] 2. Service: input assembly per design.md D2 —
  `grossMonthlySalaryCents = round((gross × parttimeFactor + bijzondereBeloning) × 100)`, table-colour
  validation (`wit`/`groen`, default `wit`), korting default true, nullable `dateOfBirth` passed
  straight through (calculator's own `isAowAge()` decides AOW), period default current month per
  REQ-PRO-001/-PRO-006
- [x] 3. Service: employer-level knobs from `SettingsService::getPayrollAofTariff()` /
  `getPayrollWhkPercentage($tables->werknemersverzekeringen()['whkDefault'])`, `awfTariff` default
  `low` (no contract), each overridable per call for the occ path per REQ-PRO-006
- [x] 4. Service: the one-off bijzondere beloning is folded into the period gross as a combined-loon
  estimate (NOT bijzonder tarief), labelled as such in the returned breakdown; no new tax logic per
  REQ-PRO-006
- [x] 5. Controller: `PayrollController::proforma()` (`#[NoAdminRequired]`) delegating to
  `ProformaPayslipService::simulate()`, returning the full breakdown JSON; 400 on malformed input per
  REQ-PRO-002
- [x] 6. Controller: `authorizeProformaAccess()` — resolve-first capability probe over the hrmq payroll
  register/schema via container ObjectService under ambient RBAC; unauthorized/unavailable → 404 (no
  capability leak, reads no object data, writes nothing) per REQ-PRO-004
- [x] 7. Route: `appinfo/routes.php` — `POST /api/payroll/proforma` → `payroll#proforma`, BEFORE the
  SPA catch-all per REQ-PRO-002
- [x] 8. Command: `lib/Command/ProformaPayslipCommand.php` (`hrmq:payroll:proforma` with
  `--gross/--table/--date-of-birth/--parttime/--bijzonder/--period/--aof/--whk`) printing the full
  breakdown; register in `appinfo/info.xml` per REQ-PRO-003
- [x] 9. Manifest: new "Simuleer loonstrook" top-level `menu` entry routing to a `type: custom`
  `ProformaPayslip` page; the schema-REQUIRED `_note` documents why open-form (persists) and api-call
  (token params + toast only) cannot express a persist-nothing live-compute form per REQ-PRO-005
- [x] 10. Frontend: host-app SFC for the `ProformaPayslip` custom page — the input form + the rendered
  breakdown, calling `POST /api/payroll/proforma`; Dutch labels, English i18n keys per REQ-PRO-005
- [x] 11. Tests: `tests/Unit/Service/ProformaPayslipServiceTest.php` — the anchor input
  (`3800/wit/below-AOW/parttime 1.0/bijzonder 0/2026-02/aof laag/whk 1.52`) reproduces loonheffing
  €718,83 / arbeidskorting €473,75 / netto €3.081,17; a `groen` case drops arbeidskorting; an AOW-age
  `dateOfBirth` takes the reduced path per REQ-PRO-006
- [x] 12. Tests: assert `simulate()` performs no ObjectService write (persists nothing) — mocked/absent
  ObjectService, object counts unchanged per REQ-PRO-001
- [x] 13. Tests: `tests/Unit/Controller/PayrollControllerProformaTest.php` — an HR caller gets the
  breakdown; a caller whose RBAC cannot resolve the payroll register gets 404; malformed gross → 400
  per REQ-PRO-002/-PRO-004
- [x] 14. Quality gates: SPDX + `@spec` tags on every new PHP method (gate-16), i18n keys ENGLISH
  (ADR-007), Dutch only in manifest labels/messages; `composer lint` green, PHPUnit suite green,
  `npm run check:manifest` PASS, `npm run build` green

Acceptance criteria (plain reminders, not tasks):
- the proforma reuses `PayrollCalculator`/`CalculationInput`/`CalculationResult`/`TaxTables` unchanged —
  no new tax logic, no new tables, no new schema
- nothing is persisted: no Employee/Contract/PayrollRun/Payslip created, no ObjectService write anywhere
- the RBAC gate collapses unknown/unauthorized to the same 404 (no capability leak); the probe reads no
  object data and writes nothing
- the anchor input reproduces the engine's €3.081,17 net exactly (input mapping is the only thing that
  can break it)
