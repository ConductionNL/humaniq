# Tasks — loonbeslag

> Verify against HEAD, not this brief — `payroll-core-engine` (retro-adjustments/leave-buy-sell
> fold pattern) and `PayrollController::mutations()`/`wkrAssess()` (admin/HR gate) are already
> merged at HEAD; this change only adds to them, it does not depend on any pending change.

- [ ] 1. Schema: NEW `lib/Settings/register.d/hr-loonbeslag.json` — `Loonbeslag` (creditor,
  dossierRef, totalClaim, orderedAmount, beslagvrijeVoet, employeeId `$ref`, effectiveFrom/
  effectiveTo, plain `status` enum `concept/actief/voldaan/ingetrokken`, activated*/settled*/
  withdrawn* audit fields) per REQ-BESLAG-001/-003
- [ ] 2. Schema: `Payslip.loonbeslag` (nullable number, euro) + `Payslip.loonbeslagId` (nullable
  `$ref` Loonbeslag) in `lib/Settings/register.d/hr-objects.json` per REQ-BESLAG-004
- [ ] 3. Service: `PayrollRunService::activeLoonbeslagenByEmployeeId(period)` — the
  status=actief + effectiveFrom/effectiveTo-covers-period index, keyed by employeeId (the
  `openSickCasesByEmployeeKey()`/`appliedRetroAdjustmentsByEmployeeId()` precedent), deterministic
  earliest-`effectiveFrom` tie-break per REQ-BESLAG-005
- [ ] 4. Service: the floor-clamp fold — `deductionCents = min(orderedAmountCents, max(0,
  nettoPaySoFarCents − beslagvrijeVoetCents))`, cents-internal/euro-external, applied AFTER
  retroAdjustment + leaveBuySell, `loonbeslagFields()` merge (null when deductionCents is 0) per
  REQ-BESLAG-002/-004
- [ ] 5. Controller: NEW `lib/Controller/LoonbeslagController.php` — `activate()`/`settle()`/
  `withdraw()`, each: `isAdminOrHr()` 403 gate BEFORE resolve, then RBAC-resolve the posted
  `loonbeslagId` (404 unknown/unauthorized), then the status-precondition check (400) and the
  stamped write per REQ-BESLAG-006
- [ ] 6. Routes: `appinfo/routes.php` — 3 new routes (`/api/loonbeslag/activate`,
  `/api/loonbeslag/settle`, `/api/loonbeslag/withdraw`) registered BEFORE the SPA catch-all per
  REQ-BESLAG-006
- [ ] 7. Checks: NEW `lib/Standards/Checks/NlLoonbeslagChecks.php` —
  `nl-loonbeslag-beslagvrije-voet-floor` (Payslip, vacuous when `loonbeslagId` null, else
  cents-exact `nettoPay ≥ beslagvrijeVoet`) per REQ-BESLAG-002/-007
- [ ] 8. Checks: `nl-loonbeslag-single-active` (Loonbeslag, flags >1 `actief` with overlapping
  effective ranges per employee) + `RuleAuditService::audit()` `payroll.loonbeslagenById` context
  enrichment per REQ-BESLAG-005/-007
- [ ] 9. Corpus: register `nl-loonbeslag-beslagvrije-voet-floor` + `nl-loonbeslag-single-active` in
  `lib/Standards/rules/loonbeslag.json` (RuleCatalogue metadata: mandatory, description, BW art.
  475b–475e / Wet vereenvoudiging beslagvrije voet legal-basis reference) per REQ-BESLAG-007
- [ ] 10. Manifest: `Loonbeslagen` index page + `LoonbeslagDetail` (three guarded `api-call` page
  actions "Verifiëren en activeren"/"Markeer voldaan"/"Intrekken", NOT `lifecycleActions`;
  admin/HR-only surface note) + Payslip detail readout of `loonbeslag`/`loonbeslagId`; `npm run
  check:manifest` passes per REQ-BESLAG-004/-006
- [ ] 11. Seed: one `Loonbeslag` for the existing seeded employee exercising the CLAMPED branch
  (`orderedAmount` exceeding headroom above `beslagvrijeVoet`) per design.md Seed Data
- [ ] 12. Tests: `PayrollRunServiceTest` — floor-clamp cases (clamped, unclamped, zero-headroom),
  fold-ordering (after retroAdjustment/leaveBuySell), idempotent recalculation (no drift across
  repeated `--recalculate`) per REQ-BESLAG-002/-004/-005
- [ ] 13. Tests: `NlLoonbeslagChecksTest` — both predicates including vacuous scopes (null
  `loonbeslagId`, single active, zero active) per REQ-BESLAG-007
- [ ] 14. Tests: `LoonbeslagControllerTest` — 403 non-admin/HR caller (before any resolve), 404
  unknown/unauthorized id, 400 wrong-status precondition, happy path for each of activate/settle/
  withdraw per REQ-BESLAG-006
- [ ] 15. README: beslagvrijeVoet-is-a-stored-input limitation + single-active-beslag MVP scope +
  the two named follow-ups (computed voet, multi-beslag priority ordering) per REQ-BESLAG-003/-005
- [ ] 16. Quality gates: `composer lint` green, full PHPUnit suite green, `npm run check:manifest`
  PASS, `npm run build` green

Acceptance criteria (plain reminders, not tasks):
- `PayrollCalculator` has zero new call sites and zero diff — loonbeslag is entirely post-net
- the floor-clamp arithmetic is integer-cents internally; every Payslip field stays euro-float
  (matching `grossPay`/`nettoPay`/`retroAdjustment`/`leaveBuySell` exactly)
- no `x-openregister-lifecycle` map on `Loonbeslag.status`; every transition is a guarded
  controller endpoint with the admin/HR-gate-before-resolve shape
- SPDX + `@spec` tags on every new/changed PHP method (gate-16); i18n keys ENGLISH (ADR-007);
  Dutch strings only in manifest labels/messages + controller error messages per existing
  convention
- `NlLoonbeslagChecks` requires zero manual registration — dropping the file under
  `lib/Standards/Checks/` implementing `CheckProvider` is sufficient (verify this by re-reading
  `RuleEngine::providers()` at implementation time, not assuming it from this brief)
