# Tasks — pension-filing-upa-mvp

- [ ] 1. Fragment: create `lib/Settings/register.d/hr-pension.json` with the `PensionFiling` schema (properties, required list, defaults, `x-schema-org: schema:Action`, icon `PiggyBankOutline`, version 0.1.0) per REQ-PFU-001
- [ ] 2. Lifecycle: add `configuration.x-openregister-lifecycle` (controleren→gecontroleerd guarded, bevestigen, verzenden, heropenen, corrigeren) to `PensionFiling` per REQ-PFU-002
  - transition descriptions document the `submittedDate`/`verzondenDoor` stamping on `verzenden`
- [ ] 3. Guard: implement `lib/Lifecycle/PayrollRunApprovedGuard.php` (LifecycleGuardInterface; lazy ObjectService via container; allow only on run status approved/posted/paid; deny with Dutch reason on empty ref, load failure, or any other status) per REQ-PFU-003
- [ ] 4. Corpus: add `nl-upa-payrollrun-approved`, `nl-upa-monthly-completeness`, `nl-upa-deadline-alert` to `lib/Standards/rules/payroll.json` (domain reporting, jurisdiction NL, framework `nl-pensioenaangifte`, SIVI sourceUrl); add the framework slug to `lib/Standards/rules/SCHEMA.md` examples; bump `RuleCatalogue::VERSION` per REQ-PFU-004
- [ ] 5. Audit context: extend `RuleAuditService::audit()` with the `$context['related']` cross-type pre-pass (PayrollRun id/period/status index + approved-run period set; PensionFiling period set) per REQ-PFU-005
- [ ] 6. Checks: implement `lib/Standards/Checks/NlPensionFilingChecks.php` (CheckProvider, no SeedsObjects) with the three predicates — reference integrity fail-closed via context, run-period completeness on `PayrollRun`, deadline overdue/14-day window on unsent filings — per REQ-PFU-005
- [ ] 7. Unit tests: PHPUnit coverage for the guard (draft denies, approved/posted/paid allow, empty/dangling ref denies) and the three checks (context-driven happy/violating paths, sent-filing never alerts) — extend `tests/Unit/`, bootstrap per `tests/bootstrap.php`
- [ ] 8. Manifest: add `PensionFilings` index page, `PensionFilingDetail` detail page (stats-block, data, related, audit tab, lifecycleActions), and the Loonadministratie menu entry per REQ-PFU-006; `npm run check:manifest` passes
- [ ] 9. Seed data: add the two approved PayrollRun seeds (GL-consistent) and three PensionFiling seeds to `lib/Settings/register.d/hr-seed.json` per REQ-PFU-007 (placeholders only)
- [ ] 10. Quality gates: `composer check:strict` green; run `occ hrmq:rules:audit` against seeded data in the dev container and confirm the expected `nl-upa-deadline-alert` violation (seed 3) appears, no completeness/reference violations on seed data, and no pre-existing rule regresses

Acceptance criteria (plain reminders, not tasks):
- lifecycle edges exactly as REQ-PFU-002 (no concept→verzonden shortcut; heropenen never from verzonden)
- the guard never allows on error — empty/dangling/unloadable references all deny
- corpus rule ids/framework/sourceUrls exactly as the design.md table; severity vocabulary is mandatory/conditional/recommended only
- fund enum exactly `abp|spw|bpf-bouw|schoonmaak|pfab|pwri`; adding funds later is an enum append, never a rename
- i18n: new page labels/actions use English keys per ADR-007 (Dutch strings go to l10n once `hrmq-i18n-locale-completeness` lands; keep keys stable)
