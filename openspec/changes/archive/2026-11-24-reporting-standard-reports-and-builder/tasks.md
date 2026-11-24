## 1. Ready-made reports

- [x] 1.1 Add `WageCostReport` and `PayrollRunsReport` with their cards. Verify:
      `npm run check:manifest` exits 0; the seeded runs show on both.
- [x] 1.2 Add `TurnoverReport` and `LeaveBalanceReport` with their cards. Verify: as 1.1.

## 2. Own reports

- [x] 2.1 Switch on `allowSavedViews` and `allowExport` on the seven index pages and
      `exportable` on their schemas. Verify: `npm run check:manifest` exits 0; the register
      imports.
- [x] 2.2 Add the "My reports" category on `Reports`. Verify: the seeded public view shows as a
      card and opens its page with the view applied.
- [x] 2.3 Seed the public view. Verify: `npm run check:seed-refs` exits 0.
      Done in the Zuiddrecht example-set fragment (`lib/Settings/example-sets/zuiddrecht.json`,
      `views`): a register seed cannot carry a saved view, OpenRegister's example set can. The view
      "Contracts ending within three months" is public, owned by `{{cast:anouk.uid}}`, over
      `humaniq`/`EmploymentContract` with `endDate[gte]` today and `endDate[lte]` today + 91 days,
      soonest first. A calendar quarter cannot be written relative to the install day, hence
      the name. `endDate` joins the `EmploymentContracts` filters so the list offers the range the
      view applies. `ZuiddrechtSharedReportTest`; `check:seed-refs` exits 0.

## 3. Verification

- [x] 3.1 e2e or reason-bearing `@e2e exclude` per scenario (gate 19), and a live save, share and
      export of a view by one user and its use by another.
      Written, runs in nightly: all four scenarios anchored in `standard-reports-and-builder.spec.ts`, including the save, share and export by one user and the use by another.
