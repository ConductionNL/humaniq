## 1. Plan

- [ ] 1.1 Add the `hr-deployment.json` fragment with `DeploymentPlan`, its lifecycle and
      calculations, and list it in `humaniq_register.json`. Verify: `occ maintenance:repair`
      imports it and `npm run check:schema-l10n` exits 0.
- [ ] 1.2 Add `CaoRegistry::annualNormHours()`, null for unverified or placeholder values.
      Verify: unit test that today's CAO PO file answers null and a verified fixture answers
      1659.
- [ ] 1.3 Verify the CAO PO normjaartaak against the PO-Raad CAO text and record the source.
      Verify: the leaf's `verified` flips only with a primary-source citation in `source`.
- [ ] 1.4 Add `DeploymentPlanService::normFor()`. Verify: unit test for a full-time contract,
      a 0.6 contract, and a contract starting in January (half the school year).

## 2. School view

- [ ] 2.1 Add `GET /api/deployment/org-units/{id}`. Verify: controller test that an
      unreadable unit answers 404 and a teacher without a plan is listed as such;
      `hydra-gate-route-auth` and `hydra-gate-no-admin-idor` pass.
- [ ] 2.2 Add `DeploymentPlans`, `DeploymentPlanDetail`, `Taakbeleid` and the menu entry.
      Verify: `npm run check:manifest` and `npm run lint` exit 0.

## 3. IPTO export

- [ ] 3.1 Obtain the current IPTO file specification from OCW and DUO and record its version.
      Verify: the specification document is linked in the service docblock.
- [ ] 3.2 Add `brinNummer` to `hrAdministration` and `IptoExportService`. Verify: unit test
      against a fixture built from the specification's own example.
- [ ] 3.3 Add `POST /api/deployment/ipto-export` (admin or HR) and the action. Verify:
      controller test that only `vastgesteld` plans are exported.
- [ ] 3.4 Seed the school, teachers and plans. Verify: `npm run check:seed-refs` exits 0.

## 4. Verification

- [ ] 4.1 e2e test or a reason-bearing `@e2e exclude` per scenario (gate 19), and `@spec`
      tags on the new methods (gate 16).
- [ ] 4.2 One live check: open `Taakbeleid` for the seeded school and read the overload;
      record the screenshot in the PR.
