## 1. Code metadata (`appinfo/info.xml`)

- [x] 1.1 Rewrite EN + NL `<description>` to include the shipped Timesheets and Expense-claims UI
      features (previously omitted), keep the verified compliance-rule-engine claims, and reword
      the register-object list so it reads as a data model audited by the rule engine rather than
      a managed UI.
- [x] 1.2 Add an explanatory `<dependencies>` XML comment declaring the OpenRegister app
      dependency, matching the fleet convention (`procest/appinfo/info.xml`).
- [x] 1.3 Switch `<website>`/`<documentation>`/`<discussion>`/`<bugs>`/`<repository>`/
      `<screenshot>` from the GitHub mirror to Codeberg (README stated the GitHub repo was then
      read-only). **Superseded**: Codeberg is retired; these now point at
      `github.com/ConductionNL/humaniq`.
- [x] 1.4 Leave `<version>0.1.0</version>` unchanged — it is the cross-surface source of truth.

## 2. Product page (`conduction-website/src/pages/apps/humaniq.mdx` + NL i18n)

- [x] 2.1 Author `conduction-website/src/pages/apps/humaniq.mdx` (EN) from the `apps/shillinq.mdx`
      structure: `DetailHero` (status Beta, version `v0.1`), feature list = Timesheets / Expense
      claims / compliance rule engine, `PairRow` limited to OpenRegister (only verified
      dependency), `CtaBanner`.
- [x] 2.2 Author `conduction-website/i18n/nl/docusaurus-plugin-content-pages/apps/humaniq.mdx` (NL)
      as a real Dutch translation (not a copy of the EN copy).
- [x] 2.3 Add an `humaniq` entry to `conduction-website/src/data/apps-catalog.js` `PRESENTATION`
      (`categories: ['Processes']`) so the new page surfaces on `/apps` instead of being
      unreachable without a direct URL.
- [x] 2.4 Add an `humaniq: 'HR'` monogram to `conduction-website/src/components/AppGlyph/AppGlyph.jsx`.

## 3. Docs site — flagged, not scaffolded

- [ ] 3.1 Decide whether to invest in a `docs/` Docusaurus site for Humaniq via `journeydoc-init`
      (app-owner decision — out of scope for this alignment pass; see proposal.md "Still
      misaligned").

## 4. Follow-ups outside this repo

- [ ] 4.1 File/track a change against `docusaurus-preset` to add an `humaniq` entry to its shipped
      `data/apps-registry.js` (consumed by `<AppCrossLinks/>` and the academy product filter) —
      cannot be edited from this repo since it ships from the preset's own package.
