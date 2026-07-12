# Tasks — loonaangifte-filing-lifecycle

- [x] 1. Schema: add `x-openregister-lifecycle` (concept→klaargezet→bevestigd→verzonden, heropenen, corrigeren) to `LoonaangifteFiling` in `lib/Settings/register.d/hr-objects.json` per REQ-LFL-001
  - transition descriptions document the `submittedDate`/`verzondenDoor` stamping on `verzenden`
- [x] 2. Schema: add `status`, `tijdvakcode`, `aangiftenummer`, `betalingskenmerk`, `responseStatus`, `responseMessage`, `verzondenDoor` properties + bump `LoonaangifteFiling` version to 0.2.0 per REQ-LFL-002
- [x] 3. Corpus: add `nl-loonaangifte-tijdvakcode` (with 2026 `parameters` code tables), `nl-loonaangifte-deadline-derivation`, `nl-loonaangifte-deadline-alert` to `lib/Standards/rules/payroll.json` per REQ-LFL-003
- [x] 4. Checks: implement the three check methods in `lib/Standards/Checks/NlWageTaxFilingChecks.php` (tijdvakcode table read from rule parameters, NOT hard-coded; deadline = period end + 1 calendar month, no weekend extension; alert window 14 days) per REQ-LFL-004
- [x] 5. Unit tests: PHPUnit coverage for the three checks — happy path, wrong code, weekend-deadline non-extension, overdue vs approaching vs sent (extend `tests/Unit/`, bootstrap per `tests/bootstrap.php`)
- [x] 6. Manifest: index columns + default deadline sort on `LoonaangifteFilings`; stats-block + lifecycleActions on `LoonaangifteFilingDetail` per REQ-LFL-005; `npm run check:manifest` passes
- [x] 7. Seed data: add the four lifecycle-state filings to `lib/Settings/register.d/hr-seed.json` per REQ-LFL-006 (placeholders only)
- [x] 8. Quality gates: `composer check:strict` green; run `occ hrmq:rules:audit` against seeded data in the dev container and confirm the expected violations (tasks 3-4) appear and no pre-existing rule regresses

Acceptance criteria (plain reminders, not tasks):
- lifecycle edges exactly as REQ-LFL-001 (no concept→verzonden shortcut)
- tijdvakcode pattern validation rejects malformed codes
- corpus rule ids/frameworks/sourceUrls exactly as design.md table
- i18n: new page labels/actions use English keys per ADR-007 (Dutch strings go to l10n once `hrmq-i18n-locale-completeness` lands; keep keys stable)
