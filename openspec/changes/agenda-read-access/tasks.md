## 1. Access

- [x] 1.1 `AgendaReadAccess::grant()` answers who may read an employee or org-unit agenda from the session user, the shared manager resolution, `HumaniqRoles` and today's placements. Verify: AgendaReadAccessTest.
- [x] 1.2 `AgendaController::agenda()` answers 403 before reading any planned item. Verify: AgendaReadAccessTest::testAnyoneElseIsRefusedAnEmployeeAgenda, testAnyoneElseIsRefusedAUnitAgenda.
- [x] 1.3 `AgendaReadAccess::visibleEntries()` drops a colleague's interviews and secondment hours. Verify: AgendaReadAccessTest::testAColleagueSeesTheAgendaWithoutInterviewsOrSecondmentHours, testAMemberSeesTheUnitAgendaWithOnlyTheirOwnInterviewsAndHours.
- [x] 1.4 Request parameters never stand in for the viewer. Verify: AgendaReadAccessTest::testForgedRequestParametersDoNotOpenAnAgenda, testForgedRequestParametersDoNotWidenAColleaguesView.

## 2. Verification

- [x] 2.1 Tests written first and red on the old controller (14 of 23).
- [x] 2.2 Capped mutants with a no-op control.
- [x] 2.3 `openspec validate agenda-read-access --strict`, `@e2e exclude` per scenario (gate 19), `@spec` tags (gate 16).
