---
status: design
---

# AOR Ambtenarenrecht — Design

## Architecture

### Data Layer

All domain data stored in OpenRegister with schemas registered in the `hrmq` register. App uses OpenRegister `ObjectService` for CRUD, `IndexService` for search+filtering, and `RelationService` for cross-entity links (register+schema+objectId). NO custom Entity/Mapper classes — follows ADR-001.

### Workflows & Lifecycles

Primary workflows use `x-openregister-lifecycle` for state transitions (schema-declarative per ADR-031):

1. **EmploymentCase lifecycle**: concept → in_behandeling → besluitvorming → afgerond | ingetrokken
2. **CaseStep workflow**: pending → in_progress → completed
3. **Klokkenluidermelding lifecycle**: registered → under_assessment → escalated | closed
4. **Besluit lifecycle**: concept → signed → notified → active | bezwaar_opened | beroep_opened

Transition guards for complex preconditions (e.g., CaseStep can only advance if assigned user role matches + dueDate not exceeded) defined in `lib/Lifecycle/` guard classes, invoked by `x-openregister-lifecycle.requires`.

### Notifications & Escalations

`x-openregister-notifications` for automated dispatch:
- EmploymentCase: Notify handler on state change; notify bestuur on escalation
- Klokkenluidermelding: Notify integriteitscoördinator on retaliation-check flag
- Besluit: Notify employee on signing (via Digitale Akte), handler on bezwaartermijn approaches (T-7, T-2, T-1)

Recipient resolvers handle role-based routing (e.g., bestuurssecretaris for collegevoorstel, vertrouwenspersoon for klokkenluider updates).

### Access Control & Confidentiality

Cases with `confidentialityLevel` ∈ {vertrouwelijk, geheim} use explicit ACL enforcement:
- `EmploymentCase.accessControlList[]`: array of role+userId tuples
- Vertrouwenspersoon role auto-grants access to klokkenluider-tier cases; regular HR roles see only standaard-tier
- Access denial logged with handler notification (ADR-005 security)
- Integration: `irma-digid-auth` for assurance-level gating on dossier access

### Document Management & Retention

- **docudesk integration**: All procedural documents, besluiten, hoorzitting-verslagen, and CRvB procesdossiers stored with `EmploymentCase.id` link + `retentionClass` metadata
- **Automatisch archivering**: On case afronding, `ArchivalService` assigns retentieklasse per Selectielijsten (ontslagdossiers: 75j post-geboorte; integriteitsmelding: 7j; tuchtbesluiten: 10j post-verwijdering)
- **Anonimisering/vernietiging**: Scheduled job (n8n workflow via ScheduledWorkflow entity) runs retention sweep, pseudonymises or destroys records per klasse, logs immutably
- **RiC-format export**: ArchivalService.exportForArchief() generates Records-in-Context MIAOU metadata for Nationaal Archief

### External Integrations

- **UWV portal**: `openconnector` adapter sends formuliereenset (A+B) + werkgevers-onderbouwing, receives beslissing status
- **Huis voor Klokkenluiders**: Export builder generates sanitised disclosure packet (melder-pseudonym, summary, supporting evidence, without BSN/persoon-gegevens)
- **Gemeentelijke besluitvorming-systemen** (iBabs, Notubiz): `openconnector` posts collegevoorstel, retrieves collegebesluitnummer + meeting-minutes
- **Berichtenbox/MijnOverheid**: Formal employee notifications (bezwaar-deadline, beroep-acknowledgement) via Digitale Akte envelope
- **payroll-engine-nl**: Case-driven payload (EmploymentCase → salaris-instructie) for loonopschorting, loonstop, transitievergoeding-payout, reversal-with-rente

### Calculations & Aggregations

**Derived fields** (via `x-openregister-calculations`):
- EmploymentCase: `daysUntilDueDate`, `riskStatus` (based on termijn proximity), `isEscalationEligible` (bestuur-level checks)
- Klokkenluidermelding: `isProtected` (now < protectedUntil), `daysSinceMelding`, `retaliationRiskLevel`
- Transitievergoeding: `grossAmount`, `netEstimate`, `overheidsToeslag`, `bovenwettelijkeComponent`

**Aggregations** (via `x-openregister-aggregations`):
- EmploymentCase: count-by-caseType, count-by-status, avg-days-to-completion, count-by-bestuur-escalation
- Klokkenluidermelding: count-by-year (trend reporting), count-by-subject-category, retaliation-checks-flagged, external-escalations-sent
- Besluit: count-by-bezwaar-outcome, count-by-appeal-outcome

These feed dashboards (mydash) and trend-reporting widgets (for integriteitscoördinator).

## Schemas

### EmploymentCase

Master record for a procedural case. Carries full audit trail, dossier folder link, and ACL.

```
EmploymentCase:
  type: object
  properties:
    caseNumber:
      type: string
      pattern: "^[A-Z]{2}\\d{4}-\\d{5}$"
      description: "Unique case identifier (e.g. ZA2026-00123)"
    employeeId:
      type: string
      description: "Reference to employee-master.Employee"
    caseType:
      type: string
      enum: [ontslag, integriteit, tucht, disciplinair, escalatie, beroep]
      description: "Primary workflow category"
    subType:
      type: string
      description: "Sub-category (e.g. 'ontslag-h-grond', 'tucht-schriftelijke-berisping')"
    status:
      type: string
      enum: [concept, in_behandeling, besluitvorming, afgerond, ingetrokken]
      description: "Current lifecycle state"
    openedAt:
      type: string
      format: date-time
    closedAt:
      type: string
      format: date-time
    caseHandlerId:
      type: string
      description: "Primary handler (HR-jurist or vertrouwenspersoon)"
    legalBasis:
      type: array
      items:
        type: string
      description: "Applicable legal references (BW art. 7:669, Awb §4:5, etc.)"
    summary:
      type: string
      description: "Case summary (max 500 chars)"
    confidentialityLevel:
      type: string
      enum: [standaard, vertrouwelijk, geheim]
      description: "Access control tier"
    accessControlList:
      type: array
      items:
        type: object
        properties:
          role: {type: string}
          userId: {type: string}
    auditTrail:
      type: array
      items:
        type: object
        properties:
          timestamp: {type: string, format: date-time}
          actor: {type: string}
          action: {type: string}
          before: {type: object}
          after: {type: object}
    dossierFolderId:
      type: string
      description: "Nextcloud Files folder (docudesk) for case documents"
    retentionClass:
      type: string
      enum: [ontslag-75j-post-geboorte, integriteit-7j, tucht-10j-post-verwijdering]
      description: "Archival retention schedule per Selectielijsten"
```

### CaseStep

A single procedural step (e.g., "Send hoorzitting uitnodiging", "Collect werkgevers-onderbouwing").

```
CaseStep:
  type: object
  properties:
    caseId:
      type: string
      description: "Reference to EmploymentCase"
    stepCode:
      type: string
      pattern: "^[A-Z]{3}-\\d{3}$"
      description: "Machine-readable step identifier (e.g. ONT-001, INT-005)"
    name_nl:
      type: string
      description: "Dutch step name (e.g. 'Hoorzitting uitnodiging verzenden')"
    name_en:
      type: string
      description: "English step name"
    dueDate:
      type: string
      format: date
      description: "Statutory or operational deadline"
    completedAt:
      type: string
      format: date-time
    assigneeId:
      type: string
      description: "User responsible for step (may be manager, HR-jurist, integriteitscoördinator)"
    outputDocumentId:
      type: string
      description: "Reference to docudesk Document (hoorzitting-verslag, werkgevers-onderbouwing, etc.)"
    slaCategory:
      type: string
      enum: [termijn-wettelijk, termijn-operational, alert-T7, alert-T2, alert-T1]
      description: "SLA tracking category for alerting"
    status:
      type: string
      enum: [pending, in_progress, completed]
```

### Besluit

Formal administrative decision with bezwaar/beroep clause.

```
Besluit:
  type: object
  properties:
    caseId:
      type: string
      description: "Reference to EmploymentCase"
    besluitType:
      type: string
      enum: [ontslagbesluit, schorsing-besluit, loonstop-besluit, tuchtbesluit, disciplinair-besluit]
    bevoegdGezag:
      type: string
      description: "Authorized decision-maker (e.g. 'HR-directeur', 'College van B&W')"
    signedById:
      type: string
      description: "User who signed the decision"
    signedAt:
      type: string
      format: date-time
    bezwaartermijn:
      type: integer
      description: "Days within which bezwaar can be filed (typically 6 weeks = 42 days)"
    bezwaarDeadline:
      type: string
      format: date
      description: "Auto-calculated from signedAt + bezwaartermijn"
    effectiveDate:
      type: string
      format: date
      description: "Date decision becomes effective (may be after notification period)"
    documentId:
      type: string
      description: "Reference to docudesk Document (PDF of signed besluit)"
    notificationLog:
      type: array
      items:
        type: object
        properties:
          notificationId: {type: string}
          channel: {type: string, enum: [digitale-akte, email, aangetekend-post]}
          sentAt: {type: string, format: date-time}
          acknowledgedAt: {type: string, format: date-time}
```

### Klokkenluidermelding

Protected whistleblower disclosure.

```
Klokkenluidermelding:
  type: object
  properties:
    caseId:
      type: string
      description: "Parent EmploymentCase (if exists)"
    melderType:
      type: string
      enum: [intern, extern, anoniem]
      description: "Disclosure source (employee, third-party, anonymous)"
    meldingChannel:
      type: string
      enum: [afdelingshoofd, vertrouwenspersoon, HvK, toezichthouder]
      description: "Channel through which disclosure was made"
    subject:
      type: string
      description: "Category of disclosure (e.g. 'integriteigeschending', 'veiligheid', 'milieu')"
    summary:
      type: string
      description: "De-identified summary of disclosure"
    protectedUntil:
      type: string
      format: date
      description: "Automatic 7 years post-melding per Wet bescherming klokkenluiders"
    retaliationCheckLog:
      type: array
      items:
        type: object
        properties:
          timestamp: {type: string, format: date-time}
          hrAction: {type: string}
          assessorId: {type: string}
          verdict: {type: string, enum: [retaliation-risk-high, retaliation-risk-low, safe-to-proceed]}
    huisVoorKlokkenluidersRef:
      type: string
      description: "External reference if escalated to Huis voor Klokkenluiders"
    melder_identity:
      type: object
      properties:
        fullName: {type: string}
        email: {type: string}
      description: "Stored encrypted, visible only to ≤2 named persons per ACL"
```

### Transitievergoeding

Severance calculation snapshot.

```
Transitievergoeding:
  type: object
  properties:
    caseId:
      type: string
      description: "Reference to EmploymentCase (termination case)"
    salaryComponents:
      type: object
      description: "Frozen salary snapshot {base, vakantiegeld, prestatiebonus, ...}"
    serviceYears:
      type: number
      description: "Complete years of service (calculated from contract.startDate)"
    ageAtTermination:
      type: integer
      description: "Employee age at termination date"
    formula:
      type: string
      description: "Statutory formula applied (e.g. '1/3 * maandsalaris * dienstjaren + evenreding')"
    grossAmount:
      type: number
      description: "Calculated statutory severance (1/3 rule)"
    overheidsToeslag:
      type: number
      description: "Government bonus if applicable (transitie-uitkering-verhoging)"
    bovenwettelijkeComponent:
      type: number
      description: "CAO-based above-statutory amount (e.g. BWNL, BWGS)"
    netEstimate:
      type: number
      description: "Estimated net after taxes (for employee transparency)"
    exclusionReason:
      type: string
      description: "If applicable (e.g. 'kleine-werkgever-uitzonderingBezwaar', 'AOW-gerechtigde-leeftijd')"
    exclusionCitation:
      type: string
      description: "Legal reference for exclusion (BW art. 7:673 lid X)"
    paidAt:
      type: string
      format: date
    approverIds:
      type: array
      items: {type: string}
      description: "Two signatories if manual override applied"
```

### IntegrityRegister

Organisation-wide integrity events index (anonymised).

```
IntegrityRegister:
  type: object
  properties:
    quarter:
      type: string
      pattern: "^\\d{4}-Q[1-4]$"
      description: "Reporting period (e.g. 2026-Q2)"
    meldingCount:
      type: integer
    meldingCountBySubject:
      type: object
      description: "{integriteitsschending: 3, veiligheid: 1, ...}"
    externalEscalationCount:
      type: integer
      description: "Escalations to HvK or toezichthouders"
    retaliationChecksTriggered:
      type: integer
    retaliationRisksIdentified:
      type: integer
    complianceNotes:
      type: string
      description: "Qualitative assessment for bestuur reporting"
    auditTrail:
      type: array
```

## Seed Data

### Example EmploymentCase Objects

```
Case 1: Standard Ontslag (Wnra context)
{
  "@self": { "register": "hrmq", "schema": "EmploymentCase", "slug": "zaak-ontslag-2026-001" },
  "caseNumber": "ON2026-00001",
  "employeeId": "emp-bernadette-99",
  "caseType": "ontslag",
  "subType": "ontslag-a-grond-bezuiniging",
  "status": "in_behandeling",
  "openedAt": "2026-05-15T09:00:00Z",
  "caseHandlerId": "user-hr-jurist-anna",
  "legalBasis": ["BW art. 7:669 sub a", "Wnra 2020"],
  "summary": "Ontslag wegens bedrijfseconomische omstandigheden (bezuiniging afdeling Communicatie)",
  "confidentialityLevel": "standaard",
  "accessControlList": [
    { "role": "hr-jurist", "userId": "user-hr-jurist-anna" },
    { "role": "hr-director", "userId": "user-hr-director-jan" }
  ],
  "dossierFolderId": "folder-zaak-ontslag-2026-001",
  "retentionClass": "ontslag-75j-post-geboorte"
}

Case 2: Integriteitsmelding (Protected tier)
{
  "@self": { "register": "hrmq", "schema": "EmploymentCase", "slug": "zaak-integriteit-2026-001" },
  "caseNumber": "IT2026-00001",
  "employeeId": "emp-harco-103",
  "caseType": "integriteit",
  "subType": "integriteit-meldplicht",
  "status": "in_behandeling",
  "openedAt": "2026-05-10T14:30:00Z",
  "caseHandlerId": "user-vertrouwenspersoon-petra",
  "legalBasis": ["Wet bescherming klokkenluiders", "Handboek Integriteit Overheid"],
  "summary": "Integriteitsmelding via vertrouwenspersoon betreffende mogelijke belangenverstrengeling",
  "confidentialityLevel": "vertrouwelijk",
  "accessControlList": [
    { "role": "vertrouwenspersoon", "userId": "user-vertrouwenspersoon-petra" },
    { "role": "integriteitscoordinator", "userId": "user-integriteitscoord-michel" }
  ],
  "dossierFolderId": "folder-zaak-integriteit-2026-001",
  "retentionClass": "integriteit-7j"
}

Case 3: Escalatie naar College
{
  "@self": { "register": "hrmq", "schema": "EmploymentCase", "slug": "zaak-escalatie-2026-001" },
  "caseNumber": "ES2026-00001",
  "employeeId": "emp-dirk-54",
  "caseType": "escalatie",
  "subType": "escalatie-college-bw-ontslag-directeur",
  "status": "besluitvorming",
  "openedAt": "2026-04-20T10:00:00Z",
  "caseHandlerId": "user-hr-director-jan",
  "legalBasis": ["Huishoudelijk Reglement college"],
  "summary": "Escalatie voor collegebesluit: ontslag directeur Dienst Ruimtelijke Ordening",
  "confidentialityLevel": "geheim",
  "accessControlList": [
    { "role": "hr-director", "userId": "user-hr-director-jan" },
    { "role": "collegesecretaris", "userId": "user-collegesec-simone" }
  ],
  "dossierFolderId": "folder-zaak-escalatie-2026-001",
  "retentionClass": "ontslag-75j-post-geboorte"
}
```

### Example CaseStep Objects

```
Step 1: Hoorzitting voor Ontslag
{
  "@self": { "register": "hrmq", "schema": "CaseStep", "slug": "step-hoorzitting-ontslag-2026-001" },
  "caseId": "zaak-ontslag-2026-001",
  "stepCode": "ONT-002",
  "name_nl": "Hoorzitting medewerker",
  "name_en": "Employee hearing",
  "dueDate": "2026-05-29",
  "assigneeId": "user-hr-jurist-anna",
  "slaCategory": "termijn-wettelijk",
  "status": "in_progress"
}

Step 2: Werkgeversbesluit voorbereiden
{
  "@self": { "register": "hrmq", "schema": "CaseStep", "slug": "step-werkgevers-ontslag-2026-001" },
  "caseId": "zaak-ontslag-2026-001",
  "stepCode": "ONT-003",
  "name_nl": "Werkgevers-onderbouwing opstellen",
  "name_en": "Employer brief preparation",
  "dueDate": "2026-06-05",
  "assigneeId": "user-hr-manager-kees",
  "slaCategory": "termijn-operational",
  "status": "pending"
}

Step 3: Klokkenluider retaliatie-check
{
  "@self": { "register": "hrmq", "schema": "CaseStep", "slug": "step-retaliation-check-int-2026" },
  "caseId": "zaak-integriteit-2026-001",
  "stepCode": "INT-001",
  "name_nl": "Retaliatie-risico-beoordeling",
  "name_en": "Retaliation risk assessment",
  "dueDate": "2026-05-11",
  "assigneeId": "user-integriteitscoord-michel",
  "slaCategory": "termijn-operational",
  "status": "completed",
  "completedAt": "2026-05-11T11:30:00Z"
}
```

### Example Besluit Objects

```
Besluit 1: Ontslagbesluit
{
  "@self": { "register": "hrmq", "schema": "Besluit", "slug": "besluit-ontslag-2026-001" },
  "caseId": "zaak-ontslag-2026-001",
  "besluitType": "ontslagbesluit",
  "bevoegdGezag": "HR-directeur",
  "signedById": "user-hr-director-jan",
  "signedAt": "2026-06-10T15:00:00Z",
  "bezwaartermijn": 42,
  "bezwaarDeadline": "2026-07-22",
  "effectiveDate": "2026-07-23",
  "documentId": "docudesk-doc-ontslag-2026-001",
  "notificationLog": [
    {
      "notificationId": "notif-001",
      "channel": "digitale-akte",
      "sentAt": "2026-06-11T09:00:00Z",
      "acknowledgedAt": "2026-06-11T10:15:00Z"
    }
  ]
}

Besluit 2: Disciplinair Besluit (Waarschuwing)
{
  "@self": { "register": "hrmq", "schema": "Besluit", "slug": "besluit-waarschuwing-2026-002" },
  "caseId": "zaak-disciplinair-2026-002",
  "besluitType": "disciplinair-besluit",
  "bevoegdGezag": "Lijnmanager",
  "signedById": "user-manager-dirk",
  "signedAt": "2026-05-20T13:00:00Z",
  "bezwaartermijn": 6,
  "bezwaarDeadline": "2026-05-26",
  "effectiveDate": "2026-05-21",
  "documentId": "docudesk-doc-waarschuwing-2026-002"
}
```

### Example Klokkenluidermelding Objects

```
Melding 1: Anoniem via vertrouwenspersoon
{
  "@self": { "register": "hrmq", "schema": "Klokkenluidermelding", "slug": "melding-anon-2026-001" },
  "caseId": "zaak-integriteit-2026-001",
  "melderType": "anoniem",
  "meldingChannel": "vertrouwenspersoon",
  "subject": "integriteigeschending",
  "summary": "Melder getuige van onterechte voorkeur in aanbesteding",
  "protectedUntil": "2033-05-10",
  "melder_identity": "[encrypted: hidden from default views]",
  "huisVoorKlokkenluidersRef": null,
  "retaliationCheckLog": []
}

Melding 2: Intern via HvK
{
  "@self": { "register": "hrmq", "schema": "Klokkenluidermelding", "slug": "melding-hvk-2026-001" },
  "caseId": null,
  "melderType": "intern",
  "meldingChannel": "HvK",
  "subject": "veiligheid",
  "summary": "Arbeidsomstandigheden afdeling niet conform CAO-bepalingen",
  "protectedUntil": "2033-05-15",
  "melder_identity": "[encrypted]",
  "huisVoorKlokkenluidersRef": "HvK-2026-3847",
  "retaliationCheckLog": [
    {
      "timestamp": "2026-05-20T08:00:00Z",
      "hrAction": "verplaatsing afdeling",
      "assessorId": "user-integriteitscoord-michel",
      "verdict": "retaliation-risk-high"
    }
  ]
}
```

### Example Transitievergoeding Objects

```
Transitie 1: Standaard berekening (1/3 regel)
{
  "@self": { "register": "hrmq", "schema": "Transitievergoeding", "slug": "transitie-2026-001" },
  "caseId": "zaak-ontslag-2026-001",
  "salaryComponents": {
    "base": 3500.00,
    "vakantiegeld": 500.00,
    "prestatiebonus": 0.00
  },
  "serviceYears": 12,
  "ageAtTermination": 48,
  "formula": "1/3 * (maandsalaris incl. toeslagen) * dienstjaren",
  "grossAmount": 14000.00,
  "overheidsToeslag": 2000.00,
  "bovenwettelijkeComponent": 5000.00,
  "netEstimate": 11200.00,
  "paidAt": "2026-07-23"
}

Transitie 2: Met uitsluitingsgrond (AOW-grens)
{
  "@self": { "register": "hrmq", "schema": "Transitievergoeding", "slug": "transitie-2026-002" },
  "caseId": "zaak-ontslag-2026-003",
  "salaryComponents": {"base": 2800.00},
  "serviceYears": 15,
  "ageAtTermination": 62,
  "formula": "Gedeeltelijke uitkering (AOW-grens bereikt)",
  "grossAmount": 7000.00,
  "overheidsToeslag": 0.00,
  "bovenwettelijkeComponent": 2000.00,
  "netEstimate": 6300.00,
  "exclusionReason": "AOW-gerechtigde-leeftijd",
  "exclusionCitation": "BW art. 7:673 lid 2",
  "paidAt": "2026-06-30",
  "approverIds": ["user-hr-director-jan", "user-finance-manager-rosa"]
}
```

## Reuse Analysis

**Existing OpenRegister services leveraged:**

- `ObjectService` — CRUD for all schemas (EmploymentCase, CaseStep, Besluit, Klokkenluidermelding, Transitievergoeding)
- `RelationService` — linking EmploymentCase ↔ CaseStep, Besluit ↔ documentId (docudesk), Klokkenluidermelding ↔ retaliationCheckLog
- `IndexService` — full-text search on EmploymentCase.summary, caseNumber, employeeId, for case discovery
- `ImportService/ExportService` — bulk case import (CSV of initial case data), export (procesdossier bundling for CRvB)
- `FileService` — attachment management within docudesk dossierFolderId
- `AuditTrailService` — immutable EmploymentCase.auditTrail, decision signing audit, termijn-expiry logging
- `NotificationService` — case-state notifications, termijn reminders, escalation alerts
- `AuthorizationService` + `PropertyRbacHandler` — field-level ACL enforcement on confidential cases
- `ArchivalService` + `RetentionService` — retentieklasse-driven anonimisering/vernietiging on schedule
- `TasksController` — CaseStep as task-tracking entities (optional integration)
- `CnDashboardPage` + `CnChartWidget` — handler SLA dashboard, integriteitscoördinator trend-widget (MyDash)

**Custom app-layer services needed** (NOT duplicating OR):

1. **TermijnCalculationService** — statutory termijn computation per caseType + legalBasis (BW 7:669 grounds, Awb bezwaartermijn, CRvB zittingsdatum, etc.)
2. **TransitionevergoedingCalculationService** — frozen-salary snapshot semantics, 1/3 rule + CAO overlays, overgangsregeling logic
3. **UWVIntegrationService** — openconnector adapter for formuliereenset A+B, werkgevers-onderbouwing submission, status polling
4. **DossierBundlingService** — procesdossier construction per CRvB instructies (chronological, derden-anonimisering), RiC-format archief export
5. **RetaliationCheckService** — HR-action-on-melder detection, risk assessment, auto-escalation to integriteitscoördinator
6. **BesluittemplateService** — template library for besluit-drafting (BW art. 7:669 grounds, Awb motivering clauses, bezwaar-termen), CAO-variant selection
7. **EscalatietoCollageService** — collegevoorstel template selection per municipality, B&W/DB agenda integration (iBabs/Notubiz), collegebesluitnummer registration
8. **PayrollMutationService** — case-to-payroll-engine-nl payload transformation (loonopschorting, loonstop, transitievergoeding, terugbetaling-met-rente)
9. **AccessControlEnforcementService** — melder-identity encryption/decryption, ACL-gate on case access, logging access denials

## Declarative-vs-Imperative Decision

**Lifecycle management is declarative** (x-openregister-lifecycle):
- EmploymentCase transitions (concept → in_behandeling → besluitvorming → afgerond)
- Klokkenluidermelding lifecycle (registered → under_assessment → escalated)
- Besluit signing & bezwaar/beroep tracking
- Transition guards invoke PHP services only for complex preconditions (TermijnCalculationService, RetaliationCheckService)

**Notifications are declarative** (x-openregister-notifications):
- Case-state-change notifications, termijn-reminder dispatch, escalation alerts
- Recipient resolution via role-based routing

**Calculations and aggregations are declarative** (x-openregister-calculations, x-openregister-aggregations):
- Derived fields: daysUntilDueDate, riskStatus, isProtected, retaliationRiskLevel
- Aggregations: case counts by type/status, avg throughput, trend counts (for integriteitscoördinator reporting)

**External API integrations remain imperative** (PHP service layer):
- UWVIntegrationService (openconnector adapter)
- Archief export (RiC-format builder)
- Payroll mutations (payroll-engine-nl instructie-gen)
- B&W/DB agenda integration (iBabs/Notubiz adapters)
