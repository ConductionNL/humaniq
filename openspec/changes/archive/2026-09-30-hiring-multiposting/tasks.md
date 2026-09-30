## 1. Schemas

- [x] 1.1 Add `Vacancy.channels` and the `VacancyPosting` schema to `hr-ats.json`, and list
      `VacancyPosting` in `humaniq_register.json`. Verify: `occ maintenance:repair` imports
      the register with the new schema and `npm run check:schema-l10n` exits 0.
- [x] 1.2 Rewrite the `Vacancy` description and the `publiceren` note. Verify: `grep -n
      "no external multiposting" lib/Settings/register.d/hr-ats.json` finds nothing.

## 2. Flows

- [x] 2.1 Declare `Vacature plaatsen` on `Vacancy` (trigger on `publiceren`, iterate
      channels, mapping, source call, posting write, error exit). Verify: after
      `occ maintenance:repair` the flow is listed in openregister's flow store as disabled
      and scoped to `Vacancy`.
- [x] 2.2 Declare `Vacature intrekken` on `Vacancy` (trigger on `sluiten`). Verify: same
      import check.
- [ ] 2.3 With a stub integriq source, publish a vacancy with two channels and one unknown
      channel. Verify: two postings `geplaatst`, one `mislukt` with the reason.

## 3. Pages and seed

- [x] 3.1 Add the channel choice and the postings `object-list` to `VacancyDetail`. Verify:
      `npm run check:manifest` exits 0.
- [x] 3.2 Seed the two postings from design.md. Verify: `npm run check:seed-refs` exits 0.

## 4. Verification

- [x] 4.1 e2e test or a reason-bearing `@e2e exclude` per scenario (gate 19).
- [ ] 4.2 One live check with integriq and a stub source: publish, read the postings on
      `VacancyDetail`, close, read `ingetrokken`; record the screenshot in the PR.
