## 0. Dependency

- [x] 0.1 filinq `templates-employer-statement` is merged and its template imports on the dev
      instance (`GET api/templates?namespace=hrmq` lists `werkgeversverklaring-standaard`).
      Done 2026-11-25 on a sandbox Nextcloud 34.0.6 (MariaDB 10.11, OpenRegister main 7997381d, humaniq development 89e5e1bf, filinq development 932a955): filinq development ships the template in its register
      (the change folder is still open in filinq, the code is merged); with filinq's template
      register and schema set (`template_register`, `template_schema`, as its admin settings do),
      `GET api/templates?namespace=hrmq` lists `werkgeversverklaring-standaard` (1 result).

## 1. Service

- [x] 1.1 In `lib/Service/HrDocumentService.php`, leave `werkgeversverklaring` out of the
      idempotency key (D2, REQ-HDD-012). Verify: unit test that two calls make two documents,
      and that `arbeidsovereenkomst` still makes one.

## 2. Page

- [x] 2.1 Add the `Werkgeversverklaring maken` action to `EmploymentContractDetail` in
      `src/manifest.d/hr-objects.json`: `type: api-call`, `POST`, `/api/documents/generate`,
      `params: {contractId: "@objectId", documentType: "werkgeversverklaring"}`,
      `confirm: true`, success and failure toasts; en and nl keys. Verify:
      `npm run check:manifest` exits 0.
- [x] 2.2 Controller test: an unreadable contract returns 404 with no filinq call
      (REQ-HDD-011).

## 3. Hand-over

- [x] 3.1 `@spec` tags and an e2e test or a reason-bearing `@e2e exclude` per scenario.
      `@spec` REQ-HDD-011 on `DocumentController::generate()` and `letterEmployeeId()` (REQ-HDD-012
      was already on `HrDocumentService`); all four scenarios in
      `tests/e2e/spec-coverage/employer-statement-from-the-contract.spec.ts`, the two that need
      filinq with or without its template skip naming what is missing. Lints and lists; not run
      against an instance yet.
- [x] 3.2 Live check: make a statement from the contract page on the dev instance with filinq;
      note the outcome in the PR body.
      Done 2026-11-25 on the same sandbox. The first attempt failed: "Make employer statement"
      posted to `/index.php/api/documents/generate` (404), because nextcloud-vue sends an
      api-call `url` through `generateUrl()`, which does not add the app, and all 45 humaniq
      api-call urls read `/api/...`. They now read `/apps/humaniq/api/...` (guard:
      `tests/js/apiCallUrlsNameTheApp.test.mjs`). After that the page makes the statement (200,
      status `generated`, PDF stored), and
      `tests/e2e/spec-coverage/employer-statement-from-the-contract.spec.ts` runs 3 passed and 1
      skipped (the without-template scenario, the template being present), after two spec fixes:
      the document is read back through the outcome's `generatedDocumentId`, and the confirm
      button is found on the page, not inside the element carrying `data-testid-modal`.
