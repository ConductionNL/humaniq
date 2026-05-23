---
kind: code
depends_on: []
status: draft
---

# Proposal: IRMA + DigiD Authentication — Government HRM Identity Layer

## Why

Dutch government HR systems (hrmq deployments) handle special-category personal data including BSNs, salary records, integrity reports, sick leave, and health indicators during reintegration. This data demands authentication assurance levels that exceed username/password — either eIDAS "substantial" (DigiD niveau midden, eHerkenning niveau 3) or "high" (DigiD hoog, eHerkenning niveau 4).

The current state has no federated identity layer. This proposal closes that gap by:

1. **Abstracting five identity providers** (DigiD, eHerkenning, Yivi, ADFS/Entra ID, Nextcloud SSO) behind a unified OIDC/SAML façade.
2. **Enforcing assurance-level-as-session-attribute**, not user-attribute — a user logged in with DigiD midden today may not perform niveau-3 actions without re-authentication via a higher-level provider.
3. **Enabling risk-based step-up authentication** — suspicious login patterns (impossible travel, new device, Tor exit) trigger forced re-authentication before sensitive actions.
4. **Delivering immutable, chained-hash audit logs** required by BIO (Baseline Informatiebeveiliging Overheid) for tamper-evidence compliance.
5. **Providing anti-fraud detection** via IP reputation, device fingerprinting, and geographic anomaly scoring.
6. **Defaulting to data minimization** — Yivi attribute-based auth reveals only strictly necessary claims (e.g., "over 18" not full identity).
7. **Offering fallback to Nextcloud SSO** when external IdPs are unreachable, with explicit degraded-mode signaling and reduced permissions.

## What Changes

A new **irma-digid-auth** app deployed into hrmq instances as an authentication and session-management service. The app:

- **Registers and configures** five identity providers (IdentityProvider register)
- **Defines action-level assurance requirements** (AuthenticationContext register) — each protected action specifies its minimum assurance level and whether step-up is required
- **Manages sessions** with live and historical audit (Session register, append-only)
- **Logs every authentication event** with cryptographic chaining (AuthEvent register, tamper-evidence)
- **Detects fraud signals** and stores them for review (FraudSignal register)
- **Maps IdP claims to hrmq fields** with transformations and data minimization (AttributeMapping register)
- **Delegates to OpenRegister** for all data persistence (no custom database schema)
- **Provides a unified login page** with IdP selection, status indicators (maintenance/suspended), and recommended defaults
- **Implements step-up flows** for sensitive actions — users can upgrade their session assurance level mid-session
- **Integrates with employee-master** for BSN validation and employment-status checks
- **Streams all AuthEvents to SIEM/SOC** via webhook (JSON-lines) for real-time security monitoring

## Capabilities

### New Capabilities

- **multi-idp-federation**: Unified login page for DigiD (niveau midden), eHerkenning (niveau 3-4), Yivi, ADFS/Entra ID, and Nextcloud SSO fallback; IdP status indicators; configurable recommended default per organisation
- **digid-niveau-midden-integration**: SAML flow with niveau midden (`AuthnContextClassRef`); BSN/name/birthdate validation against employee-master; mismatch audit events
- **yivi-attribute-based-auth**: Minimal-disclosure authorization via Privacy by Design Foundation protocol; attribute-signature verification; age-gating via Yivi attributes; organisation-issued Yivi attributes for custom claims
- **eherkenning-manager-auth**: eHerkenning niveau 3-4 for manager and HR actions; KvK validation; chain-of-trust (ketenmachtiging) logging
- **step-up-authentication**: Mid-session assurance upgrade for sensitive actions; time-limited elevated levels (15 min or action-duration); step-up cancellation handling
- **immutable-audit-logging**: Append-only AuthEvent ledger with cryptographic hash chaining (each event includes hash of previous); tamper-evidence validation; GDPR anonymization (IP → /24 for IPv4, /48 for IPv6)
- **anti-fraud-detection**: Real-time risk scoring (impossible travel, new device, Tor exit, brute force, credential stuffing); configurable severity thresholds; fraud signal storage and SOC review workflow
- **session-management-revocation**: Manual logout with Single Logout (SLO) to IdP; admin-triggered revocation on employee status change; idle-session expiration; soft-logout messaging
- **nextcloud-sso-fallback**: Fallback to built-in Nextcloud auth when external IdPs unreachable; explicit degraded-mode UI; reduced permissions (no substantial/high actions); healthcheck-triggered fallback selection
- **attribute-mapping-data-minimization**: Declarative claim mapping with transformation rules (pseudonymize_bsn, extract_initialen); discard of unmapped claims (not logged); mandatory-attribute enforcement; transformation-failure rejection

### Modified Capabilities

- **hrmq-base** (all apps): SDK updated to expose `currentUser`, `currentAssuranceLevel`, `hasStepUp(actionCode)` helpers; protected routes delegate auth to irma-digid-auth
- **openconnector**: Extended with DigiD-adapter (SAML), eHerkenning-makelaar (SAML), Yivi-server-adapter (REST), healthchecks per IdP
- **employee-master**: Integration point for BSN validation; reads employment status for session revocation; serves as identity source of truth

## Impact

- **Apps affected**: All hrmq apps (every protected route will use the new auth layer)
- **Breaking changes**: No — this is a new app; existing Nextcloud auth continues as fallback
- **Migration needed**: Yes — hrmq apps must import and wire the SDK; admin must configure IdPs and action-level policies on first deploy
- **Standards alignment**: eIDAS-verordening, Logius DigiD/eHerkenning specs, Yivi protocol, NIST SP 800-63-3, BIO, AVG, NCSC guidelines
- **Placement**: SETTING under `Configuratie › Integraties` (no top-level menu per ADR-001 rule 1)
