---
status: draft
app: hrmq
spec: hris-api-public
target_users: [integrator, devops, it-admin, third-party-vendor]
estimated_effort: L
depends_on: [employee-management, org-chart-basic, openconnector-auth-protocol-suite]
---

# Public HRIS API (REST + GraphQL + Webhooks + SCIM)

## Placement & Information Architecture

**Placement type:** `SETTING` — Setting under the app's Beheer/Admin/Configuration surface. Lives in the existing settings UI; no top-level menu entry.

**Lives at:** Configuratie › Integraties

**Rationale:** REST+GraphQL+Webhooks+SCIM.  
_Source: /tmp/ia-procest-hrmq.md_

> **Implementation note for builders:** Respect the placement above. Do not promote this spec to a top-level menu item, sub-page, or new route unless the placement type explicitly says so. If the placement is `DETAIL_TAB`, `WIDGET`, `ACTION`, `SETTING`, or `INFRA`, the feature must NOT introduce a new entry in the app sidebar. When in doubt, ask before creating a new top-level surface.

## Purpose

A first-class, documented, versioned, externally-consumable API layer over hrmq so that other systems in the customer's stack (Azure AD / Entra ID, Google Workspace, Slack, Okta, learning-management platforms, payroll providers, BI tools) can read and write HR data without screen-scraping or relying on internal openregister endpoints. Today every integration is a snowflake — a custom n8n workflow, a CSV export, a manual sync. This spec defines a stable public surface: REST for CRUD, GraphQL for flexible queries (BI use case), event-stream / webhooks for push notifications, and SCIM 2.0 for user-provisioning (the de-facto identity-management protocol that Azure AD, Okta, JumpCloud, Google all speak natively).

Depends on the openconnector `auth-protocol-suite` spec (OAuth2 client, JWT, API-key management) — this spec defines the HRIS-specific surface, openconnector provides the auth primitives.

## Data Model

**ApiKey** (per-tenant, scoped):
- `id`, `tenant_id`, `name` (human-readable), `prefix` (visible — `hrmq_live_abc...`), `hashed_secret`
- `scopes`: array (e.g. `employees:read`, `employees:write`, `leave:read`, `scim:provision`)
- `created_at`, `created_by_user_id`, `last_used_at`, `expires_at` (nullable)
- `rate_limit_tier`: enum (`free`, `standard`, `enterprise`)

**OAuthClient** (existing in openconnector, extended for hrmq scopes):
- `scopes`: superset of ApiKey scopes
- `redirect_uris`, `grant_types`

**Webhook** (per-tenant subscription):
- `id`, `tenant_id`, `url` (HTTPS only), `secret` (for HMAC signature)
- `event_types`: array (e.g. `employee.created`, `employee.updated`, `employee.terminated`, `leave.approved`, `org.restructured`)
- `active`: boolean, `retry_policy`: json (default: 5 retries, exponential backoff)

**WebhookDelivery** (audit log):
- `webhook_id`, `event_id`, `attempt_number`, `http_status`, `response_body_truncated`, `delivered_at`, `next_retry_at`

**ScimResource** (SCIM 2.0 protocol entities, mapped to Employee):
- Standard SCIM User schema + EnterpriseUser extension (manager, department, employeeNumber, costCenter, division)
- Group schema mapped to OrgUnit

## Requirements

### REQ-001: REST API — versioned, documented

**GIVEN** an external system
**WHEN** it calls `GET /api/v1/employees` with a valid `Authorization: Bearer <api-key>` header
**THEN** it receives a paginated JSON list (cursor-based pagination, default 50, max 250), with fields filtered by scope, conforming to the OpenAPI 3.1 spec published at `/api/v1/openapi.json`; rate-limit headers (X-RateLimit-Remaining, X-RateLimit-Reset) are returned

### REQ-002: GraphQL endpoint for flexible queries

**GIVEN** a BI tool needing nested data (employee + manager + org-unit + recent leave-requests in one round-trip)
**WHEN** it POSTs a GraphQL query to `/api/v1/graphql`
**THEN** the response includes only requested fields, query depth is capped at 7 levels (DoS protection), query complexity is scored and capped per rate-limit-tier, and the schema is introspectable in non-production environments

### REQ-003: Webhook subscription

**GIVEN** an integrator
**WHEN** they POST `/api/v1/webhooks` with `url` + `event_types`
**THEN** a Webhook record is created with a generated `secret`, returned once in the response (never again retrievable), and the URL is verified by an immediate `webhook.verification` event the consumer must respond to with HTTP 200 within 10 seconds

### REQ-004: Webhook delivery + HMAC signature

**GIVEN** an event matching a Webhook subscription
**WHEN** the event fires
**THEN** the system POSTs to the webhook URL with header `X-Hrmq-Signature: sha256=<hmac>` computed over the request body using the webhook secret, retries up to 5 times with exponential backoff on non-2xx, logs each attempt to WebhookDelivery, and disables the webhook after 50 consecutive failures (with notification to the tenant admin)

### REQ-005: SCIM 2.0 endpoint for identity provisioning

**GIVEN** an IdP (Azure AD, Okta, Google) configured to provision users to hrmq via SCIM
**WHEN** the IdP POSTs `/scim/v2/Users` with a SCIM-formatted user payload
**THEN** the system creates a corresponding Employee record, maps SCIM attributes per RFC 7644 + EnterpriseUser extension, returns the SCIM resource with assigned `id`, and supports the full SCIM verbs: GET (read), PATCH (incremental update), PUT (full replace), DELETE (deprovision = mark Employee inactive, do not hard-delete)

### REQ-006: SCIM Groups for org-units

**GIVEN** an IdP managing org-units as groups
**WHEN** the IdP creates / updates a SCIM Group
**THEN** the system maps it to an OrgUnit, members of the Group become OrgAssignments to that OrgUnit, and removal from the Group closes the OrgAssignment (valid_until=today)

### REQ-007: API-key management UI

**GIVEN** a tenant admin
**WHEN** they navigate to Settings > API & Integrations
**THEN** they can create API keys with scoped permissions, see last-used timestamps and usage stats (requests/day chart), rotate or revoke keys, and see all active webhooks + delivery success rate; key secrets are shown exactly once at creation

### REQ-008: Rate-limiting + quota enforcement

**GIVEN** any API consumer (key or OAuth client)
**WHEN** they exceed their rate-limit-tier (free=60 req/min, standard=600 req/min, enterprise=6000 req/min)
**THEN** the API returns HTTP 429 with `Retry-After` header, the throttling event is logged for the tenant admin to see, and persistent abuse (>1000 429s/day) triggers an alert to the platform operator

## Standards & References

- **SCIM 2.0** — RFC 7642 (use cases), RFC 7643 (schema), RFC 7644 (protocol)
- **OAuth 2.0** — RFC 6749, RFC 6750 (Bearer Tokens)
- **OAuth 2.1** — current best-practice draft (PKCE mandatory for public clients)
- **OpenAPI 3.1** — API description format
- **Webhooks** — no formal standard, follows Stripe / GitHub conventions (HMAC-SHA256 signature header)
- **JSON:API** considered but rejected — too verbose, REST-pragmatic chosen

## Cross-app Coordination

- **openconnector** — auth-protocol-suite provides OAuth2 server + JWT issuance + API-key validation; this spec depends on it; openconnector also provides the outbound webhook delivery worker (retries, backoff)
- **openregister** — ApiKey, Webhook, WebhookDelivery schemas live in shared `infra` register (reusable across all Conduction apps that expose public APIs)
- **mydash** — API-usage dashboards (requests/day, error rate, top consumers) consume hrmq API metrics
- **n8n** — replaces ad-hoc n8n integration flows with stable webhook subscriptions; n8n becomes a webhook consumer, not a screen-scraper
- **opentalk** — Slack/Teams notifications on key HR events (new hire, termination) subscribe to webhooks

## Target Users

Primary: Integration developers building Azure AD / Okta / Google / Slack / payroll-provider connectors. Secondary: customer IT-admins configuring IdP provisioning, BI engineers building HR dashboards. Out of scope: bulk-data exports for analytics (separate spec — recommends differential snapshots to data-warehouse, not API polling), real-time bidirectional sync with full conflict resolution (separate spec).
