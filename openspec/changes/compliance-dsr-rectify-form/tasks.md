## 1. Data and endpoint

- [ ] 1.1 Add `DsrRequest.requestedChanges` (field enum, value), bump the version, seed one
      request. Verify: `occ maintenance:repair` imports it; `npm run check:seed-refs` exits 0.
- [ ] 1.2 Accept the list form in `validateRectifyInput()` and refuse fields outside the list.
      Verify: controller tests for a list, a map (occ), an empty list and a forbidden field.

## 2. Page

- [ ] 2.1 Pass `changes: "@object.requestedChanges"` on `dsr-rectify` and update the note.
      Verify: `npm run check:manifest` exits 0.

## 3. Verification

- [ ] 3.1 e2e or reason-bearing `@e2e exclude` per scenario (gate 19), `@spec` tags (gate 16),
      and a live rectification from `DsrRequestDetail` on a dev instance.
