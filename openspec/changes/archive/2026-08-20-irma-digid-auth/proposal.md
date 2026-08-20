---
kind: config
depends_on: []
---

# DigiD / Yivi Authentication — Nextcloud Platform Layer, Not an hrmq Auth Stack

## Why

The 2026-05 draft `spec/irma-digid-auth` designed a bespoke authentication and
identity-federation app: five hand-rolled IdP integrations (DigiD, Yivi,
eHerkenning, ADFS/Entra ID, Nextcloud SSO fallback), an app-local
`IdentityProvider`/`AuthenticationContext`/`Session`/`AuthEvent`/`FraudSignal`/
`AttributeMapping` register set, a unified login-page façade, mid-session
assurance-level step-up, hash-chained audit logging, and real-time fraud
scoring — roughly 1,500 lines of protocol/security spec, all living inside
hrmq.

**Verified against HEAD 2026-07-18**, reading hrmq's own architecture record
(`openspec/config.yaml`'s `rules.design`: "Uses OpenRegister API directly from
the frontend, no own backend CRUD") and the already-merged `hris-api-public`
change's design.md, which reads OpenRegister's live `ObjectsController` and
states plainly: "Nextcloud's own platform-level auth stack accepts a session
cookie, an OIDC/SAML bearer token (if configured), or Basic Auth with an app
password ... for any non-public route — this is a platform mechanism, not
something hrmq or OpenRegister implements." `hris-api-public` even names this
exact finding as a forward reference: its SCIM non-goal states "authentication/
identity-provisioning is Nextcloud's layer, not a leaf app's" — the analogy
this change now confirms directly.

DigiD, eHerkenning and Yivi (formerly IRMA) are federated-identity protocols.
Nextcloud already ships first-party apps that speak them at the platform
level: `user_saml` (SAML 2.0 — the transport DigiD and eHerkenning use via the
Logius broker) and `user_oidc` (OIDC/OAuth2 — the transport a Yivi-compatible
bridge typically exposes). Once either app authenticates a person, hrmq (like
every other Nextcloud app) simply receives an already-authenticated
`\OCP\IUser` — the exact same session object it receives today for a
password-authenticated user. hrmq's manifest renderer, `@me` filter token, and
OpenRegister RBAC (per `mijn-hr-self-service`) do not distinguish *how* the NC
session was established; they only require that one exists. Building a
parallel federation stack inside hrmq would duplicate what `user_saml`/
`user_oidc` + a Logius/eIDAS broker already deliver, violate the
apps-consume-platform-abstractions principle ADR-022 establishes for
OpenRegister and `hris-api-public` already extends to identity by analogy, and
— worse — hrmq re-implementing SAML/OIDC crypto, certificate handling, and
assertion validation is precisely the kind of security-critical duplication
that principle exists to prevent.

The one idea in the draft worth testing on its own merits — mid-session
"step-up" to a higher assurance level before a sensitive self-service action
(e.g. viewing a payslip, editing bank details) — does not have a genuine
hrmq-side trigger today. `mijn-hr-self-service`'s `MijnLoonstroken` page is
already read-only, and `Employee.iban`/`tenaamstelling`
(`payroll-sepa-netpay-shillinq`) are HR/payroll-authored fields with no
self-service edit surface anywhere in hrmq — there is no sensitive
self-service *write* action to gate. Nor is there a verified mechanism for
hrmq to read an assurance/LoA claim out of whatever `user_saml`/`user_oidc`
configuration a deployment runs. Spec'ing a step-up schema now would mean
resurrecting the draft's `AuthenticationContext`/`Session` machinery on
spec-only speculation. This change records that finding as an explicit
anti-requirement with a concrete revisit trigger, rather than silently
dropping it or building it anyway.

## What Changes

- **Documentation only**: hrmq's docs gain a short "Authentication" section
  stating that DigiD, Yivi and eHerkenning are configured at the Nextcloud
  instance level via `user_saml`/`user_oidc` against a Logius (DigiD/
  eHerkenning) or Yivi-compatible OIDC broker, entirely outside hrmq's
  install/deploy footprint, and that hrmq requires nothing beyond an
  authenticated `\OCP\IUser` — no protocol code, no certificates, no IdP
  registers.
- **No new hrmq register, schema, controller, service, or manifest page.**
  This change adds zero PHP and zero Vue.
- **Explicit anti-requirements** (this is the actual spec content): hrmq
  SHALL NOT build its own federation/session/audit stack, and SHALL NOT build
  an app-local assurance-level/step-up mechanism until a genuine sensitive
  self-service write action exists *and* a concrete NC-exposed assurance
  signal has been verified to gate it. Both conditions are currently false,
  and are recorded as the revisit trigger for a future change.
- **Supersedes** `spec/irma-digid-auth` (the May-2026 draft) as the idea
  source; none of its registers, login façade, step-up flow, fraud-scoring,
  or hash-chained audit design are ported.

### Non-goals

- No `IdentityProvider`, `AuthenticationContext`, `Session`, `AuthEvent`,
  `FraudSignal`, or `AttributeMapping` OpenRegister schemas.
- No unified login-page façade, IdP status/maintenance indicators, or
  recommended-default-IdP configuration inside hrmq — `user_saml`/
  `user_oidc` each ship their own admin configuration screens for this.
  the NC login screen it produces is the "unified" surface.
- No app-local risk scoring, device fingerprinting, or fraud-signal
  detection — out of scope for a leaf HR app under any circumstance; if a
  deployment needs this, it belongs in the Nextcloud/broker layer or a
  dedicated security product, never hrmq.
- No hash-chained `AuthEvent` audit ledger — Nextcloud's own admin audit
  logging (`admin_audit` app) and `user_saml`/`user_oidc`'s own logs cover
  login events; OpenRegister's existing audit trail (ADR-022) covers object
  writes. hrmq adds no third ledger.
- No mid-session assurance-level/step-up mechanism *at this time* — see
  REQ-AUTH-003 for the exact condition under which this should be
  reconsidered.

## Capabilities

### New Capabilities

- `irma-digid-auth`: the documented finding and binding anti-requirements
  that DigiD/Yivi/eHerkenning authentication is delivered by the Nextcloud
  platform layer (`user_saml`, `user_oidc`, a Logius/eIDAS or Yivi-compatible
  broker) and is out of hrmq's scope to build, plus the named, falsifiable
  condition under which a thin hrmq-side step-up requirement would become
  genuine.

### Modified Capabilities

_None._ `mijn-hr-self-service` and `payroll-sepa-netpay-shillinq` are read
(to establish that no sensitive self-service write action exists today), not
modified.

## Impact

- `README.md` / `docs/` — new "Authentication" section (the only content
  change).
- No `lib/Settings/register.d/*.json` change — no new schema.
- No `src/manifest.json` change — no new page.
- No `lib/Controller/`, `lib/Service/`, or `appinfo/routes.php` change.
- Related: supersedes `spec/irma-digid-auth` (obsolete draft, idea source
  only). `hris-api-public` is the sibling precedent for this exact
  disposition pattern (mostly-abstraction-delivered, documentation is the
  genuine delta) and already named the identity-layer analogy this change
  confirms.
