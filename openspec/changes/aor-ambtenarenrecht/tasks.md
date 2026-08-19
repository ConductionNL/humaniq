# Tasks — aor-ambtenarenrecht

> No dependency on any in-flight change. Reuses the shipped `Employee` schema, `CheckProvider`
> auto-discovery, and the labour-corpus mechanism (`hr-signals` precedent). Verify against HEAD, not
> this brief.

- [x] 1. Schema: `Employee` (`lib/Settings/register.d/hr-objects.json`) gains `publicSectorRegime`
  (nullable enum `genormaliseerd`/`ambtenarenwet`, default null) per REQ-AOR-001
- [x] 2. Schema: `Employee` gains `ambtseedAfgelegdOp` (date, nullable) per REQ-AOR-001
- [x] 3. Schema: `Employee` gains `nevenwerkzaamhedenGemeld` (boolean, default `false`) per
  REQ-AOR-001
- [x] 4. Corpus rule `nl-ambtenaar-eed-vereist` in `lib/Standards/rules/labour.json`
  (`framework: ambtenarenwet-2017`, `Employee`, `mandatory`, `machineCheckable: true`, sourced
  Ambtenarenwet 2017 art. 5) per REQ-AOR-002
- [x] 5. Corpus rule `nl-ambtenaar-nevenwerkzaamheden-melding` in the same file
  (`framework: ambtenarenwet-2017`, `Employee`, `mandatory`, `machineCheckable: true`, sourced
  Ambtenarenwet 2017 art. 9) per REQ-AOR-003
- [x] 6. Add `ambtenarenwet-2017` to `lib/Standards/rules/SCHEMA.md`'s framework-examples list (the
  `nl-pensioenaangifte`/`hr-signals` precedent) per REQ-AOR-002 / REQ-AOR-003
- [x] 7. Bump `RuleCatalogue::VERSION` per REQ-AOR-002 / REQ-AOR-003
- [x] 8. Provider `lib/Standards/Checks/NlAorChecks.php` (`CheckProvider`, auto-discovered):
  `nl-ambtenaar-eed-vereist` predicate — vacuous when `publicSectorRegime` is null, else violates
  when `ambtseedAfgelegdOp` is null, per REQ-AOR-002
- [x] 9. Provider: `nl-ambtenaar-nevenwerkzaamheden-melding` predicate — vacuous when
  `publicSectorRegime` is null, else violates when `nevenwerkzaamhedenGemeld` is `false`, per
  REQ-AOR-003
- [x] 10. Seed data: one `genormaliseerd` `Employee` with both fields satisfied per REQ-AOR-004 /
  design.md Seed Data
- [x] 11. Seed data: one `ambtenarenwet` `Employee` with both fields satisfied per REQ-AOR-004 /
  design.md Seed Data
- [x] 12. Seed data: one `ambtenarenwet` `Employee` with `ambtseedAfgelegdOp: null` proving the
  `nl-ambtenaar-eed-vereist` violation branch per REQ-AOR-004 / design.md Seed Data
- [x] 13. Tests: `tests/Unit/Standards/NlAorChecksTest.php` — both violation branches, both clean
  passes, and vacuous pass for every `publicSectorRegime: null` employee (including the pre-existing
  seed population), driving the REAL `RuleEngine` + catalogue per REQ-AOR-002 / REQ-AOR-003 /
  REQ-AOR-004
- [x] 14. Quality gates: `composer lint` green, full PHPUnit suite green; SPDX + `@spec` tags on every
  new/changed PHP method (gate-16); i18n keys ENGLISH

Acceptance criteria (plain reminders, not tasks):
- `publicSectorRegime` is HR-set, never derived from a sector/function guess (D1)
- both rules are presence-only — content of the eed ceremony or the nevenwerkzaamheden disclosure is
  never validated (D2), the `nl-gebruikelijkloon-norm` precedent
- neither rule fires for any employee with `publicSectorRegime` null, including every pre-existing
  seeded employee
- no ontslagprocedure, transitievergoeding, integriteitsmelding case system, tuchtbesluit workflow,
  disciplinaire-maatregelen workflow, college-escalation, CRvB bundling, SLA dashboard,
  confidentiality tiering, or retention automation is built by this change (design.md D4 table) —
  and confidentiality tiering (F-009) is now STOPPED outright rather than deferred, because
  OpenRegister owns it (2026-08-19 decision; see proposal.md and the D4 table)
