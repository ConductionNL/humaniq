## 1. Endpoints and job

- [ ] 1.1 Replace the jaaropgaaf refusal in `DocumentController::generate()` with the guarded
      single-statement branch. Verify: controller tests for 404 on an unreadable statement and
      a call to `generateJaaropgaaf()` with its employee and year.
- [ ] 1.2 Add `JaaropgaafYearJob` and `POST /api/documents/jaaropgaven`. Verify: controller test
      that an employee role gets 403, the current year gets 400 and last year gets 202 with the
      count; `hydra-gate-route-auth` and `hydra-gate-no-admin-idor` pass.

## 2. Pages

- [ ] 2.1 Add "Generate PDF" to `JaaropgaafDetail` and "Generate last year's statements" to
      `Jaaropgaven`, and update the page note. Verify: `npm run check:manifest` exits 0.

## 3. Verification

- [ ] 3.1 e2e test or a reason-bearing `@e2e exclude` per scenario (gate 19), and `@spec` tags
      on every new method (gate 16).
- [ ] 3.2 One live check on a dev instance: generate one statement from its page and queue a
      year; record screenshots in the PR.
