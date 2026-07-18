# Tasks — stagiair-bbl-admin

> Verify against HEAD, not this brief — `EmploymentContract.type` enum, `NlPayrollChecks`'
> minimum-wage predicates, `cao-library`'s `caoSchaal` mechanism, `NlOnboardingChecks`' boolean-gate
> pattern and `offer-esign`'s documented external-signer limitation are already merged at HEAD;
> this change only composes them.

- [ ] 1. Schema: NEW fragment `lib/Settings/register.d/hr-stagiair.json` — `Stagiair`
  (onderwijsinstelling, opleiding, niveau enum, stagetype enum, startDate, endDate, begeleiderId
  `$ref` Employee, stagevergoedingPerMaand, bpvOvereenkomstOndertekend, verzekeringGeverifieerd,
  declarative `x-openregister-lifecycle` aangemeld→lopend→afgerond/gestopt, administrationId) per
  REQ-STAG-001
- [ ] 2. Schema: `EmploymentContract.type` enum gains `bbl` (`hr-objects.json`); NEW nullable
  `bpvOvereenkomstOndertekend` (boolean) and `bpvSchoolNaam` (string) properties, each with
  title+description; version bump per REQ-STAG-002
- [ ] 3. Register: `lib/Settings/hrmq_register.json` `info.version` bump (new fragment picked up by
  the existing Repair import, no code change)
- [ ] 4. Corpus: add `nl-bpv-overeenkomst-vereist` to `lib/Standards/rules/labour.json` (framework
  `hr-stagiair`, severity mandatory, machineCheckable true) per REQ-STAG-004; bump
  `RuleCatalogue::VERSION`; add `hr-stagiair` to `SCHEMA.md`'s framework examples
- [ ] 5. Checks: NEW `lib/Standards/Checks/NlStagiairChecks.php` — predicate registered under both
  `Stagiair` and `EmploymentContract` (guarded `type === 'bbl'`) per REQ-STAG-004/-005; auto-
  discovered, no registration step
- [ ] 6. Manifest: `Stagiairs` index (columns: naam via begeleider link is not available — list
  onderwijsinstelling/opleiding/niveau/status/startDate/endDate) + `StagiairDetail` (data +
  lifecycleActions starten/afronden/stoppen + related Employee via begeleiderId + audit sidebar)
  under the existing `Medewerkers` menu group per REQ-STAG-001/-006; `npm run check:manifest`
  passes (Ajv, 0 errors)
- [ ] 7. Seed: `stagiair-devries` (compliant, BPV signed) + `stagiair-bakker` (intended violation,
  startDate past, BPV unsigned) in `hr-seed.json` per design.md Seed Data; 1 new `EmploymentContract`
  seed with `type: bbl` against an existing employee anchor
- [ ] 8. Tests: `NlStagiairChecksTest` — Stagiair compliant/violation/future-startDate-vacuous cases;
  `EmploymentContract` type=bbl unsigned-violation + type=bbl signed-pass + type=permanent-vacuous
  (rule never fires for non-bbl/non-Stagiair) per REQ-STAG-004/-005
- [ ] 9. Tests: end-to-end `RuleAuditServiceTest` (or equivalent) confirming the seeded audit
  reports exactly one new violation (`stagiair-bakker`) and zero regressions on existing rules
- [ ] 10. README: the BPV-signing boundary (HR-entered boolean, not e-signature — cites offer-esign
  design.md point 4) and the SBB/RVO/evaluation-scheduling out-of-scope note per proposal Non-goals
- [ ] 11. Quality gates: `composer check:strict` (phpcs/phpmd/psalm/phpstan/phpunit) all green;
  `npm run check:manifest` PASS; `npm run build` green; gate-28 (title+description on every new
  property); SPDX + `@spec` tags on `NlStagiairChecks.php`

Acceptance criteria (plain reminders, not tasks):
- No `Stagiair` object is ever referenced by `PayrollRun`/`Payslip`/`PayrollMutationReport` or
  passed to `PayrollCalculator` — verify by grepping the actual diff for any new `$ref` from a
  payroll schema to `Stagiair`, at implementation time; there should be none
- `EmploymentContract` records with `type: bbl` require no new branch in `PayrollCalculator` or the
  NL jurisdiction pack — verify no `lib/Payroll/` file changes in the diff
- no numeric stagevergoeding fiscal ceiling is asserted anywhere in code, corpus, or docs without a
  cited Belastingdienst source (design.md D2) — `verified:false` + `checkAgainst` if uncited
- `nl-bpv-overeenkomst-vereist` never fires for a permanent/temporary/agency/minijob contract —
  verify the `type === 'bbl'` guard explicitly in the test suite
- i18n keys ENGLISH (ADR-007); Dutch display strings only in manifest labels/messages per existing
  convention
