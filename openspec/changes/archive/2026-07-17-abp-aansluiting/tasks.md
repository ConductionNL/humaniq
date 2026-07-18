# Tasks — abp-aansluiting

> Depends on `pension-filing-upa-mvp` (archived 2026-07-12) and `multi-administratie` (archived
> 2026-07-14): the `PensionFiling.fund` enum, the `nl-pensioenaangifte` framework, the `Administration`
> catalog, and the `RuleAuditService` context-enrichment pre-pass. Verify against HEAD, not this brief.

- [x] 1. Schema: `Administration` (`lib/Settings/register.d/hr-administratie.json`) gains
  `abpAansluitingsplichtig` (boolean, default `false`, description citing Wet Privatisering ABP 1996)
  per REQ-ABP-001
- [x] 2. Doc fix: correct `PayrollRun.administrationId`'s stale description in
  `lib/Settings/register.d/hr-objects.json` ("No Administration schema is modeled in hrmq") to
  reflect the shipped `multi-administratie` `Administration` schema — no schema/type change, comment
  only, per REQ-ABP-001
- [x] 3. Corpus rule `nl-abp-fund-required` in `lib/Standards/rules/payroll.json`
  (`framework: nl-pensioenaangifte`, `PayrollRun`, `mandatory`, `machineCheckable: true`, sourced Wet
  Privatisering ABP 1996) per REQ-ABP-002
- [x] 4. Bump `RuleCatalogue::VERSION` per REQ-ABP-002
- [x] 5. Context enrichment in `lib/Service/RuleAuditService.php`'s `buildRelatedContext()`:
  `Administration.abpPlichtigByAdministrationId` (keyed on the `administrationId` business key) per
  REQ-ABP-003
- [x] 6. Context enrichment: extend the existing `PayrollRun.byId` map with `administrationId` (the
  `Employee.byId` incremental-field precedent) per REQ-ABP-003
- [x] 7. Context enrichment: NEW `PensionFiling.abpFiledPeriodsByAdministrationId` index
  (administratie → periods with a `fund: "abp"` filing), alongside the existing, unchanged
  `filedPeriods` global set per REQ-ABP-003
- [x] 8. Provider `lib/Standards/Checks/NlAbpChecks.php` (`CheckProvider`, auto-discovered):
  `nl-abp-fund-required` predicate on `PayrollRun` — vacuous for non-NL/draft runs and for runs whose
  administratie does not resolve or is not `abpAansluitingsplichtig`; else violates when no
  `fund: "abp"` filing exists for the run's own `(period, administrationId)` per REQ-ABP-003
  / REQ-ABP-004
- [x] 9. Seed data: flip `ADM-001` to `abpAansluitingsplichtig: true` in
  `lib/Settings/register.d/hr-seed.json` per REQ-ABP-004 / design.md Seed Data
- [x] 10. Seed data: NEW `ADM-003` ("Gemeente Voorbeeld") `Administration` row
  (`abpAansluitingsplichtig: true`) + one `AdministrationAccess` row (`admin`, role `accountant`) per
  REQ-ABP-004 / design.md Seed Data
- [x] 11. Seed data: NEW approved NL `PayrollRun` scoped to `ADM-003`, period `2026-06`, no
  `PensionFiling` seeded for it — proving the violation branch and the fund/tenant-scoping divergence
  from `nl-upa-monthly-completeness` per REQ-ABP-004 / design.md Seed Data
- [x] 12. Tests: `tests/Unit/Standards/Checks/NlAbpChecksTest.php` (Checks/ subfolder — matching the
  established convention every existing CheckProvider test follows, e.g. NlPensionFilingChecksTest,
  NlAdministratieChecksTest; the flat path above was this brief's typo, verified against HEAD) —
  violation for `ADM-003`'s run, clean
  pass for both `ADM-001` runs, vacuous passes (non-NL run, draft run, non-obligated administratie,
  unresolvable `administrationId`) driving the REAL `RuleEngine` + catalogue + context per
  REQ-ABP-003 / REQ-ABP-004
- [x] 13. Tests: extend `RuleAuditServiceTest` (or equivalent) to assert the three new/extended
  context indexes are populated per REQ-ABP-003
- [x] 14. Quality gates: `composer lint` green, full PHPUnit suite green; SPDX + `@spec` tags on every
  new/changed PHP method (gate-16); i18n keys ENGLISH

Acceptance criteria (plain reminders, not tasks):
- `abpAansluitingsplichtig` is an admin-set determination, never derived from a sector/function guess
  (D1)
- `nl-abp-fund-required` is additive alongside the shipped `nl-upa-monthly-completeness` — the
  shipped rule's fund-blind MVP scope is untouched (D2)
- no ABP premium percentage, franchise amount, or FTE-factor is computed, stored, or enforced
  anywhere by this change (D4) — the verified 27,1% total lives only in proposal.md/design.md
  documentation until a shared pension-premium computation capability exists
- the `ADM-003` seed must reproduce BOTH outcomes in the same audit run: a `nl-abp-fund-required`
  violation for its own run, and silence from `nl-upa-monthly-completeness` for that same run
  (design.md Seed Data) — this is the concrete proof the two rules are not redundant
