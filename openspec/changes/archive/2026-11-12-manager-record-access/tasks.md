## 1. Schemas

- [x] 1.1 `managerUserId` and `deputyUserIds` on Employee, SickLeaveCase, LeaveTransaction and CompAdjustment, with manager and deputy read rules (update on LeaveTransaction). Verify: SchemaAccessDeclarationTest::testTheStampedManagerAndDeputiesReadTheirTeamsRecords.
- [x] 1.2 Register version 0.61.0.

## 2. Stamps

- [x] 2.1 `ManagerAccessStamps::stampsFor()` resolves the manager for today and the active deputies, never the employee themself. Verify: ManagerAccessStampsTest.
- [x] 2.2 `ManagerAccessStampListener` stamps on create and update and replaces client values; a failed resolution keeps the stored stamps. Verify: ManagerAccessStampListenerTest.
- [x] 2.3 `ManagerAccessStamps::run()` restamps stored records, idempotent, with a dry run. Verify: ManagerAccessStampsTest::testThePassStampsStoredRecordsOnceAndADryRunWritesNothing.

## 3. Refresh

- [x] 3.1 `StampManagerAccess` repair step after InitializeRegister, post-migration and install, with a done marker. Verify: StampManagerAccessTest.
- [x] 3.2 `RefreshManagerAccessJob` every six hours; `RefreshManagerAccessNowJob` queued on an org or deputy change. Verify: ManagerAccessStampListenerTest::testAnOrgOrDeputyChangeQueuesOneRefresh.
