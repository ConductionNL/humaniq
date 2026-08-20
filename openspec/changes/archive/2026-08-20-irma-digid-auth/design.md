# Design — irma-digid-auth

## Context

The 2026-05 draft `spec/irma-digid-auth` (`git show
origin/spec/irma-digid-auth:openspec/changes/irma-digid-auth/{proposal,design,specs,tasks}.md`)
proposed a standalone authentication app: five federated IdPs (DigiD, Yivi,
eHerkenning, ADFS/Entra ID, Nextcloud SSO fallback) behind a unified login
façade, an `hrmq-auth` OpenRegister register carrying `IdentityProvider`,
`AuthenticationContext`, `Session`, `AuthEvent`, `FraudSignal` and
`AttributeMapping` schemas, mid-session step-up, hash-chained audit logging,
and real-time risk scoring (impossible travel, Tor exit, credential
stuffing). It is read here as **idea source only** — none of it is ported.

**Verified against HEAD 2026-07-18:**

1. `openspec/config.yaml` (`rules.design`): "Uses OpenRegister API directly
   from the frontend (no own backend CRUD)." hrmq owns zero authentication
   surface today — every route an authenticated NC user reaches is either the
   SPA shell (`PageController`) or OpenRegister's `ObjectsController`, both
   gated by Nextcloud's own middleware, not hrmq code.
2. `openspec/changes/hris-api-public/design.md` (merged change, reading
   OpenRegister's live `ObjectsController` directly): "Nextcloud's own
   platform-level auth stack accepts a session cookie, an OIDC/SAML bearer
   token (if configured), or Basic Auth with an app password ... for any
   non-public route — this is a platform mechanism, not something hrmq or
   OpenRegister implements." This is the load-bearing fact this change
   builds on: whatever authenticates the `\OCP\IUser`, hrmq's behaviour
   downstream is identical.
3. `openspec/changes/hris-api-public/proposal.md` Non-goals already drew the
   analogy this change confirms: "SCIM manages *Nextcloud user accounts*
   across systems, which is an NC-instance-level identity concern (mirrors
   the `irma-digid-auth` finding: authentication/identity-provisioning is
   Nextcloud's layer, not a leaf app's)."
4. `openspec/specs/mijn-hr-self-service/spec.md` (REQ-MHS-004): the `Mijn HR`
   self-service surface scopes records by a denormalized `userId` property
   matched against the renderer's `@me` token — resolved from
   `getCurrentUser().uid`, i.e. whichever backend (password, SAML, OIDC)
   authenticated that Nextcloud user. `MijnLoonstroken` (Payslip) is
   explicitly **read-only** — `actionToggles` disable Add and all row
   actions (REQ-MHS-004). There is no self-service write path over Payslip.
5. `openspec/specs/payroll-sepa-netpay-shillinq/spec.md` (REQ-PNP-*):
   `Employee.iban`/`tenaamstelling` are written by payroll/HR as part of the
   net-pay pipeline. No spec anywhere gives an employee a self-service edit
   surface over their own `iban`/`tenaamstelling` — grep of every `openspec/
   specs/*/spec.md` for "iban"/"bankAccount" self-service editing turns up
   only the payroll-authored path.
6. `grep -rn "assurance\|step-up\|stepup\|two.factor\|mfa" openspec/specs/
   */spec.md openspec/architecture/*.md` — zero matches. No existing hrmq
   spec has ever needed an assurance-level concept; nothing regresses by not
   introducing one now.

**Conclusion:** the substance of "add DigiD/Yivi authentication to hrmq" is
already deliverable, today, with zero hrmq changes, by configuring
`user_saml` and/or `user_oidc` against a national broker at the Nextcloud
instance level. The one idea worth testing independently — step-up
assurance for sensitive actions — has no live trigger (no sensitive
self-service write exists) and no verified platform signal to key off. Both
are named as an explicit, falsifiable revisit condition (REQ-AUTH-003)
instead of being quietly dropped or spec'd on speculation.

## Goals / Non-Goals

**Goals:** state plainly that DigiD/Yivi/eHerkenning federation is a
Nextcloud platform-layer concern; name the concrete NC apps (`user_saml`,
`user_oidc`) and broker pattern that deliver it; bind hrmq against ever
building a parallel stack; record the one open question (step-up) with an
exact, checkable condition for when it would become real.

**Non-Goals:** any SAML/OIDC/IRMA protocol implementation inside hrmq; any
`IdentityProvider`/`Session`/`AuthEvent`/`FraudSignal`/`AttributeMapping`
schema; a unified login page (Nextcloud's own login screen, configured with
the enabled backends, already is one); risk scoring/fraud detection; a
hash-chained audit ledger; a mid-session assurance/step-up mechanism *at this
time*.

## Decisions

### D1 — Federation lives at the Nextcloud platform layer, named concretely

DigiD and eHerkenning are SAML 2.0 protocols in the Netherlands, brokered via
Logius; Yivi (formerly IRMA) typically integrates through an OIDC or SAML
bridge operated by the issuing organisation or a Yivi-compatible broker.
Nextcloud ships first-party apps for both transports:

- **`user_saml`** — SAML 2.0 user backend; the Logius DigiD/eHerkenning
  broker is configured here as the SAML IdP.
- **`user_oidc`** — OIDC/OAuth2 user backend; a Yivi-compatible OIDC bridge
  (or an OIDC-fronted DigiD/eHerkenning broker, where a deployment prefers
  OIDC over raw SAML) is configured here.

Both are installed and configured by the Nextcloud instance administrator —
outside hrmq's `appinfo/info.xml` dependencies, outside its deploy footprint,
entirely independent of whether hrmq is installed at all. Once configured,
every hrmq page, RBAC check, and `@me` filter continues to work completely
unmodified, because they only ever consumed "an authenticated `\OCP\IUser`",
never "a user authenticated by NC's local password backend specifically."

### D2 — Rejected: porting the draft's app-local federation stack

The obsolete draft's `IdentityProvider` register (per-IdP endpoint/
certificate/status config), unified login façade with maintenance-status
indicators, and `AttributeMapping` register (claim transformation, org-issued
Yivi attributes) all re-implement configuration surfaces `user_saml`/
`user_oidc` already ship as their own Nextcloud admin settings screens.
Building them in hrmq would create a second, drifting configuration surface
for the same underlying IdP connection — the exact failure mode ADR-022
documents for OpenRegister abstractions, applying identically one layer up
at the Nextcloud-platform boundary.

### D3 — Rejected: app-local Session/AuthEvent/fraud-scoring/hash-chained-audit

Session lifecycle, login audit, and anomaly detection are Nextcloud-instance
concerns (NC's own session handling, the `admin_audit` app, and whatever
security monitoring the broker or hosting provider already runs). A leaf HR
app duplicating hash-chained audit logging and real-time fraud scoring is
disproportionate to hrmq's role and duplicates infrastructure that belongs,
if anywhere, at the Nextcloud/broker layer — never inside a single installed
app's register.

### D4 — Step-up assurance: named as a falsifiable future condition, not built now

The draft's most defensible idea — sensitive self-service actions should
require a higher assurance level than "any authenticated NC user" — is not
rejected on principle. It is rejected **for now** because it fails on the
facts (Context items 4–5): no sensitive self-service *write* action exists in
hrmq today, so there is nothing to gate. REQ-AUTH-003 states the exact
condition (a genuine sensitive self-service write action is proposed, AND a
concrete NC-exposed assurance/LoA signal is verified to exist) under which a
future change should revisit this — and even then, that future change's
first job is to verify what `user_saml`/`user_oidc` actually exposes (e.g.
via a mapped SAML attribute or OIDC claim persisted on the NC user), not to
resurrect the draft's `AuthenticationContext`/`Session` schemas.

### D5 — Declarative-vs-imperative (ADR-031 decision)

| behaviour | path | rationale |
|---|---|---|
| DigiD/Yivi/eHerkenning federation | **Nextcloud platform apps** (`user_saml`, `user_oidc`) + broker, configured by the NC instance admin | the verified, already-shipping mechanism; zero hrmq code (D1) |
| Login page / IdP selection UI | Nextcloud's own login screen | `user_saml`/`user_oidc` register as login options on NC's existing screen; no hrmq façade (D2) |
| Session / login audit | Nextcloud `admin_audit` app + broker-side logs | out of hrmq's layer entirely (D3) |
| Assurance-level / step-up for sensitive actions | **not implemented** — deferred pending REQ-AUTH-003's trigger | no live sensitive self-service write exists; speculative schema work is exactly the anti-pattern being avoided (D4) |
| RBAC / who may read which schema | unchanged — OpenRegister RBAC | authentication (who you are) and authorization (what you may do) stay separately owned; this change touches only the former |

**Security note:** this change does not weaken or touch RBAC. Whatever
assurance level a deployment's `user_saml`/`user_oidc` configuration
requires to establish a session is a Nextcloud-instance policy decision,
made once, at the platform layer — not something hrmq re-litigates per
action.

## Seed Data

None. This change adds no schema, so there is no register fragment to seed.

## Risks / Trade-offs

- **A deployment might expect hrmq to "have DigiD built in."** Mitigated by
  the documentation section (proposal.md "What Changes") explaining that
  configuring `user_saml`/`user_oidc` against the Logius broker is the
  entire integration step — no hrmq-side setup exists or is needed.
- **Deferring step-up might be revisited too late** if a sensitive
  self-service write action ships without anyone checking REQ-AUTH-003
  first. Mitigated by making the condition explicit and citable in future
  proposals for any self-service write over `Employee.iban`/`tenaamstelling`
  or similarly sensitive fields.
- **Multiple simultaneous auth backends** (e.g. `user_saml` for DigiD plus
  NC's local password backend left enabled as a fallback) mean two users of
  differing real-world assurance can both appear to hrmq as an ordinary
  authenticated `\OCP\IUser`, indistinguishable without a verified platform
  signal. This is the exact scenario REQ-AUTH-003's trigger condition is
  written to catch — accepted as a known, named gap rather than hidden.

## Open Questions

- **Does `user_saml`/`user_oidc` expose a per-user assurance/LoA claim
  anywhere an app could read it** (e.g. a mapped SAML attribute persisted to
  a Nextcloud user property)? Not verified in this change — verifying this
  is the first task of whatever future change REQ-AUTH-003 triggers, not
  this one.
