---
kind: config
---

## Why

Humaniq has no public product page (`conduction-website/src/pages/apps/humaniq.mdx` did not exist, EN or
NL) and no `docs/` Docusaurus site, while `appinfo/info.xml`'s description undersold the app's only
user-facing UI (Timesheets/Expenses) and oversold the register-only schemas (Employee /
EmploymentContract / PayrollRun / Payslip / WageTaxFiling) as if Humaniq shipped UI for them. Before a
beta release, code metadata, the product page, and docs need to agree on one feature vocabulary,
and every marketing claim needs to trace to shipped code — not to a register schema with no
consuming UI.

## What Changes

**Canonical feature list** (derived from `src/manifest.json` pages/menu + `lib/Standards/` +
`lib/Command/` — the only things a user or operator can actually exercise):

1. **Timesheets** — `src/manifest.json` `Timesheets`/`TimesheetApproval`/`TimesheetDetail` pages;
   per-employee, per-period hour registration with a submit → approve/reject/reopen workflow
   (`lib/Settings/register.d/hr-timesheet.json`).
2. **Expense claims** — `Expenses`/`ExpenseApproval`/`ExpenseDetail` pages; submit →
   approve/reject → reimbursed workflow (`lib/Settings/register.d/hr-expense.json`).
3. **HR/payroll compliance rule engine** — `lib/Standards/RuleEngine.php` +
   `lib/Standards/RuleCatalogue.php` + `lib/Standards/Checks/*` (auto-discovered
   `CheckProvider`s: `NlPayrollChecks`, `NlWageTaxFilingChecks`, `EuUsPayrollChecks`) audit the
   register's Employee / EmploymentContract / PayrollRun / Payslip / WageTaxFiling objects against
   a versioned corpus (`lib/Standards/rules/payroll.json`, 1074 lines) of EU labour directives, ILO
   conventions, GDPR, and Dutch labour/wage-tax law (Wet minimumloon, Wet DBA, Wet LB 1964, Wfsv,
   Zvw) via `occ humaniq:rules:audit` (`lib/Command/RulesAuditCommand.php`).
4. **`occ humaniq:rules:seed-testdata`** — idempotent local test-data seeding
   (`lib/Command/RulesSeedTestDataCommand.php`).
5. **OpenRegister-backed data layer** — Employee, EmploymentContract, PayrollRun, Payslip,
   WageTaxFiling, Timesheet, Expense objects all live in the OpenRegister `hrmq` register
   (`lib/Settings/humaniq_register.json` + `register.d/*.json`, imported by
   `OCA\Humaniq\Repair\InitializeRegister`); Humaniq owns no database tables of its own.

**Verified gap, not fixed here (out of scope for surface alignment):** the register schemas for
Employee / EmploymentContract / PayrollRun / Payslip / WageTaxFiling exist and are audited by the
RuleEngine, but there is **no manifest page/UI** for any of them (`grep -o '"route": "[^"]*"'
src/manifest.json` returns only Timesheets/Expenses routes) — they are only reachable via the
register API or the two `occ` commands. The product page and info.xml description below are worded
to reflect this honestly (data model + CLI audit, not a UI to manage them).

### Per-surface edits

1. **`humaniq/appinfo/info.xml`**
   - EN + NL `<description>`: added the two shipped UI features (Timesheets, Expense claims) that
     were previously omitted entirely; kept the compliance-engine claims (already accurate,
     verified against `lib/Standards/`); did not add any claim about a UI for
     Employee/EmploymentContract/PayrollRun/Payslip/WageTaxFiling.
   - `<website>`/`<documentation>`/`<discussion>`/`<bugs>`/`<repository>`/`<screenshot>`: switched
     from GitHub to Codeberg, because the GitHub repo's `README.md` then stated it "has moved to
     Codeberg" and was a read-only mirror; info.xml was still pointing users/reviewers at the mirror.
     **Superseded**: Codeberg is retired — every repo URL now points at
     `github.com/ConductionNL/humaniq` again.
   - `<dependencies>`: added an explanatory XML comment declaring the OpenRegister dependency (NC's
     `app-info.xsd` has no `<dependency>` element for cross-app deps; the fleet convention, e.g.
     `procest/appinfo/info.xml`, is a comment). Verified: `src/manifest.json` declares
     `"dependencies": ["openregister"]`, and Humaniq has no own controllers beyond the SPA shell — all
     data access goes through OpenRegister.
   - `<version>` left at `0.1.0` (unchanged) — per the alignment brief this is the source of truth;
     the product page mirrors it as `v0.1`.

2. **`conduction-website/src/pages/apps/humaniq.mdx`** (new) and
   **`conduction-website/i18n/nl/docusaurus-plugin-content-pages/apps/humaniq.mdx`** (new) — authored
   from the `apps/shillinq.mdx` structure (`DetailHero` + `Section`/`FeatureList` + `PairRow` +
   `CtaBanner`). Feature list = items 1-3 above, worded identically to the reconciled info.xml
   description. `PairRow` lists only OpenRegister — the only verified dependency; a search for
   Shillinq/DocuDesk/LaunchPad/PipelinQ integration calls in `lib/` and `src/` found none (one
   register-schema comment in `hr-expense.json` explicitly says AP/GL posting is "a separate
   integration concern that OpenConnector/shillinq own" — not yet wired — so no such claim was
   made on the product page).

3. **`conduction-website/src/data/apps-catalog.js`** and
   **`conduction-website/src/components/AppGlyph/AppGlyph.jsx`** — added an `humaniq` entry
   (`categories: ['Processes']`, matching `info.xml`'s `<category>organization</category>` peers
   Shillinq/Procest/PipelinQ) and an `HR` monogram, so the new product page actually surfaces in
   the `/apps` grid instead of 404ing with no inbound link. Without this the `.mdx` page would be
   reachable only by direct URL.

4. **`humaniq/src/manifest.json` / `manifest.d/`** — no changes. The nav labels (`Uren`,
   `Onkosten`, `Urenregistratie`, `Declaraties`, etc.) already match the canonical feature
   vocabulary above; this was the source of truth the other surfaces were reconciled against.

### Claims verified vs. removed

| Claim | Verified? | Action |
| --- | --- | --- |
| Timesheets with approval workflow | Yes — `src/manifest.json`, `hr-timesheet.json` | Added to info.xml + product page (was missing from both) |
| Expense claims with approval workflow | Yes — `src/manifest.json`, `hr-expense.json` | Added to info.xml + product page (was missing from both) |
| Compliance rule engine (EU labour, ILO, GDPR, NL labour/wage-tax law) | Yes — `lib/Standards/RuleEngine.php`, `RuleCatalogue.php`, `Checks/*`, `rules/payroll.json` | Kept, worded identically across all surfaces |
| `occ humaniq:rules:audit` / `occ humaniq:rules:seed-testdata` | Yes — `lib/Command/*`, registered in `info.xml` `<commands>` | Kept |
| "All Employee/EmploymentContract/Payslip/PayrollRun objects" implying managed UI | Partially — objects exist in the register, but no manifest page reads/writes them | Reworded to "register data model audited by the rule engine," not "managed in the app" |
| Any Peppol/SEPA/BBV/DigiD-style compliance mark | Not found anywhere in `lib/`/`src/`/README | None made; not applicable to this app (that vocabulary belongs to Shillinq, not Humaniq) |
| Shillinq/DocuDesk/LaunchPad live integration | Not found — one schema comment explicitly disclaims it as unbuilt | Not claimed |

### Icon status

`humaniq/img/app.svg` (white fill, `viewBox="0 0 24 24"`, no background — brand convention),
`app-dark.svg` (same path, no fill override for dark-mode contrast), and `app-store.svg` (512×512
hexagon, `#21468B` cobalt background per brand hex, white icon) all comply with the app-icon
convention. No mismatch found; the product-page hero icon reuses the same glyph path for
consistency.

### Still misaligned / needs a decision

- **No `docs/` Docusaurus site** (`hrmq.conduction.nl` would 404). Assessed against
  `journeydoc-init`: that skill scaffolds a full 8-artifact tutorial site + opens a PR, which is
  out of proportion for a local, no-push alignment pass and duplicates work the app owner should
  decide to invest in deliberately (domain wiring, first tutorial story, sidebar). Flagging rather
  than scaffolding, per the brief's "don't scaffold a whole site unless trivial" — it is not
  trivial here (no existing scaffold to extend, unlike apps that already have a `docs/` skeleton
  missing only a chapter).
- **`@conduction/docusaurus-preset`'s shipped `apps-registry.js`** (consumed by `<AppCrossLinks/>`
  and the academy product filter) has no `humaniq` entry. That file lives in the preset package
  (`conduction-website/node_modules/@conduction/docusaurus-preset/...`), not in this repo's source
  tree, so it can't be edited here — it needs a follow-up change in the `docusaurus-preset` repo
  itself (mirrors the file's own header comment: "Names and href here are also mirrored in the
  apps-registry shipped from `@conduction/docusaurus-preset`... When you add or rename an app,
  update both.").
- **App Store listing**: per the 2026-07-07 fleet App Store audit, Humaniq was among the 14 apps not
  yet published to apps.nextcloud.com — `apps-catalog.js`'s `getApps()` will render it as "COMING
  SOON" until the `app-downloads.json` refresh picks up a real store/GitHub release, which is
  expected and requires no code change.
