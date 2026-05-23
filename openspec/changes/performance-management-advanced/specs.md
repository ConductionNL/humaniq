---
schema: spec-driven
change: performance-management-advanced
version: 1.0
date: 2026-05-23
---

# Specifications: Performance Management Advanced

## REQ-001: OKR Cascade from Company Strategy to Individual

**GIVEN** an organization has defined company-level OKRs for a performance cycle (e.g., Q2 2026)  
**WHEN** a business-unit or team leader creates a team-level OKR  
**THEN** they can:
1. Select a parent company OKR via dropdown
2. Set their team-level objective to support the parent
3. Optionally link individual OKRs to this team OKR
4. The system visualizes the full cascade tree (company → BU → team → individual)
5. The system flags "orphan" OKRs (no parent link) with a governance warning for the HR-BP

**Also:**
- Cascade visualization shows completion % of children (e.g., "3 of 5 team members have linked OKRs")
- Users can navigate up/down the tree without leaving the view
- Export cascade as PDF or Visio diagram for strategy alignment meetings

---

## REQ-002: OKR Progress Updates (Biweekly Check-ins)

**GIVEN** an employee has 3-5 active OKRs for the current cycle  
**WHEN** the biweekly progress window opens (configurable day/time)  
**THEN**:
1. Employee receives a task in their task list: "Update progress on 3 Q2 OKRs"
2. Form prompts for each Key Result:
   - Current value (numeric input, auto-prefilled with previous value)
   - Confidence score (1-10 slider, with labels: "Very confident" to "At risk")
   - Optional notes ("On track", "Blocked by...", etc.)
3. Employee submits all at once
4. System validates: all KRs have a current_value and confidence_score
5. If confidence_score drops below 5, the manager's task list includes a check-in prompt: "emp-xyz's Q2 OKR 'launch feature' has low confidence (4/10) — consider a 1-on-1"

**Also:**
- Employees can add ad-hoc progress notes between check-in windows
- Manager can request immediate progress update (out-of-cycle)
- System stores a changelog: {date, old_value, new_value, notes}

---

## REQ-003: OKR Completion Score ≠ Compensation Trigger

**GIVEN** a performance cycle ends (all OKRs are marked as final)  
**WHEN** the system calculates completion scores  
**THEN**:
1. For each Key Result:
   - `eindscore = min(current_value / target, 1.0)` (capped at 1.0)
   - Example: target 100 customers, achieved 95 → eindscore 0.95
2. For each OKR, average its Key Results' einscore
3. Classify the OKR:
   - `succesvol` if 0.7 ≤ eindscore ≤ 1.0
   - `leerzaam_gefaald` if 0.4 ≤ eindscore < 0.7
   - `te_ambitieus` if eindscore < 0.4
4. These classifications appear in the employee's performance record as evidence
5. **Critically**: The classification is NOT automatically converted to a bonus multiplier or rating
   - Example: "high-performer gets 1.2× bonus" is NOT allowed in this system
   - Instead, OKR scores are cited in `RewardLink.onderbouwing_referenties` for manual review

**Also:**
- OKR scores are visible to the employee (for their own reflection)
- Manager can add commentary: "This was blocked by X external factor"
- Year-end summary aggregates all quarters (e.g., "avg eindscore across 4 cycles = 0.82")

---

## REQ-004: 9-box Assessment with Dual-Axis Input

**GIVEN** a manager opens the 9-box assessment form for a direct report  
**WHEN** they fill in the assessment  
**THEN**:
1. Form presents two large 3-point scales side-by-side:
   - **Performance axis** (left): "Low | Medium | High" (based on last 12 months of work)
   - **Potential axis** (right): "Low | Medium | High" (manager judgment on growth trajectory 2-3 years)
2. Below each axis is a required text field (min 200 characters):
   - "Why this performance rating?" (examples: productivity, quality, collaboration)
   - "Why this potential rating?" (examples: learning speed, growth trajectory, readiness for next level)
3. Manager can also optionally link related:
   - Continuous feedback records (e.g., peer kudos)
   - Recent OKR scores from this cycle
4. Submit validation:
   - Both axes selected: ✓
   - Both text fields >= 200 chars: ✓
   - At least one supporting evidence (OKR or feedback): recommended (warning, not hard-block)
5. On submit, system auto-derives `talent_segment`:
   - High perf + High potential = `star`
   - High perf + Medium/Low potential = `steady_performer`
   - Medium perf + High potential = `high_potential_developing`
   - Medium perf + Medium potential = `emerging`
   - Low perf + Any potential = `underperformer`

**Also:**
- Assessment persists in `draft` state until manager clicks "Finalize"
- Manager can "Save as template" for reuse on similar team members (text not copied, just axis defaults)
- Bulk-import from 360-degree tool or prior-year assessments (with diff view for changes)

---

## REQ-005: 9-box Visibility Strictly Manager-and-Above

**GIVEN** an employee logs into hrmq and views their own employee profile  
**WHEN** they navigate to the "Functie & comp" detail tab  
**THEN**:
1. They see their own performance cycle overview (OKRs, feedback received, review status)
2. They do NOT see any 9-box cell, axis rating, or talent segment (not even "high potential")
3. If they somehow request the API endpoint `/api/nineboxassessment/{employee_id}` where `employee_id` is themselves:
   - The system returns 403 Forbidden (not 404, which would leak information)
   - Audit log records the unauthorized access attempt

**If a manager views their direct report's profile:**
- They see the 9-box assessment with both axes and justifications (if one has been finalized)

**If an HR-BP views any employee:**
- They see 9-box assessments for employees in their assigned scope (by team/BU/org)

**If an ExCo member views reports:**
- They see 9-box summary dashboards (aggregated counts per segment, not individual assessments, unless `confidential_level = talent_board_also`)

**Also:**
- Every read of a 9-box record is logged: {timestamp, viewer, employee, reason/context}
- GDPR DSR: Employee can request "all data about me" — system EXPLICITLY EXCLUDES 9-box unless they're an assessor
- Audit dashboard shows "9-box access" as a discrete log stream

---

## REQ-006: Calibration Session as Governance Event

**GIVEN** an HR-BP initiates a calibration session for a team of 20 employees  
**WHEN** the session is scheduled  
**THEN**:
1. System sends calendar invites to all managers in scope (with 2-week notice)
2. Pre-calibration: managers fill in 9-box assessments (individually, without seeing others' answers)
3. During session:
   - HR-BP (facilitator) loads the "Calibration Matrix" view
   - Shows all 20 pre-calibration assessments in a 3×3 grid (anonymized: "Emp-A", "Emp-B" with department only)
   - System shows:
     - Each person's name, tenure, prior-cycle position
     - Both managers' 9-box inputs (if assessed by multiple = rare, but support conflict resolution)
     - Confidence % (agreement between assessors on that cell)
4. Facilitator can edit assessments in real-time
   - Clicking an employee brings up a modal: current 9-box + justifications + feedback feed
   - Facilitator and managers discuss
   - Changes are NOT immediate — facilitator proposes changes, logs debate
5. After discussion, facilitator records the final decision:
   - **Consensus**: All agree on the axes → marked as `consensus`
   - **Facilitator override**: Manager(s) disagree, facilitator decides → marked as `facilitator_overrule` with dissenters list
   - **No change**: Axes stay as originally assessed → `no_change`
6. Every change creates a `kalibratie_log` entry:
   ```
   {
     timestamp: 2026-06-20T10:30,
     subject: emp-xyz,
     voor: "medium/high",
     na: "high/high",
     onderbouwing: "Q1 onboarding led to confusion; revised to reflect Q2 project success.",
     besluit_type: "consensus",
     dissenters: []
   }
   ```
7. After all 20 assessments are reviewed, facilitator clicks "Close Session"
   - System updates all 20 `NineBoxAssessment` records with `aangepast_na_kalibratie = true`
   - Kalibratie_log is locked (read-only)

**Also:**
- Calibration sessions for large orgs (100+ people) can be split by function/department (multiple sessions, linked by cycle)
- Managers can request a re-discussion of a specific assessment before session closes
- Session can be "paused" and resumed over 2 days (with auto-lock if >1 week passes)

---

## REQ-007: Calibration Distribution Check (Optional Guidance)

**GIVEN** a calibration session is underway with 20 employees  
**WHEN** the facilitator views the live matrix  
**THEN**:
1. System shows the 9-box grid with current counts per cell (e.g., "5 in high-high, 8 in high-medium, etc.")
2. Admin-configurable "target ranges" are displayed (default: 10-20% stars, 40-60% core, <10% risks)
3. If current distribution deviates, system visually highlights:
   - Too many stars? Heatmap cell turns orange
   - Too few core-players? Heatmap cell turns blue
   - No forced action — purely advisory
4. Facilitator can click "View recommendations" → system suggests candidates for re-assessment based on:
   - Borderline confidence scores (e.g., "emp-xyz is rated 'high potential' by 1 manager, 'medium' by another")
   - Outliers vs. peer group in same function
5. Facilitator can dismiss suggestions or use them to trigger discussion

**Also:**
- "Forced distribution" option: If enabled (admin setting), system prevents closing session until within range (default: OFF)
- Export distribution snapshot as CSV for post-session analysis

---

## REQ-008: Continuous Feedback as Cycle Evidence

**GIVEN** employees are in an active OKR cycle  
**WHEN** an employee wants to give feedback to a peer  
**THEN**:
1. Feedback form appears with:
   - Recipient (autocomplete search)
   - Type (Kudos / Constructive / Feedback question)
   - Text (free-form, max 1000 chars)
   - Optional: Link to an OKR or competency
   - Visibility (Private to recipient / Also to recipient's manager / Team-public)
2. Submit → feedback is stored immediately (no approval workflow)
3. Recipient gets a notification: "Alice gave you kudos: 'Great code review on the API...'"
4. Recipient can respond to questions
5. At end of cycle, system auto-aggregates:
   - Kudos count (total, by type)
   - Feedback items linked to OKRs (evidence of impact)
   - Feedback linked to competencies (evidence of behavior)
6. Aggregation appears in `RewardLink.onderbouwing_referenties` as:
   - "Received 7 kudos this quarter; 2 related to teamwork competency"
   - Actual feedback texts are never exposed — only counts and aggregates (privacy)

**Also:**
- Employees can request feedback: "Please share feedback on my Project X leadership"
  - System sends structured prompts to 3-5 nominated peers
  - Responses are anonymous (recipient doesn't know who said what)
  - Aggregated as evidence
- Feedback can be marked as "meegenomen_in_review" (linked to cycle) or left private
- Soft-delete (archive) feedback older than 24 months

---

## REQ-009: Reward Link as Evidence-Backed Proposal

**GIVEN** a performance cycle closes and calibration is done  
**WHEN** the manager prepares a compensation decision for an employee  
**THEN**:
1. System opens `RewardLink` form with pre-populated evidence:
   - Employee's average OKR eindscore: 0.88
   - 9-box position: "High performer, High potential" (star)
   - Calibration outcome: "Rating confirmed after discussion; mentioned as succession candidate"
   - Continuous feedback: "Received 6 kudos; 2 related to communication, 1 to technical excellence"
2. Manager proposes:
   - Bonus: 12.5% of salary
   - Promotion: Yes → Senior Engineer role effective 2026-07-01
   - Salary increase: 8.5%
3. Manager provides written rationale (text field, min 100 chars):
   - "Strong technical contributor with growth trajectory toward tech lead. Q2 OKRs exceeded target. 9-box 'star' confirms readiness."
4. Submit → RewardLink enters `voorstel` status
5. HR-BP receives task: "Review comp proposal for emp-xyz"
   - Can approve, reject, or propose counter-offer
   - If counter: HR-BP records why (e.g., "Budget constraint: approve bonus only, defer raise to Q4")
6. Reward Committee (if configured) reviews and approves
7. Once approved, system schedules effectiveness (e.g., "effective 2026-07-01")

**Critically:**
- The system does NOT enforce "star = 20% bonus"
- There is no automatic formula
- Every proposal is case-by-case reasoning with explicit evidence
- Managers who propose bonuses significantly out of line with evidence receive a coaching prompt: "Your proposal differs from peer benchmarks — consider adjusting or providing additional context"

**Also:**
- Compare mode: View all proposals for a team at once, spot inconsistencies
- Export for finance/payroll: "Approved proposals to take effect 2026-07-01"
- Audit trail: Every change to a RewardLink is logged with timestamp, who changed it, what changed

---

## REQ-010: GDPR Retention Limits and DPIA Requirement

**GIVEN** a tenant enables the 9-box assessment feature for the first time  
**WHEN** the feature activates  
**THEN**:
1. System displays a DPIA checklist:
   - [ ] Have you performed a DPIA per Article 35 AVG for this form of employee monitoring?
   - [ ] Have you documented legal basis and retention policy?
   - [ ] Have you briefed employees on what 9-box assessments mean for their data?
2. Tenant admin must acknowledge: "Yes, DPIA is complete and on file"
3. System stores this acknowledgment with timestamp
4. Banner appears for 90 days: "9-box feature is active — ensure DPIA compliance is maintained"

**For data retention:**
- 9-box assessments for active employees: keep indefinitely (or until employee's data-retention policy)
- 9-box assessments for ex-employees (status = "departed"): 
  - Retained 24 months (for potential legal claims, succession analysis)
  - After 24 months: auto-anonymize (remove name, replace with hash) or soft-delete
  - System sends HR admin a warning 20 days before deletion: "10 ex-employee assessments scheduled for deletion on 2027-06-30"
  - Admin can extend if needed (e.g., "litigation hold")

**For audit logging:**
- Every read of a 9-box assessment is logged: {timestamp, viewer, employee_subject, reason}
- Separate "9-box access" report in audit dashboard
- GDPR DSR: Employee can request "all data about me" — system excludes 9-box data (unless they are an assessor or HR-role)

**Also:**
- Export compliance: "GDPR-Ready Export" excludes 9-box by default
- Data masking: Reports can show aggregate stats (e.g., "40% of Engineering team rated as 'core-player'") without individual names

---

## REQ-011: OKR Confidence Score Triggers Manager Check-in

**GIVEN** an employee's biweekly OKR update includes a confidence score < 5  
**WHEN** the update is submitted  
**THEN**:
1. System creates a task in manager's inbox: "emp-xyz's OKR 'X' has low confidence (3/10) — schedule check-in"
2. Task includes:
   - Employee's OKR title and current status
   - Notes from the update (e.g., "Blocked by vendor API delay")
   - Linked 1-on-1 meeting template: "OKR Check-in"
3. Manager marks task as "Scheduled 1-on-1" or "Not needed" (with reasoning)

**Also:**
- If low confidence persists for >2 check-ins, escalate to HR-BP: "emp-xyz's OKR appears stalled"
- Manager can proactively lower a confidence score for peer's OKR if shared ownership (system notifies peer)

---

## REQ-012: Bulk Assessment via 360 Import

**GIVEN** an organization has external 360-degree feedback data (CSV or API)  
**WHEN** HR admin imports the file  
**THEN**:
1. System maps import columns to 9-box axes (performance, potential, comments)
2. Creates draft `NineBoxAssessment` records (not yet finalized)
3. Manager can view diff: "Here's what 360 tool suggested vs. your current assessment"
4. Manager can adopt, reject, or merge feedback into their own assessment

**Also:**
- Supports common tools: Lattice, 15Five, CultureAmp (via CSV download template)
- Validation: Check for missing fields, out-of-range values, duplicate assessments

---

## REQ-013: API Rate Limiting & Batch Operations

**GIVEN** an HR admin or integration tool needs to bulk-create OKRs or assessments  
**WHEN** the system processes large batches (>100 records)  
**THEN**:
1. Batch API endpoint accepts array of objects (max 500 per request)
2. Async processing with status URL: `POST /api/batch/okr/import` → returns `{batch_id: "xyz", status: "processing"}`
3. Poll `/api/batch/okr/import/xyz` for progress
4. Results include successes + failures (with error reason per record)

**Also:**
- Rate limit: 100 requests/min per tenant (configurable)
- Bulk DELETE also available (soft-delete for compliance)

---

## REQ-014: Export & Reporting

**GIVEN** an HR partner wants to export performance data for analysis  
**WHEN** they click "Export" in the performance module  
**THEN**:
1. Options:
   - **OKR Report**: All cycles, all employees, with einscore classifications (CSV/Excel)
   - **9-box Summary**: By department, segment counts, no individual names (CSV)
   - **Calibration Log**: All sessions, all decisions, full audit trail (CSV)
   - **Reward Decisions**: All RewardLinks, approval status, effective dates (CSV)
   - **Compliance Bundle**: Includes GDPR-safe aggregations, excludes personal 9-box assessments (PDF)
2. Exports are available for download + email to admin (configurable)
3. Exports are logged (audit trail: who exported what, when)

**Also:**
- Scheduled exports: "Every Friday, email the week's new RewardLink proposals to finance team"
- BI integration: Zapier / Power BI connectors for live dashboards

---

## REQ-015: Internationalization (Dutch + English)

**GIVEN** the app is multi-tenant with users in Dutch and English locales  
**WHEN** users interact with performance features  
**THEN**:
1. All UI text is translated (Dutch default, English fallback)
2. User's locale preference (in hrmq profile) determines language
3. Translated terms:
   - OKR = "OKR" (acronym, same in both)
   - Objective = "Doelstelling" (NL) / "Objective" (EN)
   - Key Result = "Sleutelresultaat" (NL) / "Key Result" (EN)
   - 9-box = "Talentmatrix" (NL) / "Talent Matrix" (EN)
   - Calibration = "Kalibratie" (NL) / "Calibration" (EN)
4. Dates use locale format (DD-MM-YYYY in NL, MM-DD-YYYY in EN, unless user customizes)
5. Number format: "1.234,56" (NL) vs. "1,234.56" (EN)

**Also:**
- Support for additional languages via admin panel (community translations)
- All email notifications in user's preferred language

---

## Data Validation Rules

| Entity | Field | Validation |
|--------|-------|-----------|
| OKRCycle | periode | Non-empty, unique per tenant |
| OKRCycle | start_datum ≤ eind_datum | Date range must be valid |
| OKR | objective_titel | 1-100 characters |
| OKR | parent_okr_ref (for team/individual) | Must exist in same cycle |
| OKR | key_results | ≥1, ≤10 per OKR |
| OKR Key Result | target | Must be > baseline (or = baseline for binary) |
| OKR Key Result | current_value | Must be numeric, consistent with type |
| NineBoxAssessment | onderbouwing_performance | ≥ 200 characters |
| NineBoxAssessment | onderbouwing_potential | ≥ 200 characters |
| KalibratieSessie | deelnemers_refs | ≥1 (needs managers) |
| KalibratieSessie | scope_ref | Required if scope = team or bu |
| ContinuousFeedback | tekst | 1-1000 characters |
| RewardLink | onderbouwing_referenties | ≥1 evidence item (OKR or feedback) |

---

## Role-Based Access Control

| Role | OKR | 9-box | Kalibratie | Reward |
|------|-----|-------|-----------|--------|
| **Employee** | View own, edit own, comment | NONE | NONE | View own result |
| **Manager** | Create/edit team, view cascade, comment | Create/edit direct reports, view team | Participate (invited) | Propose for directs |
| **HR-BP** | Advise, view all in scope | View/create/edit team scope, cross-team analysis | Facilitate, edit pre-calibration | Review proposals |
| **Talent Board** | Strategic view, aggregate | View high-level summaries (if `confidential_level` allows) | Attend (invited, read-only) | Approve/override |
| **Reward Manager** | Reference OKR data | Reference 9-box position | Reference calibration decisions | Manage rewards cycle |
| **Tenant Admin** | Enable/disable features, config | Manage DPIA, set retention policies | Configure distribution targets | Audit access |

---

## Error Handling

All API responses follow HAL+JSON (per ADR-002):
```json
{
  "status": "error",
  "code": "ERR_OKRXXXX",
  "message": "OKR cannot be updated after cycle closure",
  "details": {
    "okr_id": "OKR-2026-Q2-ENG-001",
    "cycle_status": "gesloten"
  }
}
```

Common errors:
- `ERR_OKR_CYCLE_CLOSED` (409) — Cannot modify closed cycle
- `ERR_9BOX_UNAUTHORIZED` (403) — User lacks permission to view/edit
- `ERR_ASSESSMENT_INCOMPLETE` (400) — Missing required fields
- `ERR_CALIBRATION_LOCKED` (409) — Session is closed
- `ERR_INVALID_CASCADE` (400) — Parent OKR does not exist

