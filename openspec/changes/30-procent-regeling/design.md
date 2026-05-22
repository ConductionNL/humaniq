---
status: draft
---

# 30%-regeling Administratie — Design

## Context

The 30%-regeling is core Dutch tax law for expat compensation. Unlike generic HR features (expense reimbursement, allowances), the 30% regeling is:

1. **Highly regulated**: Belastingdienst issues per-employee beschikkingen; system must validate against exact legal bounds (5-year max, €46.660+ salary threshold, 150-km residency rule, WNT-norm capping).
2. **Financially material**: A single error (wrong percentage, wrong threshold check, missed cutoff) propagates across 5+ years of payroll, triggering multi-thousand-euro back-taxes + penalties.
3. **Process-heavy**: Requires annual toetsing, expiry management, intrekking paperwork, and Belastingdienst-audit-ready documentation.
4. **Evolving statutory**: Afbouw rules (30/20/10) changed in 2024; partial-non-resident choice ends in 2027; further changes pending in 2026 Belastingplan.

This design embeds all 10 requirements from context-brief.md into a schema-driven architecture leveraging OpenRegister + payroll-engine-nl integration, with a compliance-audit trail at every decision point.

## Goals

**Primary:**
- Eliminate manual 30%-regeling administrative burden (spreadsheets, email tracking, disconnected PDF files).
- Auto-validate beschikkingen against regulatory bounds on entry; prevent invalid configurations.
- Calculate correct 30%-loon-impact per medewerker per loonperiode without manual intervention.
- Audit every toetsing, intrekking, and corrective action with immutable trail for Belastingdienst-controles.
- Alert HR months in advance of expiry/threshold-risk so contract renegotiation or corrective action is planned, not reactive.

**Secondary:**
- Expose 30%-status in self-service portaal so medewerkers understand their tax position.
- Provide "bewijspakket" export (PDF) combining all relevant records for Belastingdienst-audit-readiness.
- Integrate with journaalpost-export and loonaangifte-digipoort for accounting and tax-filing automation.

## Non-Goals

- **Belastingdienst beschikking application**: System does not apply for beschikkingen or contact Belastingdienst. HR responsibility to obtain geldige beschikkingen offline; system registers them.
- **150-km-residency verification**: System accepts HR input; does not validate historical residence records against cadastral data.
- **Box 2 / Box 3 tax filing**: Tracks partial-non-resident choice but delegates actual IB-aangifte filing to user's tax software.
- **Payroll system overhaul**: Loon-impact integration assumes payroll-engine-nl is operational; does not replace payroll logic.
- **Cross-border taxation advice**: System enforces Wet LB 1964 bounds only; does not advise on US/German/Belgian tax treaty implications.

## Decisions

### Decision 1: Schema-driven entity model (OpenRegister)

**Approach**: All 30%-regeling entities (`Beschikking30`, `Beschikking30Periode`, `Beschikking30Toetsing`, `Beschikking30Intrekking`, `Beschikking30LoonImpact`, `Beschikking30AlertConfig`) are registered in `hrmq_register.json` as OpenRegister schemas.

**Rationale**: 
- OpenRegister provides immutable audit trail on every field change (required for Belastingdienst-controles).
- Lifecycle state (aangevraagd → actief → verlopen/ingetrokken) becomes declarative via `x-openregister-lifecycle`, eliminating custom state-machine code.
- Relations across medewerker/beschikking/periode are typed OpenRegister relations; avoids parallel link tables and keeps queries efficient.
- Schema-driven form generation (`CnFormDialog`) auto-validates on input (looptijd, salarisdrempel, WNT-norm).
- Seed data and test data populate automatically via register-template import.

**Per ADR-031**: Lifecycle transitions (beschikking → actief → verlopen, etc.) are declared in schema via `x-openregister-lifecycle`; background jobs trigger transitions based on date/drempel-outcome. No custom MotionService-style state-machine service.

### Decision 2: Automated monthly loon-impact via payroll-engine-nl integration

**Approach**: During each loonrun, payroll-engine-nl calls a webhook or rule-evaluator (`Beschikking30LoonImpactService::evaluateForMedewerker()`) for every medewerker with an active beschikking. The service:
1. Fetches the medewerker's active beschikking + toetsing-history + current periode.
2. Calculates 30%-vergoeding = bruto_loon × percentage (30/20/10 per afbouwjaar) × parttime-factor.
3. Applies WNT-norm capping: if bruto > WNT-norm, only the first €246k is eligible for 30%.
4. Returns (`vergoeding_30_bedrag`, `wnt_aftopping_bedrag`, `percentage_toegepast`) to payroll-engine-nl.
5. Payroll-engine-nl books the 30%-vergoeding as belastingvrij and the remainder as belastingplichtig loon.
6. Persists a `Beschikking30LoonImpactEntity` record for audit trail.

**Rationale**:
- Centralises 30%-calculation logic in one place; avoids duplication across loonrun variants (gewone loonrun, 13e maand, vakantiegeld, bonus).
- Decouples 30%-regeling module from payroll data structures; payroll-engine-nl owns loon-entry/booking; hrmq owns beschikking-validation/calculation-logic.
- Every month's impact is auditable: HR can drill into `Beschikking30LoonImpact` to verify correct percentage applied, WNT-capping, parttime-factor.

### Decision 3: Annual drempel-toetsing as scheduled background job

**Approach**: Every January 1st (or on medewerker status-change if dienstverband ends), a scheduled job (`Beschikking30ToetsingJob`) runs for each medewerker with an active beschikking:
1. Sums the prior-year fiscal loon (excl. 30%-vergoeding) from `Beschikking30LoonImpact` records.
2. Applies parttime-correctie if applicable (ouderschapsverlof, geboorteverlof, langdurig ziekteverzuim).
3. Compares geannualiseerd-loon to salarisdrempel (€46.660 for 2026, €35.468 for jong-onderzoekers).
4. If threshold NOT met, creates a `Beschikking30Toetsing` record with `drempel_gehaald = false` and auto-triggers intrekking.
5. If threshold MET, creates a record with `drempel_gehaald = true` and lifecycle remains active.
6. Persists the toetsing-record (immutable for audit).
7. Notifies HR of outcome (pass/fail).

**Rationale**:
- Automation eliminates manual spreadsheet tracking; every medewerker is checked annually without HR error.
- Parttime-correctie is baked in (per REQ-004); avoids under-paying high-value part-timers and penalizing over-earning part-timers.
- Transparent audit trail: Belastingdienst sees exact loon-sum, correction factors, threshold, and pass/fail outcome.

### Decision 4: Intrekking with terugwerkende kracht and correctieaangifte

**Approach**: When a beschikking is revoked (either via auto-trigger from drempel-toetsing or manual HR action), the `Beschikking30IntrekkingService`:
1. Creates a `Beschikking30Intrekking` entity with `effectieve_datum` (default = start of relevant year, or explicitly set for terugwerkende kracht).
2. Recalculates all affected `Beschikking30LoonImpact` records retroactively: what was booked as belastingvrij 30%-vergoeding is re-classified as belastingplichtig loon.
3. Computes `terugbetaling_door_werknemer_bedrag` = total over-paid 30%-vergoeding.
4. Generates a correctieaangifte loonheffingen (via `journaalpost-export`) and queues it for Digipoort filing.
5. Generates a journaalpost (boekhoudpakket) for the back-booked loon.
6. Sends HR + medewerker + accountant notification with summary of financial impact.

**Rationale**:
- Ensures the employer files correct (or corrected) loonheffingen with Belastingdienst; avoids silent compliance drift.
- Terugwerkende kracht is legally required; system supports both retroactive-to-jan-1 and retroactive-to-first-error scenarios.
- Audit trail records exactly when the intrekking occurred, who approved it, and what financial corrections were made.

### Decision 5: Alert escalation (expiry + drempel-risk)

**Approach**: Two alert channels, configured per administratie via `Beschikking30AlertConfig`:

1. **Looptijd-einde alert** (default: 180 days before expiry):
   - Day X: HR receives action-item "Medewerker X beschikking expires on date Y — plan contract renegotiation".
   - Day X+150: If no action taken, escalate to administratie-owner with "URGENT: expiry imminent".

2. **YTD-drempel-risk alert** (default: if YTD-loon within 5% below threshold):
   - Daily check: if YTD-loon geannualiseerd < (threshold × 0.95), generate alert "Medewerker X is €N below threshold — bonus/raise needed to preserve 30%-eligibility".
   - HR can dismiss alert if corrective action already planned.

**Rationale**:
- Months-in-advance notice allows HR to plan contract changes or salarisverhoging before expiry/threshold-failure.
- YTD-tracking is a leading indicator; HR can intervene mid-year instead of discovering failure at year-end toetsing.
- Escalation to administratie-owner ensures visibility; avoids HR-assistant missing low-priority emails.

### Decision 6: Partial-non-resident (PNR) deprecation flagging

**Approach**: 
- `Beschikking30` entity includes `partial_non_resident_gekozen: bool`.
- When HR saves/updates a beschikking with PNR=true and beschikkingsjaar=2026, system accepts and logs it.
- When beschikkingsjaar=2027+, system shows warning: "PNR-keuze is wettelijk vervallen per 2027 — valideer actuele belastingadvies" and rejects new PNR selections.
- Existing 2026 PNR-selections remain valid (not retroactively deleted).

**Rationale**:
- System embeds knowledge of statutory sunset; HR is not blindsided by 2027 regulatory change.
- Provides a compliance-audit point: if HR tries to mark PNR=true for 2027+, the rejection + log entry shows due diligence.

### Decision 7: Reuse Analysis

**OpenRegister services leveraged**:
- `ObjectService` (CRUD via `saveObject()`, `deleteObject()`, `findAll()`)
- `SchemaService` (schema validation on beschikking-entry via form-dialog)
- `RelationService` (typed relations: medewerker → beschikking, beschikking → periode/toetsing/intrekking)
- `AuditTrailService` (immutable change tracking on all entities)
- `NotificationService` (alerts via in-app + email + n8n)
- `FileService` (document-vault storage of gescande beschikkingen + bewijspakket PDF)

**No overlap with existing services**: 
- `employee-master` does NOT manage 30%-regeling data (only contributes geboortedatum/diploma/woonadressen).
- `payroll-engine-nl` does NOT store 30%-beschikkingen (only consumes loon-impact rules).
- No custom registration or relationship tables created outside OpenRegister.

### Decision 8: Bewijspakket export format

**Approach**: 
- `Beschikking30ExportService::generateBewijspakketPDF()` accepts (administratie_id, medewerker_id_list, year_range).
- Returns a single PDF with:
  1. Cover sheet: administratie-name, medewerker-list summary, date-range, export-date.
  2. Per-medewerker section:
     - Gescande beschikking (from document-vault).
     - All `Beschikking30Periode` records (jaren, percentages, salarisdrempel, WNT-norm).
     - All `Beschikking30Toetsing` records (dates, loon-summaries, drempel-outcome).
     - All `Beschikking30LoonImpact` records (monthly breakdown of 30%-vergoeding applied).
     - All `Beschikking30Intrekking` records (if any, with correctieaangifte-status).
  3. Summary table: medewerker, beschikking-nr, jaren, current-status, next-toetsing-date.

**Rationale**:
- Belastingdienst expects this exact format during boekenonderzoeken (per Loonheffingen Handboek).
- One PDF per administratie reduces export friction; HR can send it directly to auditor.
- Immutable records (from AuditTrailService) ensure Belastingdienst sees exact data that was active, not retroactively-edited snapshots.

## Seed Data

### Beschikking30 (5 examples per administratie)

```json
[
  {
    "@self": {
      "register": "hrmq",
      "schema": "Beschikking30",
      "slug": "30-eur-dev-2026"
    },
    "medewerker_id": "eur-dev-1",
    "administratie_id": "scale-up-1",
    "beschikkingsnummer": "30.000001/2025",
    "beschikkingsdatum": "2025-10-15",
    "vanaf": "2026-01-01",
    "tot": "2031-01-01",
    "oorspronkelijke_looptijd_jaren": 5,
    "categorie": "regulier",
    "partial_non_resident_gekozen": true,
    "salarisdrempel_van_toepassing": 46660,
    "bron_document_uri": "document-vault://30-eur-dev-2026.pdf",
    "aanvrager_intern": "hr-mgr-1",
    "status": "actief"
  },
  {
    "@self": {
      "register": "hrmq",
      "schema": "Beschikking30",
      "slug": "30-young-researcher-2025"
    },
    "medewerker_id": "eur-researcher-young",
    "administratie_id": "scale-up-1",
    "beschikkingsnummer": "30.000002/2024",
    "beschikkingsdatum": "2024-09-20",
    "vanaf": "2025-01-01",
    "tot": "2030-01-01",
    "oorspronkelijke_looptijd_jaren": 5,
    "categorie": "jonge_onderzoeker",
    "partial_non_resident_gekozen": false,
    "salarisdrempel_van_toepassing": 35468,
    "bron_document_uri": "document-vault://30-young-researcher-2025.pdf",
    "aanvrager_intern": "hr-mgr-1",
    "status": "actief"
  },
  {
    "@self": {
      "register": "hrmq",
      "schema": "Beschikking30",
      "slug": "30-expired-2023"
    },
    "medewerker_id": "eur-consultant-1",
    "administratie_id": "scale-up-1",
    "beschikkingsnummer": "30.000003/2022",
    "beschikkingsdatum": "2022-08-01",
    "vanaf": "2023-06-01",
    "tot": "2025-06-01",
    "oorspronkelijke_looptijd_jaren": 2,
    "categorie": "regulier",
    "partial_non_resident_gekozen": false,
    "salarisdrempel_van_toepassing": 46660,
    "bron_document_uri": "document-vault://30-expired-2023.pdf",
    "aanvrager_intern": "hr-mgr-2",
    "status": "verlopen"
  },
  {
    "@self": {
      "register": "hrmq",
      "schema": "Beschikking30",
      "slug": "30-revoked-thresh-2024"
    },
    "medewerker_id": "eur-pt-employee",
    "administratie_id": "scale-up-1",
    "beschikkingsnummer": "30.000004/2023",
    "beschikkingsdatum": "2023-07-10",
    "vanaf": "2024-01-01",
    "tot": "2029-01-01",
    "oorspronkelijke_looptijd_jaren": 5,
    "categorie": "regulier",
    "partial_non_resident_gekozen": false,
    "salarisdrempel_van_toepassing": 46660,
    "bron_document_uri": "document-vault://30-revoked-thresh-2024.pdf",
    "aanvrager_intern": "hr-mgr-1",
    "status": "ingetrokken"
  },
  {
    "@self": {
      "register": "hrmq",
      "schema": "Beschikking30",
      "slug": "30-univ-scientist-2026"
    },
    "medewerker_id": "univ-researcher-1",
    "administratie_id": "universiteit-1",
    "beschikkingsnummer": "30.000001/2025",
    "beschikkingsdatum": "2025-06-15",
    "vanaf": "2026-02-01",
    "tot": "2031-02-01",
    "oorspronkelijke_looptijd_jaren": 5,
    "categorie": "wetenschappelijk_onderzoeker",
    "partial_non_resident_gekozen": true,
    "salarisdrempel_van_toepassing": 46660,
    "bron_document_uri": "document-vault://30-univ-scientist-2026.pdf",
    "aanvrager_intern": "hr-mgr-univ",
    "status": "actief"
  }
]
```

### Beschikking30Periode (years within 30-eur-dev-2026)

```json
[
  {
    "@self": {
      "register": "hrmq",
      "schema": "Beschikking30Periode",
      "slug": "30-eur-dev-2026-jaar-1"
    },
    "beschikking_id": "30-eur-dev-2026",
    "jaar": 2026,
    "percentage": 30,
    "salarisdrempel_jaar": 46660,
    "wnt_norm_jaar": 246000,
    "actief": true
  },
  {
    "@self": {
      "register": "hrmq",
      "schema": "Beschikking30Periode",
      "slug": "30-eur-dev-2026-jaar-2"
    },
    "beschikking_id": "30-eur-dev-2026",
    "jaar": 2027,
    "percentage": 30,
    "salarisdrempel_jaar": 47500,
    "wnt_norm_jaar": 248000,
    "actief": true
  },
  {
    "@self": {
      "register": "hrmq",
      "schema": "Beschikking30Periode",
      "slug": "30-eur-dev-2026-jaar-3"
    },
    "beschikking_id": "30-eur-dev-2026",
    "jaar": 2028,
    "percentage": 20,
    "salarisdrempel_jaar": 48500,
    "wnt_norm_jaar": 250000,
    "actief": true
  }
]
```

### Beschikking30Toetsing (annual re-validation example)

```json
[
  {
    "@self": {
      "register": "hrmq",
      "schema": "Beschikking30Toetsing",
      "slug": "30-eur-dev-2026-toetsing-2025"
    },
    "beschikking_id": "30-eur-dev-2026",
    "toetsingsdatum": "2026-01-15",
    "toetsingsperiode": "2025",
    "bruto_loon_excl_30": 65000,
    "bruto_loon_geannualiseerd": 65000,
    "drempel_gehaald": true,
    "wnt_grens_overschreden": false,
    "aftopping_bedrag": 0,
    "conclusie": "continueren",
    "door_gebruiker_id": "system",
    "automatisch": true
  }
]
```

### Beschikking30AlertConfig (administratie defaults)

```json
[
  {
    "@self": {
      "register": "hrmq",
      "schema": "Beschikking30AlertConfig",
      "slug": "alert-scale-up-1"
    },
    "administratie_id": "scale-up-1",
    "looptijd_einde_waarschuwing_dagen_vooraf": 180,
    "drempel_marge_percentage": 5,
    "wnt_marge_percentage": 10,
    "actief": true
  },
  {
    "@self": {
      "register": "hrmq",
      "schema": "Beschikking30AlertConfig",
      "slug": "alert-universiteit-1"
    },
    "administratie_id": "universiteit-1",
    "looptijd_einde_waarschuwing_dagen_vooraf": 180,
    "drempel_marge_percentage": 3,
    "wnt_marge_percentage": 5,
    "actief": true
  }
]
```

---

**Design approved. Ready to spec requirements.**
