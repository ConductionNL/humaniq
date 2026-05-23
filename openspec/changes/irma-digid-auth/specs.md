# Specifications: IRMA + DigiD Authentication

## REQ-001: Multi-IdP federation façade

**Narrative**: Users see a unified login page with all configured identity providers, respecting configured display order and IdP availability status.

**REQ-001-001: Login page renders configured IdPs in order**
- GIVEN any hrmq-app initiates a login request
- WHEN the user lands on the unified login page
- THEN they see the four configured IdPs (Yivi, DigiD, eHerkenning, SSO) displayed with consistent branding in the order configured by the organisation
- AND the recommended default IdP for that organisation is pre-selected
- AND each IdP shows a clear action button ("Inloggen met DigiD", etc.)

**REQ-001-002: Unavailable IdPs show degraded status**
- GIVEN an IdP is in `maintenance` or `suspended` status in IdentityProvider
- WHEN the login page renders
- THEN that IdP is either hidden OR shown with a clear "tijdelijk niet beschikbaar" badge
- AND the suggested alternative IdP is highlighted
- AND clicking the unavailable IdP shows a user-friendly explanation of when it will be available

**REQ-001-003: Custom IdP order is logged for auditability**
- GIVEN an organisation configures a custom default IdP order
- WHEN any user starts a session (first render of login page)
- THEN the configured order is logged in an AuthEvent with `eventType: login` and `metadata.idp_order: [...]`
- AND this log entry is included in audit exports for compliance review

## REQ-002: DigiD niveau midden integration

**Narrative**: DigiD midden authentication via SAML, with attribute validation against employee-master and proper mismatch handling.

**REQ-002-001: SAML AuthnRequest includes niveau midden context**
- GIVEN a medewerker chooses DigiD on the login page
- WHEN the SAML AuthnRequest is constructed
- THEN it includes `AuthnContextClassRef = urn:oasis:names:tc:SAML:2.0:ac:classes:MobileTwoFactorContract` (niveau midden per Logius spec)
- AND the request is signed with the Logius-registered certificate
- AND the request is sent to the configured DigiD endpoint

**REQ-002-002: Attribute validation against employee-master**
- GIVEN DigiD returns a successful SAML response
- WHEN the response is processed
- THEN BSN, naam, and geboortedatum are extracted and validated against employee-master records
- AND if validation succeeds, the attributes are promoted to the Session
- AND if validation fails (mismatch), login is rejected with a user-friendly "We couldn't verify your credentials" message
- AND an AuthEvent is written with `eventType: login`, `outcome: attribute_mismatch`, and the mismatched fields in metadata

**REQ-002-003: DigiD hoog step-up for sensitive actions**
- GIVEN a user is logged in with DigiD niveau midden
- WHEN they attempt a `bsn.update` action that requires `minimumAssuranceLevel = hoog`
- THEN a step-up flow is initiated requesting DigiD hoog (ID-kaart via PKIoverheid)
- AND on success, the session's assuranceLevel is upgraded and step-up history is recorded
- AND the original action is resumed with the new elevated level
- AND the elevated level is valid only for 15 minutes or the duration of the specific action (whichever is shorter)

## REQ-003: Yivi / IRMA attribute-based authentication

**Narrative**: Privacy-preserving attribute disclosure; only requested claims are revealed to hrmq.

**REQ-003-001: Minimal-disclosure disclosure session**
- GIVEN a medewerker chooses Yivi on the login page
- WHEN the disclosure request is constructed for a specific action (e.g., `employee.self-service`)
- THEN only the strictly necessary attributes for that action are included in the disclosure request
- AND examples: `pbdf.gemeente.fullname`, `pbdf.sidn-pbdf.email`, `pbdf.nijmegen.ageLowerOver18` for age-gated features
- AND NO attributes are requested beyond what the action strictly requires
- AND the Yivi attributes do NOT include full identity information — only derived attributes

**REQ-003-002: Attribute-signature verification**
- GIVEN Yivi attributes are received in the response
- WHEN they are processed
- THEN each attribute's signature is verified against the Yivi scheme-manager's public key
- AND the issue date is checked to ensure the attribute has not expired
- AND if verification fails, login is rejected with an AuthEvent `outcome: attribute_signature_invalid`
- AND if expiration is detected, login is rejected with an AuthEvent `outcome: attribute_expired`

**REQ-003-003: Organisation-issued Yivi attributes**
- GIVEN an organisation wants to issue a custom Yivi attribute for medewerkers (e.g., `gemeente-foo.medewerker.werkemail`)
- WHEN the organisation configures this in AttributeMapping
- THEN the issuer private key is stored securely in a secrets vault (HSM / Vault / Encrypted ConfigMap)
- AND attribute issuance happens ONLY via an internal admin flow, NOT from external requests
- AND issuance is logged with `eventType: attribute_issued` and includes the issuer context

## REQ-004: eHerkenning niveau 3 for managers

**Narrative**: eHerkenning niveau 3 authentication for manager and HR actions with chain-of-trust support.

**REQ-004-001: eHerkenning niveau 3 authentication**
- GIVEN a user with a management role chooses eHerkenning on the login page
- WHEN they select "Manager actions" or initiate a manager-level action
- THEN the AuthnRequest includes eIDAS-substantial (niveau 3) as the AuthnContext
- AND the KvK (Kamer van Koophandel) registration of the organisation is validated against the eHerkenning response
- AND on success, the session's assuranceLevel is set to `substantial`

**REQ-004-002: Chain-of-trust (ketelmachtiging) logging**
- GIVEN eHerkenning returns a ketelmachtiging (power of attorney) in the response
- WHEN it is processed
- THEN the machtigingsrelatie is explicitly shown to the user before they proceed
- AND the relationship is logged in `AuthEvent.metadata.chainOfTrust` with:
  - Authorizer name and KvK
  - Delegate name and role
  - Scope of delegation
  - Validity period

**REQ-004-003: Level 4 step-up for exceptional actions**
- GIVEN a user is logged in with eHerkenning niveau 3
- WHEN they attempt a `payroll.export-all` action that requires `minimumAssuranceLevel = hoog`
- THEN step-up is forced to eHerkenning niveau 4 (high) with a strong authenticator
- AND on success, the elevated level is recorded in step-up history
- AND the original action is resumed

## REQ-005: Step-up at sensitive actions

**Narrative**: Mid-session assurance upgrade for actions requiring higher authentication levels.

**REQ-005-001: Step-up flow initiation**
- GIVEN an AuthenticationContext with `stepUpRequired = true` for action `bsn.update`
- WHEN a user with insufficient assuranceLevel initiates this action
- THEN a step-up flow is automatically started
- AND the original action parameters are stored (not lost) in the session
- AND the user is presented with a choice of IdPs capable of higher levels

**REQ-005-002: Step-up cancellation handling**
- GIVEN a user initiates step-up
- WHEN they cancel the step-up flow before completion
- THEN the action is blocked with a message: "Authentication required. Please try again."
- AND the cancellation is logged as an AuthEvent with `eventType: step_up_failure`
- AND the original session remains active (NOT terminated)

**REQ-005-003: Step-up level lifetime**
- GIVEN a user successfully completes step-up
- WHEN the new session state is saved
- THEN the elevated assuranceLevel is recorded in the Session
- AND it is valid for:
  - Maximum 15 minutes from step-up completion, OR
  - The duration of the specific action (if configured shorter per AuthenticationContext)
- AND after expiration, a new step-up is required for subsequent sensitive actions

## REQ-006: Audit logging per inlog

**Narrative**: Immutable, chained audit trail for every authentication event.

**REQ-006-001: AuthEvent creation for every login attempt**
- GIVEN any login attempt (successful or not)
- WHEN it completes
- THEN an AuthEvent is written with:
  - `userId` (from employee-master or session)
  - `idpUsed` (DigiD, eHerkenning, Yivi, SSO, or fallback)
  - `assuranceLevel` (laag, midden, substantial, hoog)
  - `attributesPresented[]` (list of claims released)
  - `ipAddress` (unmasked in database, masked in logs)
  - `deviceFingerprint` (hash of User-Agent + accept-language + canvas fingerprint)
  - `userAgent` (full string)
  - `geoCity` (MaxMind GeoIP2 reverse lookup)
  - `startedAt` (login initiation timestamp)
  - `lastSeenAt` (current timestamp)
  - `stepUpHistory[]` (list of step-ups in this session, if any)
  - `status` (active, stepped_up, expired, revoked)
  - `previousEventHash` (SHA256 of previous event in this session, for chain)

**REQ-006-002: Hash chaining for tamper-evidence**
- GIVEN every new AuthEvent in a session
- WHEN it is written
- THEN its hash includes the `previousEventHash` (hash of the prior event in this session)
- AND the complete chain is stored alongside the ledger
- AND on audit export, both the chain and a validation script are provided
- AND the validation script can mechanically detect tampering (hash mismatch)

**REQ-006-003: GDPR-compliant audit export**
- GIVEN a compliance officer exports the audit log
- WHEN the export is generated
- THEN the hash chain is included for tamper-evidence verification
- AND a validation tool is provided to check chain integrity
- AND when exported for a Data Subject Access Request (DSR):
  - Only events for that user are included
  - IP addresses are anonymized to /24 for IPv4, /48 for IPv6
  - Device fingerprints are excluded
  - Location data is removed

## REQ-007: Backup auth via Nextcloud SSO

**Narrative**: Graceful fallback when external IdPs are unreachable.

**REQ-007-001: Fallback detection and offering**
- GIVEN all external IdPs (DigiD, eHerkenning, Yivi) are unreachable
- WHEN detected via healthcheck-batch (every 5 minutes)
- AND a user attempts login
- THEN the Nextcloud SSO option is displayed as the only available method
- AND an explicit "degraded mode" message is shown: "External authentication is temporarily unavailable. Using backup authentication."
- AND users are advised to try again later

**REQ-007-002: Reduced permissions in fallback mode**
- GIVEN a user logs in via Nextcloud SSO fallback
- WHEN the Session is created
- THEN `assuranceLevel = laag` (low) is set
- AND when the user attempts an action requiring `minimumAssuranceLevel >= midden`
- THEN the action is blocked with a message: "This action requires higher authentication. Please try again when external authentication is available."
- AND an AuthEvent is logged with `eventType: degraded_mode_block`

**REQ-007-003: Fallback-session upgrade on IdP recovery**
- GIVEN external IdPs become reachable again (healthcheck succeeds)
- WHEN a user with an active fallback session attempts a sensitive action
- THEN the system DOES NOT automatically upgrade the session
- INSTEAD, a herauthentication prompt is shown: "External authentication is now available. Please re-authenticate."
- AND the user may choose to re-login with the recovered IdP
- AND the new session's assuranceLevel is set according to the chosen IdP
- AND the old fallback session is NOT carried forward

## REQ-008: Anti-fraud detection

**Narrative**: Real-time risk scoring and response to anomalous authentication patterns.

**REQ-008-001: Risk signal calculation**
- GIVEN a successful login
- WHEN IP address and device fingerprint are compared to user's history
- THEN the system calculates risk signals:
  - `impossible_travel`: geo-distance vs. time (>900 km / hour between locations)
  - `new_device`: first time this device fingerprint for this user
  - `brute_force`: >5 failed login attempts in 15 minutes from same IP
  - `credential_stuffing`: >10 failed login attempts across multiple users from same IP
  - `tor_exit`: IP is known Tor exit node (via Tor exit list)
  - `known_bad_ip`: IP in threat intelligence feed
  - `attribute_mismatch`: BSN/name validation failed
- AND each signal is assigned a severity (low, medium, high, critical)
- AND they are recorded in FraudSignal with evidence and timestamp

**REQ-008-002: Risk-based step-up enforcement**
- GIVEN risk signals are calculated and scored
- WHEN the combined risk score exceeds a configurable threshold (default 70)
- THEN the session is marked `stepped_up` pending manual intervention
- AND on every subsequent action, a step-up is forced before allowing the action
- AND the user is not shown the risk score (silent enforcement)
- AND an AuthEvent is logged with `eventType: fraud_signal` and riskScore

**REQ-008-003: Critical signal handling**
- GIVEN a FraudSignal with `severity = critical`
- WHEN it is registered (e.g., multiple impossible_travel signals in one day)
- THEN the session is immediately terminated (`status: revoked`)
- AND the SOC receives a real-time webhook notification with signal details
- AND the user receives an email with account-recovery instructions
- AND an AuthEvent is logged with `eventType: fraud_signal`, `severity: critical`, `outcome: session_revoked`

## REQ-009: Attribute mapping and data minimization

**Narrative**: Declarative claim mapping with transformations; strict discarding of unmapped attributes.

**REQ-009-001: Mandatory vs. optional attribute enforcement**
- GIVEN an IdP returns a response with multiple attributes
- WHEN the response is processed
- THEN only attributes listed in AttributeMapping are considered
- AND attributes marked `mandatory: true` MUST be present; if missing, login is rejected with `outcome: missing_required_attribute`
- AND attributes marked `mandatory: false` are optional; if missing, login proceeds
- AND attributes NOT in AttributeMapping are DISCARDED without logging or retention
- AND the audit log does NOT contain discarded attributes

**REQ-009-002: Attribute transformation**
- GIVEN an AttributeMapping defines a transformExpression (e.g., `pseudonymize_bsn`, `extract_initialen`)
- WHEN the attribute value is processed
- THEN the transformation is applied atomically
- AND if transformation fails (e.g., regex doesn't match), the raw value is NEVER stored
- AND login is rejected with `outcome: transformation_failed`
- AND an AuthEvent is logged with the transformation error

**REQ-009-003: Admin notification on mapping gaps**
- GIVEN a mandatory attribute from an IdP is missing in the response
- WHEN the error is detected
- THEN a specific error log entry is created: `error: 'AttributeMapping misconfigured for {idp}:{claim}'`
- AND the admin receives an operational notification (Nextcloud notification + email)
- AND the notification suggests review and update of the AttributeMapping configuration
- AND subsequent users are NOT blocked; a graceful fallback applies

## REQ-010: Session management and revocation

**Narrative**: Explicit logout, admin-triggered revocation, idle expiration, and fallback session handling.

**REQ-010-001: Manual logout with Single Logout**
- GIVEN a user clicks "Uitloggen" / Logout
- WHEN logout is initiated
- THEN the session is immediately marked `status: revoked`
- AND a logout-event (Single Logout / SLO) is sent to the IdP (if supported)
- AND all related tokens and session data are invalidated
- AND an AuthEvent is logged with `eventType: logout`, `outcome: success`
- AND the user is redirected to a post-logout confirmation page

**REQ-010-002: Admin-triggered revocation on employee status change**
- GIVEN an employee is marked as "out of service" (uitdiensttreding) in employee-master
- WHEN this event is received (via webhook or polling)
- THEN ALL active sessions for that employee are found and revoked
- AND each is marked `status: revoked` with reason `employee_terminated`
- AND all future login attempts for that employee are rejected
- AND an AuthEvent is logged per revoked session with `eventType: admin_revocation`
- AND the employee's manager is notified (if configured)

**REQ-010-003: Idle session expiration**
- GIVEN a session's `maxIdleMinutes` is configured (default 30 minutes)
- WHEN the session reaches `lastSeenAt + maxIdleMinutes`
- AND the user makes a new request
- THEN the session is automatically expired
- AND the user is redirected to login with a message: "Uw sessie is verlopen. Inloggen aub."
- AND an AuthEvent is logged with `eventType: session_expired`, `reason: idle_timeout`
- AND the expired session remains in the audit log (not deleted)

## Standards and Compliance

- **eIDAS-verordening (EU 910/2014) + Nederlandse implementatiewet** — assurance levels low/substantial/high
- **Stelsel Elektronische Toegangsdiensten (ETD) / Logius** — DigiD and eHerkenning kaders
- **DigiD SAML 2.0 koppelvlak** — Logius interface spec and conformiteitstoets
- **eHerkenning afsprakenstelsel v1.x** — makelaar protocol
- **Yivi (IRMA) protocol** — Privacy by Design Foundation specs
- **OpenID Connect Core 1.0 + SAML 2.0** — federation protocols
- **BIO (Baseline Informatiebeveiliging Overheid)** — assurance level matching, audit requirements
- **NIST SP 800-63-3** — IAL/AAL/FAL reference
- **AVG art. 32 + DPIA** — data protection and privacy impact
- **NCSC ICT-beveiligingsrichtlijnen** — session management, anti-fraud
- **ISO 27001 A.9** — access control
- **NORA katern Informatiebeveiliging** — government architecture guidelines
- **Forum Standaardisatie** — OIDC, SAML 2.0, TLS 1.3
- **NL-GOV OIDC profiel** — Dutch government OIDC profile
- **Wet digitale overheid (Wdo)** — framework for government authentication
