## 1. Repair

- [x] 1.1 `ManagerStampRepair::run()` restamps open records whose stamp differs from the shared resolution, for the record's own date. Verify: ManagerStampRepairTest.
- [x] 1.2 `RestampOpenManagerRecords` runs it after `MigrateHoursProcess` in `appinfo/info.xml`, post-migration and install. Verify: RestampOpenManagerRecordsTest and AppInfoTest.
- [x] 1.3 `occ humaniq:manager:restamp [--dry-run]` prints counts per schema. Verify: ManagerRestampCommandTest.
- [x] 1.4 A failing record is logged and counted, and the pass carries on; the command exits 1 when a record failed. Verify: ManagerStampRepairTest::testAFailingRecordIsCountedAndThePassCarriesOn, ManagerRestampCommandTest::testAFailedRecordExitsOne.
- [x] 1.5 Every page is read (`HoursRegisterGateway::loadAll` pages to the end, up to `MAX_ROWS`), the org lookups once per run, and no stamp is cleared when a lookup read was cut off. Verify: ManagerStampRepairTest::testMoreThan500RowsAreAllRead, testAStampIsNotClearedWhenALookupWasCutOff.
- [x] 1.6 The repair step stores `manager_restamp_done` after a pass without failures and then skips. Verify: RestampOpenManagerRecordsTest.
- [x] 1.7 Expense claims of every category are in the pass. Verify: ManagerStampRepairTest::testOpenClaimsOfEveryCategoryAreRestamped.

## 2. Expense approver stamp

- [x] 2.1 `ExpenseManagerStampListener` stamps `managerUserId`, `userId` and `administrationId` on every write of an open claim, for its `expenseDate`, else today. Verify: ExpenseManagerStampListenerTest.
- [x] 2.2 It is subscribed for both pre-save events, after every other Expense listener, and merges into their modified data. Verify: TravelListenerWiringTest::testTheExpenseApproverStampIsSubscribedLast.
- [x] 2.3 `HoursRegisterGateway::loadAll` reads every page, so the resolver's indexes are complete beyond 500 rows. Verify: ManagerStampRepairTest::testMoreThan500RowsAreAllRead.

## 3. Verification

- [x] 3.1 Capped mutants with a no-op control.
- [x] 3.2 `openspec validate restamp-open-manager-records --strict`, `@e2e exclude` per scenario (gate 19), `@spec` tags (gate 16).
