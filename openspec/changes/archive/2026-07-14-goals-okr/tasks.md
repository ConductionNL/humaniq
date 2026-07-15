# Tasks — goals-okr

> Pure config (schemas + manifest + seed). No PHP, no routes. Verify against HEAD, not this brief.

- [x] 1. New fragment `lib/Settings/register.d/hr-okr.json` (`x-hrmq-fragment: hr-okr`) scaffold
  with the `components.schemas` container per REQ-OKR-001/-002
- [x] 2. `Objective` schema: slug/icon/version/title/description, required `title`+`ownerType`,
  `ownerType` enum `employee|team`, `employeeId` `$ref Employee` (nullable), `orgUnitId`
  `$ref OrgUnit` (nullable), `cycleId` `$ref ReviewCycle` (nullable), `period` string (nullable)
  per REQ-OKR-001/-005
- [x] 3. `Objective` declarative `x-openregister-lifecycle` on `status`
  (`concept → actief → afgesloten`, terminal `afgesloten`, transitions `activeren`/`afsluiten`,
  no guard) per REQ-OKR-001
- [x] 4. `Objective` `uitkomst` nullable enum (`behaald|deels-behaald|niet-behaald`) kept separate
  from `status`, plus `progress` number (writer-maintained) and `userId` denorm string per
  REQ-OKR-001/-003
- [x] 5. `KeyResult` schema: slug/icon/version/title, required `objectiveId` (`$ref Objective`) +
  `targetValue`, `title`, `unit` enum (`percentage|aantal|euro|boolean`),
  `startValue`/`currentValue`/`progress` numbers, `status` plain enum (`open|behaald|vervallen`),
  `userId` denorm per REQ-OKR-002/-003
- [x] 6. Document the D3 rationale in the `progress` property descriptions on BOTH schemas
  (writer/UI-maintained, NOT an `x-openregister-calculations` field — division/average outside the
  `prop`/`+`/`-` vocabulary; measurable truth is start/target/current) per REQ-OKR-003
- [x] 7. Manifest: `EmployeeDetail` `emp-objectives` object-list row ("Doelen & OKR's",
  `BullseyeArrow`, filter `employeeId: @objectId`, cols `title`/`status`/`progress`/`cycleId`,
  `rowRoute: ObjectiveDetail`), inserted as a full-width layout row per REQ-OKR-004
- [x] 8. Manifest: `ObjectiveDetail` page (route `/objectives/:id`, not a menu child): `data`
  widget (exclude `employeeId`/`orgUnitId`/`cycleId`/`userId`), `related`, `obj-keyresults`
  object-list (filter `objectiveId: @objectId`, cols
  `title`/`currentValue`/`targetValue`/`unit`/`progress`/`status`, `rowRoute: KeyResultDetail`),
  `lifecycleActions` `activeren`/`afsluiten`, audit sidebar tab per REQ-OKR-004
- [x] 9. Manifest: `KeyResultDetail` page (route `/key-results/:id`, not a menu child): `data`
  widget + `related` resolving `objectiveId` per REQ-OKR-004
- [x] 10. Manifest: `ReviewCycleDetail` `rc-objectives` object-list ("OKR's in deze cyclus", filter
  `cycleId: @objectId`, `rowRoute: ObjectiveDetail`) — the review-period tie per REQ-OKR-004
- [x] 11. Manifest: `MijnDoelen` index page (route `/mijn/doelen`, filter `userId: @me`, cols
  `title`/`status`/`progress`/`cycleId`) — mijn-hr self-service per REQ-OKR-005
- [x] 12. Manifest: `deepLinks` for `Objective` and `KeyResult`; `src/icons.js` registers
  `BullseyeArrow` + `TargetVariant`; NO new menu group / top-level entry (ADR-001 Rule 6) per
  REQ-OKR-004/-005
- [x] 13. Seed data in `lib/Settings/register.d/hr-seed.json`: one `actief` `Objective` for the
  seeded employee tied to the open 2026 `ReviewCycle` (`userId` set, `progress` hand-set) + 2–3
  `KeyResult`s with hand-consistent start/target/current/progress/status per REQ-OKR-006
- [x] 14. Validate: `npm run check:manifest` passes; the register fragment loads (schemas import,
  seed objects resolve their `$ref`s) per REQ-OKR-006
- [x] 15. Quality: fragment JSON is valid and re-validated after any merge; schema property
  `title`s present (gate), i18n keys ENGLISH, Dutch only in manifest labels/messages per the
  existing convention

Acceptance criteria (plain reminders, not tasks):
- `progress` is never declared as an `x-openregister-calculations`/`materialise` field — it is a
  plain stored number with the D3 rationale in its description (both schemas)
- no new PHP, no routes, no controllers, no guards on `afsluiten` — pure config
- no new top-level menu and no 10th menu entry (ADR-001 Rule 6); every surface is a detail-tab row,
  a routed detail, or a self-service index
- `objectiveId` is required on `KeyResult`; `employeeId`/`orgUnitId`/`cycleId` are nullable and
  `ownerType` says which owner is authoritative
