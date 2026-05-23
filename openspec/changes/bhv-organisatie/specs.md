---
status: draft
---

# BHV Organisatie — Specifications

## Requirements

### REQ-001: Wettelijk minimumbezetting per locatie

**Summary**: BHV schedule coverage must meet Arbowet minimum: 1 per 50 occupants + 1 extra per 100.

---

**REQ-001-001: Coverage calculation formula**

GIVEN a `Location` with `expectedOccupancy = 240`  
WHEN the system calculates `requiredCount` for a `BhvSchedule`  
THEN `requiredCount = ceil(240/50) + floor(240/100) = 5 + 2 = 7` BHV'ers  
AND the calculation respects organisation-wide overschrijdingsbuffer if configured (default: 0)

---

**REQ-001-002: Daily coverage status batch job**

GIVEN a `BhvSchedule` for a location with `requiredCount = 7` but only 5 `assignedMemberIds`  
WHEN the 06:00 batch coverage-check job runs  
THEN the status is set to `rood`  
AND the safety officer for that location receives a Slack/email notification by 08:00  
AND the notification includes 1-click redirect to roster edit screen with auto-suggested replacements

---

**REQ-001-003: Risk multiplier for high-risk activities**

GIVEN a location hosts an event (event, evenement, festiviteit) on a specific date  
WHEN a temporal risk classification is applied to that `BhvSchedule` slot  
THEN `requiredCount` is multiplied by the risk-multiplier (configurable, default: 1.5)  
AND the roster is flagged for manual review  
AND the safety officer receives a separate alert

---

### REQ-002: Certificaten met verloopbewaking

**Summary**: Real-time monitoring of BHV member certifications with expiry warnings and status transitions.

---

**REQ-002-001: 90-day expiry warning**

GIVEN a `Certification` with `expiryDate` within 90 calendar days  
WHEN the daily alert job runs (once per day, 07:00 local)  
THEN the status remains `geldig` but a warning is logged  
AND the BhvMember and their assigned safety officer receive a notification  
AND the notification includes a link to propose a training slot in training-opleidingen  
AND if the member accepts, a provisional enrollment is created with automatic scheduling attempt

---

**REQ-002-002: Expiry status transition and schedule recalculation**

GIVEN a `Certification` with `expiryDate = 2026-06-01`  
WHEN the system processes midnight UTC of the expiry date  
THEN the status auto-transitions to `verlopen`  
AND the BhvMember is immediately excluded from `BhvSchedule` coverage calculations  
AND all `BhvSchedule` records for the next 30 days that included this member are recalculated  
AND if recalculated schedule becomes `rood`, the safety officer is notified at 06:00

---

**REQ-002-003: Certificate upload with OCR validation**

GIVEN a user (safety officer or BhvMember) uploads a PDF/image of a new certificate  
WHEN the upload is processed  
THEN the system runs OCR to extract the member's name and expiry date  
WHEN extracted values are compared to the record being certified:  
  - IF name matches (fuzzy, >85% similarity): auto-accept and set `status = geldig`
  - IF name mismatches: prompt user for manual confirmation with side-by-side comparison
  - IF expiry date is unreadable or document is illegible: request re-upload or manual entry
AND all uploads are audited (who, when, confidence score)

---

### REQ-003: Roosters en aanwezigheid

**Summary**: Intelligent roster generation respecting member availability, fairness, and conflicting responsibilities.

---

**REQ-003-001: Weekly roster generation algorithm**

GIVEN the scheduler is invoked with a start date (typically Monday of next week)  
WHEN the algorithm runs  
THEN it:
  - Respects each `BhvMember.availabilityPattern` (recurring + exceptions)
  - Excludes members with overlapping `verlof-administratie` approvals
  - Spreads assignments fairly (target: equal shifts per member per month)
  - Avoids consecutive shifts for the same member (unless explicitly opted in)
  - Ensures required BHV roles are distributed (≥1 hoofd_bhv per location per shift)
AND the result is shown as draft to the safety officer for approval  
AND changes can be made before publication  
AND published roster is locked for manual edit (only safety officer can override with audit log)

---

**REQ-003-002: Same-day sick leave re-assignment**

GIVEN a BhvMember calls in sick for a shift they are assigned to  
WHEN the absence is registered in the app  
THEN the slot for that day is re-marked `geel` (warning)  
AND the system searches available members on the same day with matching certification  
AND if a replacement is found: auto-assign and notify the replacement  
AND if no replacement found: escalate to safety officer with suggestion list  
AND the outcome (found/not found) is logged and reported in daily summary

---

**REQ-003-003: Multi-location conflict prevention**

GIVEN a BhvMember has secondary locations assigned  
WHEN the scheduler attempts to assign them to overlapping time slots across locations  
THEN the assignment is blocked with error: "Conflict: member assigned to Location A 08:00–17:00 and Location B 14:00–18:00"  
AND the conflict is highlighted in the draft roster for manual resolution

---

### REQ-004: Ontruimingsplan-bibliotheek

**Summary**: Centralized evacuation plan versioning, distribution, and on-site QR-code access.

---

**REQ-004-001: Ontruimingsplan upload and distribution**

GIVEN a safety officer uploads a new ontruimingsplan PDF to a Location  
WHEN the document is accepted  
THEN it is stored in `docudesk` with version control  
AND all BhvMembers assigned to that location receive a Slack/email notification  
AND the notification includes: document name, effective date, and a "Mark as Read" button  
AND members have 7 calendar days to confirm reading  
AND the plan is NOT marked `active` until all currently assigned members confirm

---

**REQ-004-002: Plan expiry and revision reminder**

GIVEN an ontruimingsplan was uploaded >12 months ago without revision  
WHEN the quarterly compliance check runs  
THEN the plan status is flagged `review_overdue`  
AND the safety officer receives an action to update the plan  
AND the status remains visible on the Location detail page (orange banner)  
AND BhvMembers see a "Plan needs review" badge in their mobile app

---

**REQ-004-003: QR-code room-level access**

GIVEN a QR code is placed on a room wall (e.g., Meeting Room 3.2)  
WHEN a person scans it with the mobile app (or any QR scanner)  
THEN they are directed to a public URL (no login required)  
THEN they see:
  - The ontruimingsplan excerpt for their current floor/zone
  - Marked emergency exits in red/orange
  - First aid kit location (direction + distance)
  - Nearest AED location (direction + distance)
  - Language toggle (NL ↔ EN)
AND the page is cached and works offline  
AND page loads within 1 second on 4G

---

### REQ-005: Oefen-registratie

**Summary**: Recording and compliance tracking of evacuation drills and emergency exercises.

---

**REQ-005-001: Drill creation and pre-announcement**

GIVEN a user (typically ploegleider or safety officer) creates a new Drill  
WHEN the drill details are saved (type: announced/unannounced/incident_echt, date, location, scenario)  
THEN a `Drill` record is created  
AND for announced drills: all personnel on the location (BHV + non-BHV) receive a notification 24 hours prior  
AND the notification includes date, time, scenario, and assembly point  
AND for unannounced drills: no pre-notification is sent

---

**REQ-005-002: Drill execution and evaluation capture**

GIVEN a drill is executed on the scheduled date  
WHEN the ploegleider records the outcome (evacuation time in seconds, participant count, any incidents)  
THEN the `Drill` record is updated with `executedAt`, `evacuationDurationSeconds`, `participantCount`  
AND a templated evaluation form is auto-generated with sections for:
  - Evacuation time vs target (target: <10 min for full building)
  - Roll-call accuracy (all personnel accounted for?)
  - Blocked exits (any found?)
  - Equipment issues (alarms, signage, lighting)
  - Lessons learned (free text)
  - Action items (auto-trackable as tasks)

---

**REQ-005-003: Annual drill compliance check**

GIVEN a calendar year has passed  
WHEN the annual compliance check runs (e.g., Jan 2 for year 2025)  
THEN for each Location:
  - IF no `Drill` record with `executedAt` between Jan 1 – Dec 31: flag location as `red`
  - IF ≥1 drill executed: flag as `green`
AND the compliance report includes the per-location status  
AND if any location is red, the safety officer receives a high-priority action to schedule a drill

---

### REQ-006: EHBO-koffer en AED inventarischeck

**Summary**: Scheduled inspections and condition tracking for first-aid kits and automated external defibrillators.

---

**REQ-006-001: Inspection scheduling and task assignment**

GIVEN an `InventoryItem` has `lastInspectedAt` and `nextInspectionDue`  
WHEN the scheduled-jobs service runs each morning  
AND `nextInspectionDue <= today`  
THEN a task is created for the designated inspector (facility manager or safety officer)  
AND the task notification includes: item type, location, serial number, photo reference if available  
AND the inspector has a mobile-friendly checklist form (use `CnFormDialog` schema-driven)

---

**REQ-006-002: Inspection result and replacement ordering**

GIVEN an inspection is completed (items checked, condition recorded, missing items noted)  
WHEN the inspection result is saved  
THEN the `InventoryItem` record is updated with `condition`, `lastInspectedAt`, `nextInspectionDue`  
AND if condition = `defect` or `missing`:
  - A replacement order is auto-created with procurement SLA based on criticality:
    - AED: 7 days
    - EHBO core items (gauze, compress, bandage): 14 days
    - EHBO minor items (scissors, tape): 30 days
  - The order is sent to the inkoopflow module with auto-reminder at SLA/2 days
AND the location safety officer is notified

---

**REQ-006-003: AED IoT fault detection**

GIVEN an AED device sends a fault webhook (via openconnector) indicating `status = defect`  
WHEN the webhook is received  
THEN the corresponding `InventoryItem.condition` is immediately updated to `defect`  
AND the safety officer receives a SMS + email with HIGH priority  
AND if a backup AED is configured for the location: automatically designate it as primary  
AND a replacement order is auto-created with 7-day SLA  
AND the item status is visible on a live dashboard (red flag)

---

### REQ-007: Alarmflow en incidentregistratie

**Summary**: Real-time alarm dispatch, response logging, and incident report generation.

---

**REQ-007-001: Alarm trigger and on-call notification**

GIVEN an alarm is triggered (manually via app, IoT from brandmeldcentrale, or BMS building system)  
WHEN the alarm reaches the system  
THEN all on-call BhvMembers (those assigned to today's `BhvSchedule` + those with active 24/7 status) receive:
  - Push notification (mobile app)
  - SMS (if on-call tier 1)
  - Slack message (to BHV team channel)
AND each notification includes: location, alarm type (brand/ontruiming/EHBO/veiligheid), timestamp, and "Accept" button  
AND response is tracked: who accepted first, when, and if >2 min pass with no acceptance: escalate to safety officer

---

**REQ-007-002: Response timeline logging**

GIVEN during an alarm, the ploegleider or responding members log actions  
WHEN an action is recorded (evacuation initiated, assembly point reached, missing persons, all-clear)  
THEN the `AlarmEvent.responseLog` array is appended with: {timestamp, actor, action, detail}  
AND the timeline is visible in real-time on the safety officer's dashboard  
AND each log entry is immutable (no editing, only audit-trail visibility)

---

**REQ-007-003: Incident report generation and archive**

GIVEN an alarm is marked as `closedAt`  
WHEN the closure is confirmed  
THEN an incident report template is auto-generated with pre-filled fields:
  - Location, alarm type, trigger time, closure time, duration
  - All personnel present (from `Drill.participantCount` analog)
  - Response timeline (all actions from `responseLog`)
  - Any injuries or property damage (free text)
  - Root cause (if known)
AND the report is opened in a form for the ploegleider/safety officer to sign  
AND upon signature, the report is stored in `docudesk` with legal hold applied  
AND a copy is available for SZW audit export

---

### REQ-008: Compliance-rapportage Arbowet

**Summary**: Audit-ready reports for labour inspectorate, board, and internal compliance tracking.

---

**REQ-008-001: SZW audit package export**

GIVEN the safety officer requests a compliance export (typically when SZW audit announced)  
WHEN the export is triggered  
THEN a PDF + JSON package is generated with:

**Per Location:**
  - Current BHV staffing level vs Arbowet minimum (rolling 12-month trend)
  - Per-location coverage status: days red/geel/groen (%)
  - BhvMember roster with roles and certification status (valid/expiring/expired)
  - Drill history (dates, types, results, any red flags)
  - Inventory status (all EHBO/AED items, condition, expiry dates)
  - Incident timeline (all AlarmEvents with response logs)

**Summary metrics:**
  - Organization-wide compliance score (0–100%, based on coverage %, drill compliance, cert compliance)
  - Arbowet art. 15 / Arbobesluit checklist with pass/fail per location

AND the export is digitally signed (per app manifest) AND stored in docudesk with retention of 7+ years

---

**REQ-008-002: Board/OR yearly report**

GIVEN leadership requests the annual BHV summary  
WHEN the report is generated  
THEN it includes:
  - Coverage KPI: (assigned shifts / required shifts) × 100, month-over-month trend
  - Certification compliance: (valid certs / total required certs) × 100
  - Drill frequency: drills completed / locations / year
  - Average alarm response time (first acceptance, end-to-end)
  - Incident count and severity classification (0 serious incidents, 2 minor, etc.)
  - Year-over-year comparison with 2025 data (if available)
  - Benchmarking (industry average, peer municipality comparison if available)

AND charts are generated via `CnChartWidget` (line graphs for trends, pie for breakdown)

---

**REQ-008-003: Offboarding alert for hoofd-BHV vacancy**

GIVEN a BhvMember with `roles` including `hoofd_bhv` exits the organisation  
WHEN their status is changed to `uit_dienst` (triggered by employee-master offboarding)  
THEN the system identifies locations where they were the sole hoofd-BHV  
AND creates a HIGH-priority action for the safety officer: "Appoint new Hoofd-BHV for Location X"  
AND suggests up-skilling candidates from the roster with current certifications  
AND proposes a training pathway in training-opleidingen (hoofd-BHV advanced course)

---

### REQ-009: Mobiele app voor BHV'ers

**Summary**: Dedicated mobile experience for BHV members with shift visibility, QR scanning, and alarm response.

---

**REQ-009-001: Roster and certification view**

GIVEN a BhvMember installs the hrmq mobile app and logs in (via IRMA/DigiD)  
WHEN they open the app home screen  
THEN they see:
  - "My next shifts": upcoming 7 days with location, time, required role(s), co-assigned members
  - "My certifications": expiry dates, status (valid/expiring/expired), with link to training-opleidingen
  - "My locations": quick links to each assigned location's ontruimingsplan + safety info

---

**REQ-009-002: On-site QR scanning without login**

GIVEN a BhvMember scans a room's QR code (or any visitor scans it)  
WHEN the QR is scanned  
THEN the user is directed to a public webpage (short URL, cached for offline)  
THEN they see:
  - Floor-level evacuation map with exits highlighted
  - Nearest EHBO-koffer: direction (arrows) + distance (meters)
  - Nearest AED: direction + distance + if operational or defect
  - Emergency assembly point: direction + distance + role assignment (if available)
  - Local emergency contact (on-site safety officer, facility manager)
AND the page is responsive (mobile-first), loads in <1s on 4G, works offline

---

**REQ-009-003: Alarm acceptance and location sharing (consent-gated)**

GIVEN an alarm is triggered while a BhvMember has the mobile app open  
WHEN they receive the push notification  
THEN tapping "I'll respond" does:
  - Accepts the alarm response for their name + timestamp
  - IF `consentSharingMobile = true`: begins sharing their GPS location with the on-site ploegleider (visible on ploegleider's "Responder Map")
  - Opens a live incident feed (all actions logged, updates in real-time)
  - Shows navigation to location (if not already there)
AND location sharing STOPS automatically when alarm is marked `closedAt`  
AND the member can manually revoke sharing with a "Stop sharing" button at any time

---

### REQ-010: Privacy en consent

**Summary**: GDPR-compliant consent flows, data minimization, and user control.

---

**REQ-010-001: Initial consent collection and management**

GIVEN a new BhvMember is onboarded  
WHEN their profile is created  
THEN they are presented with a consent form covering:
  - Sharing mobile phone number with fellow BHV members (for on-call coordination)
  - Receiving push/SMS notifications for alarms and shift changes
  - Optional: location sharing during incident response (GPS)
AND each consent has a toggle that can be revoked at any time via Profile › Privacy  
AND revocation is immediate (number removed from contact lists, notifications halted, location sharing disabled)  
AND audit log records all consent changes (who, when, old/new value, reason)

---

**REQ-010-002: Mobile contact revocation impact**

GIVEN a BhvMember revokes consent for mobile sharing  
WHEN revocation is saved  
THEN:
  - Their phone number is immediately removed from the `BhvMember` record
  - They are removed from the on-call "BHV call tree" (no more phone calls for alerts)
  - They are removed from Slack notifications for on-call coordination
  - The safety officer receives a notification: "[Member] has revoked mobile consent; they are no longer counted as 24/7 available"
  - Their status on `BhvSchedule` assignment reverts to standard office hours only
AND they may re-consent later (re-opt-in)

---

**REQ-010-003: GDPR data subject access and deletion**

GIVEN a BhvMember or ex-member requests AVG inzageverzoek (data access request)  
WHEN the request is submitted via the app  
THEN the system (using platform's `inzageverzoek()` method) compiles:
  - All personal data (name, contact, dates of employment)
  - All shift assignments and annotations
  - All certifications and exam results
  - All alarm responses and incident reports
AND data is exported in machine-readable format (JSON/CSV) within 30 days  
AND a copy is logged in audit trail

---

**GIVEN** an ex-BhvMember requests deletion (AVG recht op vergetelheid)  
**WHEN** the deletion request is processed  
**THEN:**
  - Personal identifiers (name, contact, address) are deleted or anonymized
  - Certification records are retained in aggregated anonymized form (for compliance reporting only)
  - Shift/alarm/drill responses are anonymized (timestamp + action type preserved, actor identity removed)
  - Historical audit trail entries are anonymized
AND a deletion certificate is issued  
AND legal hold is checked (any incident ongoing? → hold deletion until closed)
