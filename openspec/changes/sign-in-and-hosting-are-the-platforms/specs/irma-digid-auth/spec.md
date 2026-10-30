# irma-digid-auth

## ADDED Requirements

### Requirement: Single sign-on SHALL come from Nextcloud's sign-in apps (REQ-AUTH-004)

Employees SHALL sign in to humaniq with the organisation's single sign-on through the
Nextcloud apps `user_saml` or `user_oidc`, configured by the instance administrator. humaniq
SHALL need no configuration of its own for a signed-in user, and the Inloggen section of the
humaniq admin settings SHALL show which of these apps is enabled and the name of each
configured provider, read only, with a link to that app's settings.

Rows: `plt-sso` (humaniq matrix). Board: `humaniq/HuInstellingen`, tab Inloggen.

#### Scenario: An employee signs in through the organisation's identity provider
- **GIVEN** a Nextcloud instance with `user_saml` connected to the organisation's identity provider
- **WHEN** an employee signs in through it and opens Mijn HR
- **THEN** humaniq shows that employee's own data, with no humaniq sign-in setting involved

#### Scenario: The administrator sees the connected method
- **GIVEN** `user_saml` enabled with one provider named "Gemeente Zuiddrecht"
- **WHEN** an administrator opens the Inloggen section of the humaniq admin settings
- **THEN** the section lists single sign-on through SAML with the provider name and a link to its settings

### Requirement: The password policy SHALL be Nextcloud's, set from the Inloggen section (REQ-AUTH-005)

The password policy for accounts outside single sign-on SHALL be the one Nextcloud's
`password_policy` app enforces. The Inloggen section SHALL show and save its minimum length,
upper and lower case, digit, special character and breached-password values in that app's
config, so the humaniq section and Nextcloud's Security settings always show the same values.
humaniq SHALL store no password rule of its own. When `password_policy` is not enabled, the
section SHALL say so and link to Nextcloud's apps page instead of showing the fields.

Rows: `dm-password-policy` (humaniq matrix). Board: `humaniq/HuInstellingen`, tab Inloggen.

#### Scenario: A saved minimum length is Nextcloud's value
- **GIVEN** `password_policy` enabled with a minimum length of 10
- **WHEN** an administrator sets the minimum length to 12 on the Inloggen section and saves
- **THEN** Nextcloud's Security settings show 12 and a new password of 11 characters is refused

#### Scenario: The policy app is missing
- **GIVEN** `password_policy` disabled
- **WHEN** an administrator opens the Inloggen section
- **THEN** the section shows no password fields and says the policy needs Nextcloud's password policy app

#### Scenario: A non-administrator cannot save the policy
- **GIVEN** a user who is not an administrator
- **WHEN** they send a save request to the sign-in settings endpoint
- **THEN** the request is refused and the policy is unchanged

### Requirement: Failed sign-in attempts SHALL be slowed down and can lock the account through Nextcloud (REQ-AUTH-006)

Repeated failed sign-in attempts SHALL be slowed down by Nextcloud's brute force protection,
and an account SHALL be locked after the number of failed attempts set in `password_policy`
when that value is set. The Inloggen section SHALL show and save that number in the
`password_policy` config. humaniq SHALL add no throttle or lock of its own.

Rows: `dm-login-lockout` (humaniq matrix). Board: `humaniq/HuInstellingen`, tab Inloggen.

#### Scenario: An account locks after the set number of failures
- **GIVEN** the Inloggen section saved with a lock after 5 failed attempts
- **WHEN** someone enters a wrong password 5 times for one account
- **THEN** Nextcloud disables that account and humaniq is not reachable with it

### Requirement: humaniq SHALL run on any self-managed Nextcloud in its supported range (REQ-AUTH-007)

humaniq SHALL install and run on a Nextcloud instance the organisation runs itself, on its own
servers or at a hosting party of its choice, within the Nextcloud versions `appinfo/info.xml`
declares (32 to 34). humaniq SHALL call no service of its own outside that instance to work.

Rows: `cmp-self-hosting` (humaniq matrix). No board: hosting happens on the server.

#### Scenario: humaniq installs on a self-hosted instance
- **GIVEN** a self-hosted Nextcloud 33 with OpenRegister installed
- **WHEN** an administrator enables humaniq
- **THEN** the app installs and its pages load without any call to a Conduction-hosted service
