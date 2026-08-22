> **Scope expansion versus this draft (recorded during implementation).**
>
> The draft was written against the pre-ADR-037 monolithic `src/manifest.json` and
> named six menu labels and two page descriptions. Two things had moved by the time
> it was implemented:
>
> 1. **The fragment pipeline landed.** `src/manifest.json` is now only the shell
>    ($schema/version/dependencies/deepLinks/runtime + the seven menu group shells +
>    three app-shell pages); every page, menu leaf, widget and lifecycle action lives
>    in `src/manifest.d/*.json` and is merged by `buildManifest()`. Editing the base
>    manifest alone would have changed almost nothing a user sees.
> 2. **The full label surface, not six labels.** The Dutch-literal-as-key defect ran
>    through eleven display properties, not two: `label`, `title`, `description`,
>    `successMessage`, `errorMessage`, `dataTitle`, `caption`, `emptyText`,
>    `relatedTitle`, `statusTitle` and banner `text`.
>
> Delivered: **463 string conversions across 32 manifest files** — 169 `label`
> (60 menu leaves in `05-menu.json` + 7 group shells in the base + column headers,
> header actions and lifecycle transition labels), 157 `title`, 53 `description`,
> 27 `successMessage`, 18 `errorMessage`, 14 `dataTitle`, 11 `caption`, 8 `emptyText`,
> 2 `relatedTitle`, 2 banner `text`, 1 `statusTitle`. Catalogues carry **465 keys each**
> (439 distinct manifest strings + 30 `t('hrmq', …)` keys from `src/views/*.vue`,
> 4 overlapping). A mechanical guard (`npm run check:l10n`) and its CI wiring were
> added so the defect cannot come back silently.

## 1. Convert manifest text to English source keys (code)

- [x] 1.1 Menu `label` values converted to English sentence-case source keys. Scope
      expanded from the draft's six to **all 169 labels**, and from
      `src/manifest.json` alone to the base **plus every `src/manifest.d/*.json`
      fragment** (ADR-037 pipeline): the seven group shells in the base
      (`Mijn HR` → `My HR`, `Personeel` → `People`, `Declaraties & assets` →
      `Expenses & assets`, `Verlof & verzuim` → `Leave & absence`,
      `Loonadministratie` → `Payroll`, `Roostering` → `Scheduling`,
      `Configuratie` → `Configuration`), the 60 menu leaves in `05-menu.json`
      (`Urenstaten` → `Timesheets`, `Urengoedkeuring` → `Timesheet approval`,
      `Declaraties` → `Expenses`, `Declaratiegoedkeuring` → `Expense approval`, …),
      plus column headers, header actions and lifecycle transition labels.
- [x] 1.2 Page `title` (157) and `config.description` (53) values converted, along
      with the seven further display properties the draft did not name —
      `successMessage` (27), `errorMessage` (18), `dataTitle` (14), `caption` (11),
      `emptyText` (8), `relatedTitle` (2), `statusTitle` (1) and banner `text` (2).
      `"Ingediende urenstaten die wachten op goedkeuring of afkeuring — open een
      urenstaat om goed of af te keuren."` → `"Submitted timesheets awaiting approval
      or rejection — open a timesheet to approve or reject it."`, and the expense and
      leave equivalents.
- [x] 1.3 The already-English widget content (`"Total hours"`, `"Claimed amount"`,
      `"Related"`, …) left as-is — they were already correct English source keys.
      They still gained an `l10n/nl.json` translation, because ADR-007 requires the
      key sets to be identical and an English-only key renders untranslated in a
      Dutch session.
- [x] 1.4 **Lifecycle action labels unified.** `LeaveTransaction` and `CompAdjustment`
      labelled their transitions `Indienen`/`Goedkeuren`/`Afwijzen` while
      `Timesheet`/`Expense`/`LeaveRequest` already used `Submit`/`Approve`/`Reject` —
      the same action wore two languages depending on which object you opened. All
      now use the English keys. The transition **`action` ids** (`"action": "indienen"`)
      are backend contract and were deliberately NOT touched.
- [x] 1.5 **Two pre-existing ADR-007 violations fixed in passing**: the title
      `"Key Result"` → `"Key result"` (title case; the sibling `dataTitle` was already
      sentence case), and the half-translated `"No uitgiftes yet"` →
      `"No asset assignments yet"`.
- [x] 1.6 **Deliberately NOT changed**, and why: schema `title`/`description` in
      `lib/Settings/register.d/*.json` (already English, and the canonical source for
      form field labels — rendered by OpenRegister, not by hrmq's manifest renderer);
      route paths and page/menu/widget ids (`WntDisclosures`, `/mijn/urenstaten`);
      lifecycle transition `action` ids; `"documentType": "loonstrook"` (an API payload
      value); the loonbeslag withdraw `reason` (written as object DATA, not displayed);
      and all `_note`/`x-notes` maintainer prose.
- [x] 1.7 **Known quirk preserved, not silently fixed**: `WntDisclosures` (index) and
      `WntDisclosureDetail` share the identical literal `"WNT-verantwoording"`, so both
      resolve to the same English key `"WNT disclosures"` — the detail page therefore
      still carries the index's heading. Translating the key did not create this; it
      is pre-existing and is left visible for a follow-up rather than quietly diverged.

## 2. Ship real locale catalogues

- [x] 2.1 `l10n/en.json` created — identity-mapped (`key === value`), **465 keys**,
      covering all 439 distinct manifest display strings plus the 30
      `t('hrmq', …)` keys in `src/views/ProformaPayslip.vue` and
      `src/views/AdministrationSwitcher.vue` (4 overlap: `Administrations`,
      `Simulate payslip`, `Gross pay`, `Net pay`).
- [x] 2.2 `l10n/nl.json` created — the same 465 keys with zero gaps, carrying the exact
      Dutch literals removed from the manifest, plus a Dutch translation for every
      string that was *already* English (those had no Dutch anywhere before, which is
      why the Dutch nav mixed languages). Both files use Nextcloud core's catalogue
      shape (`{ "translations": {…}, "pluralForm": "nplurals=2; plural=(n != 1);" }`).
- [x] 2.3 Confirmed no build or `appinfo/info.xml` wiring is needed: Nextcloud serves
      `/custom_apps/hrmq/l10n/<locale>.json` as a static file, exactly as it does for
      the sibling apps (`decidesk/l10n/`, `docudesk/l10n/`, …), which declare nothing.
      Verified `l10n/` is not swallowed by `.gitignore` (`git check-ignore -v` reports
      no match) — a substring glob has eaten real source files in this fleet before,
      and `git add` skips an ignored file without a word.

## 3. Guard the fix (added — the draft had no mechanical check)

- [x] 3.1 `tests/validate-l10n-parity.js` added (pure node, no deps, matching
      `tests/validate-seed-refs.js`'s conventions). It asserts: both catalogues exist,
      parse and carry `pluralForm`; identical key sets in both directions; `en.json` is
      identity-mapped; every `nl.json` value is a non-empty string; every manifest
      display string is a key in both; every `t('hrmq', …)` key in `src/**` is a key in
      both; and **zero Dutch literals survive in the manifest surface**, detected by
      Dutch function words (`de`/`het`/`een`/…) that cannot occur in English prose —
      so statutory Dutch proper nouns (WNT, WKR, TWK, Cao Gemeenten, UPA) legitimately
      survive while Dutch *sentences* do not.
- [x] 3.2 Wired as `npm run check:l10n` and added to the `frontend-checks` matrix in
      `.github/workflows/code-quality.yml`. That array is a real job matrix in the
      reusable workflow — a listed check whose npm script is missing hard-errors, so
      the two must land together.
- [x] 3.3 Guard proved to FAIL on the pre-change tree (restored `hr-leave.json` from
      HEAD: reported the missing keys *and* the surviving Dutch literals) and to PASS
      on the converted tree. A check that cannot fail is indistinguishable from one
      that passes.

## 4. e2e

- [x] 4.1 `tests/e2e/spec-coverage/hours-process.spec.ts` — `revealNavLeaf` matched
      leaf text exactly, and that text is now translated. The two call sites take
      bilingual RegExps (`/^(My timesheets|Mijn urenstaten)$/`,
      `/^(Time entries|Urenboekingen)$/`), keeping the spec pinned to *reachability*
      rather than to whichever locale CI boots in. The group is still expanded by
      `data-testid`, which was already language-independent.
- [x] 4.2 Audited `personal-dashboard.spec.ts`, `manifest-pages.spec.ts`,
      `core-journeys.spec.ts` and `host-app-pages.spec.ts`. No further assertion
      breaks: they address the UI by `data-testid`, page id, `href`, schema-derived
      `Add <Schema>` buttons and schema field titles — none of which this change
      touches. `host-app-pages.spec.ts` already matched both languages; its comment
      explaining *why* was inverted by this change and has been corrected, as has a
      stale `core-journeys.spec.ts` comment quoting the old Dutch title.
- [x] 4.3 The Dutch **guard** messages asserted in `hours-process.spec.ts`
      (`/eigen urenstaat/i`, `/bevat geen urenboekingen|telt op tot nul uren/`,
      `komt niet overeen`) come from PHP (`NoSelfApprovalGuard`,
      `TimesheetNotEmptyGuard`) and from seed data — they are out of this change's
      scope and stay Dutch.

## 5. Verify

- [x] 5.1 `node tests/validate-manifest.js` — Ajv PASS (0 errors), 113 effective pages,
      schema 2.23.0.
- [x] 5.2 `node tests/verify-manifest-parity.js` — re-baselined through the harness's
      own exports (`buildEffectiveManifest`/`canonical`), after first proving the
      regeneration recipe reproduces the *committed* fixtures byte-for-byte from HEAD.
      `pages.json` is UNCHANGED — the strongest available evidence that no route, id or
      page was touched; only `menu.json` and `manifest-canonical.json` moved.
      Re-run: 113/113 pages byte-identical, menu structurally equal, 6/6 collision
      routes resolve, PASS.
- [x] 5.3 `node tests/validate-widget-keys.js` — output is **byte-identical** before and
      after the change (verified by stashing `src/`), so its pre-existing exit 1 is no
      worse than `tests/fixtures/manifest-baseline/validate-widget-keys.baseline.txt`,
      which also ends `exit=1`.
- [x] 5.4 `node scripts/check-manifest-sentinels.mjs` — PASS, 44 optional
      `@workspace.*?` clauses all drop against an empty context.
- [x] 5.5 `node tests/validate-seed-refs.js` — PASS, all 107 seed relation values
      importable.
- [x] 5.6 `npm run check:l10n` — PASS (465 keys per catalogue, 439 manifest strings,
      30 `t('hrmq', …)` keys).
- [x] 5.7 `npm run build` — exit 0. `npm run lint` — clean.
      `PLAYWRIGHT_BASE_URL=… npx playwright test --list` — 55 tests in 5 files.
- [x] 5.8 `composer install && vendor/bin/phpunit` — 1283 tests, 4960 assertions,
      1 skipped, exit 0.
- [ ] 5.9 Live locale check against a running instance (nl_NL renders the Dutch labels
      from `l10n/nl.json`; en_US renders English; `loadTranslations('hrmq', …)` no
      longer 404s). NOT done in this run — no instance was serving this checkout. The
      mechanical guard covers catalogue completeness; it cannot prove the HTTP fetch
      resolves.
