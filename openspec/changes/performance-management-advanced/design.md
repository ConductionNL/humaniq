---
schema: spec-driven
change: performance-management-advanced
version: 1.0
date: 2026-05-23
---

# Design: Performance Management Advanced

## Data Model

### Core Entities

#### OKRCycle
Represents a performance management cycle (quarterly or semi-annual period) during which employees set and track objectives and key results.

**Fields:**
- `cyclus_id` (string, PK): Unique identifier (e.g., "2026-Q2")
- `periode` (string): Human-readable period label (e.g., "Q2 2026")
- `start_datum` (date): Cycle start date
- `eind_datum` (date): Cycle end date
- `bedrijfsdoelen` (json array): Strategic OKRs cascading from ExCo (nested OKR structures)
- `status` (enum): `draft` | `open` | `in_progress` | `calibratie` | `gesloten`
- `aangemaakt_door` (ref Employee): User who created the cycle
- `aangemaakt_op` (timestamp): Creation timestamp
- `gewijzigd_op` (timestamp): Last modification timestamp

---

#### OKR
An Objective (goal) with associated Key Results (measurable outcomes). OKRs can be at company, business-unit, team, or individual level.

**Fields:**
- `okr_id` (string, PK): Unique identifier (e.g., "OKR-2026-Q2-ENG-001")
- `cyclus_ref` (ref OKRCycle): Which cycle this OKR belongs to
- `eigenaar_ref` (ref Employee): Owner/accountable person
- `niveau` (enum): `company` | `business_unit` | `team` | `individu`
- `objective_titel` (string, 100 max): Goal title (e.g., "Reduce customer onboarding time")
- `objective_beschrijving` (text): Longer explanation of the objective and its strategic importance
- `parent_okr_ref` (ref OKR, nullable): Parent OKR in cascade tree; null for company-level OKRs
- `key_results` (json array): Array of key-result objects:
  - `kr_id` (uuid)
  - `titel` (string): What we're measuring (e.g., "Reduce onboarding to <2 days")
  - `baseline` (number): Starting value
  - `target` (number): Goal value (0.7-1.0 confidence typical)
  - `type` (enum): `number` | `percentage` | `binary` | `currency`
  - `current_value` (number, nullable): Most recent update
  - `last_update` (date, nullable): When current_value was last changed
  - `unit` (string, nullable): Measurement unit (e.g., "days", "%", "€")
- `confidence_score` (integer, 1-10): Owner's confidence this will be achieved (updated biweekly)
- `eindscore` (decimal 0.0-1.0, nullable): Final completion score when cycle closes
- `classificatie` (enum, nullable): Auto-derived from eindscore: `succesvol` (0.7-1.0) | `leerzaam_gefaald` (0.4-0.7) | `te_ambitieus` (<0.4)
- `aangemaakt_op` (timestamp): Creation
- `gewijzigd_op` (timestamp): Last modification

**Computed/Derived Fields:**
- Cascade completeness: percentage of direct-child OKRs with active links
- Orphan flag: True if parent_okr_ref is null and niveau is not "company"

---

#### NineBoxAssessment
A two-dimensional assessment of an employee's performance (past 12 months) and potential (next 2-3 years). Always confidential; never visible to the assessed employee.

**Fields:**
- `assessment_id` (uuid, PK): Unique identifier
- `cyclus_ref` (ref OKRCycle): Which performance cycle
- `subject_ref` (ref Employee): The employee being assessed
- `assessor_ref` (ref Employee): The manager/assessor conducting the assessment
- `performance_axis` (enum): `low` | `medium` | `high` (based on objective outputs + feedback)
- `potential_axis` (enum): `low` | `medium` | `high` (manager judgment on growth trajectory)
- `talent_segment` (enum, auto-derived): 
  - `star` (high perf, high potential)
  - `core_player` (high perf, medium potential OR medium perf, high potential)
  - `emerging` (medium perf, medium potential)
  - `steady_performer` (high perf, low potential)
  - `underperformer` (low perf, any potential)
  - `high_potential_developing` (medium/low perf, high potential)
- `onderbouwing_performance` (text, min 200 chars): Why this performance rating
- `onderbouwing_potential` (text, min 200 chars): Why this potential rating
- `gerelateerde_feedback_refs` (array of ref ContinuousFeedback): Linked feedback evidence
- `gerelateerde_okr_scores` (array): Linked OKR eindscore references
- `aangepast_na_kalibratie` (boolean): True if changed during a calibration session
- `confidential_level` (enum): 
  - `manager_only` (only direct manager)
  - `hrbp_also` (manager + HR-BP + tenant admin)
  - `talent_board_also` (+ talent board / ExCo)
- `aangemaakt_op` (timestamp)
- `gewijzigd_op` (timestamp)
- `audit_accessed_by` (json array): Log of who viewed this record (for GDPR compliance)

**Validation:**
- `onderbouwing_performance` and `onderbouwing_potential` must each be >= 200 characters (no empty or stub assessments)
- Only direct manager of `subject_ref` can create/update (unless role = HR-BP with scope permission)

---

#### KalibratieSessie
A scheduled, facilitated meeting where managers review and align 9-box assessments within a scope (team/business-unit). All decisions are logged.

**Fields:**
- `sessie_id` (uuid, PK): Unique identifier
- `cyclus_ref` (ref OKRCycle): Which cycle this calibration belongs to
- `scope` (enum): `team` | `business_unit` | `functiegroep` (e.g., all "managers" across BUs)
- `scope_ref` (ref, nullable): If scope is `team` or `business_unit`, ref to that entity
- `deelnemers_refs` (array of ref Employee): Invited managers/assessors for this session
- `facilitator_ref` (ref Employee): Usually HR-BP, runs the session
- `geplande_datum` (date): When it will/did happen
- `geplande_duur_minuten` (integer): Expected duration
- `agenda_subject_refs` (array of ref Employee): List of employees being discussed
- `kalibratie_log` (json array): Audit trail of all assessment changes:
  - `timestamp` (timestamp)
  - `subject_ref` (ref Employee)
  - `voor_performance` (enum): Performance axis BEFORE change
  - `na_performance` (enum): Performance axis AFTER change (if changed)
  - `voor_potential` (enum): Potential axis BEFORE
  - `na_potential` (enum): Potential axis AFTER (if changed)
  - `onderbouwing` (text): Why the change was made
  - `besluit_type` (enum): `consensus` | `facilitator_overrule` | `no_change`
  - `bijgewerkt_door` (ref Employee): Who made the decision (usually facilitator)
  - `dissenters_refs` (array of ref Employee, nullable): If overrule, who disagreed
- `besluiten_summary` (text, nullable): High-level summary of key changes and rationale
- `distributie_snapshot` (json, nullable): Heatmap of 9-box distribution at end of session
- `gesloten` (boolean): True when session is finalized and assessments synced
- `gesloten_op` (timestamp, nullable)
- `gesloten_door` (ref Employee, nullable): Who closed the session

**Behavior:**
- Once `gesloten=true`, all related `NineBoxAssessment` records are updated with `aangepast_na_kalibratie=true`
- No further edits allowed to kalibratie_log once closed

---

#### ContinuousFeedback
Lightweight feedback between employees — kudos, constructive notes, or peer-input requests. Evidence for review cycles.

**Fields:**
- `feedback_id` (uuid, PK)
- `gever_ref` (ref Employee): Who gave feedback
- `ontvanger_ref` (ref Employee): Who received feedback
- `type` (enum): 
  - `kudos` (praise, recognition)
  - `constructief` (developmental feedback)
  - `feedback_vraag_antwoord` (peer solicited Q&A)
- `tekst` (text, max 1000): Feedback content
- `gerelateerde_okr_ref` (ref OKR, nullable): If tied to a specific OKR
- `gerelateerde_competentie` (string, nullable): If tied to a competency (e.g., "Communication", "Leadership")
- `zichtbaarheid` (enum):
  - `alleen_ontvanger` (1-on-1)
  - `ook_manager` (employee + direct manager visible)
  - `team_public` (visible to team/department)
- `aangemaakt_datum` (timestamp)
- `gewijzigd_op` (timestamp, nullable)
- `meegenomen_in_review` (ref OKRCycle, nullable): If this feedback was aggregated into a review cycle
- `gearchiveerd` (boolean): Soft-delete for GDPR retention

---

#### RewardLink
A record summarizing performance evidence and tying it to compensation recommendations (bonus, promotion, raise). Not a decision itself — input to HR-BP and reward-committee review.

**Fields:**
- `reward_id` (uuid, PK)
- `cyclus_ref` (ref OKRCycle): Which performance cycle
- `subject_ref` (ref Employee): Employee being considered for reward
- `manager_ref` (ref Employee): Proposing manager
- `bonus_voorstel_bedrag` (decimal, nullable): € amount
- `bonus_voorstel_percentage` (decimal, nullable): % of salary
- `promotie_voorstel` (boolean): Whether a promotion is recommended
- `promotie_voorstel_rol_ref` (ref Role, nullable): New role if promoted
- `promotie_voorstel_datum` (date, nullable): When promotion would be effective
- `salarisaanpassing_voorstel` (decimal, nullable): € or % salary increase
- `onderbouwing_referenties` (json object):
  - `okr_eindscore_avg` (decimal): Average of all finalized OKRs
  - `nineboxassessment_ref` (uuid): Link to the 9-box assessment
  - `kalibratiesessie_ref` (uuid, nullable): If changed in calibration, which session
  - `continuous_feedback_refs` (array): Citations to relevant feedback records
  - `notities_manager` (text, nullable): Manager's additional context
- `besloten_door` (ref Employee, nullable): HR-BP or committee member who approved/rejected
- `besluit_status` (enum): `voorstel` | `goedgekeurd` | `afgewezen` | `gewijzigd`
- `effectuering_datum` (date, nullable): When the reward takes effect
- `aangemaakt_op` (timestamp)
- `gewijzigd_op` (timestamp)

**Validation:**
- At least ONE of `bonus_voorstel_*`, `promotie_voorstel`, or `salarisaanpassing_voorstel` must be non-null
- `onderbouwing_referenties` must contain at least one reference (OKR score or feedback)

---

## Seed Data

### OKRCycle Example
```json
{
  "cyclus_id": "2026-Q2",
  "periode": "Q2 2026",
  "start_datum": "2026-04-01",
  "eind_datum": "2026-06-30",
  "bedrijfsdoelen": [
    {
      "okr_id": "OKR-2026-CO-001",
      "niveau": "company",
      "eigenaar_ref": "emp-ceo-001",
      "objective_titel": "Schaal klantgebruik naar 1000+ organisaties",
      "objective_beschrijving": "Expand market presence in Dutch mid-market; increase customer base from 400 to 1000+ organizations.",
      "key_results": [
        {
          "titel": "500+ new customer signups",
          "baseline": 400,
          "target": 1000,
          "type": "number",
          "unit": "customers"
        },
        {
          "titel": "Customer satisfaction NPS >= 50",
          "baseline": 38,
          "target": 50,
          "type": "number",
          "unit": "NPS points"
        }
      ]
    }
  ],
  "status": "open",
  "aangemaakt_door": "emp-admin-001",
  "aangemaakt_op": "2026-03-15T09:30:00Z"
}
```

### OKR (Team Level) Example
```json
{
  "okr_id": "OKR-2026-Q2-PRODUCT-001",
  "cyclus_ref": "2026-Q2",
  "eigenaar_ref": "emp-product-lead-001",
  "niveau": "team",
  "objective_titel": "Launch OKR + 9-box capability in hrmq",
  "objective_beschrijving": "Enable enterprise performance management: OKR goal-setting, 9-box talent assessment, calibration tooling.",
  "parent_okr_ref": "OKR-2026-CO-001",
  "key_results": [
    {
      "titel": "Deploy OKR module with 95% API test coverage",
      "baseline": 0,
      "target": 1,
      "type": "binary",
      "current_value": null
    },
    {
      "titel": "Calibration tool live with 10+ pilot customers",
      "baseline": 0,
      "target": 10,
      "type": "number",
      "unit": "customers"
    }
  ],
  "confidence_score": 8,
  "aangemaakt_op": "2026-03-20T10:15:00Z"
}
```

### OKR (Individual) Example
```json
{
  "okr_id": "OKR-2026-Q2-ENG-042",
  "cyclus_ref": "2026-Q2",
  "eigenaar_ref": "emp-engineer-042",
  "niveau": "individu",
  "objective_titel": "Build robust calibration session workflow",
  "objective_beschrijving": "Implement backend APIs and business logic for facilitated 9-box calibration with audit logging.",
  "parent_okr_ref": "OKR-2026-Q2-PRODUCT-001",
  "key_results": [
    {
      "titel": "All 5 calibration APIs complete & tested",
      "baseline": 0,
      "target": 5,
      "type": "number",
      "unit": "APIs"
    },
    {
      "titel": "Audit logging captures 100% of assessment changes",
      "baseline": 0,
      "target": 1,
      "type": "binary"
    }
  ],
  "confidence_score": 9,
  "aangemaakt_op": "2026-04-01T14:00:00Z"
}
```

### NineBoxAssessment Example
```json
{
  "assessment_id": "9b-2026-Q2-emp-042",
  "cyclus_ref": "2026-Q2",
  "subject_ref": "emp-engineer-042",
  "assessor_ref": "emp-tech-lead-001",
  "performance_axis": "high",
  "potential_axis": "high",
  "talent_segment": "star",
  "onderbouwing_performance": "Over the past 12 months, delivered 4 major features on time and with minimal bugs. Code reviews consistently positive. Led incident response effectively during 3 production incidents. Mentored 2 junior engineers.",
  "onderbouwing_potential": "Demonstrates architectural thinking beyond day-to-day tasks. Took initiative to redesign database schema for 10x performance. Has expressed interest in lead/management track. Learns new technologies quickly.",
  "gerelateerde_okr_scores": [
    {
      "okr_ref": "OKR-2026-Q2-ENG-042",
      "eindscore": 0.95
    }
  ],
  "confidential_level": "hrbp_also",
  "aangemaakt_op": "2026-06-15T16:45:00Z"
}
```

### ContinuousFeedback Example
```json
[
  {
    "feedback_id": "cfb-001",
    "gever_ref": "emp-peer-043",
    "ontvanger_ref": "emp-engineer-042",
    "type": "kudos",
    "tekst": "Great collaboration on the calibration API design. Clear documentation and thorough code review helped me learn the system fast.",
    "zichtbaarheid": "ook_manager",
    "aangemaakt_datum": "2026-05-30T11:20:00Z"
  },
  {
    "feedback_id": "cfb-002",
    "gever_ref": "emp-engineer-042",
    "ontvanger_ref": "emp-peer-044",
    "type": "feedback_vraag_antwoord",
    "tekst": "How would you approach optimizing the 9-box distribution query for tenants with 5000+ employees?",
    "gerelateerde_okr_ref": "OKR-2026-Q2-ENG-042",
    "zichtbaarheid": "alleen_ontvanger",
    "aangemaakt_datum": "2026-06-10T13:00:00Z"
  }
]
```

### KalibratieSessie Example
```json
{
  "sessie_id": "kal-2026-Q2-ENG-team",
  "cyclus_ref": "2026-Q2",
  "scope": "team",
  "scope_ref": "team-engineering-001",
  "deelnemers_refs": ["emp-tech-lead-001", "emp-tech-lead-002", "emp-tech-lead-003"],
  "facilitator_ref": "emp-hrbp-001",
  "geplande_datum": "2026-06-20",
  "geplande_duur_minuten": 180,
  "agenda_subject_refs": [
    "emp-engineer-042",
    "emp-engineer-043",
    "emp-engineer-044",
    "emp-engineer-045",
    "emp-engineer-046"
  ],
  "kalibratie_log": [
    {
      "timestamp": "2026-06-20T10:15:00Z",
      "subject_ref": "emp-engineer-045",
      "voor_performance": "medium",
      "na_performance": "high",
      "voor_potential": "medium",
      "na_potential": "medium",
      "onderbouwing": "Initial assessment was too conservative. Q1 onboarding project exceeded SLAs. Peer feedback confirms consistent delivery.",
      "besluit_type": "consensus",
      "bijgewerkt_door": "emp-hrbp-001"
    }
  ],
  "besluiten_summary": "5 engineers reviewed. 1 performance rating adjustment (upward) based on Q1 project evidence. Distribution achieved: 1 star (20%), 3 core (60%), 1 emerging (20%).",
  "distributie_snapshot": {
    "high_high": 1,
    "high_medium": 2,
    "high_low": 1,
    "medium_high": 0,
    "medium_medium": 1,
    "medium_low": 0,
    "low_high": 0,
    "low_medium": 0,
    "low_low": 0
  },
  "gesloten": true,
  "gesloten_op": "2026-06-20T12:45:00Z",
  "gesloten_door": "emp-hrbp-001"
}
```

### RewardLink Example
```json
{
  "reward_id": "rew-2026-Q2-emp-042",
  "cyclus_ref": "2026-Q2",
  "subject_ref": "emp-engineer-042",
  "manager_ref": "emp-tech-lead-001",
  "bonus_voorstel_percentage": 12.5,
  "promotie_voorstel": true,
  "promotie_voorstel_rol_ref": "role-senior-engineer",
  "promotie_voorstel_datum": "2026-07-01",
  "salarisaanpassing_voorstel": 8.5,
  "onderbouwing_referenties": {
    "okr_eindscore_avg": 0.92,
    "nineboxassessment_ref": "9b-2026-Q2-emp-042",
    "kalibratiesessie_ref": "kal-2026-Q2-ENG-team",
    "continuous_feedback_refs": ["cfb-001"],
    "notities_manager": "Strong performer ready for senior IC or tech lead path. Recommend promotion to Senior Engineer with salary review."
  },
  "besloten_door": null,
  "besluit_status": "voorstel",
  "aangemaakt_op": "2026-06-25T09:00:00Z"
}
```

## API Endpoints (Preview)

These endpoints will be fully specified in `specs.md` but are previewed here for design context:

- `POST /api/okr/cycles` — Create a new OKR cycle
- `GET /api/okr/cycles/{id}` — Fetch cycle + all nested OKRs
- `POST /api/okr/{id}/progress` — Biweekly progress update (current_value, confidence)
- `GET /api/nineboxassessment/{cyclus_id}` — List all assessments in cycle (with authz checks)
- `POST /api/nineboxassessment` — Create/update assessment
- `POST /api/kalibratie/sessie/{id}/close` — Finalize calibration session
- `POST /api/continuousfeedback` — Add feedback record
- `GET /api/rewardlink/{cyclus_id}/{employee_id}` — Fetch evidence bundle for reward decision
- `POST /api/rewardlink` — Manager proposes reward action

## Frontend Surfaces (Preview)

- **OKR Detail Card**: Tree view of cascade, biweekly check-in form
- **9-box Matrix**: 3×3 heatmap with employee names (manager/HR-BP only)
- **Calibration Dashboard**: Pre-populated matrix, live change tracking, session history
- **Feedback Widget**: Add kudos/feedback, see feedback received, request peer input
- **Reward Evidence View**: Consolidated summary of OKR scores, 9-box, feedback, calibration outcome

## Security & Privacy Notes

- **9-box visibility**: Implement @requires decorator on API endpoints; never serialize 9-box data to employees' own responses
- **Audit logging**: All reads of NineBoxAssessment must be logged (Art. 35 GDPR)
- **DPIA requirement**: On first 9-box enable, system prompts tenant admin to confirm DPIA completion
- **Data retention**: Automated deletion of assessments for ex-employees after 24 months
- **Confidential levels**: `KalibratieSessie.deelnemers` can only modify assessments for their assigned scope
