---
title: WVP Module Design
change-id: sick-leave-wvp-full
status: draft
created: 2026-05-23
---

# System Design

## Data Model

### Entity: wvp-case

**Primary entity** for a single sick-leave cycle. One case per ziekmelding-event per employee. If the employee reports sick again within 4 weeks, the case is reopened (samenvoeging-4-weken-regel = true).

| Field | Type | Constraint | Description |
|-------|------|-----------|-------------|
| case-id | UUID | PK | Unique case identifier |
| tenant-id | UUID | FK (administratie) | Multi-administratie scope |
| employee-id | UUID | FK (employee-master) | Employee reference |
| case-opening-date | DATE | NOT NULL | Date HR registered the ziekmelding |
| eerste-ziektedag | DATE | NOT NULL | First day of incapacity (may be before registration) |
| expected-end-date | DATE | NULLABLE | Rolling forecast; updated as case progresses |
| actual-end-date | DATE | NULLABLE | Set when case closes (herstel, wia-approved, deceased) |
| case-status | ENUM | NOT NULL | open \| herstel \| wia-aangevraagd \| loonsanctie \| loonsanctie-bezwaar-lopend \| overleden |
| percentage-arbeidsongeschikt | INT 0-100 | NOT NULL DEFAULT 100 | Work incapacity %; updated per bedrijfsarts-advies |
| samenvoeging-4-weken-regel | BOOLEAN | DEFAULT false | True if this case reopened within 28 days of prior closure |
| bedrijfsarts-id | UUID | FK (employee-master) | Assigned occupational physician |
| casemanager-id | UUID | FK (employee-master) | Assigned HR-casemanager |
| cao-id | UUID | FK (cao-engine) | CAO classification for template & suppletie-rules |
| migration-source | STRING | NULLABLE | 'mvp' if migrated from sick-leave-mvp |
| loonsanctie-start-date | DATE | NULLABLE | When loonsanctie period starts (per UWV poortwachterstoets) |
| loonsanctie-weeks | INT | NULLABLE | Duration of sanction in weeks (typically 52) |
| created-by | UUID | FK (user) | User who created case |
| created-at | TIMESTAMP | DEFAULT now() | Audit timestamp |
| updated-at | TIMESTAMP | DEFAULT now() | Audit timestamp |

**Indexes:** (tenant-id, employee-id, case-status), (tenant-id, case-opening-date), (tenant-id, eerste-ziektedag)

**Row-level security:** All roles can read own tenant; bedrijfsarts can read only assigned cases; employee can read own case via self-service portal.

---

### Entity: wvp-milestone

**Spine of the spec.** Tracks 11 procedural milestones, each with a due-date computed from eerste-ziektedag and completion status.

| Field | Type | Constraint | Description |
|-------|------|-----------|-------------|
| milestone-id | UUID | PK | Unique milestone identifier |
| case-id | UUID | FK (wvp-case) | Reference to parent case |
| milestone-type | ENUM | NOT NULL | week-1-arbo-notification \| week-6-probleemanalyse \| week-8-pva \| week-42-uwv-melding \| week-46-to-52-eerstejaarsevaluatie \| week-52-opschudmoment \| week-68-tussenmaal-evaluatie-2e-spoor \| week-87-eindevaluatie \| week-91-wia-aanvraag-deadline \| week-104-einde-loondoorbetaling |
| due-date | DATE | NOT NULL | Computed from eerste-ziektedag per wettelijke termijn |
| completed-date | DATE | NULLABLE | When the milestone was actually completed |
| completed-by-user-id | UUID | FK (user) | Who marked it complete |
| evidence-document-id | UUID | FK (document-store) | Attached evidence (probleemanalyse PDF, PvA signature scan, etc.) |
| gevolgen-bij-niet-naleven | TEXT | NOT NULL | Description of consequences (loonsanctie-risk, WIA-claim-delay, etc.) |
| status | ENUM | NOT NULL | pending \| escalated \| at-risk \| completed |
| escalation-sent-at | TIMESTAMP | NULLABLE | Last escalation reminder sent to stakeholder |
| created-at | TIMESTAMP | DEFAULT now() | Audit |
| updated-at | TIMESTAMP | DEFAULT now() | Audit |

**Computed timeline (from eerste-ziektedag):**
- Week 1 (day 7): Notification to ARBO
- Week 6 (day 42): Probleemanalyse bedrijfsarts deadline
- Week 8 (day 56): PvA signed deadline (14 days after probleemanalyse, typically)
- Week 42 (day 294): UWV 42-week melding deadline
- Week 46-52: First-year evaluation window
- Week 52 (day 364): Decision point: continue 1e spoor or start 2e spoor
- Week 68 (day 476): Mid-course review for 2e spoor
- Week 87 (day 609): Final evaluation & RIV assembly
- Week 91 (day 637): WIA-claim submission deadline
- Week 104 (day 728): End of loondoorbetaling; WIA-uitkering starts

---

### Entity: re-integratie-dossier

**AVG Article 9 segregated medical container.** Records accessible only by bedrijfsarts, verzuimcoach, employee, and (with consent) UWV-verzekeringsarts. HR, managers, finance never see content — only metadata (count, date-range).

| Field | Type | Constraint | Description |
|-------|------|-----------|-------------|
| dossier-entry-id | UUID | PK | Unique medical record identifier |
| case-id | UUID | FK (wvp-case) | Reference to case (must be in same tenant) |
| tenant-id | UUID | FK (administratie) | Multi-administratie scope |
| entry-type | ENUM | NOT NULL | probleemanalyse \| spreekuur-verslag \| fml-functionele-mogelijkheden-lijst \| izp-inzetbaarheidsprofiel \| medisch-advies \| kosten-second-opinion |
| bedrijfsarts-author-id | UUID | FK (user) | Bedrijfsarts who created entry |
| recorded-date | DATE | NOT NULL | Date of medical observation/consultation |
| encrypted-payload | BYTEA | NOT NULL | Encrypted content (AES-256); key in HSM |
| share-with-uwv-bij-riva | BOOLEAN | DEFAULT false | Include in RIV-export only if true AND employee-consent = true |
| employee-viewed-at | TIMESTAMP | NULLABLE | When employee accessed this record via self-service portal |
| created-at | TIMESTAMP | DEFAULT now() | Audit |
| deleted-at | TIMESTAMP | NULLABLE | Soft-delete for retention audit |

**Encryption:** All payloads encrypted via HSM key per tenant. Decryption allowed only for:
- bedrijfsarts-author-id
- case-casemanager-id (with audit log)
- case-employee-id (own portal)
- UWV-verzekeringsarts (only if employee-consent-flag = true in case)

**Row-level security:** SEPARATE SCHEMA from HR-data. Queries from HR-roles are rewritten to return only metadata (COUNT, MIN(recorded-date), MAX(recorded-date)).

**Access audit:** Every successful decryption logged to `medical-access-audit` (reader-id, record-id, timestamp, IP, purpose-code).

**Retention:** Hard-delete 24 months after case-actual-end-date per AVG, with audit-log retention per Belastingdienst 7-year rule.

---

### Entity: plan-van-aanpak (PvA)

**Bilateral reintegration plan document.** Must be created within 14 days of probleemanalyse, signed by both employer and employee by week 8.

| Field | Type | Constraint | Description |
|-------|------|-----------|-------------|
| pva-id | UUID | PK | Unique PvA version identifier |
| case-id | UUID | FK (wvp-case) | Reference to case |
| version | INT | NOT NULL | 1, 2, 3... (PvA may be revised) |
| pva-status | ENUM | NOT NULL | concept \| werkgever-signed \| werknemer-signed \| vastgesteld \| werknemers-bezwaar |
| doelstelling-re-integratie | ENUM | NOT NULL | volledig-hervatting-eigen-functie \| aangepast-werk-eigen-werkgever \| extern-werk \| wia-aanvraag |
| acties | JSON | NOT NULL | Array of action objects: [{verantwoordelijke, beschrijving, termijn-weken, status}] |
| evaluatie-frequentie-weken | INT | DEFAULT 6 | How often evaluatie gesprekken happen |
| volgende-evaluatie-datum | DATE | NULLABLE | Next scheduled evaluation |
| casemanager-id | UUID | FK (employee-master) | HR-manager handling this PvA |
| werkgever-signed-by-user-id | UUID | FK (user) | HR who signed for employer |
| werkgever-signed-on | TIMESTAMP | NULLABLE | When employer signature was affixed |
| werkgever-signature-document-id | UUID | FK (document-store) | Scanned signature or e-sign record |
| werknemer-signed-by-user-id | UUID | FK (user) | Employee who signed |
| werknemer-signed-on | TIMESTAMP | NULLABLE | When employee signature was affixed |
| werknemer-signature-document-id | UUID | FK (document-store) | Scanned signature or e-sign record |
| template-cao-id | UUID | FK (cao-engine) | Which CAO template was used as basis |
| re-integratiebudget-eur | DECIMAL 10,2 | NULLABLE | Per CAO, standard budget (e.g., EUR 4.500 voor Gemeenten) |
| created-at | TIMESTAMP | DEFAULT now() | Audit |
| updated-at | TIMESTAMP | DEFAULT now() | Audit |

**Validation rules:**
- `vastgesteld` status requires both werkgever-signed-on AND werknemer-signed-on NOT NULL
- If werknemer marks `niet akkoord`, status becomes `werknemers-bezwaar` and deskundigenoordeel-aanvraag is generated

---

### Entity: tweede-spoor-traject

**External reintegration bureau contract.** Opened at/before week 52 if 1e spoor (own employer) is exhausted.

| Field | Type | Constraint | Description |
|-------|------|-----------|-------------|
| traject-id | UUID | PK | Unique trajectory identifier |
| case-id | UUID | FK (wvp-case) | Reference to case |
| traject-status | ENUM | NOT NULL | concept \| actief \| voortgangsrapportage-overdue \| einddatum-verstreken \| afgesloten |
| re-integratiebureau-id | UUID | FK (partner-registry) | External bureau (must have Blik op Werk status) |
| contract-start-date | DATE | NULLABLE | When bureau engagement begins |
| contract-end-date | DATE | NULLABLE | When bureau engagement ends |
| contracted-amount-eur | DECIMAL 10,2 | NULLABLE | Total fee for trajectory |
| progress-rapportage-frequentie-days | INT | DEFAULT 90 | How often bureau must submit reports (typically 90 days) |
| last-voortgangsrapportage-date | DATE | NULLABLE | When last quarterly report was received |
| created-at | TIMESTAMP | DEFAULT now() | Audit |
| updated-at | TIMESTAMP | DEFAULT now() | Audit |

**Voortgangsrapporten:** Stored as documents linked to this traject via document-store.

**Risk detection:** If `today - last_voortgangsrapportage_date > (progress_rapportage_frequentie_days + 14)`, case flags as `2e-spoor-niet-bijgehouden-risico`.

---

### Entity: loondoorbetaling-line

**Payroll ledger for sick-leave compensation.** One line per case per pay-period. Linked to payroll-engine-nl for gross-loon calculation.

| Field | Type | Constraint | Description |
|-------|------|-----------|-------------|
| loondoorbetaling-id | UUID | PK | Unique ledger line |
| case-id | UUID | FK (wvp-case) | Reference to case |
| payroll-run-id | UUID | FK (payroll-engine-nl) | Reference to the monthly/biweekly run |
| pay-period-start | DATE | NOT NULL | Start of pay period |
| pay-period-end | DATE | NOT NULL | End of pay period |
| year-of-sickness | INT | NOT NULL | 1 or 2 (jaar 1 vs jaar 2; resets if 2e spoor) |
| refundable-loon-amount-eur | DECIMAL 10,2 | NOT NULL | Last-known-loon used as basis |
| percentage-applicable | DECIMAL 3,2 | NOT NULL | 0.70 (or higher per CAO-suppletie) |
| loondoorbetaling-gross-eur | DECIMAL 10,2 | NOT NULL | Calculated gross pay (may include minimum-loon floor in jaar 1) |
| cao-suppletie-eur | DECIMAL 10,2 | DEFAULT 0.00 | Additional pay per CAO (e.g., 100% jaar 1 voor Gemeenten = suppletie of 30% loon) |
| total-gross-eur | DECIMAL 10,2 | NOT NULL | loondoorbetaling-gross-eur + cao-suppletie-eur |
| days-paid | INT | NOT NULL | Number of calendar days in this period the employee was on sick-leave |
| sanction-extension-applied | BOOLEAN | DEFAULT false | True if loonsanctie extended this line (week 52 becomes week 104) |
| created-at | TIMESTAMP | DEFAULT now() | Audit |
| updated-at | TIMESTAMP | DEFAULT now() | Audit |

**Business logic:**
- jaar 1: `loondoorbetaling-gross-eur = MAX(refundable-loon-amount-eur * 0.70, wettelijk-minimum-loon)`
- jaar 2: `loondoorbetaling-gross-eur = refundable-loon-amount-eur * 0.70` (no minimum-loon floor)
- If loonsanctie is imposed, extend all future lines by sanctie-weeks

---

### Entity: eerstejaars-evaluatie

**Formal year-1 review (week 46-52).** Decision: continue 1e spoor or start 2e spoor.

| Field | Type | Constraint | Description |
|-------|------|-----------|-------------|
| evaluatie-id | UUID | PK | Unique evaluation record |
| case-id | UUID | FK (wvp-case) | Reference to case |
| scheduled-date | DATE | NOT NULL | When the meeting is scheduled |
| completed-date | DATE | NULLABLE | When the meeting actually occurred |
| completed-by-user-id | UUID | FK (user) | Casemanager who facilitated |
| besluit | ENUM | NULLABLE | voortzetting-1e-spoor \| start-2e-spoor \| wia-aanvraag-recommended |
| bedrijfsarts-opinion-document-id | UUID | FK (document-store) | Bedrijfsarts summary & recommendation |
| minutes-document-id | UUID | FK (document-store) | Meeting minutes signed by all parties |
| created-at | TIMESTAMP | DEFAULT now() | Audit |
| updated-at | TIMESTAMP | DEFAULT now() | Audit |

---

### Entity: eindeva-luatie-riva

**Final evaluation (week 87-91).** Assembles Re-integratieverslag (RIV) PDF-A with all supporting evidence.

| Field | Type | Constraint | Description |
|-------|------|-----------|-------------|
| riva-id | UUID | PK | Unique RIV record |
| case-id | UUID | FK (wvp-case) | Reference to case |
| requested-date | DATE | NOT NULL | When RIV assembly was initiated |
| completed-date | DATE | NULLABLE | When RIV PDF was generated |
| requested-by-user-id | UUID | FK (user) | Who triggered assembly |
| riva-pdf-document-id | UUID | FK (document-store) | Generated PDF-A file |
| riva-checksum | VARCHAR 64 | NULLABLE | SHA256 checksum of PDF for integrity |
| werknemer-signed-on | TIMESTAMP | NULLABLE | When employee signed the RIV |
| werknemer-signature-document-id | UUID | FK (document-store) | Signed copy or e-sign record |
| uwv-submitted-on | TIMESTAMP | NULLABLE | When RIV was transmitted to UWV |
| uwv-submission-reference | VARCHAR 32 | NULLABLE | UWV reference number for tracking |
| created-at | TIMESTAMP | DEFAULT now() | Audit |
| updated-at | TIMESTAMP | DEFAULT now() | Audit |

**RIV content:** Bundle of:
1. Probleemanalyse (from re-integratie-dossier)
2. FML (Functionele Mogelijkheden Lijst)
3. All PvA versions with signatures
4. Eerstejaars-evaluatie if applicable
5. All 6-weekly adjustments
6. 2e-spoor-rapportages (if 2e spoor was activated)
7. Eindevaluatie with bedrijfsarts opinion

---

## Seed Data

### Seed: wvp-case #1 – Active Case (Week 24)

```json
{
  "case-id": "c7a3d1e9-2f5a-4b2e-9c8f-1a6d4e2b7f9c",
  "tenant-id": "t-gemeente-amsterdam-001",
  "employee-id": "e-piet-jansen-002",
  "case-opening-date": "2026-02-15",
  "eerste-ziektedag": "2026-02-12",
  "expected-end-date": "2027-02-12",
  "actual-end-date": null,
  "case-status": "open",
  "percentage-arbeidsongeschikt": 100,
  "samenvoeging-4-weken-regel": false,
  "bedrijfsarts-id": "e-dr-hansen-arbo-001",
  "casemanager-id": "e-maria-hr-001",
  "cao-id": "cao-gemeenten",
  "loonsanctie-start-date": null,
  "loonsanctie-weeks": null,
  "created-by": "e-maria-hr-001",
  "created-at": "2026-02-15T08:30:00Z"
}
```

### Seed: wvp-case #2 – Loonsanctie Risk (Week 50, probleemanalyse missing)

```json
{
  "case-id": "a4b2e1f8-3c9d-4a7e-8b1f-2e5c9d1a3f6b",
  "tenant-id": "t-ministerie-justitie-001",
  "employee-id": "e-karin-van-dijk-003",
  "case-opening-date": "2025-12-20",
  "eerste-ziektedag": "2025-12-18",
  "expected-end-date": "2026-12-18",
  "actual-end-date": null,
  "case-status": "open",
  "percentage-arbeidsongeschikt": 75,
  "samenvoeging-4-weken-regel": false,
  "bedrijfsarts-id": "e-dr-pieterse-arbo-002",
  "casemanager-id": "e-jan-hr-002",
  "cao-id": "cao-rijk",
  "loonsanctie-start-date": null,
  "loonsanctie-weeks": null,
  "created-by": "e-jan-hr-002",
  "created-at": "2025-12-20T10:15:00Z"
}
```

### Seed: wvp-milestone – Probleemanalyse (pending)

```json
{
  "milestone-id": "m-prob-c7a3d1e9-001",
  "case-id": "c7a3d1e9-2f5a-4b2e-9c8f-1a6d4e2b7f9c",
  "milestone-type": "week-6-probleemanalyse",
  "due-date": "2026-03-26",
  "completed-date": null,
  "evidence-document-id": null,
  "gevolgen-bij-niet-naleven": "Geen probleemanalyse door week 6 → loonsanctie-risico. UWV kan loondoorbetaling weigeren en sanctie opleggen (tot 52 weken extra).",
  "status": "pending",
  "escalation-sent-at": null,
  "created-at": "2026-02-15T08:30:00Z"
}
```

### Seed: wvp-milestone – Probleemanalyse (escalated)

```json
{
  "milestone-id": "m-prob-a4b2e1f8-001",
  "case-id": "a4b2e1f8-3c9d-4a7e-8b1f-2e5c9d1a3f6b",
  "milestone-type": "week-6-probleemanalyse",
  "due-date": "2026-01-29",
  "completed-date": null,
  "evidence-document-id": null,
  "gevolgen-bij-niet-naleven": "Geen probleemanalyse door week 6 → case in loonsanctie-risico per UWV poortwachters-artikel.",
  "status": "at-risk",
  "escalation-sent-at": "2026-01-22T14:00:00Z",
  "created-at": "2025-12-20T10:15:00Z"
}
```

### Seed: plan-van-aanpak – Vastgesteld

```json
{
  "pva-id": "pva-c7a3d1e9-v1",
  "case-id": "c7a3d1e9-2f5a-4b2e-9c8f-1a6d4e2b7f9c",
  "version": 1,
  "pva-status": "vastgesteld",
  "doelstelling-re-integratie": "aangepast-werk-eigen-werkgever",
  "acties": [
    {
      "verantwoordelijke": "werkgever",
      "beschrijving": "Inzetbaarheidsgesprek voeren en functies inventariseren",
      "termijn-weken": 2,
      "status": "completed"
    },
    {
      "verantwoordelijke": "bedrijfsarts",
      "beschrijving": "FML (Functionele Mogelijkheden) opstellen",
      "termijn-weken": 4,
      "status": "in-progress"
    },
    {
      "verantwoordelijke": "werknemer",
      "beschrijving": "Deelnemen aan aanbiedingen aangepast werk",
      "termijn-weken": 12,
      "status": "pending"
    }
  ],
  "evaluatie-frequentie-weken": 6,
  "volgende-evaluatie-datum": "2026-04-12",
  "casemanager-id": "e-maria-hr-001",
  "werkgever-signed-by-user-id": "e-hr-manager-gemeente-001",
  "werkgever-signed-on": "2026-03-15T11:20:00Z",
  "werkgever-signature-document-id": "doc-pva-sig-w-001",
  "werknemer-signed-by-user-id": "e-piet-jansen-002",
  "werknemer-signed-on": "2026-03-16T09:45:00Z",
  "werknemer-signature-document-id": "doc-pva-sig-e-001",
  "template-cao-id": "cao-gemeenten",
  "re-integratiebudget-eur": 4500.00,
  "created-at": "2026-03-10T08:00:00Z"
}
```

### Seed: re-integratie-dossier – Probleemanalyse (encrypted)

```json
{
  "dossier-entry-id": "med-d1c9a7f2-e4b3-11eb-ba80-0242ac130003",
  "case-id": "c7a3d1e9-2f5a-4b2e-9c8f-1a6d4e2b7f9c",
  "tenant-id": "t-gemeente-amsterdam-001",
  "entry-type": "probleemanalyse",
  "bedrijfsarts-author-id": "e-dr-hansen-arbo-001",
  "recorded-date": "2026-03-20",
  "encrypted-payload": "[ENCRYPTED: AES-256-HSM / 1847 bytes]",
  "share-with-uwv-bij-riva": true,
  "employee-viewed-at": "2026-03-21T14:30:00Z",
  "created-at": "2026-03-20T15:45:00Z"
}
```

### Seed: loondoorbetaling-line – Year 1, Month 1

```json
{
  "loondoorbetaling-id": "ldb-c7a3d1e9-202602",
  "case-id": "c7a3d1e9-2f5a-4b2e-9c8f-1a6d4e2b7f9c",
  "payroll-run-id": "pr-gemeente-amsterdam-202602",
  "pay-period-start": "2026-02-15",
  "pay-period-end": "2026-02-28",
  "year-of-sickness": 1,
  "refundable-loon-amount-eur": 3200.00,
  "percentage-applicable": 0.70,
  "loondoorbetaling-gross-eur": 2240.00,
  "cao-suppletie-eur": 960.00,
  "total-gross-eur": 3200.00,
  "days-paid": 14,
  "sanction-extension-applied": false,
  "created-at": "2026-02-10T09:00:00Z"
}
```

---

## System Architecture

### Sequence: WVP-Case Creation

```
1. HR-medewerker submits ziekmelding via REST API (POST /ziekmelding)
2. Ziekmelding service:
   a. Checks employee-master for existing WVP-cases in past 28 days
   b. If none found, triggers CREATE wvp-case (status=open)
   c. Computes 11 milestone-due-dates from eerste-ziektedag
   d. Creates 11 wvp-milestone rows (all status=pending)
   e. Assigns bedrijfsarts per employee-master.bedrijfsarts-id
   f. Triggers notification-engine to email bedrijfsarts: "Probleemanalyse due by [date]"
   g. Returns case-id to HR-portal dashboard
3. HR-medewerker can now see case on "WVP-dossiers" sub-page with all milestones listed
4. Casemanager is notified via dashboard alert; can begin PvA prep
```

### Sequence: Probleemanalyse Submission & Escalation

```
1. Bedrijfsarts logs into segregated medical portal, uploads probleemanalyse PDF
2. Upload handler:
   a. Validates user-role = bedrijfsarts (security)
   b. Creates re-integratie-dossier entry with encrypted-payload (AES-256)
   c. Logs access to medical-access-audit table (reader-id, record-id, timestamp, IP)
   d. Updates wvp-milestone.completed-date, .evidence-document-id
   e. Triggers notification-engine: casemanager & employee notified
3. HR-views same case → medische-dossier shows only:
   - "Probleemanalyse received [date]" (no content visible)
   - Count of medical records (e.g., "3 medical entries, dates 2026-03-20 to 2026-03-28")
4. At day 28 and day 35, if milestone is still pending:
   - Nightly cron job triggers escalation-job
   - Sends email to bedrijfsarts: "Probleemanalyse due [due-date]"
   - Updates milestone.escalation-sent-at
5. At day 42 (week-6 deadline):
   - If milestone.completed-date is NULL, status → at-risk
   - Case status → loonsanctie-risico
   - HR receives alert: "Probleemanalyse missed — loonsanctie penalty at risk"
```

### Sequence: PvA Bilateral Signing

```
1. Casemanager opens case, clicks "PvA opstellen"
2. System fetches cao-id from case, loads CAO-specific PvA template
3. Casemanager fills in:
   - Doelstelling (volledig hervatting / aangepast werk / extern / WIA)
   - Acties array (verantwoordelijke, beschrijving, termijn, status)
   - Volgende evaluatie datum
4. System validates acties against PvA-template-schema
5. Casemanager signs (e-sign or scanned signature) → werkgever-signed-on = now()
6. System generates link, emails employee: "Review and sign your PvA"
7. Employee logs into self-service portal:
   - Views PvA (formatted, not raw JSON)
   - Clicks "Akkoord en ondertekenen" → signs
   - OR clicks "Niet akkoord, toelichting" → enters reason, status → werknemers-bezwaar
8. If akkoord:
   - pva-status → vastgesteld
   - casemanager notified
   - wvp-milestone (week-8-pva) → completed
9. If niet akkoord:
   - deskundigenoordeel-aanvraag template generated (per Artikel 32 WIA)
   - Employee receives form to submit to UWV for arbitration
   - Case flagged for escalation to management
```

### Sequence: RIV Export (Week 87-91)

```
1. Casemanager clicks "RIV samenstellen" at week 87
2. System queries:
   - wvp-case (all milestones completed or marked final)
   - wvp-milestone (all 11 records)
   - re-integratie-dossier entries where share-with-uwv-bij-riva = true AND employee-consent = true
   - plan-van-aanpak (all versions)
   - eerstejaars-evaluatie (if exists)
   - tweede-spoor-rapportages (if exists)
3. Document-template-engine renders master RIV template:
   - Bundles all documents into single PDF-A (ISO/IEC 19005-1 archival format)
   - Generates cover page with:
     - Case summary (employee, dates, milestones, outcomes)
     - Checksum (SHA256) of PDF content
     - UWV submission instructions
4. PDF stored in document-store; eindevaluatie-riva.riva-pdf-document-id = [doc-id]
5. Email to employee: "Your RIV is ready. Review and sign by [week-91-deadline]"
6. Employee logs into portal, reviews PDF in-browser, signs electronically
7. HR transmits signed RIV to UWV Werkgevers-Portal (REST API) with checksum validation
8. UWV-submission-reference returned; case notified
9. If employee does NOT sign by week 91:
   - HR receives alert: "Employee did not sign RIV; you may submit without signature per UWV instructions"
```

---

## API Surface

### Main Resources

**POST /wvp-cases** — Create a new case (called by ziekmelding-engine)  
**GET /wvp-cases/{case-id}** — Retrieve case details (role-filtered: medische dossier hidden for HR)  
**PATCH /wvp-cases/{case-id}** — Update case (casemanager) or percentage-arbeidsongeschikt (bedrijfsarts)  
**GET /wvp-cases/{case-id}/milestones** — List 11 milestones with due-dates and status  
**POST /wvp-cases/{case-id}/pvas** — Create PvA  
**PATCH /wvp-cases/{case-id}/pvas/{pva-id}** — Update PvA, sign  
**POST /wvp-cases/{case-id}/riva** — Trigger RIV assembly  
**GET /wvp-cases/{case-id}/medical-dossier** — Retrieve (bedrijfsarts only; HR sees metadata only)  
**POST /wvp-cases/{case-id}/loondoorbetaling-lines** — Create payroll ledger entry  
**GET /dashboard/wvp-alerts** — Casemanager dashboard (next-due milestones, overdue cases, escalations)  

### Medical Portal (Segregated)

**POST /medical/dossier-entries** — Bedrijfsarts uploads probleemanalyse, FML, etc. (encrypted)  
**GET /medical/dossier-entries/{entry-id}** — Retrieve (bedrijfsarts, employee, or UWV with consent)  
**GET /medical/dossier-entries/{entry-id}/audit-log** — Access-audit trail  

### Self-Service Portal (Employee)

**GET /self-service/my-case** — Employee views own case (no medical content, only metadata)  
**GET /self-service/my-pva** — Employee reviews PvA  
**POST /self-service/my-pva/{pva-id}/sign** — Employee signs or files objection  
**GET /self-service/my-riva** — Employee reviews RIV draft  
**POST /self-service/my-riva/{riva-id}/sign** — Employee signs RIV  

---

## Security & Encryption

**Medical-data encryption:** AES-256 with HSM key per tenant. Payloads encrypted at application layer before database storage. Decryption only allowed for authorized roles via explicit permission check.

**Row-level security:** `re-integratie-dossier` queries from HR-roles are transparently rewritten via Postgres RLS policies to return only count/date-range, never content.

**Access audit:** Every successful decryption logged with reader-id, record-id, timestamp, IP, access-purpose. Audit-log retained for 7 years per tax law.

**HTTPS-only:** All APIs TLS 1.2+, HSTS header enforced. E-sign integrations via qualified TSA (e.g., DigiD, KoppelingNL).

---

## Integration Touchpoints

**Upstream:**
- employee-master: Fetch dienstverband, voltijdsfactor, bedrijfsarts-assignment
- payroll-engine-nl: Send loondoorbetaling-lines for payroll run
- cao-engine: Fetch suppletie-percentages, re-integratiebudget defaults, PvA templates
- document-template-engine: Render PvA, eerstejaars-evaluatie, eindevaluatie, RIV templates
- notification-engine: Send escalation reminders, notifications, email alerts

**Downstream:**
- wnt-disclosure: Aggregates loondoorbetaling as topfunctionaris remuneration (if applicable)
- verzuim-kpi-dashboard: Consumes aggregated case-status data for metrics
- boekhouding-export: Reads loondoorbetaling-lines, posts to GL 4040 (social-security sick-leave)

**External:**
- UWV Werkgevers-Portal: 42-week melding submission, RIV-indiening, poortwachterstoets-response
- Arbodiensten (Arbo Unie, ArboNed, etc.): Probleemanalyse & spreekuur-verslag via OAGI/HL7-FHIR
- Blik op Werk register: Validate reintegration-bureau certifications

