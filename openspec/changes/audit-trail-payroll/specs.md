# Specifications: Audit Trail Payroll

## Requirement Definitions

### REQ-001: Append-only Storage — No UPDATE, No DELETE

**Category**: Immutability  
**Priority**: P0 (Critical)  
**Sources**: Art. 52 AWR, AVG art. 32, NEN 7510

Audit events must be storable only via INSERT. UPDATE and DELETE operations must be rejected at all layers: database trigger, ORM/repository, and service layer. Every rejection must raise an `ImmutabilityViolationException` and be logged as a security incident.

#### Specification

**REQ-001-001**: Database-level enforcement  
GIVEN an audit event is stored in `payroll_audit_events`  
WHEN a database administrator attempts `UPDATE payroll_audit_events SET motivering = '...' WHERE id = ...`  
THEN the database trigger `payroll_audit_events_prevent_modification` rejects the operation with error message "Immutability violation: PayrollAuditEvent records can never be updated or deleted"

**REQ-001-002**: ORM-level enforcement  
GIVEN a developer calls `$repository->update($event)` on an existing audit event  
WHEN the repository executes the ORM method  
THEN the repository raises `ImmutabilityViolationException` before any SQL is issued

**REQ-001-003**: Service-layer enforcement  
GIVEN the AuditLogger SDK method `log(...)` is called  
WHEN the service validates the request  
THEN the service only permits INSERT mode; any attempt to update an existing event_id raises `AuditLogValidationError`

**REQ-001-004**: Correction events for data fixes  
GIVEN an audit event contains incorrect data and must be corrected  
WHEN the correction is needed  
THEN a new event with `event_type = 'correctie'` is created that semantically contradicts the original, but the original event remains in the log and is never deleted

**REQ-001-005**: Immutability violation logging  
GIVEN someone attempts to violate immutability (REQ-001-001, 001-002, or 001-003)  
WHEN the violation is detected  
THEN a security incident is logged with: timestamp, actor_id, attempted operation, target event_id, and alert severity = HIGH

---

### REQ-002: Hash-Chain Integrity per Administratie

**Category**: Cryptography & Integrity  
**Priority**: P0 (Critical)  
**Sources**: RFC 6962 (Certificate Transparency), NIST SP 800-92

Each event stores the SHA-256 hash of the previous event in the same administratie; any tampering breaks the chain and is immediately detected by weekly verification runs.

#### Specification

**REQ-002-001**: Hash-chain structure  
GIVEN a new event is inserted into administratie A  
WHEN the event is assigned `vorige_event_id` (the ID of the chronologically previous event)  
THEN `vorige_event_hash` is set to SHA-256(previous_event_full_record), and `eigen_hash` is calculated as SHA-256(all fields including vorige_event_hash)

**REQ-002-002**: Chain verification job  
GIVEN 10 events exist in administratie A in chronological order  
WHEN the weekly verification job runs (Monday 03:00 UTC)  
THEN the job recalculates the hash of each event using the same algorithm and compares to stored `eigen_hash`:  
- If all match: log "chain_valid" with total event count  
- If mismatch at event E: log "chain_broken at event {E.id}, hash mismatch: expected {expected_hash}, got {E.eigen_hash}"

**REQ-002-003**: Corruption detection & alerting  
GIVEN the verification job detects a broken chain at event E  
WHEN the mismatch is found  
THEN (a) create a HIGH priority security alert, (b) send notification to security team and compliance officer, (c) log incident with corrupted event IDs

**REQ-002-004**: First-event edge case  
GIVEN the first event in administratie A is inserted  
WHEN the event has no previous event  
THEN `vorige_event_id = NULL`, `vorige_event_hash = NULL`, and `eigen_hash` is calculated over only the own fields

**REQ-002-005**: Hash stability  
GIVEN the same event record (same field values)  
WHEN `eigen_hash` is recalculated  
THEN the result must be identical across multiple calculations (deterministic hash)

---

### REQ-003: Weekly Merkle-Root Anchoring

**Category**: Cryptography & Integrity  
**Priority**: P0 (Critical)  
**Sources**: RFC 6962, RFC 3161

A cumulative Merkle root is computed over all events since the last anchor, optionally signed by a Time Stamping Authority. This provides external proof that the audit trail has not been retroactively restored.

#### Specification

**REQ-003-001**: Anchor creation schedule  
GIVEN the system is running  
WHEN the anchor job is triggered at Sunday 02:00 UTC  
THEN for each administratie, a new `PayrollAuditChainAnchor` record is created with `anker_tijdstip` set to current UTC timestamp

**REQ-003-002**: Merkle-root calculation  
GIVEN N events since the last anchor in administratie A  
WHEN the anchor job calculates `cumulatieve_root_hash`  
THEN it computes a Merkle tree over the `eigen_hash` of each event in chronological order and stores the root hash

**REQ-003-003**: Anchor for zero-event periods  
GIVEN no new events were added to administratie A since the last anchor  
WHEN the anchor job runs  
THEN a new anchor is created with `cumulatieve_root_hash` = previous anchor's hash (to maintain continuity)

**REQ-003-004**: Internal signature (default mode)  
GIVEN `anker_methode = 'interne_ondertekening'`  
WHEN the anchor job creates the anchor  
THEN the root hash is signed with the system's private key (RSA 4096-bit or better) and the signature is stored in `anker_bewijs`

**REQ-003-005**: RFC 3161 Time Stamp Authority (optional mode)  
GIVEN `anker_methode = 'rfc3161_tsa'` and a TSA endpoint is configured  
WHEN the anchor job runs  
THEN (a) the root hash is sent to the TSA, (b) the TSA response (timestamp token) is stored in `anker_bewijs`, (c) on TSA unavailability, fallback to internal signature with retry on next job run

**REQ-003-006**: Anchor chain continuity  
GIVEN the current anchor (N) is created  
WHEN N is inserted  
THEN `N.vorige_anker_id` is set to the ID of the previous anchor (N-1), creating an unbroken chain

**REQ-003-007**: Anchor recalculation prevention  
GIVEN an anchor is created  
WHEN someone attempts to recalculate or update the anchor  
THEN the anchor record is treated as immutable (same enforcement as events)

---

### REQ-004: Mandatory Motivation for High-Impact Events

**Category**: Governance & Audit Trail Semantics  
**Priority**: P1  
**Sources**: Art. 7:611 BW (labor law), internal control requirements

Certain payroll events (30%-regeling intrekking, beslagvrije-voet override, IBAN wijziging for salary payment, handmatige loonrun-correctie) require a non-empty, minimum-length motivation field.

#### Specification

**REQ-004-001**: High-impact event list  
GIVEN the following event types exist  
WHEN they are processed  
THEN `motivering` field is mandatory and validated:

| Event Type | Minimum Length | Context |
|---|---|---|
| `beschikking30.handmatig_ingetrokken` | 20 chars | Labor law requires justification for revoking 30% arrangement |
| `beschikking30.handmatig_verleend` | 20 chars | Granting 30% also requires documentation |
| `beslag.nieuw_handmatig_toegevoegd` | 20 chars | Manual garnishment override |
| `beslagvrije_voet.handmatig_gewijzigd` | 20 chars | Override of standard exemption |
| `medewerker.iban_betaling_gewijzigd` | 20 chars | Change of salary payment bank account |
| `loonrun.handmatig_gecorrigeerd` | 25 chars | Correction of automated payroll calculation |
| `pensioen_aanspraak.handmatig_gewijzigd` | 20 chars | Manual pension adjustment |

**REQ-004-002**: Missing motivation validation  
GIVEN an event of type `beschikking30.handmatig_ingetrokken` is being created WITHOUT a motivering field  
WHEN the service layer validates the request  
THEN it raises `AuditLogValidationError` with message "motivering verplicht voor event_type {event_type}" (in Dutch)

**REQ-004-003**: Undersized motivation validation  
GIVEN an event of type `beschikking30.handmatig_ingetrokken` is being created with motivering = "X" (1 char, below 20 minimum)  
WHEN the service validates  
THEN it raises `AuditLogValidationError` with message "motivering te kort — minstens 20 tekens vereist voor {event_type}"

**REQ-004-004**: Motivation acceptance  
GIVEN an event of type `beschikking30.handmatig_ingetrokken` is created with motivering = "In accordance with employee resignation notice dated 2026-02-28, 30% arrangement expires" (82 chars, >= 20)  
WHEN the service validates  
THEN the validation passes and the event is stored with the motivering included

**REQ-004-005**: Non-high-impact events  
GIVEN an event of type `medewerker.salaris_gewijzigd` (NOT in high-impact list) is created  
WHEN the service validates  
THEN `motivering` is optional (not required, but if provided is stored)

---

### REQ-005: Full Reproducibility of Historical Payroll Runs

**Category**: Auditability & Reproducibility  
**Priority**: P0 (Critical)  
**Sources**: Art. 52 AWR (fiscal record-keeping), audit & accounting standards

Every Loonrun event must include `engine_versie` and `wet_versie` so that an accountant years later can re-execute the calculation with the exact same rules and verify the historical result.

#### Specification

**REQ-005-001**: Engine version capture  
GIVEN a Loonrun event is created by `payroll-engine-nl`  
WHEN the event is logged  
THEN `engine_versie` is set to the semver of the executing engine (e.g., "1.4.2")

**REQ-005-002**: Law/CAO version capture  
GIVEN a Loonrun event is created  
WHEN the event is logged  
THEN `wet_versie` is set to a human-readable reference of the legal/CAO versions in effect (e.g., "2026-loonheffing-tabel-rev3, cao-industrie-rev1")

**REQ-005-003**: Validation of required fields  
GIVEN a Loonrun event is being created WITHOUT `engine_versie` or `wet_versie`  
WHEN the SDK validates  
THEN it raises `AuditLogValidationError: engine_versie en wet_versie verplicht voor entiteit_type Loonrun`

**REQ-005-004**: Historical re-execution  
GIVEN a loonrun from 2024-06 was executed with `engine_versie = 1.3.1` and `wet_versie = 2024-loonheffing-tabel-rev2`  
WHEN an accountant in 2029 requests reproduction  
THEN (a) the system queries the audit event to retrieve these versions, (b) the system retrieves version 1.3.1 of the payroll engine from an archive, (c) the engine is executed with the same input data and settings, (d) the output is compared to the original `payload_na.totaal_bruto` value

**REQ-005-005**: Bit-exact reproducibility (no drift)  
GIVEN a loonrun calculation  
WHEN it is re-executed with identical engine and law versions  
THEN the salary amounts match to the cent (no rounding drift or precision errors)

**REQ-005-006**: Other entities optional versioning  
GIVEN events for other entity types (e.g., Medewerker salary wijziging) are created  
WHEN the event is logged  
THEN `engine_versie` and `wet_versie` are optional (Loonrun-specific)

---

### REQ-006: Proof-Package Export for Authorities

**Category**: Compliance & External Reporting  
**Priority**: P1  
**Sources**: Belastingdienst boekenonderzoek requirements, accountant audit standards

One-click export of a filtered audit trail in multiple formats suitable for Belastingdienst, UWV, accountants, and labor courts.

#### Specification

**REQ-006-001**: Export formats  
GIVEN an export is requested  
WHEN `export_formaat` is specified  
THEN the system supports:
- `pdf_bewijspakket`: PDF with Dutch narrative, summary per loonperiode, all events as appendix
- `csv`: Tab-separated values for import into Excel/BI tools
- `json_signed`: JSON with hash-chain, signed with system key
- `xbrl_payroll`: XBRL format for financial reporting systems

**REQ-006-002**: Export ZIP structure  
GIVEN a `pdf_bewijspakket` export is generated  
WHEN the ZIP is created  
THEN it contains:
- `manifest.json`: metadata (periode, administratie, export_hash, created_at)
- `bewijspakket.pdf`: narrative guide in Dutch + period summaries
- `events.json`: all events in canonical form with hashes
- `chain_anchors.json`: applicable `PayrollAuditChainAnchor` records
- `verification_script.sh`: bash script to verify integrity (works on macOS/Linux/Windows via bash/Git Bash)
- `README.nl.md`: Dutch-language instructions for Belastingdienst/UWV

**REQ-006-003**: Filter support  
GIVEN an export is requested with `filter = {"entiteit_type": "Loonrun", "periode_van": "2025-01-01", "periode_tot": "2025-12-31"}`  
WHEN the export job runs  
THEN only events matching all filter criteria are included (AND logic)

**REQ-006-004**: Chain-of-custody logging  
GIVEN an export is downloaded  
WHEN the download happens  
THEN a `PayrollAuditAccessLog` entry is created with: `gebruiker_id`, `timestamp`, `query_filter` (from the export request), `rechtvaardiging` (mandatory; must be entered by requester before export starts)

**REQ-006-005**: Export status lifecycle  
GIVEN an export is requested  
WHEN the request is accepted  
THEN status transitions: `in_uitvoering` → `gereed` or `mislukt`  
AND: status `gereed` exports remain available for 30 days, after which auto-cleanup removes the file and marks status `verlopen`

**REQ-006-006**: Verification script  
GIVEN the `verification_script.sh` from an export ZIP is run  
WHEN the script is executed with `./verification_script.sh`  
THEN it:
- Verifies the hash of each event in `events.json`
- Recalculates the Merkle root from event hashes and compares to `chain_anchors.json`
- Reports "✓ Integrity verified" or "✗ Chain broken at event {id}" in Dutch
- Returns exit code 0 (success) or 1 (failure)

**REQ-006-007**: Large export handling  
GIVEN an export is requested for a large period (e.g., 2025 full year for 500-employee administratie = ~50K events)  
WHEN the export job runs  
THEN it completes within 5 minutes and generates a ZIP < 100 MB

---

### REQ-007: Retention Lifecycle & Legal Hold

**Category**: Data Governance & Compliance  
**Priority**: P1  
**Sources**: Art. 52 AWR (7 years), pension law (10 years), AVG art. 5(1)(e) (storage limitation)

Events are retained for a minimum of 10 years by default; after that, they are either pseudonimized or deleted according to policy, with legal-hold support for active disputes.

#### Specification

**REQ-007-001**: Default retention  
GIVEN a `PayrollAuditEvent` is created in administratie A  
WHEN no override policy exists  
THEN it is eligible for cleanup 10 years after creation (creation_date + 3650 days)

**REQ-007-002**: Per-administratie override  
GIVEN a custom `PayrollAuditRetentionPolicy` is defined for administratie A  
WHEN the policy specifies `retentie_jaren = 15`  
THEN events in A are retained for 15 years, not the fleet default of 10

**REQ-007-003**: Per-entity-type override  
GIVEN a custom policy specifies `entiteit_type = 'Pensioenaanspraak'` and `retentie_jaren = 20`  
WHEN events of that type are created  
THEN Pensioenaanspraak events are retained 20 years, others follow standard retention

**REQ-007-004**: Deletion method policy  
GIVEN a retention policy specifies `vernietigingsmethode = 'volledige_verwijdering'`  
WHEN events become eligible for cleanup  
THEN (a) before physical deletion, a "tombstone" event is created with `event_type = 'verwijderd_fysiek'` and `entiteit_id` = original event's entiteit_id, (b) the original event is deleted from the table, (c) the tombstone preserves the chain link

**REQ-007-005**: Pseudonimization method  
GIVEN a retention policy specifies `vernietigingsmethode = 'pseudonimisering_met_behoud_metadata'`  
WHEN events become eligible  
THEN (a) `payload_voor` and `payload_na` are replaced with `{pseudonimized: true}`, (b) `actor_label` is redacted, (c) `motivering` is kept (not personal data), (d) the event record itself is kept (metadata for audit compliance)

**REQ-007-006**: Legal hold placement  
GIVEN an `arbeidsgeschil` (labor dispute) is ongoing  
WHEN a legal hold is created for administratie A  
THEN events in A are marked with `legal_hold_active = true` and are excluded from cleanup

**REQ-007-007**: Legal hold cleanup suspension  
GIVEN a legal hold is active for administratie A  
WHEN the monthly cleanup job runs  
THEN no events from A are processed (deleted or pseudonimized); the job logs "Cleanup skipped for administratie A: legal hold active until {hold_expiry_date}"

**REQ-007-008**: Legal hold expiration  
GIVEN a legal hold expires  
WHEN the expiration date arrives  
THEN `legal_hold_active = false` and events become eligible for cleanup on the next run

**REQ-007-009**: Compliance reporting  
GIVEN a compliance officer wants to know cleanup status  
WHEN they run a cleanup report  
THEN the report shows: events deleted this period, events pseudonimized, events on legal hold, applicable retention policies

---

### REQ-008: AVG-Compliant Access Isolation

**Category**: Privacy & Data Protection  
**Priority**: P0 (Critical)  
**Sources**: AVG art. 5 (accountability), art. 30 (processing register), art. 32 (security)

Access to `PayrollAuditEvent` is restricted to users with a dedicated `payroll_audit_lezer` role, separate from general admin rights. All access is logged in `PayrollAuditAccessLog` with mandatory justification.

#### Specification

**REQ-008-001**: Role-based access control  
GIVEN a user lacks the `payroll_audit_lezer` role  
WHEN they attempt `GET /api/payroll-audit/events`  
THEN the API returns `403 Forbidden: Insufficient permissions. Required role: payroll_audit_lezer`

**REQ-008-002**: Role separation  
GIVEN a user has `appAdmin` (general application admin) role  
WHEN they attempt to access the audit trail without `payroll_audit_lezer`  
THEN access is denied (appAdmin does not imply payroll_audit_lezer)

**REQ-008-003**: Access logging  
GIVEN a user with `payroll_audit_lezer` queries events with filter `administratie_id = adm-nl-2026-01, entiteit_type = Medewerker, entiteit_id = emp-456, periode_van = 2025-01-01, periode_tot = 2026-06-30`  
WHEN the query returns 127 events  
THEN a `PayrollAuditAccessLog` record is created: `gebruiker_id`, `timestamp`, `query_filter` (JSON), `aantal_events_geraadpleegd = 127`, `rechtvaardiging` (mandatory, must be entered before or with the query)

**REQ-008-004**: Mandatory justification  
GIVEN a user with `payroll_audit_lezer` attempts `GET /api/payroll-audit/events?...` without providing `rechtvaardiging` in the request body  
WHEN the request is made  
THEN the API returns `400 Bad Request: rechtvaardiging is verplicht (mandatory field)`

**REQ-008-005**: Justification examples (valid)  
GIVEN a user provides one of the following justifications  
WHEN the query is executed  
THEN access is granted and logged:
- "Voorbereiding jaarrekeningcontrole 2026 (externe auditor)"
- "Belastingdienst boekenonderzoek 2026-Q2"
- "Onderzoek arbeidsgeschil medewerker X (HR)"
- "Incident post-mortem: loonrun 2025-12 berekening"

**REQ-008-006**: Audit-of-auditors reporting  
GIVEN an HR manager wants to audit who accessed payroll data  
WHEN they request an access report for period 2026-02  
THEN the report shows: user_id, timestamp of access, query_filter, count of events accessed, justification text, for every access in that period

**REQ-008-007**: Role assignment governance  
GIVEN the `payroll_audit_lezer` role is assigned to a user  
WHEN the role assignment occurs  
THEN (a) it requires approval by Compliance Officer or DPO, (b) assignment is logged as an audit event, (c) email notification is sent to the user's manager

**REQ-008-008**: Forbidden super-admin bypass  
GIVEN someone with `super_admin` role attempts to bypass `payroll_audit_lezer` checks  
WHEN they try to query the audit trail  
THEN the access control still requires `payroll_audit_lezer` role (no bypass exists)

---

### REQ-009: Performance Under Scale

**Category**: Performance & Scalability  
**Priority**: P1  
**Sources**: Internal SLA requirements

The system must handle large administraties (10K+ employees, 12 payroll runs per year = 120+ events per employee = 1.2M+ events per year) with < 2s P95 latency for typical queries.

#### Specification

**REQ-009-001**: Query latency SLA  
GIVEN a query filters events for administratie A (1M events total) with: `entiteit_id = 'emp-456', periode_van = 2025-01-01, periode_tot = 2026-06-30`  
WHEN the query is executed  
THEN P95 latency is < 2 seconds (i.e., 95 out of 100 repeat queries complete within 2s)

**REQ-009-002**: Write throughput SLA  
GIVEN a loonrun for 50K medewerkers is being executed  
WHEN the payroll-engine inserts 50K events within 30 seconds  
THEN (a) all events are inserted without loss, (b) hash-chain integrity is preserved, (c) database remains responsive for concurrent reads (no lock-out)

**REQ-009-003**: Index strategy  
GIVEN the system is optimized for performance  
WHEN it is deployed  
THEN it includes composite indexes on: (administratie_id, tijdstip_utc DESC), (administratie_id, entiteit_type, entiteit_id), (administratie_id, actor_id)

**REQ-009-004**: Per-administratie serialization  
GIVEN events from multiple capabilities are inserted concurrently for the same administratie  
WHEN the inserts happen  
THEN a locking mechanism (row lock, single-writer queue, or similar) ensures strict chronological ordering and correct hash-chain assignment (no race conditions)

**REQ-009-005**: Query optimization — filtering  
GIVEN a user queries with filters on multiple fields (administratie, entiteit_type, entiteit_id, date range)  
WHEN the query planner optimizes  
THEN the index strategy is chosen to minimize full-table scans

**REQ-009-006**: Pagination  
GIVEN a query matches 50K events  
WHEN the API is called without pagination parameters  
THEN it returns the first 100 results by default (to prevent memory overload) and provides a `next_page` token

---

### REQ-010: SDK for Integration by Other Capabilities

**Category**: API & Usability  
**Priority**: P1  
**Sources**: DRY principle, maintainability

A reusable SDK allows other hrmq capabilities (loonbeslag-admin, pensioen-aangifte, etc.) to log payroll audit events without building their own audit infrastructure.

#### Specification

**REQ-010-001**: SDK availability  
GIVEN a capability like `loonbeslag-admin` needs to log audit events  
WHEN the capability imports `Hrmq\Audit\AuditLoggerInterface`  
THEN it can call `$auditLogger->log(...)` without implementing hash-chain logic

**REQ-010-002**: Auto-filled fields  
GIVEN a capability calls `$auditLogger->log('Beslag', 'beslag-123', 'aangemaakt', 'beslag.nieuw', null, $payload, [])`  
WHEN the log call is executed  
THEN the SDK automatically fills: `vorige_event_id`, `vorige_event_hash`, `eigen_hash`, `actor_id`, `actor_label` (from current session), `actor_ip`, `sessie_id`, `oorzaak_keten_id`, `tijdstip_utc`, `tijdstip_lokaal_amsterdam`

**REQ-010-003**: Validation  
GIVEN a capability calls `$auditLogger->log(...)` with insufficient data (e.g., missing `wet_versie` for a Loonrun event)  
WHEN the SDK validates  
THEN it raises `AuditLogValidationError` with message "wet_versie verplicht voor entiteit_type Loonrun"

**REQ-010-004**: Transaction atomicity  
GIVEN a capability inserts an audit event via the SDK  
WHEN the event is logged  
THEN the entire event (hash calculation, database insert, index update) happens atomically — either fully succeeds or fully rolls back

**REQ-010-005**: SDK backward compatibility  
GIVEN the SDK is upgraded from v1.0 to v2.0  
WHEN existing capabilities use the SDK without code changes  
THEN the v2.0 SDK remains backward-compatible; breaking changes require a major version bump and migration guide

**REQ-010-006**: SDK documentation  
GIVEN the SDK is released  
WHEN capabilities want to integrate  
THEN comprehensive documentation is available in Dutch and English with: method signatures, example usage per event type, error handling, validation rules

---

## Cross-Functional Requirements

### CFR-001: Internationalization (i18n)

All error messages, field labels, and PDF export narratives must be available in Dutch (primary) and English (secondary). User-facing validation errors must be in the user's language preference.

### CFR-002: Audit Trail Logging of Audit Trail

Every operation on the audit system itself (anchor creation, export request, legal hold placement) must be logged to the general application audit trail (not the payroll audit trail) for troubleshooting and compliance.

### CFR-003: Observability & Monitoring

Metrics must be exposed for: event-insert rate per minute, event-query latency histogram (p50, p95, p99), chain-verification duration, export-job throughput, legal-hold active count.

### CFR-004: Data Minimization

The SDK and API must not capture unnecessary PII. For example, `actor_ip` is optional; `actor_user_agent` is only stored if relevant to the event context.

---

## Acceptance Criteria Summary

| Requirement | Acceptance Criterion |
|---|---|
| REQ-001: Append-only | UPDATE/DELETE rejected at all 3 layers (DB, ORM, service) with ImmutabilityViolationException |
| REQ-002: Hash-chain | Weekly verification job recalculates all hashes, detects chain breaks, raises HIGH alert |
| REQ-003: Weekly anchors | Sunday 02:00 UTC anchor with Merkle root; supports internal sig + RFC 3161 TSA |
| REQ-004: Motivation | High-impact events require >= 20-char motivering or validation fails |
| REQ-005: Reproducibility | Loonrun events capture engine_versie & wet_versie; historical re-execution possible |
| REQ-006: Export | Exports as PDF/CSV/JSON/XBRL, include verification script, support 5+ filters |
| REQ-007: Retention | 10-year default; per-admin/entity override; legal-hold support; deletion with tombstone |
| REQ-008: Access control | payroll_audit_lezer role required; all reads logged in AccessLog with justification |
| REQ-009: Performance | < 2s P95 latency for typical queries on 1M+ event tables; 1000+ events/s write |
| REQ-010: SDK | Reusable integration library; auto-fills hashes; validates required fields |

---

## Non-Functional Requirements

### Security
- Immutability enforced at database, ORM, and service layers
- Cryptographic hash-chain prevents tampering detection
- Role-based access control (payroll_audit_lezer)
- All access logged and auditable

### Compliance
- AVG art. 5, 30, 32 (accountability, processing register, security)
- Art. 52 AWR (fiscal record retention)
- NEN 7510 / ISO 27001 A.12.4 (logging & monitoring)

### Availability
- No single points of failure for event insert
- Chain anchor job has graceful fallback (internal sig if TSA unavailable)
- Export job auto-cleanup (30-day retention) prevents storage bloat

### Maintainability
- Clear API contracts for insertion, querying, export
- Reusable SDK reduces code duplication
- Comprehensive error messages in Dutch
- Documentation for operators and integrators

