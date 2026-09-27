## 1. Secondment

- [ ] 1.1 Add `lib/Settings/register.d/hr-secondment.json` with `Secondment` and its
      lifecycle. Verify: `occ maintenance:repair` imports it through `InitializeRegister`.
- [ ] 1.2 Add the `secondment` source to `AgendaComposer` and the kind to
      `AvailabilityService::committedHours()`. Verify: unit test that a 16-hour secondment
      lowers available hours by 16 a week and that a `concept` secondment does not.
- [ ] 1.3 Add `Secondments`, `SecondmentDetail` and an object list on `EmployeeDetail`.
      Verify: `npm run check:manifest` exits 0.

## 2. Side activities

- [ ] 2.1 Add `SideActivity` with its lifecycle and `NoSelfApprovalGuard`. Verify: import
      succeeds; unit test that the subject cannot approve their own report.
- [ ] 2.2 Add `SideActivityAttestationListener`. Verify: unit tests for a first report
      (flag true), a nil report (flag true) and a withdrawn last report (flag false).
- [ ] 2.3 Add `SideActivities`, `SideActivityDetail` and `MijnNevenwerkzaamheden` (`userId:
      "@me"`, report form). Verify: `npm run check:manifest` exits 0.

## 3. Seed and verification

- [ ] 3.1 Seed the two examples. Verify: `npm run check:seed-refs` exits 0.
- [ ] 3.2 e2e or reason-bearing `@e2e exclude` per scenario (gate 19), `@spec` tags (gate
      16), and a live report and approval of a side activity.
