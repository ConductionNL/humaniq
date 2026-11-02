## 1. Access

- [x] 1.1 `AgendaReadAccess::employeeScope()` limits availability and capacity to who may read the agenda. Verify: AgendaReadAccessTest::testAUnitsHoursOpenForWhoMayReadItsAgenda, testAnyoneElseIsRefusedAUnitsHours, testWithoutAUnit*.
- [x] 1.2 A unit agenda opens for managers above it and a deputy of today. Verify: AgendaReadAccessTest::testParentManagersAndDeputiesSeeTheWholeUnitAgenda, testAnEndedDeputyIsRefusedTheUnitAgenda, testParentManagersAndDeputiesDoNotOpenAnEmployeeAgenda.
- [x] 1.3 No Employee record, an unregistered unit or an unreadable placement date opens nothing. Verify: AgendaReadAccessTest::testASubjectWithoutAnEmployeeRecordIsRefused, testAPlacementInAnUnregisteredUnitOpensNothing, testAPlacementWithAnUnreadableDateDoesNotCount.
- [x] 1.4 A refused resource agenda carries a reason. Verify: AgendaReadAccessTest::testARefusedResourceAgendaSaysWhy.

## 2. Verification

- [x] 2.1 Tests written first and red on the `agenda-read-access` code.
- [x] 2.2 Capped mutants with a no-op control.
- [x] 2.3 `openspec validate agenda-hours-read-access --strict`, `@e2e exclude` per scenario (gate 19), `@spec` tags (gate 16).
