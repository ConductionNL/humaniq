---
kind: code
---

# Sign-in and hosting come from the platform, shown on the Inloggen tab

## Why

Four matrix rows are built today, but by Nextcloud, not by humaniq: single sign-on, the
password policy, slowing down or locking sign-in after failed attempts, and running on your
own servers. No humaniq spec says so, so the matrix counts them as unspecified.
`irma-digid-auth` already fixes the principle (REQ-AUTH-001: humaniq builds no
authentication stack of its own). This change adds the requirements that name what the
platform delivers and where an administrator finds it.

The board `humaniq/HuInstellingen` draws an Inloggen tab: how employees, applicants and former
employees sign in, the password rules for accounts outside single sign-on, and the throttle
after failed attempts. The board is the UI canon, so the spec follows it: the tab shows and
saves the platform's own settings and stores nothing in humaniq.

### Matrix rows (humaniq `openspec/parity/capabilities.json`)

| row | capability | humaniq today |
|---|---|---|
| `plt-sso` | Sign in with the organisation's single sign-on. | built by Nextcloud (`user_saml`, `user_oidc`); no humaniq page |
| `dm-password-policy` | Set the password policy from the admin screens. | built by Nextcloud's `password_policy` app; no humaniq page |
| `dm-login-lockout` | Lock an account or slow down sign-in after repeated failed attempts. | built by Nextcloud's brute force protection and `password_policy`; no humaniq page |
| `cmp-self-hosting` | Run the HR system on your own servers or a Dutch cloud. | built: humaniq is a Nextcloud app (`appinfo/info.xml`, Nextcloud 32 to 34) |

## What changes

- **Platform requirements.** `irma-digid-auth` gains four requirements: single sign-on, the
  password policy and failed sign-in handling are Nextcloud's, and humaniq runs on any
  self-managed Nextcloud in its supported range.
- **Inloggen tab.** The humaniq admin settings get an Inloggen section as drawn on
  HuInstellingen. It shows the connected sign-in methods, and reads and saves the password
  policy and failed sign-in values in Nextcloud's own app config. When the needed Nextcloud
  app is not enabled, the section says so and links to Nextcloud's settings.

## Non-goals

- No authentication code, protocol handling or session model in humaniq (REQ-AUTH-001 stands).
- Configuring the identity provider itself (certificates, metadata) stays in `user_saml` and
  `user_oidc`.
- Hosting has no screen: it happens on the organisation's server.
