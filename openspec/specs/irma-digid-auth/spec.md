# irma-digid-auth Specification

## Purpose
Signing in to humaniq is signing in to Nextcloud. This capability says what humaniq leaves to Nextcloud and its sign-in apps (single sign-on, DigiD and similar federation, password rules, lockout), what the Inloggen section of the admin settings may set there, and that humaniq runs on any self-managed Nextcloud in its supported range.

## Requirements

### Requirement: humaniq SHALL NOT implement its own authentication or identity-federation stack (REQ-AUTH-001)

humaniq SHALL NOT introduce any `IdentityProvider`, `AuthenticationContext`, `Session`, `AuthEvent`, `FraudSignal`, or `AttributeMapping` OpenRegister schema, and SHALL NOT implement SAML, OIDC, or IRMA/Yivi protocol handling (certificate management, assertion validation, attribute-signature verification) in its own PHP or Vue code. DigiD, Yivi and eHerkenning federation is a Nextcloud instance-level concern, delivered by first-party Nextcloud apps, not by any leaf app's register or controller.

#### Scenario: No federation schema or protocol code exists in the diff
- **GIVEN** this change's full diff
- **WHEN** it is searched for any new schema fragment, controller, or service implementing SAML/OIDC/IRMA protocol logic
- **THEN** none exists — the only new artefact is documentation

#### Scenario: Obsolete draft is superseded, not ported
- **GIVEN** the 2026-05 `spec/irma-digid-auth` draft's `IdentityProvider`/`AuthenticationContext`/`Session`/`AuthEvent`/`FraudSignal`/`AttributeMapping` registers, unified login façade, and hash-chained audit design
- **WHEN** this change is read as its replacement
- **THEN** none of those registers, the login façade, the fraud-scoring engine, or the hash-chained audit ledger are carried forward into humaniq

### Requirement: DigiD, Yivi and eHerkenning federation SHALL be delivered by the named Nextcloud platform apps against a national broker (REQ-AUTH-002)

humaniq's documentation SHALL name `user_saml` (SAML 2.0 — the transport for DigiD and eHerkenning via the Logius broker) and `user_oidc` (OIDC/OAuth2 — the transport for a Yivi-compatible broker, or an OIDC-fronted DigiD/eHerkenning broker) as the Nextcloud apps that deliver this federation, configured by the Nextcloud instance administrator entirely outside humaniq's install/deploy footprint. Once either backend authenticates a person, humaniq SHALL require no additional humaniq-side configuration, certificate, or setup step — it consumes the resulting authenticated `\OCP\IUser` exactly as it consumes a password-authenticated one.

#### Scenario: A DigiD-authenticated employee reaches Mijn HR unmodified
- **GIVEN** an NC instance with `user_saml` configured against the Logius DigiD broker
- **WHEN** an employee authenticates via DigiD and opens `MijnUren`
- **THEN** the page's `@me` base filter resolves to that employee's Nextcloud user id exactly as it would for a password-authenticated user, with zero humaniq-side auth configuration

#### Scenario: Documentation names the real Nextcloud apps
- **GIVEN** humaniq's "Authentication" documentation section
- **WHEN** it describes how DigiD/Yivi/eHerkenning are enabled
- **THEN** it names `user_saml` and `user_oidc` as the configured Nextcloud apps and the Logius/eIDAS or Yivi-compatible broker as the federated IdP, not an humaniq-built equivalent

### Requirement: humaniq SHALL NOT build an app-local assurance-level or step-up mechanism unless a genuine sensitive self-service write action exists and a platform assurance signal is verified (REQ-AUTH-003)

humaniq SHALL NOT introduce a mid-session assurance-level, step-up, or re-authentication mechanism (as the obsolete draft's `AuthenticationContext`/`Session.assuranceLevel` design proposed) unless both of the following hold at proposal time: (a) a genuine humaniq self-service action exists that writes a sensitive field (e.g. an employee-editable bank-details or BSN field) rather than merely reading one, and (b) a concrete, verified mechanism exists by which `user_saml` or `user_oidc` exposes an assurance/LoA signal that humaniq could read for the authenticated session. As of this change, condition (a) is false — `mijn-hr-self-service`'s `MijnLoonstroken` page is read-only and `Employee.iban`/`tenaamstelling` (`payroll-sepa-netpay-shillinq`) have no self-service edit surface — and condition (b) has not been verified.

#### Scenario: No step-up schema or session model exists in the diff
- **GIVEN** this change's full diff
- **WHEN** it is searched for any schema, service, or manifest change implementing assurance-level tracking or step-up flows
- **THEN** none exists

#### Scenario: The trigger condition is checkable by a future change
- **GIVEN** a future proposal adding a self-service write over a sensitive Employee field
- **WHEN** its author checks this requirement
- **THEN** they find the exact two-part condition (sensitive write action exists; platform assurance signal verified) needed before building any step-up mechanism, rather than a blanket prohibition or a pre-built schema to extend

#### Scenario: Current self-service surface is confirmed read-only for sensitive data
- **GIVEN** the `mijn-hr-self-service` and `payroll-sepa-netpay-shillinq` specs at HEAD
- **WHEN** `MijnLoonstroken`'s `actionToggles` and `Employee.iban`'s authoring path are inspected
- **THEN** `MijnLoonstroken` disables Add and all row actions, and `iban`/`tenaamstelling` are written only via payroll/HR paths, confirming condition (a) is unmet

### Requirement: Single sign-on SHALL come from Nextcloud's sign-in apps (REQ-AUTH-004)

Employees SHALL sign in to humaniq with the organisation's single sign-on through the
Nextcloud apps `user_saml` or `user_oidc`, configured by the instance administrator. humaniq
SHALL need no configuration of its own for a signed-in user, and the Inloggen section of the
humaniq admin settings SHALL show which of these apps is enabled and the name of each
configured provider, read only, with a link to that app's settings.

Rows: `plt-sso` (humaniq matrix). Board: `humaniq/HuInstellingen`, tab Inloggen.

#### Scenario: An employee signs in through the organisation's identity provider
@e2e exclude signing in through an identity provider is Nextcloud's user_saml; the e2e instance has no identity provider, and humaniq adds no code to the sign-in path
- **GIVEN** a Nextcloud instance with `user_saml` connected to the organisation's identity provider
- **WHEN** an employee signs in through it and opens Mijn HR
- **THEN** humaniq shows that employee's own data, with no humaniq sign-in setting involved

#### Scenario: The administrator sees the connected method
@e2e exclude the e2e instance has no user_saml provider; covered by SignInSettingsServiceTest::testTheSignInMethodsShowTheEnabledAppAndItsProviders and SignInProviderNamesTest::testSamlNamesComeFromTheConfiguration
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
@e2e exclude covered by SignInSettingsServiceTest::testASaveWritesThePolicyAppsOwnConfig and SignInSettingsControllerTest::testASavedLengthIsReadBack; checked live on 2026-11-17 (13 saved on Inloggen, 13 on Nextcloud's Security page, a 12-character password refused)
- **GIVEN** `password_policy` enabled with a minimum length of 10
- **WHEN** an administrator sets the minimum length to 12 on the Inloggen section and saves
- **THEN** Nextcloud's Security settings show 12 and a new password of 11 characters is refused

#### Scenario: The policy app is missing
@e2e exclude disabling password_policy changes the shared e2e instance for every other spec; covered by SignInSettingsServiceTest::testWithoutThePolicyAppThereIsNoPolicy and SignInSettingsControllerTest::testWithoutThePolicyAppASaveIsAConflict
- **GIVEN** `password_policy` disabled
- **WHEN** an administrator opens the Inloggen section
- **THEN** the section shows no password fields and says the policy needs Nextcloud's password policy app

#### Scenario: A non-administrator cannot save the policy
@e2e exclude the refusal is Nextcloud's admin middleware on a route without NoAdminRequired; covered by SignInSettingsControllerTest::testOnlyAdministratorsReachIt
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
@e2e exclude locking an account is Nextcloud's password_policy app and would lock the e2e user; humaniq only saves the number, covered by SignInSettingsServiceTest::testASaveWritesThePolicyAppsOwnConfig
- **GIVEN** the Inloggen section saved with a lock after 5 failed attempts
- **WHEN** someone enters a wrong password 5 times for one account
- **THEN** Nextcloud disables that account and humaniq is not reachable with it

### Requirement: humaniq SHALL run on any self-managed Nextcloud in its supported range (REQ-AUTH-007)

humaniq SHALL install and run on a Nextcloud instance the organisation runs itself, on its own
servers or at a hosting party of its choice, within the Nextcloud versions `appinfo/info.xml`
declares (32 to 34). humaniq SHALL call no service of its own outside that instance to work.

Rows: `cmp-self-hosting` (humaniq matrix). No board: hosting happens on the server.

#### Scenario: humaniq installs on a self-hosted instance
@e2e exclude installation is what the e2e job itself does on a plain Nextcloud before any spec runs
- **GIVEN** a self-hosted Nextcloud 33 with OpenRegister installed
- **WHEN** an administrator enables humaniq
- **THEN** the app installs and its pages load without any call to a Conduction-hosted service
