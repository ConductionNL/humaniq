## 1. Repair

- [x] 1.1 `ManagerStampRepair::run()` restamps open records whose stamp differs from the shared resolution, for the record's own date. Verify: ManagerStampRepairTest.
- [x] 1.2 `RestampOpenManagerRecords` runs it after `MigrateHoursProcess` in `appinfo/info.xml`, post-migration and install. Verify: RestampOpenManagerRecordsTest and AppInfoTest.
- [x] 1.3 `occ humaniq:manager:restamp [--dry-run]` prints counts per schema. Verify: ManagerRestampCommandTest.

## 2. Verification

- [x] 2.1 Capped mutants with a no-op control.
- [x] 2.2 `openspec validate restamp-open-manager-records --strict`, `@e2e exclude` per scenario (gate 19), `@spec` tags (gate 16).
