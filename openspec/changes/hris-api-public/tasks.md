# Tasks: Public HRIS API (REST + GraphQL + Webhooks + SCIM)

**Change:** hris-api-public  
**Status:** draft  

---

## Phase 1: Foundation & Authentication

### Task 1.1: Schema Registration — ApiKey Entity
- [ ] Create OpenRegister schema: `lib/Settings/openregister_infra.json` (or extend existing)
- [ ] Define ApiKey schema with properties: id, tenant_id, name, prefix, hashed_secret, scopes[], created_at, created_by_user_id, last_used_at, expires_at, rate_limit_tier, active, metadata
- [ ] Include validation rules: scopes must be subset of [employees:read, employees:write, leave:read, leave:write, org:read, scim:provision]
- [ ] Document in spec that secrets are one-way hashed on storage (bcrypt or argon2)
- [ ] Add 2 seed ApiKey objects (Azure AD, BI tool) per design.md

### Task 1.2: Schema Registration — Webhook Entity
- [ ] Create Webhook schema in OpenRegister with properties: id, tenant_id, url, secret (hashed), event_types[], active, created_at, created_by_user_id, last_triggered_at, retry_policy (json), metadata
- [ ] Define enum for event_types: employee.created, employee.updated, employee.terminated, leave.approved, org.restructured, webhook.verification
- [ ] Add validation: url must be HTTPS
- [ ] Add 2 seed Webhook objects (Slack, n8n) per design.md

### Task 1.3: Schema Registration — WebhookDelivery Entity
- [ ] Create WebhookDelivery schema in OpenRegister
- [ ] Properties: id, webhook_id (ref), event_id, event_type, event_payload_summary, attempt_number, http_status, response_body_truncated, delivered_at, next_retry_at, created_at
- [ ] Add 2 seed WebhookDelivery objects (one successful, one retrying) per design.md

### Task 1.4: API Key Service Layer
- [ ] Create `ApiKeyService` with methods:
  - `generateKey(tenantId, name, scopes[], rateLimitTier): { secret, apiKey }` — generates prefix, hashes secret, stores ApiKey
  - `validateKey(secret): ApiKey | null` — validates key against hashed storage, returns ApiKey if valid
  - `rotateKey(keyId): { oldKey, newKey }` — generates new secret, marks old as expired
  - `revokeKey(keyId)` — marks key as inactive
  - `restoreKey(keyId)` — reactivates revoked key
  - `getUsageStats(keyId, days=30): { totalRequests, requestsByDay[], errorRate, lastUsed }`
- [ ] Integrate with ObjectService for CRUD persistence
- [ ] Add audit logging for create, rotate, revoke, restore actions

### Task 1.5: Scope Validation Middleware
- [ ] Create `ApiKeyAuthMiddleware` that:
  - Extracts Bearer token from Authorization header
  - Calls ApiKeyService.validateKey() to verify token
  - Checks expiration date (expires_at)
  - Stores `$request->apiKey` and `$request->tenant` for downstream use
  - Returns 401 Unauthorized with appropriate error message if invalid/expired
- [ ] Create `ScopeValidator` class that checks if apiKey.scopes includes required scope for each endpoint
- [ ] Add to API routing as pre-condition for all /api/v1/* and /scim/v2/* endpoints

### Task 1.6: Rate-Limit Middleware & Enforcement
- [ ] Create `RateLimitMiddleware` that:
  - Extracts rate_limit_tier from ApiKey
  - Increments request counter in Redis (key: `ratelimit:{tenant_id}:{api_key_id}:{minute}`)
  - Checks if current minute count exceeds tier limit (free=60, standard=600, enterprise=6000)
  - Returns 429 Too Many Requests if exceeded, with `Retry-After` header
  - Sets response headers: `X-RateLimit-Limit`, `X-RateLimit-Remaining`, `X-RateLimit-Reset`
- [ ] Create alert handler for >1000 429s/day per tenant (log to platform operator, notify tenant admin)
- [ ] Create cache for rate-limit checks (configurable TTL, Redis backend)

### Task 1.7: GraphQL Complexity Scoring
- [ ] Implement GraphQL complexity analyzer that:
  - Recursively scores query cost (configurable weight per field)
  - Caps depth at 7 levels (reject queries with deeper traversal)
  - Compares total score to rate_limit_tier cap (free=50, standard=500, enterprise=5000)
  - Returns 400 Bad Request if depth exceeded, 429 if complexity exceeded
  - Includes `X-GraphQL-Complexity: <score>/<limit>` header in response
- [ ] Create configuration for field weights (e.g., simple fields=1 point, relations=10 points, lists=5 points per item)

---

## Phase 2: REST API Implementation

### Task 2.1: Employee Endpoints (CRUD)
- [ ] Implement `GET /api/v1/employees` — list with pagination, filtering, sorting
  - Support cursor-based pagination: `first=50, after=<cursor>`
  - Support filters: `?filter[status]=active&filter[orgUnit]=<org-id>`
  - Support sorting: `?sort=-createdAt,firstName`
  - Return paginated response: `{ data[], pageInfo, total }`
- [ ] Implement `GET /api/v1/employees/{id}` — get single employee
- [ ] Implement `POST /api/v1/employees` — create employee (scope: employees:write)
  - Validate required fields per Employee schema
  - Return 201 with created object and Location header
  - Audit log creation
- [ ] Implement `PUT /api/v1/employees/{id}` — full replace (scope: employees:write)
  - Validate all required fields
  - Audit log changes
  - Return 200 with updated object
- [ ] Implement `PATCH /api/v1/employees/{id}` — partial update (scope: employees:write)
  - Support JSON Patch (RFC 6902) or JSON Merge Patch (RFC 7386)
  - Audit log changes
  - Return 200 with updated object
- [ ] Implement `DELETE /api/v1/employees/{id}` — delete employee (scope: employees:write)
  - Hard-delete or soft-delete per hrmq policy (recommend soft-delete with status=archived)
  - Audit log deletion
  - Return 204 No Content
- [ ] Add field-level scope filtering: if key scoped to employees:read, hide sensitive fields (salary, SSN, bank account, etc.)

### Task 2.2: Org-Unit Endpoints (Read)
- [ ] Implement `GET /api/v1/org-units` — list with hierarchy support
  - Return tree structure: `{ id, name, parent, children[], members[] }`
  - Support pagination if flattened view
  - Scope: org:read
- [ ] Implement `GET /api/v1/org-units/{id}` — get single org-unit with hierarchy

### Task 2.3: Leave Request Endpoints (Read + Manage)
- [ ] Implement `GET /api/v1/leave-requests` — list with filters (status, employee, date range)
  - Support pagination, filtering, sorting
  - Scope: leave:read
- [ ] Implement `GET /api/v1/leave-requests/{id}` — get single leave request
- [ ] Implement `POST /api/v1/leave-requests` — create leave request (scope: leave:write)
  - Validate date range, employee existence, leave type validity
  - Return 201 with created object
- [ ] Implement `PATCH /api/v1/leave-requests/{id}` — approve/reject (scope: leave:write)
  - Support operations: approve (status=approved), reject (status=rejected, reason required)
  - Audit log approvals
  - Return 200

### Task 2.4: OpenAPI 3.1 Documentation
- [ ] Generate OpenAPI spec programmatically from code annotations (OpenAPI attributes on controller classes/methods)
- [ ] Define schemas for Employee, OrgUnit, LeaveRequest, ApiKey, Webhook, etc.
- [ ] Document all endpoint request/response formats with examples
- [ ] Document rate-limit headers and scope requirements per endpoint
- [ ] Publish at `GET /api/v1/openapi.json` (public, no auth required)
- [ ] Ensure spec is up-to-date with each deployment (validate in CI)

### Task 2.5: Error Handling
- [ ] Standardize error responses: `{ "error": "<message>", "code": "<error-code>", "details": {...} }`
- [ ] Define error codes: API_KEY_INVALID, API_KEY_EXPIRED, SCOPE_REQUIRED, RATE_LIMIT_EXCEEDED, QUERY_TOO_DEEP, VALIDATION_ERROR, RESOURCE_NOT_FOUND, INTERNAL_ERROR
- [ ] Handle 400 (validation), 401 (auth), 403 (scope/permission), 404 (not found), 429 (rate limit), 500 (server error)
- [ ] Never expose stack traces in API responses

### Task 2.6: Response Serialization
- [ ] Implement response serializer that:
  - Converts Employee/OrgUnit/LeaveRequest objects to JSON
  - Applies field-level scope filtering (if scope doesn't allow, field is omitted)
  - Includes pagination metadata (total, page, pages)
  - Includes rate-limit headers
  - Handles null/empty values consistently

---

## Phase 3: GraphQL Implementation

### Task 3.1: GraphQL Schema Definition
- [ ] Define GraphQL schema:
  - Types: Employee, OrgUnit, LeaveRequest, EmployeeConnection, PageInfo, etc.
  - Queries: employees(first, after, filter), employee(id), orgUnits, leaveRequests
  - Relationships: Employee.manager, Employee.orgUnit, OrgUnit.children, etc.
- [ ] Add scalar types: Date, DateTime, JSON
- [ ] Document complexity weights for each field in schema

### Task 3.2: GraphQL Query Resolver
- [ ] Implement query resolver that:
  - Accepts GraphQL query from POST /api/v1/graphql
  - Validates query syntax
  - Applies complexity scorer (Task 1.7)
  - Executes query against database (using existing services)
  - Applies field-level scope filtering
  - Returns JSON response with requested fields only
- [ ] Implement depth limiting (max 7 levels of nesting)
- [ ] Add execution timeout (e.g., 30 seconds max per query)

### Task 3.3: GraphQL Introspection
- [ ] Implement introspection endpoint that:
  - Returns full schema definition in production (disable introspection in prod per REQ-002)
  - Allows introspection in staging/dev environments
  - Returns 400 if introspection queried in production
- [ ] Add schema caching for performance

### Task 3.4: GraphQL Error Handling
- [ ] Standardize GraphQL error responses per spec
- [ ] Include error type in response: QUERY_TOO_DEEP, COMPLEXITY_EXCEEDED, AUTHENTICATION_ERROR, etc.
- [ ] Add execution path in errors for debugging

---

## Phase 4: Webhook System

### Task 4.1: Webhook Subscription Service
- [ ] Create `WebhookService` with methods:
  - `createWebhook(tenantId, url, eventTypes[]): Webhook` — creates subscription and initiates verification
  - `verifyWebhook(webhookId, challenge): boolean` — waits for webhook URL to respond with challenge within 10 seconds, activates if successful
  - `updateWebhook(webhookId, eventTypes[]): Webhook` — update subscribed events
  - `deleteWebhook(webhookId)` — delete subscription
  - `listWebhooks(tenantId): Webhook[]` — list all subscriptions (secrets hidden)
  - `testWebhook(webhookId, eventType?): void` — immediately POST sample event
- [ ] Integrate with ObjectService for persistence

### Task 4.2: Webhook Verification Protocol
- [ ] Implement verification flow:
  - On webhook creation, POST verification event: `{ type: "webhook.verification", challenge: "<token>", timestamp: "<iso8601>" }`
  - Wait up to 10 seconds for response
  - Consumer must return 200 with response body containing challenge
  - If successful, set webhook.active=true
  - If timeout or non-200, set webhook.active=false and return error
- [ ] Support retries if first attempt fails (e.g., URL temporarily down)

### Task 4.3: Event Dispatcher
- [ ] Create `EventDispatcher` service that:
  - Listens for domain events: employee.created, employee.updated, employee.terminated, leave.approved, org.restructured
  - For each event, looks up subscribed Webhooks
  - Queues webhook delivery jobs (async, non-blocking)
  - Triggers retry logic if delivery fails
- [ ] Integrate with existing notification/event system in hrmq
- [ ] Document event payloads per event type (e.g., employee.created includes full Employee object)

### Task 4.4: Webhook Delivery Worker
- [ ] Create background worker that:
  - Fetches queued webhook delivery jobs
  - Computes HMAC-SHA256 signature using webhook secret
  - POSTs to webhook URL with headers: X-Hrmq-Signature, X-Hrmq-Event-Type, X-Hrmq-Event-Id, X-Hrmq-Timestamp
  - Records response status in WebhookDelivery audit log
  - On failure (non-2xx), schedules retry with exponential backoff (1s, 2s, 4s, 8s, 16s = 5 retries)
  - After 5 failed attempts, disable webhook with reason and notify tenant admin
- [ ] Implement concurrent delivery (multiple webhooks in parallel)
- [ ] Add monitoring/alerting for delivery failures

### Task 4.5: Webhook Management UI
- [ ] Create Settings page: Settings > Configuratie > Integraties > Webhooks
- [ ] List view:
  - Name, URL, event types, last triggered, status (active/disabled), actions (view, test, edit, delete)
- [ ] Create form:
  - URL field (HTTPS validation)
  - Checkboxes for event types
  - Submit → triggers verification
  - Show secret once, then hide it
  - Copy-to-clipboard for secret
- [ ] Detail view:
  - Webhook info, verification status
  - Last triggered timestamp, error message if disabled
  - Delivery log (audit trail): list of all delivery attempts with status, timestamp, retry count
  - "Test Webhook" button to manually trigger sample event
- [ ] Delivery audit log:
  - Table: Event Type, Event ID, Attempt #, Status, Response, Timestamp, Next Retry
  - Link to full event payload (expandable)
  - "Retry" button per failed delivery

### Task 4.6: Deduplication Check
- [ ] Search codebase for existing webhook implementations:
  - openconnector.WebhookService (if exists)
  - openregister.WebhookDeliveryService (if exists)
  - Any custom webhook code in hrmq
- [ ] Document findings: are we reusing or building new?
- [ ] If reusing, extend existing service; if building new, document why

---

## Phase 5: SCIM 2.0 Implementation

### Task 5.1: SCIM User Endpoint
- [ ] Implement `POST /scim/v2/Users` — create user from SCIM payload
  - Map SCIM User + EnterpriseUser extension to Employee (see design.md mapping)
  - Validate required attributes (userName, name.givenName, name.familyName)
  - Scope: scim:provision
  - Return 201 with SCIM User response including id, meta.location
  - Audit log creation
- [ ] Implement `GET /scim/v2/Users` — list users in SCIM format
  - Support pagination: startIndex, count, totalResults (SCIM standard, not cursor-based)
  - Return SCIM ListResponse with User resources
  - Scope: scim:provision
- [ ] Implement `GET /scim/v2/Users/{id}` — get user in SCIM format
  - Scope: scim:provision
  - Return SCIM User with all attributes
- [ ] Implement `PUT /scim/v2/Users/{id}` — full replace (SCIM semantics)
  - Replace entire Employee with SCIM payload
  - Omitted fields are cleared (except meta)
  - Return 200 with updated SCIM User
  - Scope: scim:provision
  - Audit log changes
- [ ] Implement `PATCH /scim/v2/Users/{id}` — incremental update (RFC 6902 JSON Patch)
  - Support op: "add", "replace", "remove"
  - Validate patch operations
  - Apply to Employee
  - Return 200 with updated User
  - Scope: scim:provision
  - Audit log changes
- [ ] Implement `DELETE /scim/v2/Users/{id}` — deprovision
  - Mark Employee as inactive (do NOT hard-delete)
  - Return 204 No Content
  - Scope: scim:provision
  - Audit log deprovision action

### Task 5.2: SCIM Group Endpoint
- [ ] Implement `POST /scim/v2/Groups` — create group from SCIM payload
  - Map SCIM Group to OrgUnit
  - Extract members[] array and create OrgAssignments
  - Scope: scim:provision
  - Return 201 with SCIM Group response including id
  - Audit log creation
- [ ] Implement `GET /scim/v2/Groups` — list groups in SCIM format
  - Pagination: startIndex, count (SCIM standard)
  - Return SCIM ListResponse with Group resources
  - Scope: scim:provision
- [ ] Implement `GET /scim/v2/Groups/{id}` — get group detail
  - Return SCIM Group with members[]
  - Scope: scim:provision
- [ ] Implement `PUT /scim/v2/Groups/{id}` — full replace
  - Replace OrgUnit and update OrgAssignments
  - Return 200 with updated Group
  - Scope: scim:provision
  - Audit log changes
- [ ] Implement `PATCH /scim/v2/Groups/{id}` — incremental update
  - Support member add/remove operations
  - Update OrgAssignments accordingly
  - Return 200
  - Scope: scim:provision
  - Audit log changes
- [ ] Implement `DELETE /scim/v2/Groups/{id}` — delete group
  - Mark OrgUnit as inactive, close OrgAssignments
  - Return 204
  - Scope: scim:provision

### Task 5.3: SCIM Schema Endpoints
- [ ] Implement `GET /scim/v2/.well-known/scim-configuration`
  - Return SCIM provider metadata per RFC 7644 section 4
  - Document User and Group resource types, endpoints, supported schemas
  - Document auth methods (Bearer token)
- [ ] Implement `GET /scim/v2/Schemas`
  - Return list of supported SCIM schemas (User, Group, EnterpriseUser)
- [ ] Implement `GET /scim/v2/Schemas/{id}`
  - Return specific schema definition (SCIM-standard format)

### Task 5.4: SCIM Attribute Mapping
- [ ] Create mapping layer Employee ↔ SCIM User:
  - Bidirectional conversion (hrmq object ↔ SCIM JSON)
  - Handle EnterpriseUser extension attributes (manager, department, employeeNumber, costCenter, division)
  - Handle optional attributes (phone, address, etc.)
  - Handle custom extensions (future-proofing for tenant-specific attributes)
- [ ] Create mapping layer OrgUnit ↔ SCIM Group:
  - Map displayName ↔ OrgUnit.name
  - Map members[] ↔ OrgAssignments
- [ ] Add validation: required attributes per SCIM spec vs. hrmq business rules
- [ ] Add error handling: return SCIM-standard error response if mapping fails

### Task 5.5: SCIM Error Responses
- [ ] Implement SCIM-standard error responses (RFC 7644 section 3.12):
  - Format: `{ "schemas": ["urn:ietf:params:scim:api:messages:2.0:Error"], "status": "...", "detail": "..." }`
  - Status codes: 400 (invalid request), 401 (unauthorized), 403 (forbidden), 404 (not found), 409 (conflict), 500 (error)
  - Error examples: INVALID_SYNTAX, NO_TARGET, MUTABILITY, INVALID_VALUE, RESOURCE_NOT_FOUND, ALREADY_EXISTS, INVALID_FILTER, TOO_MANY, INTERNAL_SERVER_ERROR

---

## Phase 6: API Key Management UI

### Task 6.1: API Key List View
- [ ] Create Settings page: Settings > Configuratie > Integraties > API-sleutels
- [ ] List table with columns:
  - Name, Scopes (comma-separated), Created, Last Used, Rate Limit Tier, Status (Active/Revoked), Actions
- [ ] Actions: View Detail, Rotate, Revoke, Delete
- [ ] Filter by status (active/revoked)
- [ ] Search by name

### Task 6.2: API Key Creation Dialog
- [ ] Form fields:
  - Name (required, text input)
  - Scopes (required, checkboxes: employees:read, employees:write, leave:read, leave:write, org:read, scim:provision)
  - Expiration Date (optional, date picker)
  - Rate Limit Tier (required, radio: free/standard/enterprise)
  - Description (optional, textarea)
- [ ] On submit:
  - Call ApiKeyService.generateKey()
  - Display secret once: "Your secret: `hrmq_live_abc123xyz...` (save this now, it won't be shown again)"
  - Add copy-to-clipboard button
  - Close dialog on user click

### Task 6.3: API Key Detail View
- [ ] Display:
  - Name, scopes, created date, created by user
  - Last-used timestamp
  - Rate-limit tier
  - Status (active/revoked)
- [ ] Usage chart:
  - Line chart: requests/day over last 30 days
  - Y-axis: request count, X-axis: date
  - Fetch from ApiKeyService.getUsageStats()
- [ ] Error rate panel:
  - % of failed requests (4xx/5xx) over last 7 days
  - Breakdown by status code (401, 403, 429, 500, etc.)
- [ ] Actions:
  - Edit Name, Rate Limit Tier, Description
  - Rotate Secret → confirm dialog, display new secret once
  - Revoke → confirm, disable key, show confirmation
  - Restore → if revoked, allow reactivation
  - Delete → confirm, hard-delete

### Task 6.4: Usage Stats Endpoint
- [ ] Implement `GET /api/v1/settings/api-keys/{id}/stats` (admin only)
  - Return usage metrics: total requests, requests by day (30-day window), error rate by status
  - Cache results (e.g., 1-hour TTL) to avoid expensive aggregations
  - Return JSON per design.md REQ-008 schema

### Task 6.5: Webhook List & Management (UI)
- [ ] Create Settings page: Settings > Configuratie > Integraties > Webhooks
- [ ] (Details in Task 4.5 above)

### Task 6.6: Audit Logging
- [ ] Log all API key operations: create, update, rotate, revoke, delete (include user, timestamp, before/after state)
- [ ] Log all webhook operations: create, update, delete, test (include user, timestamp)
- [ ] Audit logs accessible via Settings > Audit Trail (existing feature, ensure hris-api-public logs are included)

---

## Phase 7: Testing & Validation

### Task 7.1: Unit Tests
- [ ] ApiKeyService unit tests (generate, validate, rotate, revoke, stats)
- [ ] WebhookService unit tests (create, verify, update, delete)
- [ ] RateLimiter unit tests (increment, check, reset)
- [ ] GraphQL complexity scorer unit tests
- [ ] SCIM mapping unit tests (Employee ↔ SCIM User, OrgUnit ↔ Group)
- [ ] Error response formatting unit tests

### Task 7.2: Integration Tests
- [ ] REST endpoint integration tests:
  - GET /api/v1/employees (list, pagination, filtering, sorting)
  - POST /api/v1/employees (create with validation)
  - PATCH /api/v1/employees/{id} (partial update)
  - Scope enforcement (employees:read vs. employees:write)
  - Rate limiting (60/min for free, 600/min for standard)
- [ ] GraphQL endpoint integration tests:
  - Query depth limiting (reject depth > 7)
  - Query complexity scoring
  - Field filtering by scope
  - Error handling
- [ ] SCIM endpoint integration tests:
  - POST /scim/v2/Users (create, map attributes, return SCIM format)
  - GET /scim/v2/Users/{id}
  - PATCH /scim/v2/Users/{id} (RFC 6902)
  - DELETE /scim/v2/Users/{id} (deprovision, not hard-delete)
  - POST /scim/v2/Groups (create group, map members)
- [ ] Webhook integration tests:
  - Create webhook → verification flow
  - Event dispatch → delivery
  - Retry logic (exponential backoff)
  - HMAC signature verification
  - Disable on 50+ failures
- [ ] API key lifecycle tests (create, rotate, revoke, restore, delete)

### Task 7.3: Security Tests
- [ ] Rate-limit bypass attempts (manipulating headers, rotating keys)
- [ ] Scope enforcement (try employees:write with employees:read-only key)
- [ ] SCIM deprovision (verify hard-delete is NOT performed)
- [ ] Webhook signature verification (forge event with wrong secret, verify rejection)
- [ ] Authorization bypass (try accessing other tenant's data)
- [ ] SQL injection / NoSQL injection via filter/sort parameters
- [ ] XSS in API responses (e.g., if employee name contains `<script>`)
- [ ] CSRF on settings pages (API key creation, webhook subscription)

### Task 7.4: Performance Tests
- [ ] GraphQL query performance (P95 latency < 1s for typical nested query)
- [ ] REST list endpoint performance (P95 latency < 500ms)
- [ ] Rate limiter performance (Redis, sub-millisecond checks)
- [ ] Webhook delivery throughput (>1000 deliveries/sec without queue backlog)
- [ ] Load test with 600 req/min sustained (standard tier capacity)

### Task 7.5: Documentation
- [ ] OpenAPI spec generation and validation (Task 2.4)
- [ ] Webhook developer guide (event types, retry logic, signature verification)
- [ ] SCIM integration guide (Azure AD, Okta, Google configuration steps)
- [ ] API key management guide (create, rotate, scope permissions)
- [ ] GraphQL introspection documentation (schema, query examples)
- [ ] Error code reference (all possible 4xx/5xx responses)

### Task 7.6: Deduplication Check
- [ ] Search openregister and openconnector for existing webhook/API-key implementations
- [ ] Search hrmq codebase for any prior REST API or SCIM endpoints
- [ ] Document findings: what are we reusing vs. building new
- [ ] Update design.md Reuse Analysis section with final findings

---

## Phase 8: Deployment & Operations

### Task 8.1: Database Migrations
- [ ] Create migration to add ApiKey table (if not in OpenRegister)
- [ ] Create migration to add Webhook table
- [ ] Create migration to add WebhookDelivery table
- [ ] Add indexes on frequently-queried columns (tenant_id, active, event_type)
- [ ] Test migrations: fresh install, upgrade from prior version

### Task 8.2: Configuration
- [ ] Environment variables:
  - `HRIS_API_RATE_LIMIT_FREE` (default: 60)
  - `HRIS_API_RATE_LIMIT_STANDARD` (default: 600)
  - `HRIS_API_RATE_LIMIT_ENTERPRISE` (default: 6000)
  - `HRIS_API_RATE_LIMIT_ALERT_THRESHOLD` (default: 1000 429s/day)
  - `HRIS_GRAPHQL_MAX_DEPTH` (default: 7)
  - `HRIS_GRAPHQL_COMPLEXITY_FREE` (default: 50)
  - `HRIS_GRAPHQL_COMPLEXITY_STANDARD` (default: 500)
  - `HRIS_GRAPHQL_COMPLEXITY_ENTERPRISE` (default: 5000)
  - `HRIS_WEBHOOK_MAX_RETRIES` (default: 5)
  - `HRIS_WEBHOOK_DISABLE_THRESHOLD` (default: 50 consecutive failures)
- [ ] Settings UI for tenant admins to override defaults per tenant
- [ ] Document configuration in admin guide

### Task 8.3: Monitoring & Alerting
- [ ] Metrics to export (Prometheus):
  - `hris_api_requests_total` (counter by endpoint, status, tier)
  - `hris_api_latency_seconds` (histogram by endpoint)
  - `hris_api_rate_limit_exceeded` (counter)
  - `hris_webhook_deliveries_total` (counter by event_type, status)
  - `hris_webhook_delivery_latency_seconds` (histogram)
  - `hris_webhook_disabled_total` (counter)
- [ ] Alerts:
  - P99 latency > 2 seconds for REST endpoints
  - P99 latency > 5 seconds for GraphQL
  - Error rate (5xx) > 1%
  - >1000 429s/day per tenant
  - Webhook delivery success rate < 95% (per webhook)
- [ ] Dashboards in Grafana (if available):
  - API requests by endpoint, tier, status
  - Rate-limit pressure (% of requests throttled)
  - GraphQL complexity distribution
  - Webhook delivery success rate

### Task 8.4: Backups & Disaster Recovery
- [ ] Document backup policy for ApiKey, Webhook, WebhookDelivery tables
- [ ] Ensure API key secrets are never included in backups (only hashes)
- [ ] Test recovery: restore backup, verify keys still work
- [ ] Document webhook secret rotation in case of compromise

### Task 8.5: Compliance & Security
- [ ] GDPR compliance:
  - Webhook delivery audit log retention policy (how long are logs kept?)
  - SCIM deprovision implementation (mark inactive, not hard-delete)
  - Data subject access requests (support export of webhook delivery logs for user)
- [ ] Penetration testing checklist (covered in Task 7.3)
- [ ] Security review of webhook signature verification (HMAC-SHA256 strength)
- [ ] Review rate-limiter implementation for race conditions

### Task 8.6: Seed Data
- [ ] Create seed data file: `lib/Settings/openregister_infra.json` with:
  - 2 ApiKey objects (Azure AD, BI tool) per design.md
  - 2 Webhook objects (Slack, n8n) per design.md
  - 2 WebhookDelivery objects (one successful, one retrying) per design.md
- [ ] Load seed data on fresh install (via ConfigurationService.importFromApp())
- [ ] Test: fresh install includes seed objects, can query via API
- [ ] Document: seed data is optional, admins can delete or replace

---

## Phase 9: Handoff & Support

### Task 9.1: Integration Testing with Real IdPs
- [ ] Test Azure AD SCIM provisioning:
  - Configure Azure AD with hrmq SCIM endpoint + API key
  - Provision test user → verify Employee created in hrmq
  - Update user in Azure → verify Employee updated
  - Deprovision user → verify Employee marked inactive
- [ ] Test Okta SCIM provisioning (similar steps)
- [ ] Test Google Workspace SCIM (if applicable)
- [ ] Document integration steps per IdP

### Task 9.2: Integration Testing with n8n & Slack
- [ ] Configure webhook subscription to employee.created, employee.terminated
- [ ] Create n8n workflow subscribed to webhook
- [ ] Create Slack bot subscribed to webhook
- [ ] Test: create new employee → n8n triggered, Slack notification posted
- [ ] Verify HMAC signature validation in n8n/Slack handlers
- [ ] Document webhook integration guide

### Task 9.3: End-to-End Testing
- [ ] Scenario 1: Create API key → call REST endpoint → verify rate limiting
- [ ] Scenario 2: Query GraphQL with nested data → verify complexity scoring
- [ ] Scenario 3: Subscribe to webhook → create employee → webhook delivered with signature
- [ ] Scenario 4: Setup Azure AD SCIM → sync users → verify in hrmq
- [ ] Scenario 5: API key rotation → old key rejected, new key accepted

### Task 9.4: Release Preparation
- [ ] Version number: increment per semver (e.g., 1.0.0 for first release)
- [ ] CHANGELOG: document all new features, breaking changes, bug fixes
- [ ] Migration scripts: ensure clean upgrade from prior version
- [ ] Release notes for customers: overview of new features, integration guides, security notes
- [ ] Blog post (if applicable): "Introducing Public HRIS API"

### Task 9.5: Rollout Plan
- [ ] Phased rollout (if multi-customer environment):
  - Week 1: Internal testing (Conduction team)
  - Week 2: Beta with 3-5 early adopter customers
  - Week 3: Public release to all customers
- [ ] Monitor for issues during rollout (dashboards, alerts)
- [ ] Support plan: escalation contacts, SLA for issues
- [ ] Rollback plan: if critical issue, how to revert

---

## Blockers & Dependencies

### Deduplication Check
- [ ] Search for existing implementations before starting (Task 7.6)
- [ ] If openconnector.WebhookService exists, integrate instead of building new
- [ ] If openregister has ApiKey schema, extend instead of creating new

### Required Dependencies
- openconnector (auth-protocol-suite): OAuth2, JWT, API-key validation primitives
- openregister: schema storage, object CRUD, migrations
- employee-management: Employee entity and data
- org-chart-basic: OrgUnit entity and data

### Optional but Recommended
- mydash: for API usage dashboard widgets
- Grafana: for monitoring/alerting dashboards

---

## Success Criteria

1. **Functionality**: All 12 REQ-* requirements implemented and tested
2. **Security**: Rate limiting, scope enforcement, webhook signature verification all working
3. **Performance**: P95 latency <500ms REST, <1s GraphQL, rate limiter sub-ms
4. **Adoption**: ≥3 integrations in first 6 months (Azure AD, Okta, Slack)
5. **Uptime**: API SLA ≥99.5% over any 30-day period
6. **Documentation**: OpenAPI spec, SCIM guide, webhook guide, integration guides all published

