# Tasks: payroll-wage-tax-remittance-shillinq

## 1. Data

- [x] 1.1 Add `WageTaxRemittance` 0.1.0 in `lib/Settings/register.d/hr-remittance.json` with one
      seed object; register 0.57.0. Verify: the record the service writes validates with
      `RegisterSchemaValidator`; `npm run check:seed-refs` exits 0.
- [x] 1.2 Copy shillinq's merged `APTransaction` schema to
      `tests/fixtures/shillinq/ap-transaction-schema.json` with its source commit. Verify: the
      fixture's `required` matches shillinq e079c43d.

## 2. The hand-off

- [x] 2.1 `WageTaxRemittanceService::processRun()` (D1-D4). Verify:
      `testAConfirmedReturnBecomesOneDraftPayableThatFitsShillinqsSchema`,
      `testThePremiumsArePaidWithTheWageTaxToOneCreditor`, `testNothingToPayWritesNoPayable`.
- [x] 2.2 Fail closed and wait (D3, D4). Verify: `testAMissingPaymentReferenceWritesNothing`,
      `testAnUnknownPayeeWritesNothing`, `testADraftReturnIsNotPaidYet`.
- [x] 2.3 Once only, and adopt after a crash (D5). Verify: `testASecondHandOffIsANoOp`,
      `testAPayableWrittenBeforeACrashIsAdopted`.
- [x] 2.4 Duck-typed shillinq (D6). Verify: `testWithoutShillinqTheHandOffIsSkipped`.
- [x] 2.5 `SettingsService::getWageTaxPayeeId()` (`wagetax_payee_id`).

## 3. Trigger, pages and verification

- [x] 3.1 `humaniq:wagetax:remit` registered in `appinfo/info.xml` (D7). Verify, from the caller:
      `WageTaxRemitCommandTest::testTheCommandHandsTheRunsReturnToShillinq` runs the command on
      the real service and finds the payable; `::testInfoXmlRegistersTheCommand`.
- [ ] 3.2 Pages `WageTaxRemittances` and `WageTaxRemittanceDetail` under payroll; l10n en and nl.
      Verify: `npm run check:manifest`, `npm run check:l10n` exit 0.
- [x] 3.3 `@spec` tags and reason-bearing `@e2e exclude` per scenario.
- [ ] 3.4 Live: on the dev instance with shillinq, create a Belastingdienst payee, set
      `wagetax_payee_id`, confirm a month's return, run `occ humaniq:wagetax:remit`, and see
      the draft payable in shillinq's purchase invoices.
