## 1. Installable app

- [x] 1.1 Add `PwaController` with `manifest()` and `serviceWorker()` and the two routes
      before `page#catchAll`. Verify: controller test for content types and the
      `Service-Worker-Allowed` header; `hydra-gate-route-auth` passes.
- [x] 1.2 Add `src/service-worker.js`, its webpack entry, and the icons. Verify: `npm run
      lint` exits 0 and the build emits `service-worker.js`.
- [x] 1.3 Link the web manifest in the page head from `Application.php`. Verify: the SPA
      page source carries the manifest link.
- [x] 1.4 Cache the shell only. Verify: a unit test of the fetch handler that an
      `/apps/humaniq/api/` request is never answered from cache.

## 2. Phone use

- [x] 2.1 Add the three quick actions to `MijnHr`. Verify: `npm run check:manifest` exits 0.
- [ ] 2.2 Add the capture hint to the expense receipt field. Verify: `npm run
      check:manifest` exits 0.
- [x] 2.3 Add the opt-in notification setting on `Mijn HR` calling openregister's web-push
      registration. Verify: no permission prompt fires on page load in the e2e run.
      Done: a `device-push` widget ("Notifications on this device") at the bottom of `MijnHr`
      (`DevicePushWidget`, logic in `src/pwa/pushOptIn.js`). Loading reads the device state only;
      flipping the switch fetches openregister's VAPID key, asks for permission, subscribes and
      posts the subscription to `/apps/openregister/webpush/subscription`; off deletes it there
      first. It says why when it cannot: blocked, iPhone not on the home screen, no Push API,
      no key on the server. Verified by `pushOptIn.test.mjs` (7), including that reading the
      state never prompts; the e2e run is excluded per 3.1.

## 3. Verification

- [x] 3.1 e2e test or a reason-bearing `@e2e exclude` per scenario (gate 19), and `@spec`
      tags on the new methods (gate 16).
      Done: a reason-bearing `@e2e exclude` on each of the four scenarios (each needs a phone,
      a camera, an offline device or a push service), naming the unit tests that cover what can
      be covered; `@spec` on the new methods, `PwaHeadListener::addHeader` included.
- [ ] 3.2 One live check on a phone: add `Mijn HR` to the home screen, request leave and
      photograph a receipt into an expense; record the screenshots in the PR.
