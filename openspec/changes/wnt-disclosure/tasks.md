# Tasks — wnt-disclosure

> Depends on `30-procent-regeling` (active, not yet merged) for the `dertigProcentRegeling`
> `TaxTables` accessor carrying the verified WNT-norm `aftoppingsgrens` leaf. Verify against HEAD,
> not this brief — if that change has not landed yet, `nl-wnt-norm-overschrijding` must degrade to a
> vacuous pass (design.md D1 Risks), never a hardcoded fallback figure.

- [ ] 1. Schema: `Employee` (`lib/Settings/register.d/hr-objects.json`) gains `wntTopfunctionaris`
  (boolean, default `false`) per REQ-WNT-001
- [ ] 2. Schema: `Employee` gains `wntUitzonderingReden` (nullable enum
  `overgangsrecht`/`ontheffing-minister`, default null) per REQ-WNT-001
- [ ] 3. NEW fragment `lib/Settings/register.d/hr-wnt.json` (`x-humaniq-fragment: hr-wnt`) declaring
  `WntDisclosure` (slug `WntDisclosure`, `x-schema-org: schema:Report`, required
  `[employeeId, year, totalCompensation, status]`) with `employeeId` ($ref Employee), `year` (string,
  YYYY), `totalCompensation` (number, cents or euro — pick one and document it), `status` (enum
  `concept`/`gepubliceerd`, default `concept`) per REQ-WNT-002
- [ ] 4. Lifecycle: `WntDisclosure.configuration.x-openregister-lifecycle` — `field: status`,
  `initial: concept`, transition `publiceren` (concept → gepubliceerd), no guard per REQ-WNT-002 /
  design.md D2
- [ ] 5. Corpus rule `nl-wnt-norm-overschrijding` in `lib/Standards/rules/payroll.json` (new
  `framework: wnt-2013`, `WntDisclosure`, `mandatory`, `machineCheckable: true`, sourced WNT art. 2.3,
  BWBR0032249) per REQ-WNT-003
- [ ] 6. Add `wnt-2013` to `lib/Standards/rules/SCHEMA.md`'s framework-examples list per REQ-WNT-003
- [ ] 7. Bump `RuleCatalogue::VERSION` per REQ-WNT-003
- [ ] 8. Context enrichment: extend `RuleAuditService::buildRelatedContext()`'s existing
  `Employee.byId` map with `wntUitzonderingReden` (the incremental-field precedent) per REQ-WNT-003
- [ ] 9. Provider `lib/Standards/Checks/NlWntChecks.php` (`CheckProvider`, auto-discovered):
  `nl-wnt-norm-overschrijding` predicate on `WntDisclosure` — reads the WNT-norm via the
  `TaxTables`/`30-procent-regeling` accessor (design.md D1); vacuous when the accessor/leaf does not
  exist yet, when the referenced employee is not `wntTopfunctionaris`, or when
  `wntUitzonderingReden` is non-null; else violates when `totalCompensation` exceeds the norm per
  REQ-WNT-003
- [ ] 10. Manifest: `WntDisclosures` index page (columns `year`/`employeeId`/`totalCompensation`/
  `status`) + `WntDisclosureDetail` detail page + `lifecycleActions` widget (Publiceren), landing as
  a sibling entry in the existing `PayrollGroup` menu (design.md D6); `npm run check:manifest` PASS
  per REQ-WNT-004
- [ ] 11. Seed data: topfunctionaris `Employee` + `WntDisclosure` 2026 at/under norm (clean pass) per
  REQ-WNT-005 / design.md Seed Data
- [ ] 12. Seed data: topfunctionaris `Employee` (`wntUitzonderingReden: "overgangsrecht"`) +
  `WntDisclosure` 2026 over norm (clean pass, exemption gate) per REQ-WNT-005 / design.md Seed Data
- [ ] 13. Seed data: topfunctionaris `Employee` (`wntUitzonderingReden: null`) + `WntDisclosure` 2026
  over norm (violation branch) per REQ-WNT-005 / design.md Seed Data
- [ ] 14. Tests: `tests/Unit/Standards/NlWntChecksTest.php` — the violation branch, both clean
  passes, vacuous pass for non-topfunctionaris employees and for the entire pre-existing seed
  population, driving the REAL `RuleEngine` + catalogue + `TaxTables` per REQ-WNT-003 / REQ-WNT-005
- [ ] 15. Quality gates: `composer lint` green, full PHPUnit suite green, `npm run check:manifest`
  PASS; SPDX + `@spec` tags on every new/changed PHP method (gate-16); i18n keys ENGLISH, Dutch only
  in manifest labels

Acceptance criteria (plain reminders, not tasks):
- the WNT-norm figure (€262.000 for 2026) is read from the `30-procent-regeling` `TaxTables` leaf —
  it is NEVER re-declared as a second corpus figure anywhere in this change (D1)
- `totalCompensation` is hand-entered; this change computes nothing and aggregates nothing across
  payroll runs (D3)
- the annual WNT-verantwoording is the filtered set of a year's `WntDisclosure` rows — there is no
  separate aggregate "report" schema (D2)
- no dashboard, alerting, klasse-indeling, severance administration, recovery-tracking, PDF
  generation, RBAC role, or ZIP export is built by this change
