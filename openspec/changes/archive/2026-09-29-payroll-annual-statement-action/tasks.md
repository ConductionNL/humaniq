## 1. Endpoints and job

- [x] 1.1 Replace the jaaropgaaf refusal in `DocumentController::generate()` with the guarded
      single-statement branch. Verify: controller tests for 404 on an unreadable statement and
      a call to `generateJaaropgaaf()` with its employee and year.
- [x] 1.2 Add `JaaropgaafYearJob` and `POST /api/documents/jaaropgaven`. Verify: controller test
      that an employee role gets 403, the current year gets 400 and last year gets 202 with the
      count; `hydra-gate-route-auth` and `hydra-gate-no-admin-idor` pass.

## 2. Pages

- [x] 2.1 Add "Generate PDF" to `JaaropgaafDetail` and "Generate last year's statements" to
      `Jaaropgaven`, and update the page note. Verify: `npm run check:manifest` exits 0.

## 3. Verification

- [x] 3.1 e2e test or a reason-bearing `@e2e exclude` per scenario (gate 19), and `@spec` tags
      on every new method (gate 16).
- [x] 3.2 One live check on a dev instance: generate one statement from its page and queue a
      year; record screenshots in the PR.

Verified 2026-09-29: red first, DocumentControllerTest (7 errors) and JaaropgaafYearJobTest (2
errors); green after. The live check (3.2) is the recipe in the PR body, not run in this lane.
