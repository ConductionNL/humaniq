---
schema: spec-driven
change: performance-management-advanced
version: 1.0
date: 2026-05-23
---

# Implementation Tasks: Performance Management Advanced

## Phase 1: Data Model & Core APIs (Week 1-2)

### 1.1 Create OpenRegister schema for OKRCycle
- [ ] Define `OKRCycle` entity in the OpenRegister schema
  - Fields: cyclus_id, periode, start_datum, eind_datum, bedrijfsdoelen (JSON), status, aangemaakt_door, aangemaakt_op, gewijzigd_op
  - Validate date range (start ≤ end)
  - Add migration/seeding scripts
- [ ] Create `POST /api/okr/cycles` endpoint
  - Accept JSON body with cycle details
  - Return HAL response with cycle object + links
  - Validate only CHRO/admin can create
- [ ] Create `GET /api/okr/cycles` endpoint (list with pagination)
  - Filter by status, date range
  - Return count, links to next/prev
- [ ] Add unit tests (3+ test cases: happy path, invalid dates, permission denied)

### 1.2 Create OpenRegister schema for OKR
- [ ] Define `OKR` entity in OpenRegister
  - Fields: okr_id, cyclus_ref, eigenaar_ref, niveau, objective_titel, objective_beschrijving, parent_okr_ref, key_results (JSON), confidence_score, eindscore, classificatie, aangemaakt_op, gewijzigd_op
  - Validate: min 1 key_result, max 5; target > baseline for numeric types
  - Add constraint: parent_okr_ref must exist in same cycle
- [ ] Create `POST /api/okr` endpoint (create OKR)
  - Accept OKR object with 1-5 key_results
  - Return created OKR with 201 status
  - Validate owner permission (must be self or manager/HR-BP)
- [ ] Create `GET /api/okr/{id}` endpoint
  - Return OKR with full key_results array
  - Include computed fields: cascade_completeness, orphan_flag
- [ ] Create `GET /api/okr/cycles/{cyclus_id}/tree` endpoint (cascade view)
  - Return nested OKR tree (company → BU → team → individual)
  - Include completion % per level
- [ ] Add unit tests (4+ cases: create with children, cascade validation, permissions, orphan detection)

### 1.3 Create OpenRegister schema for NineBoxAssessment
- [ ] Define `NineBoxAssessment` entity in OpenRegister
  - Fields: assessment_id, cyclus_ref, subject_ref, assessor_ref, performance_axis, potential_axis, talent_segment, onderbouwing_performance, onderbouwing_potential, gerelateerde_feedback_refs, gerelateerde_okr_scores, aangepast_na_kalibratie, confidential_level, aangemaakt_op, gewijzigd_op, audit_accessed_by (JSON array)
  - Validate: performance & potential axes must be "low", "medium", or "high"
  - Validate: onderbouwing fields must be >= 200 characters
  - Auto-derive talent_segment from axes
- [ ] Create `POST /api/nineboxassessment` endpoint
  - Accept assessment object with required fields
  - Return 201 with assessment (not including assessor's identity if subject is different)
  - Validate only manager/HR-BP can create for others
- [ ] Create `GET /api/nineboxassessment/{id}` endpoint
  - Return assessment with auth check (only manager/HR-BP/self if assessor)
  - DO NOT return 9-box data to non-privileged employees
  - Log access to audit_accessed_by
- [ ] Create `GET /api/nineboxassessment/cycles/{cyclus_id}` endpoint (list)
  - Return array of assessments in cycle (for managers, filtered by scope)
  - Exclude assessments not in scope
  - Exclude subject identity if requester is not authorized
- [ ] Create `PATCH /api/nineboxassessment/{id}` endpoint (update)
  - Allow changes to axes and onderbouwing
  - Prevent changes if `aangepast_na_kalibratie = true` (unless authorized re-assessment)
  - Log all changes with timestamp
- [ ] Add unit tests (5+ cases: create with validation, 403 for unauthorized reads, audit logging, talent_segment derivation, permission scopes)

### 1.4 Create OpenRegister schema for ContinuousFeedback
- [ ] Define `ContinuousFeedback` entity in OpenRegister
  - Fields: feedback_id, gever_ref, ontvanger_ref, type, tekst, gerelateerde_okr_ref, gerelateerde_competentie, zichtbaarheid, aangemaakt_datum, gewijzigd_op, meegenomen_in_review, gearchiveerd
  - Validate: tekst must be 1-1000 characters
  - Validate: type must be one of "kudos", "constructief", "feedback_vraag_antwoord"
- [ ] Create `POST /api/continuousfeedback` endpoint
  - Accept feedback object
  - Return 201 with feedback record
  - Notify recipient (internal task)
- [ ] Create `GET /api/continuousfeedback/received/{employee_id}` endpoint
  - Return feedback received by employee, filtered by visibility
  - Self sees all ("alleen_ontvanger" + "ook_manager" + "team_public")
  - Manager sees feedback intended for them to see
  - Peer sees only "team_public"
- [ ] Create `GET /api/continuousfeedback/aggregate/{cyclus_id}/{employee_id}` endpoint
  - Return aggregated stats: kudos count, feedback-question count, competency tags
  - NO personal feedback texts (privacy)
- [ ] Add unit tests (4+ cases: create, visibility filtering, aggregate without exposing texts, permission checks)

---

## Phase 2: Calibration Session Infrastructure (Week 2-3)

### 2.1 Create OpenRegister schema for KalibratieSessie
- [ ] Define `KalibratieSessie` entity in OpenRegister
  - Fields: sessie_id, cyclus_ref, scope, scope_ref, deelnemers_refs, facilitator_ref, geplande_datum, geplande_duur_minuten, agenda_subject_refs, kalibratie_log (JSON array), besluiten_summary, distributie_snapshot (JSON), gesloten, gesloten_op, gesloten_door
  - Validate: scope_ref required for team/bu scope
  - Validate: kalibratie_log entries have all required fields (timestamp, subject_ref, vor/na axes, onderbouwing, besluit_type)
- [ ] Create `POST /api/kalibratie/sessie` endpoint
  - Accept session object with deelnemers_refs, scope, agenda_subject_refs
  - Send calendar invites to deelnemers
  - Return 201 with session
  - Validate only HR-BP can create
- [ ] Create `GET /api/kalibratie/sessie/{id}` endpoint
  - Return session with kalibratie_log (full audit trail)
  - Only deelnemers + facilitator can view
- [ ] Create `GET /api/kalibratie/sessie/{id}/matrix` endpoint
  - Return pre-populated 9-box matrix (assessments for agenda_subjects)
  - Anonymize employee identities in response (show "Emp-A", department only)
  - Return with visual layout data (cell counts, positions)
- [ ] Create `POST /api/kalibratie/sessie/{id}/decision` endpoint
  - Accept assessment change: {subject_ref, performance_before, performance_after, potential_before, potential_after, onderbouwing, besluit_type, dissenters_refs}
  - Validate gesloten = false
  - Log change to kalibratie_log
  - Do NOT update NineBoxAssessment yet (only on session close)
  - Return 201 with logged decision
- [ ] Create `POST /api/kalibratie/sessie/{id}/close` endpoint
  - Validate gesloten = false
  - For each entry in kalibratie_log:
    - If performance or potential changed (before != after), update corresponding NineBoxAssessment
    - Set `aangepast_na_kalibratie = true`
  - Lock kalibratie_log (no further edits)
  - Set `gesloten = true, gesloten_op = now, gesloten_door = request.user`
  - Return 200 with final session state
- [ ] Add unit tests (6+ cases: create session, log decisions, distribution snapshot calculation, close with sync, permission checks, prevent edits after close)

### 2.2 Distribution Monitoring UI & API
- [ ] Create `GET /api/kalibratie/sessie/{id}/distribution` endpoint
  - Return 9×9 heatmap with current counts
  - Include target ranges (from admin config)
  - Return visual deviation indicators (color coding)
- [ ] Create `GET /api/kalibratie/sessie/{id}/recommendations` endpoint
  - Identify borderline assessments (confidence < 70% between two cells)
  - Identify outliers (assessment far from peer group in same function)
  - Return list with reasons + suggested actions
  - Return 200 with array of recommendations
- [ ] Add unit tests (2+ cases: distribution calculation, recommendation logic)

---

## Phase 3: OKR Progress & Scoring (Week 3-4)

### 3.1 OKR Progress Tracking API
- [ ] Create `POST /api/okr/{id}/progress` endpoint
  - Accept: {current_value, confidence_score (1-10), notes}
  - Validate: all key_results get updates at once (batch)
  - Store changelog entry
  - If confidence < 5, create task for manager: "Check-in needed on OKR X"
  - Return 200 with updated OKR
- [ ] Create `GET /api/okr/{id}/progress-history` endpoint
  - Return changelog of all updates with timestamps
  - Include: who updated, old/new values, notes
- [ ] Create biweekly progress trigger (scheduler task)
  - Every 2 weeks (configurable day), find active OKRs
  - Create tasks for all employees: "Update progress on 3 Q2 OKRs"
  - Link to progress-entry form
- [ ] Add unit tests (3+ cases: update with validation, confidence trigger, progress history)

### 3.2 OKR Completion Scoring
- [ ] Implement eindscore calculation logic
  - For each key_result: `eindscore = min(current_value / target, 1.0)`
  - For OKR: average of its key_results' einscore
  - Store eindscore when OKR marked as final
- [ ] Create `POST /api/okr/{id}/finalize` endpoint
  - Calculate eindscore (all key_results must have current_value)
  - Classify: succesvol | leerzaam_gefaald | te_ambitieus
  - Set status = "gesloten"
  - Return 200 with finalized OKR + score + classification
- [ ] Create `GET /api/okr/cycles/{cyclus_id}/scorecard` endpoint
  - Return all OKRs in cycle with their einscore, classification, status
  - Aggregate by owner and niveau
  - Include: count of successful, learning, overambitious
- [ ] Add unit tests (4+ cases: eindscore calculation, classification, aggregate scorecard, finalization validation)

---

## Phase 4: Reward Link & Evidence Bundling (Week 4)

### 4.1 Create OpenRegister schema for RewardLink
- [ ] Define `RewardLink` entity in OpenRegister
  - Fields: reward_id, cyclus_ref, subject_ref, manager_ref, bonus_voorstel_bedrag, bonus_voorstel_percentage, promotie_voorstel, promotie_voorstel_rol_ref, promotie_voorstel_datum, salarisaanpassing_voorstel, onderbouwing_referenties (JSON), besloten_door, besluit_status, effectuering_datum, aangemaakt_op, gewijzigd_op
  - Validate: at least one reward type (bonus/promotion/raise) is set
  - Validate: onderbouwing_referenties contains at least one evidence item
- [ ] Create `POST /api/rewardlink` endpoint
  - Accept reward proposal from manager
  - Return 201 with proposal (status = "voorstel")
  - Notify HR-BP/Reward Committee
- [ ] Create `GET /api/rewardlink/cycles/{cyclus_id}/proposals` endpoint
  - Return all reward proposals in cycle (for HR-BP, Reward Committee)
  - Filter by status, employee, manager
- [ ] Create `PATCH /api/rewardlink/{id}` endpoint
  - Allow HR-BP/Reward Committee to update proposal
  - Track who changed what
  - Change status: voorstel → goedgekeurd | afgewezen | gewijzigd
- [ ] Add unit tests (4+ cases: create with validation, evidence aggregation, status transitions, permissions)

### 4.2 Evidence Bundling API
- [ ] Create `GET /api/rewardlink/{id}/evidence-bundle` endpoint
  - Pre-populate onderbouwing_referenties by querying:
    - OKRs finalized for subject in this cycle (get eindscore_avg)
    - Most recent NineBoxAssessment for subject (get talent_segment)
    - Calibration session (if subject's assessment was changed, get session_ref + log excerpt)
    - Continuous feedback aggregates (get kudos_count, linked feedback)
  - Return bundle as structured JSON for form pre-fill
  - Return 200 with bundle
- [ ] Create `POST /api/rewardlink/{id}/generate-summary` endpoint
  - Generate natural-language summary of evidence
  - Example: "High performer (OKR avg 0.92, 9-box star), received 7 kudos, key contributor to [project]"
  - Return summary as suggested text for onderbouwing
- [ ] Add unit tests (2+ cases: evidence aggregation completeness, summary generation)

---

## Phase 5: Authorization & Privacy (Week 5)

### 5.1 GDPR & Data Privacy Implementation
- [ ] Implement 9-box access control decorator
  - Create `@requiresNineBoxAccess(scope)` PHPDoc attribute
  - Prevents returning 9-box data to non-authorized roles
  - Returns 403 (not 404) for unauthorized attempts
  - All 403s logged to audit trail with timestamp, user, resource
- [ ] Create DPIA acknowledgment workflow
  - First time 9-box is enabled: show checklist, require admin sign-off
  - Store acknowledgment with timestamp, admin identity
  - Banner for 90 days
- [ ] Implement automated ex-employee assessment deletion
  - Cron job: every week, find NineBoxAssessments for employees with status = "departed", older than 24 months
  - Log scheduled deletion
  - Send admin warning email (20 days before)
  - On due date: soft-delete or anonymize (per tenant config)
  - Log completion with count
- [ ] Create GDPR DSR exclusion for 9-box
  - Implement "Data about me" export endpoint
  - Return all employee data EXCEPT 9-box (unless requesting user is assessor)
  - Log DSR requests
- [ ] Add unit tests (5+ cases: 403 for unauthorized reads, DPIA prompt, ex-employee deletion scheduling, DSR exclusion, audit logging)

### 5.2 Audit Logging
- [ ] Create audit-log entries for:
  - Every read of NineBoxAssessment (user, timestamp, resource)
  - Every write to NineBoxAssessment (user, old/new values, timestamp)
  - Every KalibratieSessie decision (auto-logged in kalibratie_log)
  - Every RewardLink proposal/approval (auto-logged in patch history)
- [ ] Create `GET /api/audit/9box-access` endpoint
  - Return access log for 9-box data (for GDPR compliance)
  - Filterable by date, user, employee
- [ ] Add unit tests (2+ cases: all mutations logged, audit endpoint returns expected format)

---

## Phase 6: Frontend - OKR Views (Week 5-6)

### 6.1 OKR Cascade Tree Component
- [ ] Create Vue component: `OKRCascadeTree.vue`
  - Fetch cycle with nested OKRs via API
  - Render tree with expand/collapse per level
  - Show completion % per node
  - Highlight orphan OKRs (red warning icon)
  - Click to open OKR detail modal
  - Responsive layout (works on mobile)
- [ ] Create accompanying tests (3+ test cases: tree rendering, expand/collapse, orphan highlighting)

### 6.2 OKR Detail & Edit Form
- [ ] Create Vue component: `OKRDetailForm.vue`
  - Fetch OKR by ID
  - Render:
    - Title, description (editable for owner/manager)
    - Parent OKR selector (for cascade linking)
    - Key results table (1-5 rows, each with: title, baseline, target, type, current_value, unit)
    - Confidence slider (1-10)
    - Progress history panel (changelog view)
  - On save, submit to `PATCH /api/okr/{id}`
  - Validate before submit (target > baseline, etc.)
- [ ] Create tests (3+ cases: form rendering, validation, save, permission checks)

### 6.3 Biweekly Progress Entry
- [ ] Create Vue component: `OKRProgressCheckIn.vue`
  - Fetch all active OKRs for current employee
  - Render form with:
    - OKR title + description (read-only)
    - Current value input (pre-filled from last update)
    - Confidence slider (1-10)
    - Notes textarea
    - Submit button
  - On submit, call `POST /api/okr/{id}/progress`
  - If any confidence < 5, show warning: "Manager will be notified for check-in"
  - Success message + redirect to list
- [ ] Create tests (3+ cases: form rendering, confidence trigger warning, success/error flows)

### 6.4 OKR Scorecard View
- [ ] Create Vue component: `OKRScorecard.vue`
  - Fetch cycle scorecard via `GET /api/okr/cycles/{id}/scorecard`
  - Render:
    - Summary: X successful, Y learning, Z overambitious
    - Grouped by owner (manager first, then their team)
    - Each row shows: OKR title, status, eindscore, classification, actions (view detail, print)
    - Drill-down: click row to see key results + individual scores
  - Export as CSV/PDF button
- [ ] Create tests (2+ cases: scorecard data rendering, drill-down navigation)

---

## Phase 7: Frontend - 9-Box Views (Week 6-7)

### 7.1 9-Box Assessment Form
- [ ] Create Vue component: `NineBoxAssessmentForm.vue`
  - Render two large 3-point scales (side-by-side):
    - Performance (Low | Medium | High)
    - Potential (Low | Medium | High)
  - Below each scale: required textarea (min 200 chars)
    - "Why this performance rating?"
    - "Why this potential rating?"
  - Optional section: link related feedback/OKRs (multi-select)
  - Validation: both axes selected + 200+ chars each
  - On submit, call `POST /api/nineboxassessment`
  - Success message + return to employee list
- [ ] Create tests (4+ cases: form rendering, validation, character count enforcement, success/error flows)

### 7.2 9-Box Matrix View (Manager)
- [ ] Create Vue component: `NineBoxMatrix.vue`
  - Fetch assessments for manager's direct reports (from API)
  - Render 3×3 heatmap grid
  - Each cell shows count + (on hover) employee names + avatars
  - Color code cells by talent segment
  - Click employee name to open assessment detail modal
  - Export matrix as image/PDF
- [ ] Create tests (3+ cases: matrix rendering, cell population, click navigation)

### 7.3 Prevent Employee View of 9-Box
- [ ] In `EmployeeProfile.vue` (or detail-tab component):
  - Check for NineBoxAssessment data in API response
  - If present and requester is the subject (not manager/HR-BP), do NOT render
  - Log the check (so no 9-box is ever shown to employees)
- [ ] Create tests (2+ cases: manager can see 9-box, employee cannot)

---

## Phase 8: Frontend - Calibration Views (Week 7-8)

### 8.1 Calibration Session Setup
- [ ] Create Vue component: `KalibratieSessieForm.vue`
  - Form fields:
    - Scope (Team | Business Unit | Function)
    - Scope selector (dropdown to pick team/BU/function)
    - Date + time
    - Duration (minutes)
    - Deelnemers (multi-select of managers)
    - Agenda employees (multi-select of subject employees)
  - On submit, call `POST /api/kalibratie/sessie`
  - Send calendar invites (internal task)
  - Success message + redirect to session detail
- [ ] Create tests (2+ cases: form rendering, submit with validation)

### 8.2 Calibration Matrix (Live View)
- [ ] Create Vue component: `KalibratieSessieMatrix.vue`
  - Fetch session + matrix via `GET /api/kalibratie/sessie/{id}/matrix`
  - Render:
    - 3×3 grid with current assessment positions
    - Each cell anonymized (Emp-A, Emp-B, etc., dept only)
    - Cell counts + target range comparison (color coding)
    - Click employee to open calibration decision modal
  - Facilitator can edit assessments in real-time (modal)
  - Save each decision via `POST /api/kalibratie/sessie/{id}/decision`
  - After close, lock UI (read-only mode)
- [ ] Create tests (4+ cases: matrix rendering, target range highlighting, decision modal, lock on close)

### 8.3 Calibration Decision Modal
- [ ] Create Vue component: `KalibratieSessieDecision.vue`
  - Modal opened from matrix click
  - Display:
    - Employee name + department + role + tenure
    - Current assessment (performance, potential, segment, justifications)
    - Linked feedback + OKR scores (read-only)
  - Facilitator can:
    - Edit axes (drag between cells)
    - Record why (onderbouwing text field)
    - Select decision type (Consensus | Facilitator Override)
    - If override, add dissenters list (checkboxes of deelnemers)
  - Submit → call `POST /api/kalibratie/sessie/{id}/decision`
  - Close modal + return to matrix
- [ ] Create tests (3+ cases: modal rendering, decision logging, dissenters tracking)

### 8.4 Distribution Monitoring Panel
- [ ] Create Vue component: `KalibratieSessieDistribution.vue`
  - Fetch distribution via `GET /api/kalibratie/sessie/{id}/distribution`
  - Render:
    - Current distribution (cell counts) vs. target range
    - Visual heatmap (green = within range, orange = deviation)
    - Cell labels (Star, Core Player, etc.)
    - Recommendations panel: "Click to discuss emp-xyz — borderline between medium and high potential"
  - Recommendations fetched via `GET /api/kalibratie/sessie/{id}/recommendations`
- [ ] Create tests (2+ cases: distribution rendering, recommendation highlighting)

---

## Phase 9: Frontend - Continuous Feedback & Reward (Week 8-9)

### 9.1 Feedback Entry Widget
- [ ] Create Vue component: `ContinuousFeedbackForm.vue`
  - Simple form:
    - Recipient (autocomplete search)
    - Type (radio: Kudos | Constructive | Question)
    - Text (textarea, 1-1000 chars)
    - Link to OKR (optional multi-select)
    - Link to competency (optional)
    - Visibility (radio: Private | Manager Sees | Team Sees)
  - On submit, call `POST /api/continuousfeedback`
  - Success message, optionally send another
- [ ] Create tests (3+ cases: form rendering, validation, submit)

### 9.2 Feedback Feed View
- [ ] Create Vue component: `FeedbackFeed.vue`
  - Fetch feedback received via `GET /api/continuousfeedback/received/{emp_id}`
  - Render:
    - List of feedback items
    - Show giver (name/avatar), type, date, text
    - Filter by type (Kudos | Constructive | Question)
    - Group by month (reverse chronological)
  - Visibility: show only what user is allowed to see
- [ ] Create tests (2+ cases: feed rendering, visibility filtering)

### 9.3 Reward Proposal Form
- [ ] Create Vue component: `RewardLinkForm.vue`
  - Fetch employee + evidence bundle via `GET /api/rewardlink/{id}/evidence-bundle`
  - Render:
    - Employee summary (name, role, manager, 9-box segment, OKR avg, feedback agg)
    - Proposed reward options (checkboxes):
      - [ ] Bonus: __% or __€
      - [ ] Promotion: [role selector] effective [date]
      - [ ] Salary increase: __% or __€
    - Onderbouwing textarea (with suggested text generator button)
    - Submit → `POST /api/rewardlink`
  - Success message + notification to HR-BP
- [ ] Create tests (3+ cases: form rendering, evidence pre-fill, submit)

### 9.4 Reward Proposals List (HR-BP)
- [ ] Create Vue component: `RewardProposalsList.vue`
  - Fetch proposals via `GET /api/rewardlink/cycles/{id}/proposals`
  - Render:
    - Table: Employee | Proposed Bonus | Promotion | Raise | Status | Manager | Actions
    - Filter by status (Proposal | Approved | Rejected | Modified)
    - Click row to open detail/edit modal
    - Bulk approve/reject (if configured)
  - Export as CSV/Excel
- [ ] Create tests (2+ cases: list rendering, status filtering, export)

---

## Phase 10: Documentation & Testing (Week 9-10)

### 10.1 API Documentation
- [ ] Document all endpoints in OpenAPI/Swagger format
  - Include: method, path, request body, response (200/400/403/409), auth required
  - Add examples for each endpoint
- [ ] Generate HTML docs (via Swagger UI or similar)
- [ ] Add to app README under "Performance Management APIs"

### 10.2 Feature Documentation
- [ ] Write user guide (Dutch + English):
  - "Setting up your first OKR cycle"
  - "Running a calibration session"
  - "Requesting and giving feedback"
  - "Preparing reward proposals"
- [ ] Add to docs/ folder + link from README
- [ ] Screenshot guide for key flows

### 10.3 Test Coverage
- [ ] Run coverage report: `npm run test -- --coverage`
  - Target: >= 80% overall, >= 90% for critical paths (9-box access control, calibration log, data validation)
- [ ] Fix any gaps
- [ ] Document coverage in TESTING.md

### 10.4 End-to-End Testing
- [ ] Create e2e test suite (e.g., Cypress or Playwright):
  - Scenario 1: Manager creates OKRs, employees update progress, cycle closes with scoring
  - Scenario 2: Calibration session (facilitator + 3 managers) with 6 employees, distribution check, session close
  - Scenario 3: Feedback flow (give kudos, receive feedback, verify aggregation in reward evidence)
  - Scenario 4: Reward proposal (manager proposes, HR-BP approves, data export)
- [ ] Run e2e suite before release
- [ ] Document in e2e/ folder

---

## Phase 11: GDPR & Compliance Audit (Week 10-11)

### 11.1 DPIA Review
- [ ] Prepare Data Protection Impact Assessment for 9-box feature
  - Risk analysis: potential-rating as employee monitoring
  - Mitigation: access control, audit logging, retention limits
  - Document: DPIA-performance-management.md (store in docs/)
  - Review with legal/privacy team
  - Tenant admins must acknowledge before using 9-box

### 11.2 Data Retention & Deletion
- [ ] Verify automated deletion job works:
  - Test: create NineBoxAssessment for ex-employee, set departed_date to 25 months ago
  - Run cron job manually
  - Verify record is deleted or anonymized
  - Log entry created
- [ ] Document retention policy in Privacy FAQ (docs/PRIVACY.md)

### 11.3 Audit Trail Verification
- [ ] Spot-check audit logs:
  - Query `audit_log` for all 9-box reads over past week
  - Verify format: {timestamp, viewer, resource, action}
  - Verify no sensitive data leaked
- [ ] Generate sample audit report (CSV) for admin review

---

## Phase 12: Pilot & QA (Week 11-12)

### 12.1 Pilot Deployment
- [ ] Deploy to staging with 2-3 customer tenants (volunteers)
- [ ] Brief pilot tenants:
  - "Pilot period: 4 weeks"
  - "Feedback required on usability, feature completeness"
  - "Known limitations: [list any]"
- [ ] Monitor for errors/bugs
- [ ] Collect feedback via survey + 1-on-1 interviews

### 12.2 Bug Triage & Fixes
- [ ] Triage pilot feedback
  - Critical bugs (data loss, auth bypass): fix immediately
  - UX issues: evaluate for next sprint
  - Feature requests: backlog for future
- [ ] Create follow-up PRs for critical fixes

### 12.3 Load Testing
- [ ] Test calibration matrix with 500 employees (1 session)
  - API response time < 2s for matrix fetch
  - UI renders smoothly
- [ ] Test OKR list with 5000 OKRs in a cycle
  - List endpoint returns paginated (50 per page)
  - Filter/search < 1s
- [ ] Document performance baselines in PERFORMANCE.md

---

## Phase 13: Release Prep & GA (Week 12-13)

### 13.1 Release Notes
- [ ] Write release notes (Dutch + English):
  - New features summary
  - Known limitations
  - Upgrade path (if applicable)
  - Support contacts
- [ ] Post to app changelog / release page

### 13.2 Rollout Plan
- [ ] Create phased rollout plan:
  - Week 1: Early-adopter tenants (10-20 customers)
  - Week 2: Mid-market tenants (100+ more)
  - Week 3: General availability
- [ ] Define rollback procedure (if needed)
- [ ] Identify on-call support contact for first week

### 13.3 GA Release
- [ ] Merge all PRs to main branch
- [ ] Tag release (e.g., `v1.0.0-performance-mgmt`)
- [ ] Deploy to production
- [ ] Monitor error rates + performance metrics for 24 hours
- [ ] Post GA announcement

---

## Phase 14: Post-Release Monitoring (Week 13+)

### 14.1 Production Monitoring
- [ ] Monitor metrics:
  - API error rates (target: < 0.1%)
  - Response times (p95 < 2s)
  - OKR/calibration/reward feature usage (adoption %)
- [ ] Weekly check-ins with pilot tenants
- [ ] Capture bugs/feature requests in backlog

### 14.2 Docs & Support
- [ ] Respond to support tickets (SLA: <4 hours)
- [ ] Update FAQ based on common questions
- [ ] Create video tutorials (top 3 flows)

---

## Cross-Cutting Concerns (All Phases)

### Security
- [ ] All API endpoints require authentication (Nextcloud auth)
- [ ] RBAC enforcement: no user can access data outside their scope
- [ ] Input validation: all API inputs sanitized (no SQL injection, XSS, etc.)
- [ ] Rate limiting: 100 req/min per tenant

### Internationalization (Dutch + English)
- [ ] All UI text in `translations.json` or i18n strings
- [ ] Date/number formatting by locale
- [ ] Email templates in both languages

### Code Quality
- [ ] PHP: PSR-12 style, no warnings
- [ ] Vue: ESLint compliant, no warnings
- [ ] Test: run `npm run test` before each commit
- [ ] Pre-commit hook: linter + type check (if TypeScript)

### Performance
- [ ] Database queries: add indices on frequently filtered columns (cyclus_ref, subject_ref, status)
- [ ] API endpoints: pagination for lists >50 items
- [ ] Frontend: lazy-load heavy components (matrix, tree)
- [ ] Caching: consider Redis for aggregated stats (OKR scorecard, distribution)

### Monitoring & Observability
- [ ] Log all API calls (request, response, latency, user)
- [ ] Log all data mutations (who changed what, when)
- [ ] Dashboards: error rate, latency, feature usage
- [ ] Alerts: error spike (>0.5%), timeout rate >5%

