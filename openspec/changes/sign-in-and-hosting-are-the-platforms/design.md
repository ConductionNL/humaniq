# Design: sign-in and hosting come from the platform

## Context

Read at humaniq `development` 0c6d973.

- `appinfo/info.xml` declares `<nextcloud min-version="32" max-version="34"/>` and PHP 8.3.
- `lib/` holds no SAML, OIDC or password code (`grep -rniE 'saml|oidc|password' lib/`).
- `src/views/settings/AdminSettings.vue` renders the admin settings with
  `CnAdminSettingsShell` and `CnSettingsSection` from `@conduction/nextcloud-vue`.
- Nextcloud keeps the password policy in the `password_policy` app config (minimum length,
  upper and lower case, numbers, special characters, breached-password check, maximum
  failed attempts) and the brute force throttle in core.

## Decisions

- **D1. Read and write the platform's config, never a copy.** The Inloggen section calls a
  small admin-only humaniq endpoint that reads and writes the `password_policy` keys through
  `IAppConfig`. humaniq stores no sign-in value of its own, so the Nextcloud admin page and
  the humaniq tab always agree.
- **D2. Sign-in methods are read only.** The section lists which of `user_saml` and
  `user_oidc` are enabled and their configured provider names. Changing a provider links to
  that app's own settings page.
- **D3. Missing app.** When `password_policy` is disabled, the password fields are not shown;
  the section says the policy needs Nextcloud's password policy app and links to the apps page.
- **D4. Admin only.** The endpoint carries no `NoAdminRequired`; the admin settings page is
  already admin-only. The section is not added to the in-app router.

## Risks

- `password_policy` config key names can change between Nextcloud majors. The endpoint keeps
  one list of keys with a unit test per key, and the supported range is 32 to 34.
