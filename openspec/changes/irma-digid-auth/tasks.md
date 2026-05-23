# Tasks: IRMA + DigiD Authentication Implementation

## Phase 1: Schema & Data Layer Setup

- [ ] Create OpenRegister schema definitions in `lib/Settings/irma_digid_auth_register.json`
  - [ ] IdentityProvider schema with code, displayName_nl, protocol, endpoint, assuranceLevel, status
  - [ ] AuthenticationContext schema with actionCode, minimumAssuranceLevel, stepUpRequired
  - [ ] Session schema with sessionId, userId, idpUsed, assuranceLevel, attributesPresented, ipAddress, stepUpHistory, status
  - [ ] AuthEvent schema with eventType, outcome, metadata, previousEventHash, eventHash
  - [ ] FraudSignal schema with signalType, severity, evidence, reviewStatus
  - [ ] AttributeMapping schema with idpCode, idpClaimName, hrmqField, transformExpression, mandatory
  - [ ] Add x-openregister-lifecycle to Session (creation, active → stepped_up, active → expired, active → revoked, expired, revoked)

- [ ] Add seed data to register file
  - [ ] IdentityProvider seed: digid, yivi, eherkenning, nextcloud-sso (4 objects)
  - [ ] AuthenticationContext seed: employee.self-service, payroll.mutate, bsn.update, payroll.export-bulk (4 objects)
  - [ ] Session seed: 2 realistic session objects (active and stepped_up state)
  - [ ] Seed data matches design.md Seed Data section exactly

- [ ] Register schemas and seed via `lib/Loader/IrmqDigidAuthLoader.php`
  - [ ] Extend `LoaderInterface`, implement `load()` method
  - [ ] Call `importFromApp()` with register JSON and `force: false` for idempotency

- [ ] Add migration: `lib/Migration/Version010000Date20260523000000.php`
  - [ ] Execute `IrmqDigidAuthLoader::load()` to create schemas and seed objects
  - [ ] Mark as applied in OpenRegister version tracking

## Phase 2: Identity Provider Adapters

- [ ] Create DigiD SAML adapter at `lib/Service/IdP/DigidAdapter.php`
  - [ ] Implement `IdPAdapterInterface::initiateLogin(context, metadata)` → SAML AuthnRequest
  - [ ] Generate SAML AuthnRequest with `AuthnContextClassRef = urn:oasis:names:tc:SAML:2.0:ac:classes:MobileTwoFactorContract` (niveau midden)
  - [ ] Sign request with Logius certificate from IdentityProvider configuration
  - [ ] Implement `validateResponse(samlResponse)` → attributes array
  - [ ] Extract BSN, name, birthdate from SAML response
  - [ ] Validate signature and assertions
  - [ ] Call employee-master API to validate BSN/name match
  - [ ] Return attributes or throw ValidationException

- [ ] Create Yivi/IRMA adapter at `lib/Service/IdP/YiviAdapter.php`
  - [ ] Implement `IdPAdapterInterface::initiateLogin()` → Yivi disclosure request JSON
  - [ ] Load required attributes from AttributeMapping filtered for this action
  - [ ] Construct minimal-disclosure request (only required attributes)
  - [ ] Implement `validateResponse(yiviResponse)` → attributes array
  - [ ] Verify attribute signatures against Yivi scheme-manager public keys
  - [ ] Check attribute issue dates (not expired)
  - [ ] Transform attributes per AttributeMapping rules
  - [ ] Return verified attributes or throw ValidationException

- [ ] Create eHerkenning adapter at `lib/Service/IdP/EherkenningAdapter.php`
  - [ ] Implement `IdPAdapterInterface::initiateLogin()` → SAML AuthnRequest with niveau 3/4
  - [ ] Support level escalation: request `substantial` for managers, `hoog` for step-up
  - [ ] Validate KvK registration in response against configuration
  - [ ] Extract ketelmachtiging (chain-of-trust) if present
  - [ ] Return attributes with chainOfTrust metadata

- [ ] Create ADFS/Entra ID adapter at `lib/Service/IdP/EntraIdAdapter.php`
  - [ ] Implement OIDC flow: authorize → token → userinfo
  - [ ] Handle token refresh (if needed)
  - [ ] Map claims to hrmq attributes

- [ ] Create Nextcloud SSO adapter at `lib/Service/IdP/NextcloudSsoAdapter.php`
  - [ ] Reuse Nextcloud built-in session when external IdPs unavailable
  - [ ] Set assuranceLevel = laag
  - [ ] Log as fallback mode

- [ ] Create IdP health checker at `lib/Service/HealthCheckService.php`
  - [ ] Every 5 minutes, probe each active IdentityProvider endpoint
  - [ ] Update IdentityProvider.status (active → maintenance if unhealthy)
  - [ ] Return list of unavailable IdPs for login-page rendering
  - [ ] Register as background job via ScheduledWorkflow in manifest.json

## Phase 3: Session & Audit Management

- [ ] Create SessionService at `lib/Service/SessionService.php`
  - [ ] `createSession(idp, userId, attributes, ipAddress, deviceFingerprint)` → Session object
  - [ ] Assign sessionId (UUID), startedAt, expiresAt (based on maxIdleMinutes from AuthenticationContext)
  - [ ] Derive assuranceLevel from IdP configuration
  - [ ] Store Session via ObjectService
  - [ ] Emit webhook to SIEM with SessionCreated event

- [ ] Implement step-up flow in SessionService
  - [ ] `stepUp(session, targetLevel, idp)` → updated Session
  - [ ] Call appropriate IdP adapter to initiate auth at higher level
  - [ ] On success, append to stepUpHistory with expiresAt = now + stepUpValidityMinutes
  - [ ] Update session.assuranceLevel to targetLevel
  - [ ] Emit AuthEvent `eventType: step_up_success`

- [ ] Implement session revocation in SessionService
  - [ ] `revokeSession(sessionId, reason)` → void
  - [ ] Mark session.status = revoked
  - [ ] Call appropriate IdP adapter for Single Logout (SLO) if supported
  - [ ] Emit AuthEvent `eventType: logout` / `admin_revocation`

- [ ] Implement idle timeout check
  - [ ] Before every request, check `lastSeenAt + maxIdleMinutes < now`
  - [ ] If expired, revoke and redirect to login with message
  - [ ] Emit AuthEvent `eventType: session_expired`, `reason: idle_timeout`

- [ ] Create AuthEventService at `lib/Service/AuthEventService.php`
  - [ ] `logEvent(sessionId, eventType, outcome, metadata)` → AuthEvent
  - [ ] Compute eventHash = SHA256(eventJson)
  - [ ] Fetch previous event in session, compute previousEventHash = SHA256(previous eventJson)
  - [ ] Store AuthEvent via ObjectService
  - [ ] Stream to SIEM webhook if configured (CloudEvents format, JSON-lines)

- [ ] Create HashChainValidator at `lib/Service/HashChainValidator.php`
  - [ ] `validateChain(sessionId)` → boolean
  - [ ] Retrieve all AuthEvents for sessionId in timestamp order
  - [ ] Recompute each event's hash and previousEventHash
  - [ ] Verify chain integrity (each hash matches computed hash)
  - [ ] Log validation result in audit system

## Phase 4: Risk Scoring & Fraud Detection

- [ ] Create FraudDetectionService at `lib/Service/FraudDetectionService.php`
  - [ ] `scoreLogin(userId, ipAddress, geoCity, deviceFingerprint, userAgent)` → array of FraudSignals
  - [ ] Implement impossible_travel check
    - [ ] Fetch last login location/timestamp from Session/AuthEvent
    - [ ] Calculate distance and time delta
    - [ ] If distance > 900 km and time < 1 hour, create FraudSignal (high severity)
  - [ ] Implement new_device check
    - [ ] Compare deviceFingerprint to previous logins
    - [ ] If first time, create FraudSignal (low severity)
  - [ ] Implement brute_force check
    - [ ] Count failed logins from ipAddress in last 15 minutes
    - [ ] If > 5, create FraudSignal (high severity)
  - [ ] Implement credential_stuffing check
    - [ ] Count failed logins from ipAddress across all users in last 15 minutes
    - [ ] If > 10, create FraudSignal (high severity)
  - [ ] Implement tor_exit check
    - [ ] Query Tor exit node list (cached, refreshed daily)
    - [ ] If ipAddress in list, create FraudSignal (critical severity)
  - [ ] Implement known_bad_ip check
    - [ ] Query threat intelligence feed (AbuseIPDB API or similar)
    - [ ] If IP reputation low, create FraudSignal (medium/high severity)
  - [ ] Implement attribute_mismatch check (integration with BSN validation)
    - [ ] Log during attribute validation in adapters
    - [ ] Create FraudSignal if mismatch detected

- [ ] Create risk score aggregator
  - [ ] Sum signal severities (low=10, medium=30, high=50, critical=100)
  - [ ] If total >= 70 (configurable), mark session as requiring step-up for all future actions
  - [ ] Emit AuthEvent `eventType: fraud_signal`, `riskScore: X`

- [ ] Implement critical signal handler
  - [ ] On critical FraudSignal creation
  - [ ] Revoke session immediately
  - [ ] Send webhook to SOC with signal details
  - [ ] Send user email with account recovery link
  - [ ] Emit AuthEvent `eventType: fraud_signal`, `severity: critical`, `outcome: session_revoked`

## Phase 5: Attribute Mapping & Data Minimization

- [ ] Create AttributeMappingService at `lib/Service/AttributeMappingService.php`
  - [ ] `mapAttributes(idp, idpAttributes, actionCode)` → mapped attributes
  - [ ] Load AttributeMapping records for idpCode
  - [ ] Load AuthenticationContext for actionCode to get requiredAttributes
  - [ ] For each requiredAttribute:
    - [ ] Find mapping with idpClaimName matching the attribute
    - [ ] If mapping.mandatory = true and attribute absent, throw ValidationException
    - [ ] Apply transformExpression if defined
    - [ ] If transformation fails, throw ValidationException without storing raw value
  - [ ] Discard all unmapped attributes (not logged, not retained)
  - [ ] Return only mapped attributes

- [ ] Create transformation registry at `lib/Service/Transformation/*.php`
  - [ ] PseudonymizeBsn transformer
  - [ ] ExtractInitialen transformer
  - [ ] RegexExtractor (generic regex pattern matcher)
  - [ ] Each transformer implements `transform(value): string` or throws TransformationException

- [ ] Create AttributeMappingValidator at `lib/Service/AttributeMappingValidator.php`
  - [ ] `validateMapping(mapping)` → void
  - [ ] Ensure transformExpression is either null or references valid transformer
  - [ ] On validation failure, log admin notification and operational alert

## Phase 6: Controllers & API Endpoints

- [ ] Create LoginController at `lib/Controller/LoginController.php`
  - [ ] GET `/api/login/page` → render unified login page
    - [ ] Fetch all IdPs from ObjectService
    - [ ] Filter by status (active only, or show maintenance with badge)
    - [ ] Sort by displayOrder
    - [ ] Load customer's recommended default IdP
    - [ ] Pass to LoginPageComponent with all metadata

- [ ] Implement IdP selection handler
  - [ ] POST `/api/login/select-idp` → redirect to IdP auth flow
    - [ ] Parse idp parameter (digid, yivi, eherkenning, sso)
    - [ ] Call appropriate adapter's initiateLogin()
    - [ ] Redirect to IdP endpoint with AuthnRequest/OIDC authorize URI
    - [ ] Store session state in server-side session cache

- [ ] Implement IdP callback handlers (one per IdP)
  - [ ] GET `/api/login/digid/callback` (SAML assertion consumer)
  - [ ] GET `/api/login/yivi/callback` (IRMA session status)
  - [ ] GET `/api/login/eherkenning/callback` (SAML assertion consumer)
  - [ ] GET `/api/login/entra/callback` (OIDC token exchange)
  - [ ] Each handler:
    - [ ] Validate response from IdP
    - [ ] Call adapter's validateResponse()
    - [ ] Call AttributeMappingService to map claims
    - [ ] Call FraudDetectionService to score risk
    - [ ] Create Session via SessionService
    - [ ] Log AuthEvent
    - [ ] On FraudSignal (high), initiate step-up flow instead of direct login
    - [ ] On success, set Nextcloud user session and redirect to referer or dashboard
    - [ ] On failure, redirect to login with error message

- [ ] Create StepUpController at `lib/Controller/StepUpController.php`
  - [ ] POST `/api/step-up/initiate` → redirect to IdP for higher level
    - [ ] Validate current session exists and is active
    - [ ] Load AuthenticationContext for the requested action
    - [ ] If current assuranceLevel >= minimumAssuranceLevel, return 400 (not needed)
    - [ ] Call appropriate IdP adapter with targetLevel parameter
    - [ ] Store original action parameters in session cache
    - [ ] Redirect to IdP
  - [ ] GET `/api/step-up/callback` → same as login callback but update existing Session
    - [ ] Increment stepUpHistory
    - [ ] Update session.assuranceLevel
    - [ ] Resume original action (fetch from cache)

- [ ] Create SessionController at `lib/Controller/SessionController.php`
  - [ ] GET `/api/session/current` → current user context (no auth required, returns 401 if no session)
    - [ ] Return { userId, assuranceLevel, sessionId, stepUpHistory, expiresAt, canStepUp }
  - [ ] POST `/api/session/logout` → revoke session
    - [ ] Call SessionService.revokeSession()
    - [ ] Redirect to post-logout page
  - [ ] POST `/api/session/step-up-required` → check if action requires step-up
    - [ ] Load AuthenticationContext for actionCode
    - [ ] Compare current assuranceLevel vs minimumAssuranceLevel
    - [ ] Return { stepUpRequired: boolean, targetLevel: string }

- [ ] Create SettingsController for admin panel at `lib/Controller/SettingsController.php`
  - [ ] GET `/api/admin/settings` → list all configurations
    - [ ] Require `#[AuthorizedAdminSetting]`
    - [ ] Return all IdentityProvider + AuthenticationContext records
  - [ ] PUT `/api/admin/idp/:code` → update IdentityProvider
    - [ ] Validate IdP code and fields
    - [ ] Update via ObjectService
    - [ ] Log to audit trail
  - [ ] PUT `/api/admin/action/:actionCode` → update AuthenticationContext
    - [ ] Validate action code and assurance level
    - [ ] Update via ObjectService
  - [ ] POST `/api/admin/test-idp/:code` → healthcheck a specific IdP
    - [ ] Probe endpoint and return status

- [ ] Create AuditController at `lib/Controller/AuditController.php`
  - [ ] GET `/api/admin/audit/events` → query AuthEvents with filtering
    - [ ] Require `#[AuthorizedAdminSetting]`
    - [ ] Support filters: userId, eventType, dateRange
    - [ ] Return paginated results with total count
  - [ ] GET `/api/admin/audit/export` → download audit log
    - [ ] JSON-lines format with tamper-evidence hash chain
    - [ ] Return ZIP with validation script
  - [ ] GET `/api/dsar/export` → Data Subject Access Request export
    - [ ] Authenticated user only (no admin required)
    - [ ] Return user's AuthEvents with anonymized IPs / no device fingerprints

- [ ] Create FraudSignalController at `lib/Controller/FraudSignalController.php`
  - [ ] GET `/api/admin/fraud-signals` → list open signals
    - [ ] Require `#[AuthorizedAdminSetting]`
    - [ ] Filter by reviewStatus = open
    - [ ] Sort by severity (critical first) and timestamp
  - [ ] PUT `/api/admin/fraud-signals/:signalId` → SOC analyst decision
    - [ ] Update reviewStatus, reviewerId, decision
    - [ ] Log update in audit trail
    - [ ] If decision = acknowledged_user, allow user's next login

## Phase 7: Frontend Components

- [ ] Create LoginPage component at `src/pages/LoginPage.vue`
  - [ ] Render unified login page with all available IdPs
  - [ ] Display IdP status (active, maintenance with until-date, suspended)
  - [ ] Show recommended default IdP pre-selected
  - [ ] On IdP button click, POST to `/api/login/select-idp` and redirect

- [ ] Create StepUpDialog component at `src/components/StepUpDialog.vue`
  - [ ] Show when action requires step-up
  - [ ] Display current level and required level
  - [ ] List IdPs capable of higher level
  - [ ] On selection, POST to `/api/step-up/initiate` and redirect to IdP
  - [ ] Handle cancellation (go back to action without stepping up)

- [ ] Create SessionStatusBar component at `src/components/SessionStatusBar.vue`
  - [ ] Display in app header: current user, assurance level, session expiry countdown
  - [ ] Show warning 5 minutes before idle timeout
  - [ ] Logout button

- [ ] Create AdminSettingsPanel at `src/pages/AdminSettingsPanel.vue`
  - [ ] Tab 1: Identity Providers
    - [ ] List all IdPs with status indicators
    - [ ] Edit form for each IdP (endpoint, certificate, displayOrder, etc.)
    - [ ] Test button for each IdP (healthcheck)
  - [ ] Tab 2: Authentication Policies
    - [ ] List all AuthenticationContexts with actionCode, minimumAssuranceLevel, etc.
    - [ ] Edit form for creating/updating policies
  - [ ] Tab 3: Attribute Mappings
    - [ ] List mappings per IdP
    - [ ] Edit form for each mapping
  - [ ] Tab 4: Audit Log
    - [ ] Search/filter AuthEvents by userId, eventType, date
    - [ ] Pagination with export button
  - [ ] Tab 5: Fraud Signals
    - [ ] List open signals with severity badges
    - [ ] Decision form (valid_threat / false_positive / acknowledged_user)
    - [ ] Link to user's session details

- [ ] Create DegradeMode indicator component at `src/components/DegradedModeAlert.vue`
  - [ ] Show when user logged in via fallback SSO
  - [ ] Message: "External authentication is temporarily unavailable. Limited features available."
  - [ ] Advise retry when external IdPs available

## Phase 8: Integration with hrmq-base

- [ ] Create SDK at `lib/SDK/SessionContext.php`
  - [ ] Expose `currentUser` → employee record from employee-master
  - [ ] Expose `currentAssuranceLevel` → session's assuranceLevel
  - [ ] Expose `hasStepUp(actionCode)` → boolean (check against AuthenticationContext)
  - [ ] Middleware to load current context for every request

- [ ] Create integration guide in `/docs/INTEGRATION.md`
  - [ ] How to import irma-digid-auth SDK
  - [ ] How to protect routes with assurance levels
  - [ ] How to trigger step-up from actions
  - [ ] Example controller code

## Phase 9: Integration with Employee-Master

- [ ] Create EmployeeMasterConnector at `lib/Service/EmployeeMasterConnector.php`
  - [ ] Validate BSN against employee-master on DigiD login
  - [ ] Fetch employee record on successful login
  - [ ] Subscribe to employee.terminated webhook for session revocation
  - [ ] Cache employee records for performance (TTL 1 hour)

- [ ] Update EmployeeMaster integration
  - [ ] On employee.terminated event, revoke all sessions for that employee
  - [ ] Mark as `status: revoked`, reason: `employee_terminated`

## Phase 10: Tests & Quality

- [ ] Unit tests for IdP adapters
  - [ ] `tests/Unit/IdP/DigidAdapterTest.php` — SAML request/response validation
  - [ ] `tests/Unit/IdP/YiviAdapterTest.php` — Yivi disclosure validation
  - [ ] `tests/Unit/IdP/EherkenningAdapterTest.php` — eHerkenning chain-of-trust
  - [ ] Test both success and failure paths

- [ ] Unit tests for SessionService
  - [ ] Create, step-up, revoke, idle timeout
  - [ ] Hash chain validation

- [ ] Unit tests for FraudDetectionService
  - [ ] Each signal type (impossible_travel, new_device, etc.)
  - [ ] Risk score aggregation
  - [ ] Critical signal workflow

- [ ] Unit tests for AttributeMappingService
  - [ ] Mandatory vs optional attributes
  - [ ] Transformations (pseudonymization, extraction)
  - [ ] Unmapped attribute discarding (not logged)

- [ ] Integration tests
  - [ ] End-to-end DigiD login flow (mock IdP)
  - [ ] Step-up flow
  - [ ] Session revocation
  - [ ] Audit log generation and export
  - [ ] Fallback mode when IdPs unavailable

- [ ] Run hydra-gates
  - [ ] Gate-1: SPDX headers (@license, @copyright)
  - [ ] Gate-5: Route authentication (`#[NoAdminRequired]` + per-object check or `#[AuthorizedAdminSetting]`)
  - [ ] Gate-23: Action authorization (call `actionAuth->requireAction()` or `#[AuthorizedAdminSetting]`)
  - [ ] Gate-9: Semantic auth (auth attribute matches body checks)

- [ ] Security review checklist
  - [ ] No PII in logs (IPs masked to /24 in audit export, no device fingerprints)
  - [ ] No raw attribute values logged on transformation failure
  - [ ] No stack traces in API responses
  - [ ] CSRF protection on all state-changing endpoints
  - [ ] CORS headers for login page (origin: self only)
  - [ ] Rate limiting on login endpoints (5 attempts per 15 min per IP)

## Phase 11: Seed Data Task

- [ ] Generate seed data in register JSON
  - [ ] IdentityProvider objects (digid, yivi, eherkenning, nextcloud-sso)
  - [ ] AuthenticationContext objects (employee.self-service, payroll.mutate, bsn.update, payroll.export-bulk)
  - [ ] Session seed objects (2-3 realistic sessions in active/stepped_up states)
  - [ ] All with realistic Dutch company data (municipalities, KvK, BSNs matching 11-proef)
  - [ ] Task runs during migration via `IrmqDigidAuthLoader::load()`

## Phase 12: Manifest & Configuration

- [ ] Create or update `src/manifest.json`
  - [ ] Register UnifiedLoginPage as public page
  - [ ] Register StepUpDialog as a dialog component
  - [ ] Register SettingsPanel under Configuratie › Integraties
  - [ ] Declare background jobs: HealthCheckService (every 5 min)
  - [ ] Declare webhooks: SIEM event stream, SOC notifications

- [ ] Create `.env.example`
  - [ ] Configure IdP endpoints (Logius, Privacy by Design, eHerkenning makelaar)
  - [ ] SIEM webhook URL
  - [ ] Threat intelligence API keys (if used)
  - [ ] MaxMind GeoIP2 license key (optional)
  - [ ] Secrets vault credentials (for issuing Yivi attributes)

## Phase 13: Documentation

- [ ] Write deployment guide at `docs/DEPLOYMENT.md`
  - [ ] Configure IdPs (DigiD, eHerkenning, Yivi)
  - [ ] Set up certificates and endpoints
  - [ ] Configure SIEM webhook
  - [ ] Run migrations

- [ ] Write admin guide at `docs/ADMIN.md`
  - [ ] IdP status management
  - [ ] Action-level policy definition
  - [ ] Audit log review
  - [ ] Fraud signal response workflow

- [ ] Write user guide at `docs/USER.md`
  - [ ] Unified login page walkthrough
  - [ ] Step-up flow for sensitive actions
  - [ ] Session expiry and re-authentication
  - [ ] Account recovery on fraud signal

## Phase 14: Performance & Monitoring

- [ ] Add performance metrics
  - [ ] Track login latency per IdP
  - [ ] Monitor audit log query performance
  - [ ] Alert on slow fraud detection scoring

- [ ] Add operational monitoring
  - [ ] Alert on critical FraudSignals
  - [ ] Alert on IdP health check failures
  - [ ] Track session creation/revocation rates

## Phase 15: Compliance & Audit Readiness

- [ ] Document compliance mapping
  - [ ] eIDAS-verordening → assurance levels in each IdP
  - [ ] BIO → hash-chained audit log, immutability proof
  - [ ] AVG → data minimization in attributes, DSAR export

- [ ] Prepare for security audit
  - [ ] Hashing algorithm (SHA256) used for chains
  - [ ] Secrets storage (HSM for Yivi issuer keys)
  - [ ] Rate limiting / brute-force protection
  - [ ] IP reputation and Tor exit detection
