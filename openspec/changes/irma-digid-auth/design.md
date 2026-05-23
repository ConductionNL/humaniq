# Design: IRMA + DigiD Authentication

## Architecture Overview

The irma-digid-auth app manages authentication and authorization for hrmq deployments across five identity providers. It abstracts provider-specific protocols (SAML, OIDC, IRMA) into a unified session and audit model, delegating all persistence to OpenRegister.

### Core Concept: Assurance-Level-as-Session-Attribute

Unlike traditional single-sign-on where "logged in" is binary, this design treats **assurance level as mutable session state**:

- A user logged in via DigiD niveau midden has `assuranceLevel = midden`
- The same user cannot execute `bsn.update` actions (requiring `substantial`) without step-up
- If they step up via DigiD hoog, their session gains `assuranceLevel = hoog` for 15 minutes
- After step-up expires, they revert to `midden` without re-logging out

This prevents "cached privilege escalation" where older credentials silently grant access to new, higher-security operations.

## Entity Schemas (OpenRegister)

All schemas live in `hrmq-auth` register (security-isolated from primary `hrmq` register).

### IdentityProvider

Configuration record per IdP. Controls availability, protocol, and assurance mapping.

**Schema:**
```json
{
  "$schema": "http://json-schema.org/draft-07/schema#",
  "title": "IdentityProvider",
  "type": "object",
  "properties": {
    "code": {
      "type": "string",
      "enum": ["digid", "yivi", "eherkenning", "adfs", "sso"],
      "description": "Unique IdP identifier"
    },
    "displayName_nl": {
      "type": "string",
      "description": "Dutch display name (e.g., 'DigiD')"
    },
    "displayName_en": {
      "type": "string",
      "description": "English display name"
    },
    "protocol": {
      "type": "string",
      "enum": ["saml", "oidc", "irma"],
      "description": "Federation protocol"
    },
    "endpoint": {
      "type": "string",
      "format": "uri",
      "description": "IdP endpoint URL"
    },
    "metadataUrl": {
      "type": "string",
      "format": "uri",
      "description": "SAML metadata or OIDC discovery URL"
    },
    "certificate": {
      "type": "string",
      "description": "PEM-encoded IdP certificate (or null for symmetric)"
    },
    "assuranceLevel": {
      "type": "string",
      "enum": ["laag", "midden", "substantial", "hoog"],
      "description": "eIDAS assurance level provided by this IdP"
    },
    "eidasMapping": {
      "type": "string",
      "description": "Mapping to eIDAS specification (e.g., 'eIDAS-substantial')"
    },
    "status": {
      "type": "string",
      "enum": ["active", "suspended", "maintenance"],
      "description": "Operational status"
    },
    "maintenanceUntil": {
      "type": "string",
      "format": "date-time",
      "description": "When maintenance ends (null if not in maintenance)"
    },
    "displayOrder": {
      "type": "integer",
      "description": "Order on login page (0=first)"
    },
    "isDefault": {
      "type": "boolean",
      "description": "Pre-selected on login page"
    }
  },
  "required": ["code", "displayName_nl", "protocol", "endpoint", "assuranceLevel", "status"]
}
```

### AuthenticationContext

Per-action policy defining assurance requirements and re-auth rules.

**Schema:**
```json
{
  "$schema": "http://json-schema.org/draft-07/schema#",
  "title": "AuthenticationContext",
  "type": "object",
  "properties": {
    "actionCode": {
      "type": "string",
      "pattern": "^[a-z0-9]+\\.[a-z0-9-]+$",
      "description": "Action identifier (e.g., 'payroll.mutate', 'bsn.update')"
    },
    "minimumAssuranceLevel": {
      "type": "string",
      "enum": ["laag", "midden", "substantial", "hoog"],
      "description": "Required assurance level"
    },
    "requiredAttributes": {
      "type": "array",
      "items": { "type": "string" },
      "description": "Mandatory attributes for this action (e.g., ['bsn', 'email'])"
    },
    "stepUpRequired": {
      "type": "boolean",
      "description": "Force step-up before action (even if current level sufficient)"
    },
    "maxIdleMinutes": {
      "type": "integer",
      "minimum": 5,
      "maximum": 1440,
      "description": "Idle timeout for this action's session (default 30)"
    },
    "reauthenticationRequired": {
      "type": "boolean",
      "description": "Force full re-login before action"
    },
    "stepUpValidityMinutes": {
      "type": "integer",
      "minimum": 5,
      "maximum": 60,
      "description": "How long step-up elevation lasts (default 15)"
    },
    "policyOwner": {
      "type": "string",
      "description": "Contact email or department responsible for this policy"
    }
  },
  "required": ["actionCode", "minimumAssuranceLevel"]
}
```

### Session

Live and historical session records. Append-only record of login/step-up/logout events per session.

**Schema:**
```json
{
  "$schema": "http://json-schema.org/draft-07/schema#",
  "title": "Session",
  "type": "object",
  "properties": {
    "sessionId": {
      "type": "string",
      "format": "uuid",
      "description": "Unique session identifier"
    },
    "userId": {
      "type": "string",
      "description": "hrmq user ID (from Nextcloud or employee-master)"
    },
    "idpUsed": {
      "type": "string",
      "enum": ["digid", "yivi", "eherkenning", "adfs", "sso", "fallback"],
      "description": "Which IdP authenticated the user"
    },
    "assuranceLevel": {
      "type": "string",
      "enum": ["laag", "midden", "substantial", "hoog"],
      "description": "Current session assurance level"
    },
    "attributesPresented": {
      "type": "array",
      "items": {
        "type": "object",
        "properties": {
          "claim": { "type": "string" },
          "value": { "type": "string" },
          "source": { "type": "string" }
        }
      },
      "description": "Claims released by IdP"
    },
    "ipAddress": {
      "type": "string",
      "format": "ipv4|ipv6",
      "description": "Client IP (unmasked in DB, masked in logs)"
    },
    "deviceFingerprint": {
      "type": "string",
      "description": "Hash(User-Agent + accept-language + canvas fingerprint)"
    },
    "userAgent": {
      "type": "string",
      "description": "Full User-Agent string"
    },
    "geoCity": {
      "type": "string",
      "description": "City from GeoIP2 reverse lookup"
    },
    "startedAt": {
      "type": "string",
      "format": "date-time",
      "description": "Session creation timestamp"
    },
    "expiresAt": {
      "type": "string",
      "format": "date-time",
      "description": "Session expiration (idle timeout + login time)"
    },
    "lastSeenAt": {
      "type": "string",
      "format": "date-time",
      "description": "Last activity timestamp"
    },
    "stepUpHistory": {
      "type": "array",
      "items": {
        "type": "object",
        "properties": {
          "timestamp": { "type": "string", "format": "date-time" },
          "fromLevel": { "type": "string" },
          "toLevel": { "type": "string" },
          "idpUsed": { "type": "string" },
          "expiresAt": { "type": "string", "format": "date-time" }
        }
      },
      "description": "History of assurance-level upgrades in this session"
    },
    "status": {
      "type": "string",
      "enum": ["active", "stepped_up", "expired", "revoked"],
      "description": "Session state"
    }
  },
  "required": ["sessionId", "userId", "idpUsed", "assuranceLevel", "ipAddress", "startedAt", "status"]
}
```

### AuthEvent

Immutable audit ledger. Each event's hash includes the previous event's hash (chain).

**Schema:**
```json
{
  "$schema": "http://json-schema.org/draft-07/schema#",
  "title": "AuthEvent",
  "type": "object",
  "properties": {
    "eventId": {
      "type": "string",
      "format": "uuid"
    },
    "sessionId": {
      "type": "string",
      "format": "uuid",
      "description": "Foreign key to Session"
    },
    "userId": {
      "type": "string"
    },
    "eventType": {
      "type": "string",
      "enum": ["login", "logout", "step_up_request", "step_up_success", "step_up_failure", "reauth", "fraud_signal", "anomaly", "session_expired", "admin_revocation"],
      "description": "Event category"
    },
    "idp": {
      "type": "string"
    },
    "actionContext": {
      "type": "object",
      "description": "Action that triggered this event (if any)"
    },
    "riskScore": {
      "type": "number",
      "minimum": 0,
      "maximum": 100
    },
    "outcome": {
      "type": "string",
      "enum": ["success", "failure", "attribute_mismatch", "attribute_signature_invalid", "attribute_expired", "transformation_failed", "missing_required_attribute", "degraded_mode_block", "session_revoked"],
      "description": "Event outcome"
    },
    "metadata": {
      "type": "object",
      "additionalProperties": true,
      "description": "Event-specific data (e.g., fraud signals, step-up details, chain-of-trust)"
    },
    "timestamp": {
      "type": "string",
      "format": "date-time"
    },
    "previousEventHash": {
      "type": "string",
      "description": "SHA256(previous event in session for chain)"
    },
    "eventHash": {
      "type": "string",
      "description": "SHA256 of this event (computed and stored for chain validation)"
    }
  },
  "required": ["eventId", "userId", "eventType", "timestamp", "outcome"]
}
```

### FraudSignal

Detected anomalies and their review status.

**Schema:**
```json
{
  "$schema": "http://json-schema.org/draft-07/schema#",
  "title": "FraudSignal",
  "type": "object",
  "properties": {
    "signalId": {
      "type": "string",
      "format": "uuid"
    },
    "userId": {
      "type": "string"
    },
    "signalType": {
      "type": "string",
      "enum": ["impossible_travel", "new_device", "brute_force", "credential_stuffing", "tor_exit", "known_bad_ip", "attribute_mismatch"],
      "description": "Type of anomaly"
    },
    "severity": {
      "type": "string",
      "enum": ["low", "medium", "high", "critical"],
      "description": "Risk severity"
    },
    "evidence": {
      "type": "object",
      "description": "Detailed evidence (e.g., {lastCity: 'Amsterdam', currentCity: 'Berlin', minutesElapsed: 10})"
    },
    "detectedAt": {
      "type": "string",
      "format": "date-time"
    },
    "reviewStatus": {
      "type": "string",
      "enum": ["open", "reviewed", "dismissed", "escalated"],
      "description": "SOC review status"
    },
    "reviewerId": {
      "type": "string",
      "description": "User ID of SOC analyst who reviewed"
    },
    "decision": {
      "type": "string",
      "enum": ["valid_threat", "false_positive", "acknowledged_user"],
      "description": "Analyst decision"
    }
  },
  "required": ["signalId", "userId", "signalType", "severity", "detectedAt"]
}
```

### AttributeMapping

Declarative mapping of IdP claims to hrmq fields with transformations.

**Schema:**
```json
{
  "$schema": "http://json-schema.org/draft-07/schema#",
  "title": "AttributeMapping",
  "type": "object",
  "properties": {
    "mappingId": {
      "type": "string",
      "format": "uuid"
    },
    "idpCode": {
      "type": "string",
      "description": "Which IdP (digid, yivi, eherkenning, etc.)"
    },
    "idpClaimName": {
      "type": "string",
      "description": "Claim name from IdP (e.g., 'bsn', 'email')"
    },
    "hrmqField": {
      "type": "string",
      "description": "Target field in hrmq (e.g., 'employee.bsn')"
    },
    "transformExpression": {
      "type": "string",
      "description": "Transformation function (e.g., 'pseudonymize_bsn', 'extract_initialen', or regex pattern)"
    },
    "mandatory": {
      "type": "boolean",
      "description": "Login fails if this attribute is missing"
    },
    "lastVerifiedAt": {
      "type": "string",
      "format": "date-time",
      "description": "When this mapping was last validated"
    }
  },
  "required": ["idpCode", "idpClaimName", "hrmqField", "mandatory"]
}
```

## Seed Data

### IdentityProvider Seed Objects

```json
[
  {
    "@self": {
      "register": "hrmq-auth",
      "schema": "IdentityProvider",
      "slug": "digid-midden"
    },
    "code": "digid",
    "displayName_nl": "DigiD",
    "displayName_en": "DigiD",
    "protocol": "saml",
    "endpoint": "https://logius.nl/saml/sso",
    "metadataUrl": "https://logius.nl/saml/metadata",
    "certificate": "-----BEGIN CERTIFICATE-----\nMIIBkTCB+wIJAKHHxxxxx\n-----END CERTIFICATE-----",
    "assuranceLevel": "midden",
    "eidasMapping": "eIDAS-substantial",
    "status": "active",
    "displayOrder": 0,
    "isDefault": true
  },
  {
    "@self": {
      "register": "hrmq-auth",
      "schema": "IdentityProvider",
      "slug": "yivi-gemeente"
    },
    "code": "yivi",
    "displayName_nl": "Yivi (IRMA)",
    "displayName_en": "Yivi",
    "protocol": "irma",
    "endpoint": "https://privacybydesign.foundation/irma",
    "metadataUrl": "https://privacybydesign.foundation/irma/api/v2",
    "certificate": null,
    "assuranceLevel": "midden",
    "eidasMapping": "attribute-based",
    "status": "active",
    "displayOrder": 1,
    "isDefault": false
  },
  {
    "@self": {
      "register": "hrmq-auth",
      "schema": "IdentityProvider",
      "slug": "eherkenning-makelaar"
    },
    "code": "eherkenning",
    "displayName_nl": "eHerkenning (management)",
    "displayName_en": "eHerkenning",
    "protocol": "saml",
    "endpoint": "https://eherkenning-makelaar.nl/saml/sso",
    "metadataUrl": "https://eherkenning-makelaar.nl/metadata",
    "certificate": "-----BEGIN CERTIFICATE-----\nMIIBkTCB+wIJAKHHxxxxx\n-----END CERTIFICATE-----",
    "assuranceLevel": "substantial",
    "eidasMapping": "eIDAS-substantial",
    "status": "active",
    "displayOrder": 2,
    "isDefault": false
  },
  {
    "@self": {
      "register": "hrmq-auth",
      "schema": "IdentityProvider",
      "slug": "nextcloud-sso-fallback"
    },
    "code": "sso",
    "displayName_nl": "Nextcloud SSO",
    "displayName_en": "Nextcloud SSO",
    "protocol": "oidc",
    "endpoint": "https://instance.example.com/oidc",
    "metadataUrl": null,
    "certificate": null,
    "assuranceLevel": "laag",
    "eidasMapping": "fallback-only",
    "status": "active",
    "displayOrder": 99,
    "isDefault": false
  }
]
```

### AuthenticationContext Seed Objects

```json
[
  {
    "@self": {
      "register": "hrmq-auth",
      "schema": "AuthenticationContext",
      "slug": "employee-self-service"
    },
    "actionCode": "employee.self-service",
    "minimumAssuranceLevel": "midden",
    "requiredAttributes": ["bsn", "email"],
    "stepUpRequired": false,
    "maxIdleMinutes": 30,
    "reauthenticationRequired": false,
    "stepUpValidityMinutes": 15,
    "policyOwner": "hr-team@example.gov"
  },
  {
    "@self": {
      "register": "hrmq-auth",
      "schema": "AuthenticationContext",
      "slug": "payroll-mutation"
    },
    "actionCode": "payroll.mutate",
    "minimumAssuranceLevel": "substantial",
    "requiredAttributes": ["bsn", "email", "name"],
    "stepUpRequired": true,
    "maxIdleMinutes": 20,
    "reauthenticationRequired": false,
    "stepUpValidityMinutes": 15,
    "policyOwner": "security-team@example.gov"
  },
  {
    "@self": {
      "register": "hrmq-auth",
      "schema": "AuthenticationContext",
      "slug": "bsn-update"
    },
    "actionCode": "bsn.update",
    "minimumAssuranceLevel": "substantial",
    "requiredAttributes": ["bsn", "email", "name", "birthdate"],
    "stepUpRequired": true,
    "maxIdleMinutes": 15,
    "reauthenticationRequired": true,
    "stepUpValidityMinutes": 10,
    "policyOwner": "compliance@example.gov"
  },
  {
    "@self": {
      "register": "hrmq-auth",
      "schema": "AuthenticationContext",
      "slug": "payroll-export-bulk"
    },
    "actionCode": "payroll.export-bulk",
    "minimumAssuranceLevel": "hoog",
    "requiredAttributes": ["bsn", "email", "name"],
    "stepUpRequired": true,
    "maxIdleMinutes": 10,
    "reauthenticationRequired": true,
    "stepUpValidityMinutes": 20,
    "policyOwner": "ciso@example.gov"
  }
]
```

### Session Seed Objects

```json
[
  {
    "@self": {
      "register": "hrmq-auth",
      "schema": "Session",
      "slug": "session-alice-digid-2026-05-23"
    },
    "sessionId": "550e8400-e29b-41d4-a716-446655440000",
    "userId": "alice.schmidt",
    "idpUsed": "digid",
    "assuranceLevel": "midden",
    "attributesPresented": [
      { "claim": "bsn", "value": "123456789", "source": "digid" },
      { "claim": "email", "value": "alice.schmidt@example.gov", "source": "digid" },
      { "claim": "name", "value": "Alice Schmidt", "source": "digid" }
    ],
    "ipAddress": "203.0.113.42",
    "deviceFingerprint": "c4ca4238a0b923820dcc509a6f75849b",
    "userAgent": "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36",
    "geoCity": "Amsterdam",
    "startedAt": "2026-05-23T09:30:00Z",
    "expiresAt": "2026-05-23T10:00:00Z",
    "lastSeenAt": "2026-05-23T09:45:00Z",
    "stepUpHistory": [],
    "status": "active"
  },
  {
    "@self": {
      "register": "hrmq-auth",
      "schema": "Session",
      "slug": "session-bob-eherkenning-stepped"
    },
    "sessionId": "550e8400-e29b-41d4-a716-446655440001",
    "userId": "bob.manager",
    "idpUsed": "eherkenning",
    "assuranceLevel": "substantial",
    "attributesPresented": [
      { "claim": "bsn", "value": "987654321", "source": "eherkenning" },
      { "claim": "email", "value": "bob.manager@example.gov", "source": "eherkenning" },
      { "claim": "kvk", "value": "12345678", "source": "eherkenning" }
    ],
    "ipAddress": "198.51.100.15",
    "deviceFingerprint": "c81e728d9d4c2f636f067f89cc14862c",
    "userAgent": "Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7)",
    "geoCity": "Den Haag",
    "startedAt": "2026-05-23T08:00:00Z",
    "expiresAt": "2026-05-23T08:50:00Z",
    "lastSeenAt": "2026-05-23T08:45:00Z",
    "stepUpHistory": [
      {
        "timestamp": "2026-05-23T08:30:00Z",
        "fromLevel": "midden",
        "toLevel": "substantial",
        "idpUsed": "eherkenning",
        "expiresAt": "2026-05-23T08:45:00Z"
      }
    ],
    "status": "stepped_up"
  }
]
```

## Reuse Analysis

Per ADR-001 (Data Layer), all domain data for sessions, audit, and fraud signals persist via OpenRegister. The app leverages:

- **ObjectService** for CRUD on all five schemas
- **IndexService** for searching sessions, audit events, fraud signals
- **AuditTrailService** for tracking changes to IdentityProvider and AuthenticationContext (meta-audit)
- **WebhookService** for streaming AuthEvents to SIEM (CloudEvents format)
- **NotificationService** for SOC alerts on critical fraud signals
- **FileService** for audit export downloads (JSON-lines, ZIP with validation script)

No custom database schema, Entity classes, or Mapper classes are created. All configuration is JSON in OpenRegister.

## Declarative-vs-Imperative Decisions

Per ADR-031 (Schema-declarative business logic):

| Behaviour | Path | Rationale |
|-----------|------|-----------|
| **Session lifecycle** (creation → active → expired → revoked) | Declarative (x-openregister-lifecycle in Session schema) | State machine is simple and audit-critical; declarative ensures immutability and versioning |
| **Assurance-level step-up** | Imperative (custom StepUpService) | Domain-specific business logic (IdP selection, attribute validation, level-duration rules) justifies a service class; too complex for declarative field logic |
| **Risk scoring and FraudSignal** | Imperative (custom FraudDetectionService) | Scoring algorithm involves external APIs (GeoIP, Tor exit list, threat feeds); needs coordinated state updates and webhook dispatch |
| **Idle timeout enforcement** | Declarative (x-openregister-lifecycle with expiration rule) | Auto-expire based on lastSeenAt + maxIdleMinutes is purely rule-based; can be a lifecycle transition |
| **Audit event chaining** | Imperative (custom HashChainService in AuthEvent creation) | Cryptographic chaining (previous-event hash) is low-level security infrastructure; belongs in application code, not schema logic |
| **Attribute transformation** | Declarative (x-openregister-transform on AttributeMapping fields) | Regex and pseudonymization rules are declarative; should live in schema config |
| **IdP healthcheck** | Imperative (HealthCheckCommand scheduled job) | External HTTP requests; scheduled every 5 minutes via n8n ScheduledWorkflow |
| **Single Logout dispatch** | Imperative (custom SloService) | Sending SLO requests to multiple IdPs with fallback logic is orchestration work |

## Integration with hrmq Base

Spec authors integrate via an SDK:

```php
use OCA\IrmqDigidAuth\Service\SessionService;
use OCA\IrmqDigidAuth\DTO\CurrentUserContext;

// In a controller:
$context = $this->sessionService->getCurrentContext($request);
// Returns: { userId, currentAssuranceLevel, sessionId, stepUpHistory, ... }

if (!$this->sessionService->hasStepUp($context, 'payroll.mutate')) {
    return new JSONResponse(['error' => 'Higher authentication required'], Http::STATUS_FORBIDDEN);
}
```

All other hrmq apps consume this SDK; no direct OpenRegister access for auth.

## Data Minimization & Privacy

Per AVG art. 32 and GDPR compliance:

- **Session attributes** store only claims needed for the current action (never historical claims)
- **Fraud signals** do not include full user PII; only behavioral hashes
- **Audit logs** support pseudonymization for Data Subject Access Requests:
  - IP addresses anonymized to /24 (IPv4) / /48 (IPv6)
  - Device fingerprints excluded
  - Geo-city aggregated to region level
- **Attribute discards** are not logged (unmapped claims discarded immediately, not retained for debugging)
- **Session cleanup** — expired sessions retained in audit log; personal data (IPs, devices) removed after 90 days per retention policy

## Error Handling & Fallback

- **IdP unreachable** → fallback to Nextcloud SSO with degraded-mode UI
- **Attribute validation failure** → admin notification + user-friendly error + AuthEvent logged
- **Hash chain validation failure** → audit system alerts (potential tampering)
- **Step-up timeout** → session reverts to original level; action is NOT auto-retried
