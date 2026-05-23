# Specifications: Public HRIS API (REST + GraphQL + Webhooks + SCIM)

**Change:** hris-api-public  
**Status:** draft  

---

## REQ-001: REST API — versioned, documented

**GIVEN** an external system  
**WHEN** it calls `GET /api/v1/employees` with a valid `Authorization: Bearer <api-key>` header  
**THEN** it receives:
- A paginated JSON list in cursor-based pagination format (default 50 rows, max 250)
- Response includes `data[]`, `pageInfo` (hasNextPage, endCursor), `total` count
- Fields are filtered by the API key's scopes (e.g., if key scoped to employees:read, sensitive fields hidden)
- Rate-limit headers: `X-RateLimit-Limit`, `X-RateLimit-Remaining`, `X-RateLimit-Reset` (Unix timestamp)
- Response conforms to OpenAPI 3.1 spec published at `/api/v1/openapi.json`

**Additional scenarios:**

**AND** the API key has `employees:write` scope  
**WHEN** it calls `POST /api/v1/employees` with a valid Employee payload  
**THEN** a new Employee is created with HTTP 201 response including the created object with generated `id`

**AND** the API key has expired (`expires_at` < now)  
**WHEN** it includes the expired key in the Authorization header  
**THEN** the API returns HTTP 401 Unauthorized with error message "API key expired"

**AND** the request includes an invalid API key  
**WHEN** it calls any `/api/v1/*` endpoint  
**THEN** the API returns HTTP 401 Unauthorized with error message "Invalid or missing API key"

**AND** the API key's rate-limit tier is `free` (60 req/min)  
**WHEN** it makes 61 requests within 60 seconds  
**THEN** the 61st request returns HTTP 429 Too Many Requests with header `Retry-After: 60`

**AND** the API key has only `employees:read` scope  
**WHEN** it calls `DELETE /api/v1/employees/{id}`  
**THEN** the API returns HTTP 403 Forbidden with error message "Missing scope: employees:write"

---

## REQ-002: GraphQL endpoint for flexible queries

**GIVEN** a BI tool needing nested data (employee + manager + org-unit + recent leave-requests in one round-trip)  
**WHEN** it POSTs a GraphQL query to `/api/v1/graphql` with a valid Bearer token:
```graphql
query {
  employees(first: 10) {
    edges {
      node {
        id
        firstName
        lastName
        manager { firstName lastName }
        orgUnit { name }
        recentLeave(limit: 5) { type dates status }
      }
    }
    pageInfo { hasNextPage endCursor }
    total
  }
}
```
**THEN** the response:
- Includes only the requested fields (no extraneous data returned)
- Returns all nested objects in a single JSON response
- P95 latency < 1 second for queries with ≤100 employees
- Includes `X-RateLimit-*` headers same as REST endpoints

**AND** the query requests a depth of 8 levels (deeper than cap of 7)  
**WHEN** the query traverses employee → manager → manager → manager... (8 deep)  
**THEN** the API returns HTTP 400 Bad Request with error "Query depth exceeds maximum of 7"

**AND** the query has a complexity score of 500 points (tier allows 500 for standard tier)  
**WHEN** the query is submitted with a `standard` tier API key  
**THEN** the response includes a header `X-GraphQL-Complexity: 500/500` and is processed successfully

**AND** the query has a complexity score of 501 points  
**WHEN** the query is submitted with a `standard` tier API key (max 500)  
**THEN** the API returns HTTP 429 Too Many Requests with error "Query complexity 501 exceeds tier limit 500"

**AND** the environment is production  
**WHEN** the request includes `{ __schema { ... } }` (introspection query)  
**THEN** the API returns HTTP 400 Bad Request with error "Introspection queries not allowed in production"

**AND** the environment is non-production (staging/dev)  
**WHEN** the request includes a valid introspection query  
**THEN** the API returns the full GraphQL schema definition

---

## REQ-003: Webhook subscription

**GIVEN** an integrator  
**WHEN** they POST `/api/v1/webhooks` with valid JSON body:
```json
{
  "url": "https://integrator.example.com/webhook",
  "event_types": ["employee.created", "employee.updated"]
}
```
**THEN**:
- A Webhook record is created with HTTP 201 response
- A one-time webhook secret is generated and returned in the response (e.g., `secret_xyz...`)
- The secret is hashed on storage and never retrievable again (subsequent GETs return secret hidden)
- The system immediately POSTs a `webhook.verification` event to the URL:
```json
{
  "type": "webhook.verification",
  "challenge": "verification-token-123",
  "timestamp": "2026-05-23T10:00:00Z"
}
```
- The consumer MUST respond with HTTP 200 and body containing the same `challenge` value within 10 seconds
- If verification fails (timeout or non-200 response), the Webhook is created with `active: false` and an error returned

**AND** the integrator responds to the verification event  
**WHEN** the system receives a 200 response with correct challenge within 10 seconds  
**THEN** the Webhook is automatically set to `active: true`

**AND** the webhook URL uses HTTP (not HTTPS)  
**WHEN** the request is submitted  
**THEN** the API returns HTTP 400 Bad Request with error "Webhook URL must use HTTPS"

**AND** the integrator cannot respond to the verification challenge  
**WHEN** 10 seconds elapse without a valid 200 response  
**THEN** the Webhook is created with `active: false` and in the response `verified: false` is returned

---

## REQ-004: Webhook delivery + HMAC signature

**GIVEN** an event matching a Webhook subscription (e.g., employee.created)  
**WHEN** the event fires in hrmq  
**THEN** the system:
1. Constructs the event payload as JSON
2. Computes HMAC-SHA256 signature using the webhook secret: `sha256=<hex(HMAC-SHA256(secret, body))>`
3. POSTs to the webhook URL with headers:
   - `Content-Type: application/json`
   - `X-Hrmq-Signature: sha256=<hmac>`
   - `X-Hrmq-Event-Type: employee.created`
   - `X-Hrmq-Event-Id: <uuid>`
   - `X-Hrmq-Timestamp: <iso8601>`
4. Returns immediately after POST (non-blocking)

**AND** the webhook URL returns HTTP 200  
**WHEN** the delivery attempt is made  
**THEN** a WebhookDelivery record is created with `http_status: 200`, `delivered_at: <timestamp>`, `next_retry_at: null`

**AND** the webhook URL returns HTTP 500  
**WHEN** the delivery attempt is made  
**THEN**:
- A WebhookDelivery record is created with `http_status: 500`, `delivered_at: null`
- A retry is scheduled for 2 seconds in the future (exponential backoff: 2^(attempt-1) * 1000ms)
- On attempt 2: retry at 2 seconds, on attempt 3: retry at 4 seconds, etc., up to 5 retries total
- `next_retry_at` is set to the scheduled retry time

**AND** all 5 retry attempts fail  
**WHEN** the 5th attempt is made and returns non-2xx  
**THEN**:
- A WebhookDelivery record is created with the final attempt's http_status
- The Webhook is set to `active: false` and `metadata.disable_reason: "50 consecutive failures"`
- A notification is sent to the tenant admin: "Webhook `<name>` has been disabled due to delivery failures"

**AND** webhook has been disabled but then re-enabled by admin  
**WHEN** the admin POSTs `/api/v1/webhooks/{id}` with `active: true`  
**THEN** the webhook is reactivated and next event will be attempted

**AND** the integrator wants to verify the webhook signature  
**WHEN** they receive the POST with header `X-Hrmq-Signature: sha256=<hmac>`  
**THEN** they can compute their own HMAC using the stored secret and verify it matches the header value (protects against forged events)

---

## REQ-005: SCIM 2.0 endpoint for identity provisioning

**GIVEN** an IdP (Azure AD, Okta, Google) configured to provision users to hrmq via SCIM  
**WHEN** the IdP POSTs `/scim/v2/Users` with a SCIM-formatted user payload:
```json
{
  "schemas": ["urn:ietf:params:scim:schemas:core:2.0:User", "urn:ietf:params:scim:schemas:extension:enterprise:2.0:User"],
  "userName": "jan.janssen@gemeente.nl",
  "name": {
    "givenName": "Jan",
    "familyName": "Janssen"
  },
  "emails": [
    {
      "value": "jan.janssen@gemeente.nl",
      "type": "work",
      "primary": true
    }
  ],
  "active": true,
  "urn:ietf:params:scim:schemas:extension:enterprise:2.0:User": {
    "department": "HR",
    "employeeNumber": "EMP001",
    "costCenter": "CC-001"
  }
}
```
**THEN**:
- A new Employee record is created
- Attributes are mapped per SCIM standard + EnterpriseUser extension (see design.md mapping table)
- The system returns HTTP 201 Created with SCIM response including:
```json
{
  "id": "<generated-uuid>",
  "userName": "jan.janssen@gemeente.nl",
  "name": { "givenName": "Jan", "familyName": "Janssen" },
  "emails": [ { "value": "jan.janssen@gemeente.nl", "type": "work", "primary": true } ],
  "active": true,
  "meta": {
    "resourceType": "User",
    "created": "2026-05-23T10:00:00Z",
    "lastModified": "2026-05-23T10:00:00Z",
    "location": "https://hrmq.example.com/scim/v2/Users/<id>"
  }
}
```

**AND** a SCIM client calls `GET /scim/v2/Users/{id}` with valid API key  
**WHEN** the request is made  
**THEN** the Employee is returned in SCIM User format (read-only fields like meta, read-write fields updatable)

**AND** a SCIM client calls `PATCH /scim/v2/Users/{id}` with JSON Patch operations (RFC 6902):
```json
[
  { "op": "replace", "path": "/name/givenName", "value": "Johannes" },
  { "op": "add", "path": "/phoneNumbers", "value": { "value": "+31612345678", "type": "work" } }
]
```
**THEN**:
- The Employee is updated with the patch operations
- HTTP 200 is returned with the updated SCIM User representation
- Other fields remain unchanged

**AND** a SCIM client calls `PUT /scim/v2/Users/{id}` with full User object  
**WHEN** the request is made  
**THEN**:
- The entire Employee record is replaced (full PUT semantics)
- Any fields not in the PUT payload are cleared (except id, meta)
- HTTP 200 is returned with the full updated User

**AND** a SCIM client calls `DELETE /scim/v2/Users/{id}`  
**WHEN** the request is made  
**THEN**:
- The Employee record is NOT hard-deleted
- Instead, the Employee is marked as inactive (`status: inactive`)
- The deprovision action is logged for audit purposes
- HTTP 204 No Content is returned

---

## REQ-006: SCIM Groups for org-units

**GIVEN** an IdP managing org-units as groups  
**WHEN** the IdP POSTs `/scim/v2/Groups` with a SCIM Group payload:
```json
{
  "schemas": ["urn:ietf:params:scim:schemas:core:2.0:Group"],
  "displayName": "Afdeling HR",
  "members": [
    { "value": "<employee-id-1>", "display": "Jan Janssen" },
    { "value": "<employee-id-2>", "display": "Marie Poppins" }
  ]
}
```
**THEN**:
- A new OrgUnit record is created with name "Afdeling HR"
- For each member in the array, an OrgAssignment record is created linking the Employee to the OrgUnit
- HTTP 201 Created is returned with SCIM Group response including `id`

**AND** the IdP updates a Group (PATCH) to add a member  
**WHEN** the PATCH operation adds a new employee id to the members array  
**THEN** a new OrgAssignment is created for that employee
- Existing OrgAssignments remain unchanged
- HTTP 200 is returned

**AND** the IdP updates a Group (PATCH) to remove a member  
**WHEN** the PATCH operation removes an employee id from members  
**THEN**:
- The OrgAssignment for that employee is closed (set `valid_until` to today)
- The employee is no longer a member of the OrgUnit
- HTTP 200 is returned

**AND** the IdP deletes a SCIM Group  
**WHEN** DELETE `/scim/v2/Groups/{id}` is called  
**THEN**:
- The OrgUnit is marked as inactive (or deleted, depending on hrmq policy)
- All OrgAssignments for that OrgUnit are closed
- HTTP 204 No Content is returned

---

## REQ-007: API-key management UI

**GIVEN** a tenant admin  
**WHEN** they navigate to Settings > Configuratie > Integraties > API-sleutels  
**THEN** they see a list view with columns:
- Key Name (e.g., "Azure AD Provisioning")
- Scopes (e.g., "employees:read, employees:write, scim:provision")
- Created (date/time)
- Last Used (date/time, or "Never")
- Rate Limit Tier (free / standard / enterprise)
- Status (Active / Revoked)
- Actions (View, Rotate, Revoke, Delete)

**AND** the admin clicks "New API Key"  
**WHEN** a dialog opens  
**THEN** they can:
1. Enter a name (e.g., "Azure AD Provisioning")
2. Select scopes via checkboxes (employees:read, employees:write, leave:read, leave:write, org:read, scim:provision)
3. Set expiration date (optional)
4. Select rate-limit tier (free/standard/enterprise)
5. Click "Create"
- Response shows the secret exactly once: "Your secret: `hrmq_live_abc123xyz...` (save this now, it won't be shown again)"
- Secret is not shown again in list or detail views

**AND** the admin views an existing API key detail  
**WHEN** they click on the key name in the list  
**THEN** they see:
- Name, scopes, created date, created by user
- Last-used timestamp
- Rate-limit tier
- Status (active/revoked)
- Usage chart: requests/day over last 30 days (line chart)
- Error rate % over last 7 days
- Actions: Edit, Rotate, Revoke, Delete

**AND** the admin clicks "Rotate"  
**WHEN** a confirmation dialog appears  
**THEN**:
- A new secret is generated
- The new secret is displayed once (same as creation)
- The old secret is marked as expired (can no longer be used)
- Both old and new keys are shown in the audit log

**AND** the admin clicks "Revoke"  
**WHEN** confirmed  
**THEN**:
- The key is immediately disabled (status: revoked)
- All in-flight requests using this key fail with 401
- The key can be re-enabled via "Restore" action, or deleted

---

## REQ-008: Rate-limiting + quota enforcement

**GIVEN** any API consumer (key or OAuth client)  
**WHEN** they exceed their rate-limit-tier:
- `free`: 60 requests/minute
- `standard`: 600 requests/minute
- `enterprise`: 6000 requests/minute

**THEN** the API returns HTTP 429 Too Many Requests with:
- Header `Retry-After: 60` (seconds until quota resets)
- JSON body: `{ "error": "Rate limit exceeded", "retry_after": 60 }`
- The throttling event is logged in the tenant's audit trail

**AND** a consumer exceeds 1000 429 responses in a single day  
**WHEN** the threshold is crossed  
**THEN**:
- An alert is triggered for the platform operator: "Tenant `<tenant-id>` has experienced 1000+ rate-limit failures in the last 24 hours"
- The tenant admin receives a Nextcloud notification: "Unusual API activity detected on your account. Please review recent integrations."
- A WebhookDelivery-like audit log entry is created for operator review

**AND** an API consumer requests usage stats  
**WHEN** they call `GET /api/v1/settings/api-keys/{id}/stats` (admin only)  
**THEN** they receive:
```json
{
  "api_key_id": "<id>",
  "period": "last_30_days",
  "total_requests": 45678,
  "requests_per_day": [
    { "date": "2026-05-23", "count": 1234 },
    { "date": "2026-05-22", "count": 1056 },
    ...
  ],
  "error_rate": "0.2%",
  "errors_by_status": {
    "401": 45,
    "403": 12,
    "429": 5
  },
  "last_used_at": "2026-05-23T14:30:00Z"
}
```

---

## REQ-009: OpenAPI 3.1 Specification

**GIVEN** an API consumer  
**WHEN** they call `GET /api/v1/openapi.json`  
**THEN** they receive a valid OpenAPI 3.1 specification that:
- Defines all REST endpoints (GET /employees, POST /employees, etc.)
- Includes request/response schemas for each endpoint
- Documents rate-limit headers (X-RateLimit-Remaining, X-RateLimit-Reset)
- Documents scopes required for each endpoint (employees:read, employees:write, etc.)
- Includes example requests/responses for key operations
- Is generated from code annotations (no manual YAML edits) and deployed with each app version

---

## REQ-010: SCIM Provider Metadata

**GIVEN** an IdP or SCIM client  
**WHEN** they call `GET /scim/v2/.well-known/scim-configuration` (RFC 7644 section 4)  
**THEN** they receive a JSON response documenting:
```json
{
  "schemas": [
    "urn:ietf:params:scim:api:messages:2.0:ListResponse"
  ],
  "documentationUri": "https://hrmq.example.com/api/v1/docs",
  "authenticationSchemes": [
    {
      "type": "oauthbearertoken",
      "name": "OAuth 2.0 Bearer Token"
    },
    {
      "type": "httpbasic",
      "name": "HTTP Basic Authentication"
    }
  ],
  "resourceTypes": [
    {
      "schemas": [
        "urn:ietf:params:scim:schemas:core:2.0:User",
        "urn:ietf:params:scim:schemas:extension:enterprise:2.0:User"
      ],
      "name": "User",
      "endpoint": "/Users",
      "description": "SCIM User resource representing hrmq Employee"
    },
    {
      "schemas": [
        "urn:ietf:params:scim:schemas:core:2.0:Group"
      ],
      "name": "Group",
      "endpoint": "/Groups",
      "description": "SCIM Group resource representing hrmq OrgUnit"
    }
  ]
}
```

---

## REQ-011: Cross-origin Resource Sharing (CORS)

**GIVEN** a browser-based client (e.g., JavaScript dashboard)  
**WHEN** it makes a cross-origin request to `/api/v1/*`  
**THEN** the API:
- Responds to `OPTIONS` preflight requests with appropriate CORS headers
- Allows requests from origins matching the tenant's configured CORS allowlist (configurable in Settings)
- Returns header `Access-Control-Allow-Credentials: true` to support cookie-based sessions
- Returns header `Access-Control-Expose-Headers: X-RateLimit-*, X-GraphQL-Complexity` for client-side rate-limit handling

---

## REQ-012: Pagination and Filtering

**GIVEN** an API consumer calling `GET /api/v1/employees`  
**WHEN** they want to paginate through results  
**THEN** they can use:
- `first=50` (limit, default 50, max 250) and `after=<cursor>` for cursor-based pagination
- Response includes `pageInfo: { hasNextPage: boolean, endCursor: string }`

**AND** they want to filter employees  
**WHEN** they query `GET /api/v1/employees?filter[status]=active&filter[orgUnit]=<org-id>`  
**THEN** results are filtered by those criteria

**AND** they want to sort  
**WHEN** they query `GET /api/v1/employees?sort=-createdAt,firstName` (- prefix for descending)  
**THEN** results are sorted by createdAt descending, then firstName ascending

