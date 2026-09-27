## 1. Installable app

- [ ] 1.1 Add `PwaController` with `manifest()` and `serviceWorker()` and the two routes
      before `page#catchAll`. Verify: controller test for content types and the
      `Service-Worker-Allowed` header; `hydra-gate-route-auth` passes.
- [ ] 1.2 Add `src/service-worker.js`, its webpack entry, and the icons. Verify: `npm run
      lint` exits 0 and the build emits `service-worker.js`.
- [ ] 1.3 Link the web manifest in the page head from `Application.php`. Verify: the SPA
      page source carries the manifest link.
- [ ] 1.4 Cache the shell only. Verify: a unit test of the fetch handler that an
      `/apps/humaniq/api/` request is never answered from cache.

## 2. Phone use

- [ ] 2.1 Add the three quick actions to `MijnHr`. Verify: `npm run check:manifest` exits 0.
- [ ] 2.2 Add the capture hint to the expense receipt field. Verify: `npm run
      check:manifest` exits 0.
- [ ] 2.3 Add the opt-in notification setting on `Mijn HR` calling openregister's web-push
      registration. Verify: no permission prompt fires on page load in the e2e run.

## 3. Verification

- [ ] 3.1 e2e test or a reason-bearing `@e2e exclude` per scenario (gate 19), and `@spec`
      tags on the new methods (gate 16).
- [ ] 3.2 One live check on a phone: add `Mijn HR` to the home screen, request leave and
      photograph a receipt into an expense; record the screenshots in the PR.
