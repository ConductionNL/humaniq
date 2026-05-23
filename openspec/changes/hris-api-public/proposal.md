# Proposal: Public HRIS API (REST + GraphQL + Webhooks + SCIM)

**Change:** hris-api-public  
**Status:** draft  
**App:** hrmq  
**Target Users:** integrator, devops, it-admin, third-party-vendor  
**Estimated Effort:** L  
**Depends on:** employee-management, org-chart-basic, openconnector-auth-protocol-suite

---

## Purpose

Expose a first-class, documented, versioned, externally-consumable API layer over hrmq so that other systems in the customer's stack (Azure AD / Entra ID, Google Workspace, Slack, Okta, learning-management platforms, payroll providers, BI tools) can read and write HR data without screen-scraping or relying on internal openregister endpoints.

Today every integration is a snowflake — a custom n8n workflow, a CSV export, a manual sync. This spec defines a stable public surface: **REST for CRUD**, **GraphQL for flexible queries** (BI use case), **event-stream / webhooks** for push notifications, and **SCIM 2.0** for user-provisioning (the de-facto identity-management protocol that Azure AD, Okta, JumpCloud, Google all speak natively).

---

## Information Architecture

**Placement type:** `SETTING` — Setting under the app's Beheer/Admin/Configuration surface. Lives in the existing settings UI; no top-level menu entry.

**Lives at:** Configuratie › Integraties

---

## Customer Demands

### 1. Stable API for Enterprise Integration
**Demand Score:** 10/10  
**Persona:** Integration Developer

Azure AD, Okta, and Google need a documented, versioned API to provision users and groups into hrmq. Today this requires custom n8n workflows that break on every hrmq schema change. A stable REST + GraphQL + SCIM surface eliminates the need for screen-scraping and custom sync logic.

**What they need:**
- Documented REST endpoints for employees, org-units, leave-requests
- GraphQL for complex multi-entity queries (employee + manager + org-unit + recent leave in one call)
- SCIM 2.0 protocol support for IdP integration (Azure, Okta, Google, JumpCloud)
- Webhook subscriptions for real-time event notification (employee created, terminated, leave approved)

---

### 2. Secure Credential Management for API Consumers
**Demand Score:** 9/10  
**Persona:** IT Admin, DevOps

Integrations need scoped API keys with permission boundaries (employees:read, leave:write, scim:provision). Keys must be rotatable, revocable, and rate-limited per tier. Webhooks must use HMAC-SHA256 signatures for authenticity verification.

**What they need:**
- API key self-service in Settings UI (create, rotate, revoke)
- Scoped permissions per key (read-only, write, SCIM-specific)
- Rate-limit tiers (free, standard, enterprise)
- Usage dashboard (requests/day, success rate, top consumers)
- Webhook secret management and signature verification

---

### 3. Flexible Data Access for BI Tools
**Demand Score:** 8/10  
**Persona:** BI Engineer, Data Analyst

BI tools (Tableau, Power BI, Looker) need a flexible GraphQL endpoint to fetch complex nested queries (employee with manager info, org hierarchy, recent leave balances) in a single round-trip instead of 10 REST calls.

**What they need:**
- GraphQL endpoint with introspectable schema
- Support for nested queries (employee → manager → department → cost-center)
- Query complexity scoring to prevent DoS
- Rate-limiting per GraphQL tier
- Non-production environment introspection

---

### 4. Real-time Event Notifications
**Demand Score:** 8/10  
**Persona:** Integration Developer, Platform Engineer

Integrations subscribed to HR events (new hire, termination, leave approval, org restructuring) should receive push notifications instead of polling. Event delivery must be reliable with retry logic and audit logging.

**What they need:**
- Webhook subscription management (create, list, test, delete)
- Event types: employee.created, employee.updated, employee.terminated, leave.approved, org.restructured
- Automatic retry with exponential backoff
- Webhook delivery audit log with status per attempt
- Disable after 50 consecutive failures with admin notification

---

## User Stories

### US-001: As an integrator, I want to subscribe to employee lifecycle events so that I can push hrmq changes to n8n or Slack in real-time

**Acceptance Criteria:**
- GIVEN I am logged in as a tenant admin
- WHEN I navigate to Settings > Integraties and click "New Webhook"
- THEN I can enter a webhook URL, select event types (employee.created, employee.updated, etc.), and submit
- AND the system immediately tests the URL with a `webhook.verification` event
- AND I receive a one-time webhook secret to verify HMAC signatures
- AND the secret is never shown again

---

### US-002: As a BI engineer, I want to query employee data hierarchically so that I can fetch employee + manager + department in one GraphQL request

**Acceptance Criteria:**
- GIVEN I am calling `POST /api/v1/graphql` with a valid API key (scope: employees:read)
- WHEN I submit a query requesting employee fields + manager (recursive) + org-unit + recent leave
- THEN the response includes only the requested fields
- AND query depth is capped at 7 levels (prevents runaway recursion)
- AND query complexity is scored and compared against my rate-limit tier (free=50 points, standard=500, enterprise=5000)
- AND query introspection is allowed in non-production environments only

---

### US-003: As an IT admin, I want to see API key usage statistics so that I can identify unused keys or abuse patterns

**Acceptance Criteria:**
- GIVEN I navigate to Settings > Integraties > API Keys
- WHEN I view the key list
- THEN I see: key prefix (hrmq_live_abc...), scopes, created date, last-used timestamp, requests/day chart (last 30 days)
- AND I can click "Rotate" to generate a new secret and mark the old one as expired
- AND I can click "Revoke" to immediately disable the key

---

### US-004: As an integrator, I want to provision users from Azure AD to hrmq via SCIM so that I don't need a custom sync flow

**Acceptance Criteria:**
- GIVEN Azure AD is configured to provision to hrmq's SCIM endpoint
- WHEN Azure AD POSTs a SCIM User payload to `/scim/v2/Users`
- THEN the system creates a corresponding Employee record
- AND SCIM standard attributes (name, email, phone) are mapped to Employee fields
- AND EnterpriseUser extension attributes (manager, department, costCenter) are mapped
- AND SCIM operations (GET, PATCH, PUT, DELETE) follow RFC 7644 semantics
- AND DELETE (deprovision) marks the Employee inactive but does not hard-delete

---

## Customer Journeys

### Journey 1: Azure AD Integration Setup
**Trigger:** Tenant admin wants to provision Azure AD users to hrmq automatically

**Pain Points:**
1. Today requires a custom n8n flow that breaks whenever hrmq schema changes
2. No way to revoke access to a specific integration without rebuilding the flow
3. Deprovisioning (removing a user) is manual or requires screen-scraping

**Happy Path:**
1. Admin navigates to Settings > Integraties > SCIM Configuration
2. Generates an API key scoped to `scim:provision`
3. Copies SCIM endpoint URL and API key
4. Configures Azure AD with endpoint + credentials
5. Azure AD immediately syncs all users to hrmq
6. Future user creates/updates flow automatically to hrmq

---

### Journey 2: Real-time Slack Notification on New Hire
**Trigger:** Integrator wants to post to Slack when new employees are created

**Pain Points:**
1. Polling the REST API every minute is wasteful and slow
2. No way to verify authenticity of webhook calls (easy target for forged events)
3. Webhook delivery failures are silent — Slack notifications never arrive

**Happy Path:**
1. Integrator creates a webhook subscription: URL=slack-webhook, events=[employee.created]
2. hrmq immediately POSTs a verification event to the URL
3. Integrator's Lambda/webhook handler responds with 200
4. When new employee is added, hrmq POSTs signed event to Slack webhook
5. Integrator verifies HMAC signature using stored secret
6. Slack posts notification immediately
7. Integrator checks Settings > Integraties > Webhook Deliveries for delivery audit log

---

### Journey 3: BI Tool Querying Employee Hierarchy
**Trigger:** BI engineer needs to build a dashboard of employees by manager and department

**Pain Points:**
1. REST API requires separate calls for employees, managers (lookup), departments, leave balances
2. 10+ API calls per dashboard row = slow and rate-limited
3. No way to cap query complexity — bad queries can accidentally DoS the API

**Happy Path:**
1. BI engineer calls `POST /api/v1/graphql` with one query: `{ employees { firstName, lastName, manager { firstName }, orgUnit { name }, recentLeave { type, dates } } }`
2. Response includes all requested data in single round-trip
3. GraphQL schema is introspectable so BI tool can auto-discover fields
4. Query complexity is scored; if tier is exceeded, response includes `retry_after` header

---

## Stakeholders

### Integration Developer
**Role:** Builds connectors to hrmq (Azure AD, Okta, Slack, n8n, payroll providers)  
**Goals:** 
- Stable, documented API surface that doesn't break on hrmq updates
- Support for industry-standard protocols (SCIM, GraphQL, webhooks)
- Easy credential management and rotation

**Responsibilities:**
- Implement API consumers using REST, GraphQL, or SCIM
- Handle webhook events and verify signatures
- Monitor API usage and adjust rate-limit tier as needed

---

### IT Admin / DevOps
**Role:** Manages integrations and API keys in production  
**Goals:**
- See who is calling the API and how often
- Rotate credentials on schedule
- Audit webhook delivery success/failure
- Enforce least-privilege scopes on each key

**Responsibilities:**
- Create and rotate API keys
- Monitor webhook delivery logs
- Revoke compromised keys
- Configure rate-limit tiers based on workload

---

### BI Engineer / Data Analyst
**Role:** Builds dashboards and ad-hoc queries against hrmq data  
**Goals:**
- Query complex nested data (employee + manager + org + leave) in one call
- Flexible field selection (GraphQL introspection)
- Predictable rate-limiting so queries don't randomly fail

**Responsibilities:**
- Write optimized GraphQL queries
- Monitor query complexity scores
- Cache results to minimize API calls

---

### Nextcloud Admin
**Role:** Operates hrmq in production  
**Goals:**
- Prevent API abuse and DoS attacks
- Monitor integration health
- Alert on webhook delivery failures

**Responsibilities:**
- Set default rate-limit tiers per customer
- Monitor dashboard for abuse patterns (>1000 429s/day)
- Alert integration owners on webhook disable events

---

## Dependencies

- **openconnector** — auth-protocol-suite provides OAuth2 server + JWT issuance + API-key validation; this spec depends on it
- **openregister** — ApiKey, Webhook, WebhookDelivery schemas live in shared `infra` register (reusable across all Conduction apps)
- **employee-management** — source data for Employee entity
- **org-chart-basic** — source data for OrgUnit entity

---

## Success Metrics

1. **Adoption:** ≥3 integrations live in first 6 months (Azure AD, Okta, Slack)
2. **Uptime:** API SLA ≥99.5% over any 30-day period
3. **Performance:** P95 latency <500ms for REST, <1s for GraphQL queries
4. **Security:** Zero unmitigated webhook signature verification bypasses
5. **Scalability:** Support ≥600 req/min on standard tier without 429s under sustained load
