# Tasks: Audit Trail Payroll

## Phase 0: Foundation & Setup (Weeks 1-2)

- [ ] **T-001: Create project & repository structure**
  - [ ] Create `/audit-trail-payroll` directory under hrmq app
  - [ ] Create PHP namespace `Hrmq\AuditTrail`
  - [ ] Add `composer.json` with dependencies (PostgreSQL driver, UUID library, Crypto libs)
  - [ ] Create `ARCHITECTURE.md` with system design summary
  - Status: Ready to start

- [ ] **T-002: Design database schema**
  - [ ] Write SQL migration for `payroll_audit_events` table (UUID v7, indexes, constraints)
  - [ ] Write SQL migration for `payroll_audit_chain_anchors`
  - [ ] Write SQL migration for `payroll_audit_retention_policies`
  - [ ] Write SQL migration for `payroll_audit_exports`
  - [ ] Write SQL migration for `payroll_audit_access_logs`
  - [ ] Create database trigger function `raise_immutability_violation()`
  - [ ] Run migrations on local dev database
  - [ ] Verify indexes are created and efficient
  - Status: Ready to start

- [ ] **T-003: Set up CI/CD pipeline**
  - [ ] Create GitHub Actions workflow for: lint, test, coverage, security scan
  - [ ] Configure codecov integration
  - [ ] Add pre-commit hooks: PHP CodeSniffer, PHPStan (level 9)
  - [ ] Document local development setup in README
  - Status: Ready to start

## Phase 1: Core Immutability & Hash Chain (Weeks 2-4)

- [ ] **T-004: Implement PayrollAuditEvent entity & repository**
  - [ ] Create `PayrollAuditEvent` entity class (Doctrine ORM or custom mapper)
  - [ ] Create `PayrollAuditEventRepository` with insert-only interface
  - [ ] Implement `append()` method (INSERT only, prevents UPDATE/DELETE)
  - [ ] Implement validation in `append()`: check required fields, call hash calculation
  - [ ] Write unit tests (100% coverage for append method)
  - [ ] Write integration tests: verify INSERT works, UPDATE fails, DELETE fails
  - [ ] Verify ORM layer raises `ImmutabilityViolationException` on update/delete attempts
  - Status: After T-002

- [ ] **T-005: Implement hash calculation & storage**
  - [ ] Create `HashCalculator` service for SHA-256 hashing
  - [ ] Implement canonical JSON serialization for deterministic hashing
  - [ ] Add `vorige_event_id` & `vorige_event_hash` lookup on event insert
  - [ ] Calculate `eigen_hash` and store before INSERT
  - [ ] Write unit tests: verify hash determinism, chain linking
  - [ ] Integration test: insert 10 events, verify each hash matches previous
  - Status: After T-004

- [ ] **T-006: Implement database-level immutability enforcement**
  - [ ] Create PostgreSQL trigger `payroll_audit_events_prevent_modification` (BEFORE UPDATE OR DELETE)
  - [ ] Test directly via `psql`: attempt UPDATE, verify error message
  - [ ] Test directly via `psql`: attempt DELETE, verify error message
  - [ ] Verify trigger does NOT block INSERT
  - [ ] Document trigger logic in migration comment
  - Status: After T-002

- [ ] **T-007: Implement chain verification job**
  - [ ] Create `ChainVerificationJob` service
  - [ ] Implement algorithm: iterate chronologically, recalculate each hash, compare to stored
  - [ ] Log results: "chain_valid" + count, or "chain_broken at event {id}" + mismatch details
  - [ ] Implement HIGH-priority security alert on chain break
  - [ ] Schedule job to run weekly: Monday 03:00 UTC (via Laravel scheduler or Cron)
  - [ ] Write unit tests: mock events with correct chain, verify pass; mock with broken chain, verify fail
  - [ ] Integration test: insert 100 events, manually corrupt one, run job, verify detection
  - Status: After T-005

## Phase 2: Anchoring & Cryptography (Weeks 4-5)

- [ ] **T-008: Implement PayrollAuditChainAnchor & Merkle root**
  - [ ] Create `PayrollAuditChainAnchor` entity & repository
  - [ ] Implement `MerkleTreeCalculator` for Merkle-root over event hashes
  - [ ] Write unit tests: verify Merkle-root determinism, verify root changes with event addition
  - [ ] Integration test: insert 20 events, calculate root, insert 1 more event, verify root changed
  - Status: After T-005

- [ ] **T-009: Implement anchor job (internal signature mode)**
  - [ ] Create `AnchorCreationJob` service
  - [ ] Implement internal signature mode: sign root with system RSA private key
  - [ ] Generate RSA 4096-bit key pair during setup
  - [ ] Store public key in config (for verification), private key in secrets manager
  - [ ] Schedule job: Sunday 02:00 UTC
  - [ ] Handle zero-event periods: create anchor with identical hash to previous
  - [ ] Write unit tests: verify signature generation & verification
  - [ ] Integration test: run anchor job, verify PayrollAuditChainAnchor record created with valid signature
  - Status: After T-008

- [ ] **T-010: Implement RFC 3161 TSA support (optional mode)**
  - [ ] Create `TimeStampAuthorityClient` for RFC 3161 integration
  - [ ] Add configuration option: `anker_methode` = "rfc3161_tsa" + TSA endpoint URL
  - [ ] Implement fallback: if TSA unavailable, use internal signature + retry
  - [ ] Write integration tests with mock TSA server
  - [ ] Document TSA setup & configuration
  - Status: After T-009 (lower priority, can defer to later phase)

## Phase 3: Event Creation & Validation (Weeks 5-6)

- [ ] **T-011: Implement high-impact event motivation validation**
  - [ ] Create `MotivationValidator` service
  - [ ] Define high-impact event list: beschikking30.handmatig_ingetrokken, beslag.*, IBAN wijziging, etc.
  - [ ] Implement: if event is high-impact AND motivering is empty, raise `AuditLogValidationError`
  - [ ] Implement: if event is high-impact AND motivering.length < 20, raise validation error
  - [ ] Test matrix: each high-impact event type with valid/invalid/missing motivering
  - [ ] Write unit tests: 100% coverage for validation logic
  - Status: After T-004

- [ ] **T-012: Implement audit SDK (AuditLogger interface)**
  - [ ] Create `Hrmq\Audit\AuditLoggerInterface` with `log()` method
  - [ ] Implement `AuditLogger` class that:
    - [ ] Auto-fills: `vorige_event_id`, `vorige_event_hash`, `eigen_hash`, `actor_id`, `actor_label` (from session)
    - [ ] Validates required fields (REQ-004 high-impact, REQ-005 Loonrun fields, etc.)
    - [ ] Calls `HashCalculator` to compute hashes
    - [ ] Inserts via `PayrollAuditEventRepository->append()`
    - [ ] Handles transactions atomically
  - [ ] Write unit tests: test log() with various event types, test auto-fill behavior
  - [ ] Write integration tests: log from a simulated capability, verify event inserted correctly
  - [ ] Create comprehensive SDK documentation in Dutch & English
  - Status: After T-004, T-011

- [ ] **T-013: Add logging to application boundaries**
  - [ ] Identify key integration points: Medewerker create/update, Contract change, Loonrun calculate, Beschikking30 action, Beslag insert, etc.
  - [ ] Add calls to `$auditLogger->log()` at each boundary
  - [ ] Test: verify every mutation is logged with correct event_type, event_naam, payloads
  - [ ] Document which capabilities have been instrumented
  - Status: After T-012

## Phase 4: Access Control & Audit-of-Auditors (Weeks 6-7)

- [ ] **T-014: Implement payroll_audit_lezer role & RBAC**
  - [ ] Create `payroll_audit_lezer` role in permission system
  - [ ] Add API middleware: check role before accessing `GET /api/payroll-audit/*`
  - [ ] Return `403 Forbidden` if role is missing
  - [ ] Write unit tests: test with/without role, verify 403 response
  - Status: After T-012

- [ ] **T-015: Implement PayrollAuditAccessLog & access logging**
  - [ ] Create `PayrollAuditAccessLog` entity & repository
  - [ ] Implement logging middleware/service: on every successful query to audit events, create AccessLog entry
  - [ ] Require `rechtvaardiging` parameter; reject if missing (400 Bad Request)
  - [ ] Store: `gebruiker_id`, `query_filter`, `aantal_events_geraadpleegd`, `rechtvaardiging`, `tijdstip`
  - [ ] Write unit tests: test logging on query, test rechtvaardiging validation
  - [ ] Integration test: query events with justification, verify AccessLog entry created
  - Status: After T-004, T-014

- [ ] **T-016: Implement role assignment governance**
  - [ ] Create admin interface for assigning `payroll_audit_lezer` role
  - [ ] Require approval workflow: DPO/Compliance Officer signs off
  - [ ] Log role assignment as security event to general application audit trail
  - [ ] Send email notification to user & manager on assignment
  - [ ] Implement "audit of auditors" report: who accessed payroll data, when, why
  - Status: After T-015

## Phase 5: Export & Reporting (Weeks 7-8)

- [ ] **T-017: Implement export request API & lifecycle**
  - [ ] Create `PayrollAuditExport` entity & repository
  - [ ] Implement export request endpoint: `POST /api/payroll-audit/export`
  - [ ] Validate: `periode_van`, `periode_tot`, `export_formaat`, `rechtvaardiging` required
  - [ ] Status transitions: accept request → queue job → `in_uitvoering` → `gereed` / `mislukt`
  - [ ] Auto-cleanup: mark as `verlopen` after 30 days, delete file
  - [ ] Write unit tests: request validation, status transitions
  - [ ] Integration test: create export, poll status, verify file ready
  - Status: After T-012

- [ ] **T-018: Implement PDF proof-package generation**
  - [ ] Create `ProofPackageGenerator` service
  - [ ] Generate narrative PDF with: administratie info, period, high-level summary, events per loonperiode
  - [ ] Include: all audit events in JSON appendix with hashes, applicable chain anchors
  - [ ] Write in Dutch; add English fallback
  - [ ] Test: generate PDF for sample data, verify readability, verify no truncation
  - Status: After T-017

- [ ] **T-019: Implement verification script**
  - [ ] Create `verification_script.sh`: portable bash script (works on macOS/Linux/Windows via Git Bash)
  - [ ] Script functionality:
    - [ ] Parse `events.json` from ZIP
    - [ ] Recalculate each event hash, compare to stored hash
    - [ ] Recalculate Merkle root from event hashes
    - [ ] Compare to `chain_anchors.json` root
    - [ ] Report "✓ Integrity verified" or "✗ Chain broken" in Dutch
    - [ ] Return exit code 0/1
  - [ ] Test on macOS, Linux, Windows (Git Bash)
  - [ ] Include in every export ZIP
  - Status: After T-018

- [ ] **T-020: Implement CSV & JSON export formats**
  - [ ] CSV format: comma/tab-separated, one event per row, all fields
  - [ ] JSON format: signed (include signature of entire JSON blob with system key)
  - [ ] Test: export sample data in both formats, verify parseable by tools
  - Status: After T-017

- [ ] **T-021: Implement XBRL export (optional, defer if needed)**
  - [ ] Create `XbrlExportGenerator`
  - [ ] Map audit events to XBRL financial reporting context
  - [ ] Write test: generate XBRL, validate against schema
  - Status: After T-020 (lower priority)

## Phase 6: Retention & Legal Hold (Weeks 8-9)

- [ ] **T-022: Implement retention policy management**
  - [ ] Create `PayrollAuditRetentionPolicy` entity & repository
  - [ ] Implement admin interface: create/edit retention policies (per-administratie, per-entity-type)
  - [ ] Seed fleet-wide default policy on app setup (10 years, pseudonimization)
  - [ ] Write unit tests: policy override resolution (admin-specific vs. fleet default)
  - Status: After T-002

- [ ] **T-023: Implement legal hold management**
  - [ ] Create `LegalHold` entity: administratie_id, hold_reason, expires_at, created_by
  - [ ] Implement admin interface: place hold, list active holds, expire hold
  - [ ] Log hold placement/expiration to security audit trail
  - [ ] Write unit tests: hold creation, expiration, listing
  - Status: After T-022

- [ ] **T-024: Implement event cleanup job (deletion & pseudonimization)**
  - [ ] Create `EventCleanupJob` service
  - [ ] Algorithm:
    - [ ] Query events eligible for cleanup (created_at + retention_jaren < now)
    - [ ] For each administratie, check for active legal holds
    - [ ] For events NOT on hold:
      - [ ] If policy = 'volledige_verwijdering':
        - [ ] Create "tombstone" event (event_type = 'verwijderd_fysiek')
        - [ ] Delete original event
      - [ ] If policy = 'pseudonimisering':
        - [ ] Redact payload_voor, payload_na, actor_label
        - [ ] Set `pseudonimized = true`
        - [ ] Keep metadata (motivering, timing, etc.)
  - [ ] Schedule: monthly (e.g., first day of month, 03:00 UTC)
  - [ ] Logging: log count deleted, count pseudonimized, count skipped (legal hold)
  - [ ] Write unit tests: verify cleanup logic, verify legal hold suspension
  - [ ] Integration test: create events, set retention policy, trigger cleanup, verify results
  - Status: After T-023

- [ ] **T-025: Implement cleanup reporting & compliance dashboard**
  - [ ] Create admin dashboard showing: events deleted, pseudonimized, on legal hold (past 12 months)
  - [ ] Report retention policy compliance per administratie
  - [ ] Write retention policy audit report: which policies are active, when they were last reviewed
  - Status: After T-024

## Phase 7: Performance Optimization (Weeks 9-10)

- [ ] **T-026: Optimize database indexes & query plans**
  - [ ] Create composite indexes as designed:
    - [ ] (administratie_id, tijdstip_utc DESC)
    - [ ] (administratie_id, entiteit_type, entiteit_id)
    - [ ] (administratie_id, actor_id)
  - [ ] Run EXPLAIN ANALYZE on typical queries
  - [ ] Optimize query plans if needed (e.g., reorder WHERE clauses)
  - [ ] Write performance test: 1M events, measure P50/P95/P99 latency
  - [ ] Document index maintenance strategy
  - Status: After T-004

- [ ] **T-027: Implement per-administratie serialization (locking strategy)**
  - [ ] Choose locking approach: PostgreSQL `pg_advisory_lock()` on administratie_id, OR single-writer queue per administratie
  - [ ] Implement: prevent race conditions when multiple capabilities insert events simultaneously
  - [ ] Write integration test: concurrent inserts from multiple threads, verify chain integrity
  - [ ] Benchmark: measure latency overhead of locking
  - Status: After T-004

- [ ] **T-028: Implement pagination & cursor-based queries**
  - [ ] Add pagination to `GET /api/payroll-audit/events`: default 100 results, provide `next_page_cursor`
  - [ ] Implement cursor based on (administratie_id, tijdstip_utc, event_id) for stable pagination
  - [ ] Write unit tests: paginate through large result set, verify no duplicates/gaps
  - Status: After T-012

- [ ] **T-029: Add caching strategy (optional, if needed)**
  - [ ] Evaluate cache: Redis for query results, or in-memory cache for Merkle root
  - [ ] Implement TTL: 5 minutes for query caches (to stay fresh)
  - [ ] Write tests: cache hit/miss, cache invalidation on new event
  - Status: After T-028 (if performance testing shows bottleneck)

## Phase 8: Integration & Testing (Weeks 10-11)

- [ ] **T-030: Integrate with employee-master**
  - [ ] Add audit logging to Medewerker create/update/delete operations
  - [ ] Verify: each Medewerker change creates corresponding audit event
  - [ ] Test: Medewerker creation, salary change, termination all logged
  - Status: After T-012, T-013

- [ ] **T-031: Integrate with payroll-engine-nl**
  - [ ] Add audit logging to Loonrun calculation (event_type = 'berekend')
  - [ ] Capture: `engine_versie`, `wet_versie`, `payload_na` with totals
  - [ ] Verify: REQ-005 reproducibility: stored versioning enables re-execution
  - [ ] Test: run loonrun, verify audit event with correct versions
  - Status: After T-012, T-013

- [ ] **T-032: Integrate with 30-procent-regeling**
  - [ ] Add audit logging for Beschikking30: aangemaakt, gewijzigd, ingetrokken (with motivering)
  - [ ] Test: issue 30% arrangement, modify, revoke — all logged with motivation
  - Status: After T-012, T-013

- [ ] **T-033: Integrate with loonbeslag-admin**
  - [ ] Add audit logging: Beslag create, update, removal
  - [ ] Test: garnishment actions all logged
  - Status: After T-012, T-013

- [ ] **T-034: Run end-to-end integration tests**
  - [ ] Scenario 1: Create employee → salary change → loonrun calculation → export audit trail → verify integrity
  - [ ] Scenario 2: Revoke 30% arrangement (high-impact, requires motivation) → verify audit log captures motivation
  - [ ] Scenario 3: Perform query with justification → verify AccessLog entry created
  - [ ] Scenario 4: Run chain verification job, then cleanup job → verify tombstone events created, chain intact
  - Status: After T-013, T-032

- [ ] **T-035: Load testing & performance validation**
  - [ ] Simulate 10K employee administratie with 12 monthly loonruns = 120 events/employee/year
  - [ ] Load test: insert 50K events within 30 seconds (loonrun scenario)
  - [ ] Query test: filter by medewerker ID across full year, measure latency P95 < 2s
  - [ ] Report: throughput, latency, bottlenecks, recommendations
  - Status: After T-026, T-027

## Phase 9: Documentation & Deployment (Weeks 11-12)

- [ ] **T-036: Write comprehensive documentation**
  - [ ] Operators guide: installation, configuration, monitoring, troubleshooting
  - [ ] SDK integration guide: how to use AuditLogger in new capabilities
  - [ ] Architecture documentation: system design, data model, security model
  - [ ] Compliance documentation: how the system meets Art. 52 AWR, AVG, NEN 7510
  - [ ] Retention policy guide: how to set up and manage policies
  - [ ] Dutch & English versions
  - Status: After T-025

- [ ] **T-037: Create deployment & rollout plan**
  - [ ] Database migration strategy: apply schema on dev → stage → production
  - [ ] Feature flag setup: audit logging can be enabled per-administratie (gradual rollout)
  - [ ] Backup strategy: test restore from backup to verify audit trail integrity
  - [ ] Rollback procedure: if audit job fails, how to recover
  - [ ] Communication plan: notify stakeholders of launch
  - Status: After T-036

- [ ] **T-038: Security audit & compliance review**
  - [ ] Have security team review:
    - [ ] Immutability enforcement (database, ORM, service)
    - [ ] Cryptographic implementation (hash, signature)
    - [ ] Access control (role-based, mandatory justification)
    - [ ] Secrets management (RSA private key, TSA credentials)
  - [ ] Have compliance officer review:
    - [ ] Retention policy implementation
    - [ ] Legal hold governance
    - [ ] AVG compliance (accountability, data minimization)
    - [ ] Export formatting for Belastingdienst
  - [ ] Remediate findings before production deploy
  - Status: After T-035

- [ ] **T-039: Cutover & go-live**
  - [ ] Deploy to production
  - [ ] Enable audit logging for initial administraties (gradual ramp-up)
  - [ ] Monitor: event insert rate, query latency, error rates
  - [ ] Verify: sample exports, chain verification job, access logging
  - [ ] Incident response: escalation path for audit system issues
  - Status: After T-038

## Phase 10: Post-Launch (Weeks 12+)

- [ ] **T-040: Monitor & optimize**
  - [ ] Set up observability: metrics for insert rate, query latency, job duration
  - [ ] Establish runbook for common issues: chain corruption (rare), export job timeout, TSA unavailability
  - [ ] Quarterly performance reviews: identify slow queries, optimize indexes if needed
  - [ ] Document operational runbooks
  - Status: Ongoing after go-live

- [ ] **T-041: Feature enhancements (backlog items)**
  - [ ] Implement blockchain-based anchoring (anker_methode = 'blockchain_ots') — deferred to 2027
  - [ ] Advanced export formats (XBRL, SWIFT XML) — as needed by customers
  - [ ] Multi-language audit narratives — defer to Q4 2026
  - [ ] Integration with external compliance platforms (Workiva, Domo) — customer-driven

---

## Task Dependency Map

```
T-001 (setup)
├─ T-002 (schema design)
│  ├─ T-003 (CI/CD)
│  ├─ T-006 (DB trigger)
│  ├─ T-022 (retention policy setup)
│  └─ T-023 (legal hold entity)
│
├─ T-004 (PayrollAuditEvent entity)
│  ├─ T-005 (hash calculation)
│  │  ├─ T-007 (chain verification job)
│  │  ├─ T-008 (Merkle root)
│  │  │  ├─ T-009 (anchor job - internal sig)
│  │  │  └─ T-010 (anchor job - TSA)
│  │  └─ T-027 (per-admin serialization)
│  │
│  ├─ T-011 (motivation validation)
│  │  └─ T-012 (audit SDK)
│  │     ├─ T-013 (logging at boundaries)
│  │     ├─ T-014 (RBAC)
│  │     │  └─ T-015 (access logging)
│  │     │     └─ T-016 (role governance)
│  │     │
│  │     ├─ T-017 (export request API)
│  │     │  ├─ T-018 (PDF generation)
│  │     │  │  └─ T-019 (verification script)
│  │     │  ├─ T-020 (CSV/JSON export)
│  │     │  └─ T-021 (XBRL export)
│  │     │
│  │     ├─ T-030 (employee-master integration)
│  │     ├─ T-031 (payroll-engine integration)
│  │     ├─ T-032 (30%-regeling integration)
│  │     ├─ T-033 (loonbeslag integration)
│  │     └─ T-034 (E2E tests)
│  │
│  ├─ T-024 (cleanup job)
│  │  └─ T-025 (cleanup reporting)
│  │
│  └─ T-026 (index optimization)
│     └─ T-035 (load testing)
│
└─ T-028 (pagination)
   └─ T-029 (caching, optional)
```

---

## Success Criteria

- All tasks completed with acceptance criteria met
- 100% of new code has > 90% unit test coverage
- All E2E scenarios pass (T-034)
- Load test shows P95 latency < 2s for typical queries (T-035)
- Security audit finds zero critical/high issues (T-038)
- Compliance review confirms AVG/fiscal/labor-law requirements met
- Documentation reviewed & approved by legal/compliance teams
- Go-live rollout completed with zero data loss (T-039)

