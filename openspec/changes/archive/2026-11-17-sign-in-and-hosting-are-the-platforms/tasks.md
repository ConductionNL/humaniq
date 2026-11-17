## 1. Backend

- [x] 1.1 Add `SignInSettingsController` with `GET` and `PUT /api/settings/sign-in` (admin only,
      no `NoAdminRequired`) and a `SignInSettingsService` that reads and writes the
      `password_policy` keys through `IAppConfig` and lists enabled `user_saml` / `user_oidc`
      providers. Register both routes in `appinfo/routes.php`. Verify: unit tests for a read,
      a save, a disabled `password_policy` app and an unknown key refused.

## 2. Admin settings

- [x] 2.1 Add an Inloggen `CnSettingsSection` to `src/views/settings/AdminSettings.vue` as drawn
      on HuInstellingen: sign-in methods, password rules for accounts outside single sign-on,
      failed sign-in throttle, Opslaan. Verify: `npm run lint` exits 0 and the section shows
      the missing-app message when `password_policy` is disabled.

## 3. Verification

- [x] 3.1 `@spec` tags on every new method (gate 16) and an e2e test or a reason-bearing
      `@e2e exclude` per scenario (gate 19).
      Done in the live pass: the controller and service carry `@spec`; each of the seven
      scenarios carries a reason-bearing `@e2e exclude`.
- [x] 3.2 One live check on a dev instance: change the minimum password length on the Inloggen
      section and see the same value on Nextcloud's Security admin page.
      Done in the live pass (2026-11-17, clean install, password_policy enabled): the
      Inloggen section read 10, was set to 13 and saved (PUT 200); Nextcloud's Security page
      showed 13 and `occ config:app:get password_policy minLength` read 13; a new account with
      a 12-character password was refused.
