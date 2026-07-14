# Tasks — payroll-core-schema

- [x] 1. Tables: create `lib/Standards/tables/nl-2026.json` with the complete verified 2026
  parameter set — copy the values, sources and verified/placeholder flags EXACTLY from design.md
  D3 (do not re-derive from memory or blogs; the two primary PDFs are normative) per REQ-PCS-001
- [x] 2. Tables: create `lib/Standards/tables/SCHEMA.md` (shape, leaf format, euros-2-decimals,
  annual re-issue discipline, NOT-OpenRegister rationale) mirroring `rules/SCHEMA.md` per
  REQ-PCS-002
- [x] 3. Schema: add `Employee.loonheffingskortingToegepast` (boolean, default true, description
  per design.md) + bump Employee to 0.4.0 in `lib/Settings/register.d/hr-objects.json` per
  REQ-PCS-003
- [x] 4. Schema: add `PayrollRun.calculatedAt` (date-time, nullable) + `PayrollRun.engineVersion`
  (string, nullable) + bump PayrollRun to 0.2.0 per REQ-PCS-004
- [x] 5. Schema: add `Payslip.arbeidskorting` (number, nullable) + `Payslip.payrollRunId` (string,
  uuid, `$ref` PayrollRun, nullable) + bump Payslip to 0.3.0 per REQ-PCS-005
- [x] 6. Corpus: add `nl-engine-table-version` + `nl-engine-output-consistency` to
  `lib/Standards/rules/payroll.json` (fields exactly per design.md D5 table, incl. the declared
  net equation in the statement) per REQ-PCS-006
- [x] 7. Corpus: bump `RuleCatalogue::VERSION` to `2026-07.6` per REQ-PCS-006
  (deviation: HEAD's catalogue was already at `2026-07.11` when this change was built — bumped to
  `2026-07.12` instead, per explicit builder instruction to check current-and-bump-by-one; see
  DEVIATIONS in the final report)
- [x] 8. Unit guard: extend the existing Standards tests (tests/Unit/Standards/) with a tables-file
  sanity test — nl-2026.json parses, `id/jurisdiction/year/basedOn` present, every parameter leaf
  carries `value`+`source`+`verified`, the whk leaf carries `placeholder: true` (REQ-PCS-001
  scenarios; pure static-data assertions, no engine logic — that is spec 2)
- [x] 9. Quality gates: `composer check:strict` green; in the dev container re-import the register
  (Repair step), run `occ hrmq:rules:audit` and confirm (a) zero new violations on existing seeds,
  (b) the two new rules appear as machine-checkable-but-unenforced (REQ-PCS-004/-006 scenarios)

Acceptance criteria (plain reminders, not tasks):
- every number in nl-2026.json byte-matches design.md D3 (the design is the verified record;
  a mismatch is a defect in the task, not the design)
- no manifest change, no seed change, no PHP beyond the VERSION constant and the static test
- new property descriptions name their rules (fleet convention) and are English (i18n keys —
  ADR-007 applies to UI strings, schema descriptions follow the existing file's English style)
- do NOT register any predicate for the two new rules (no vacuous `return true` — the check
  provider is payroll-core-engine's task)
