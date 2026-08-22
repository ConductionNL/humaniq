# irma-digid-auth Specification

## Purpose
TBD - created by archiving change irma-digid-auth. Update Purpose after archive.

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
