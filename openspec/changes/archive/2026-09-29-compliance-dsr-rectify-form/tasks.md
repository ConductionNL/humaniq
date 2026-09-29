## 1. Data and endpoint

- [x] 1.1 Add `DsrRequest.requestedChanges` (field enum, value), bump the version, seed one
      request. Verify: `occ maintenance:repair` imports it; `npm run check:seed-refs` exits 0.
- [x] 1.2 Accept the list form in `validateRectifyInput()` and refuse fields outside the list.
      Verify: controller tests for a list, a map (occ), an empty list and a forbidden field.

## 2. Page

- [x] 2.1 Pass `changes: "@object.requestedChanges"` on `dsr-rectify` and update the note.
      Verify: `npm run check:manifest` exits 0.

## 3. Verification

- [x] 3.1 e2e or reason-bearing `@e2e exclude` per scenario (gate 19), `@spec` tags (gate 16),
      and a live rectification from `DsrRequestDetail` on a dev instance.

Verified 2026-09-29: red run 1 error and 4 failures (DsrRectifyDeclarationTest, two new
AvgDsrControllerTest cases) before the code; green after. The live rectification is the
live-check recipe in the PR body, not run in this lane.
