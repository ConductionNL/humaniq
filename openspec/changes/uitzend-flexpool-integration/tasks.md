---
name: uitzend-flexpool-integration-tasks
title: Implementation Tasks — Uitzendkrachten & Flexpool-integratie
version: 0.1.0
status: draft
---

# Implementation Tasks: Uitzendkrachten & Flexpool-integratie

## Phase 1: Data Model & Schema (Foundation)

### Task 1.1: Define schema for Bureau entity
- [ ] Create migration: add `Bureau` table with columns (kvk, naam, type, sna_keurmerk_status, sna_vervaldatum, nen_4400_1_certificaat, nen_4400_2_certificaat, g_rekening_iban, g_rekening_percentage, abu_of_nbbu_lid, contract_raamovereenkomst_ref, created_at, updated_at, created_by, updated_by)
- [ ] Add unique index on `kvk`
- [ ] Add validation constraints (g_rekening_iban NL only, sna_vervaldatum >= today if status=geldig)
- [ ] Write DB unit tests for Bureau creation, validation, and mutation

### Task 1.2: Define schema for InhuurOpdracht entity
- [ ] Create migration: add `InhuurOpdracht` table with columns (opdracht_nr, bureau_ref FK, kandidaat_naam, kandidaat_bsn_optional encrypted, inhurende_manager_ref FK, inhurende_kostenplaats FK, functie_titel, referent_eigen_functie_ref FK, cao_toepassing, fase, startdatum, geplande_einddatum, werkelijke_einddatum, uurtarief_inkoop, aantal_uren_per_week, werklocatie, status, inlenersbeloning_onderbouwing_ref FK, notes, created_at, updated_at, created_by, updated_by)
- [ ] Add unique index on `opdracht_nr` per year
- [ ] Add CHECK constraints (geplande_einddatum > startdatum, startdatum <= today+90d)
- [ ] Add foreign key constraints to Bureau, Employee (manager), FunctieProfiel (referent), InlenersBeloningOnderbouwing
- [ ] Write DB unit tests for InhuurOpdracht creation and state transitions

### Task 1.3: Define schema for InlenersBeloningOnderbouwing entity
- [ ] Create migration: add `InlenersBeloningOnderbouwing` table with columns (opdracht_ref FK, vaststellingsdatum, loon_per_uur_eigen_werknemer, adv_dagen_per_jaar, toeslagen_overzicht JSON, periodieken_staffel, kostenvergoedingen_overzicht JSON, vakantiebijslag_percentage, aanvullende_voorzieningen JSON, onderbouwing_document_url, geldig_tot, revised_from_id FK, created_at, created_by)
- [ ] Add CHECK constraint: all 6 elements must have non-null values when status=approved
- [ ] Add reference to document-storage for onderbouwing_document_url
- [ ] Write DB unit tests for onderbouwing creation and revision history

### Task 1.4: Define schema for UrenRegistratieFlex entity
- [ ] Create migration: add `UrenRegistratieFlex` table with columns (opdracht_ref FK, week_nr, jaar, uren_per_dag JSON, overuren, goedgekeurd_door_manager_ref FK, goedgekeurd_datum, factuur_ref FK, status, created_at, created_by, updated_at)
- [ ] Add CHECK constraint (sum(uren_per_dag) <= 10 per day, overuren validated per CAO)
- [ ] Add unique constraint on (opdracht_ref, week_nr, jaar) to prevent duplicate registrations
- [ ] Write DB unit tests for uren validation and approval workflow

### Task 1.5: Define schema for FactuurFlex entity
- [ ] Create migration: add `FactuurFlex` table with columns (bureau_ref FK, factuurnr, factuurdatum, periode_van, periode_tot, regels JSON array, subtotaal, btw, totaal, g_rekening_split JSON, status, match_afwijkingen JSON array, goedgekeurd_door_ref FK, goedgekeurd_datum, betaaldatum, created_at)
- [ ] Add unique constraint on (bureau_ref, factuurnr)
- [ ] Add CHECK constraints (periode_tot >= periode_van, totaal = subtotaal + btw)
- [ ] Write DB unit tests for FactuurFlex creation and matching logic

---

## Phase 2: API Endpoints & Core Logic

### Task 2.1: Bureau management API
- [ ] POST `/api/bureaus` — Create Bureau with SNA-validation hook (async call to KvK registry lookup)
- [ ] GET `/api/bureaus` — List all Bureaus with filtering (type, sna_status, etc.)
- [ ] GET `/api/bureaus/{id}` — Retrieve single Bureau detail
- [ ] PUT `/api/bureaus/{id}` — Update Bureau (sna_status, g_rekening_iban, etc.)
- [ ] POST `/api/bureaus/{id}/validate-sna` — Manual refresh of SNA-keurmerk status from KvK (trigger daily batch)
- [ ] Implement SNA-validation middleware: reject any Opdracht creation for bureau with sna_status != geldig or sna_vervaldatum < today
- [ ] Write API integration tests for Bureau endpoints

### Task 2.2: InhuurOpdracht management API
- [ ] POST `/api/inhuur-opdrachten` — Create Opdracht in draft status
  - Validate bureau_ref exists and passes SNA check
  - Validate inhurende_manager_ref is active employee
  - Validate referent_eigen_functie_ref exists
  - Block if candidaat_naam already exists as Employee
- [ ] GET `/api/inhuur-opdrachten` — List opdrachten with filtering (status, bureau, manager, cao, fase)
- [ ] GET `/api/inhuur-opdrachten/{id}` — Retrieve single opdracht detail with related onderbouwing and uren
- [ ] PUT `/api/inhuur-opdrachten/{id}` — Update opdracht (only draft fields before activation)
- [ ] POST `/api/inhuur-opdrachten/{id}/activate` — Change status from draft to actief (requires onderbouwing)
- [ ] POST `/api/inhuur-opdrachten/{id}/extend` — Extend geplande_einddatum
- [ ] POST `/api/inhuur-opdrachten/{id}/terminate` — Change status to beëindigd + set werkelijke_einddatum
- [ ] Implement state-transition guards (draft→actief requires onderbouwing, etc.)
- [ ] Write API integration tests for all Opdracht workflows

### Task 2.3: InlenersBeloningOnderbouwing API
- [ ] POST `/api/inlenersbeloning-onderbouwingen` — Create new onderbouwing
  - Validate all 6 elements present
  - Validate loon_per_uur >= CAO-minimum for referent-functie (query payroll-engine-nl)
  - Require onderbouwing_document_url (file upload)
  - Calculate geldig_tot = vaststellingsdatum + 12 months
- [ ] GET `/api/inlenersbeloning-onderbouwingen/{id}` — Retrieve onderbouwing detail with audit trail
- [ ] PUT `/api/inlenersbeloning-onderbouwingen/{id}` — Update onderbouwing (only draft status)
- [ ] POST `/api/inlenersbeloning-onderbouwingen/{id}/approve` — Approve onderbouwing for linking to Opdracht
- [ ] POST `/api/inlenersbeloning-onderbouwingen/{id}/revise` — Create new onderbouwing from existing (revised_from_id tracking)
- [ ] Implement CAO-mutation event listener: on `cao-staffel-mutation` from payroll-engine-nl, mark related onderbouwingen as "pending_review"
- [ ] Write API integration tests for onderbouwing workflows

### Task 2.4: UrenRegistratieFlex API
- [ ] POST `/api/uren-registraties` — Create uren registration
  - Validate uren_per_dag array (length 5, each <= 10)
  - Validate overuren per CAO rules
  - Create task for inhurende_manager with approval link
  - Set status = ingevoerd initially
- [ ] GET `/api/uren-registraties` — List uren with filtering (status, opdracht, week, manager)
- [ ] GET `/api/uren-registraties/{id}` — Retrieve single uren registration
- [ ] POST `/api/uren-registraties/{id}/approve` — Manager approves uren
  - Set status = goedgekeurd
  - Record goedgekeurd_door_manager_ref + goedgekeurd_datum
  - Mark task complete
- [ ] POST `/api/uren-registraties/{id}/reject` — Manager rejects with comment
  - Set status back to ingevoerd
  - Create follow-up task for coordinator/bureau contact
- [ ] Implement approval workflow scheduler:
  - 3 days after ingevoerd → send reminder email to manager
  - 7 days after ingevoerd → escalate to coordinator + finance-admin
- [ ] Write API integration tests for uren approval workflows

### Task 2.5: FactuurFlex import & matching API
- [ ] POST `/api/facturen/import` — Upload/import FactuurFlex
  - Parse CSV/PDF/JSON payload
  - Create FactuurFlex record with status = ontvangen
  - Trigger matching algorithm for each regel (see Task 3.2)
- [ ] GET `/api/facturen` — List facturen with status filtering
- [ ] GET `/api/facturen/{id}` — Retrieve factuur detail with match_afwijkingen
- [ ] POST `/api/facturen/{id}/match` — Trigger matching algorithm (or auto-run on import)
- [ ] POST `/api/facturen/{id}/dispute/{regel_idx}` — Mark single regel as disputed (create dispute task)
- [ ] POST `/api/facturen/{id}/approve-payment` — Approve factuur and trigger G-rekening split + betaalopdracht export
- [ ] Implement CSV parser for bureau invoice formats (template-based, extensible for different bureaus)
- [ ] Write API integration tests for import and matching

---

## Phase 3: Matching Logic & Automation

### Task 3.1: Uren approval scheduler
- [ ] Implement background job (cron): every night at 02:00
  - Find all UrenRegistratieFlex with status = ingevoerd + created_at > now - 3 days
  - Send reminder email to inhurende_manager
  - Find all UrenRegistratieFlex with status = ingevoerd + created_at > now - 7 days
  - Escalate to coordinator + finance-admin by moving task owner
- [ ] Implement task-management integration: create/update/complete tasks via task-management API
- [ ] Write unit tests for scheduler logic

### Task 3.2: Factuur-matching algorithm
- [ ] Implement `matchFactuurRegels()` function:
  1. For each regel in FactuurFlex.regels:
  2. Look up InhuurOpdracht by opdracht_ref (or by bureau_ref + period + fuzzy kandidaat match)
  3. Find all UrenRegistratieFlex for that opdracht in periode_van..periode_tot where status = goedgekeurd
  4. Sum uren_goedgekeurd → expected_uren
  5. Compare regel.uren vs. expected_uren:
     - If abs(uren_mismatch) <= 10% of expected: ✓
     - Else: add to match_afwijkingen with type = uren_mismatch
  6. Compare regel.tarief_per_uur vs. InhuurOpdracht.uurtarief_inkoop:
     - If match (tolerance ±0.01 EUR): ✓
     - Else: add to match_afwijkingen with type = tarief_mismatch
  7. Validate opdracht_ref exists:
     - If not found: add to match_afwijkingen with type = opdracht_onbekend
  8. Return status (all_matched / disputes_found)
- [ ] Implement dispute-task creation: for each afwijking, create task for finance-admin
- [ ] Write comprehensive unit tests for matching algorithm with edge cases

### Task 3.3: Fase-progression automation
- [ ] Implement `detectPhaseProgression()` function triggered on UrenRegistratieFlex approval:
  1. Query total gewerkte_uren for InhuurOpdracht (all approved uren since startdatum or since 2024-01-01 for ABU)
  2. For ABU: check if gewerkte_uren >= 52 weeks (2080 hours) AND fase = A
     - If yes: update fase to B + create tasks for coordinator + manager
  3. For NBBU: check thresholds (39, 78, 156 weeks)
     - If threshold crossed: update fase + create tasks
  4. For fase-B (ABU): check contract count in last 4 years
     - If creating new opdracht and count >= 6: block with warning
- [ ] Implement contract-count lookup (query all completed InhuurOpdrachten for kandidaat + bureau)
- [ ] Write unit tests for fase-progression logic

### Task 3.4: G-rekening-split calculation
- [ ] Implement `calculateGRekeningSlip()` function in FactuurFlex context:
  1. Get Bureau.g_rekening_percentage (default 25% if not set)
  2. Calculate bedrag_naar_g_rekening = FactuurFlex.totaal × percentage / 100
  3. Calculate bedrag_naar_reguliere_rekening = FactuurFlex.totaal - bedrag_naar_g_rekening
  4. Populate FactuurFlex.g_rekening_split with {percentage, bedrag_naar_g_rekening, bedrag_naar_reguliere_rekening}
- [ ] Implement validation: g_rekening_iban must be configured on Bureau
- [ ] Write unit tests for split calculation with edge cases (0%, 100%, decimal percentages)

---

## Phase 4: Frontend UI & Workflows

### Task 4.1: Sub-page structure (Medewerkers › Uitzendkrachten)
- [ ] Create route `/medewerkers/uitzendkrachten` with 5 tabs:
  1. Actieve opdrachten (list view)
  2. Urenregistratie (list + approval workflow)
  3. Bureaus & relaties (list + detail panel)
  4. Facturen (list + matching detail)
  5. TCO Dashboard (pivot + calculator)
- [ ] Implement tab navigation + route preservation (e.g., `/...?tab=bureaus`)
- [ ] Implement responsive layout (mobile: vertical tabs or dropdown menu)
- [ ] Write component/integration tests for page structure

### Task 4.2: Actieve opdrachten tab (list & detail)
- [ ] List view component:
  - Columns: opdracht_nr, kandidaat_naam, bureau, manager, functie, fase, startdatum, geplande_einde, uurtarief, acties
  - Filters: status, bureau, manager, cao, kostenplaats
  - Sorting: by column (default: created_at DESC)
  - Row actions: [Detail] → opens side panel
- [ ] Detail panel component:
  - Form sections: Basis info, Inhuring context, Tarificering, CAO & fase, Inlenersbeloning, Status & notes
  - Validation: inline error messages on blur
  - Actions: [Opslaan] [Activeer] [Heractiveer] [Beëindig] [Revisie inlenersbeloning]
  - Linked onderbouwing display (status badge, geldig_tot with warning if < 30d)
- [ ] Implement create workflow: [Nieuwe opdracht] → open detail panel in create mode
- [ ] Write component tests for list + detail interactions

### Task 4.3: Urenregistratie tab (approval workflow)
- [ ] List view component:
  - Show UrenRegistratieFlex records in in_afwachting_goedkeuring status
  - Columns: week, opdracht, kandidaat, uren, overuren, status, goedgekeurd_door, days_pending, acties
  - Highlight rows aged > 3 days (reminder) and > 7 days (escalation) in different colors
  - Row actions: [Review] → open detail panel
- [ ] Detail panel component:
  - Display: week_nr, opdracht_ref, uren_per_dag (table), totaal_uren, overuren
  - Actions: [Goedkeuren] [Afwijzen] [Aanpassingen vragen]
  - After action: show confirmation + return to list
- [ ] Integration with task-management: display task context if opened from task
- [ ] Write component tests for approval workflows

### Task 4.4: Bureaus & relaties tab (management & compliance)
- [ ] List view component:
  - Columns: Naam, Type, SNA status, SNA vervaldatum (with warning color if < 30d), G-rekening, Lid, Acties
  - Filters: type, sna_status
  - Row actions: [Detail]
- [ ] Detail panel component:
  - Form sections: Basis info (kvk, naam, type), Compliance (sna_status, sna_vervaldatum, nen certificaten, g_rekening_iban), CAO affiliation
  - Actions: [Opslaan] [Keurmerk-status updaten (manual refresh)] [Bekijk raamovereenkomst]
  - Display: count of active opdrachten for this bureau
- [ ] Implement SNA-status refresh action: call POST `/api/bureaus/{id}/validate-sna` and show result
- [ ] Write component tests for bureau management

### Task 4.5: Facturen tab (matching & payment)
- [ ] List view component:
  - Columns: Factuurnr, Periode, Bureau, Subtotaal, Totaal, Status, Disputes (count), Acties
  - Highlight status=dispute_open in red
  - Filters: status, bureau, periode
  - Row actions: [Detail]
- [ ] Detail panel component:
  - Display: all factuur fields (period, bureau, regels table, totaal, g_rekening_split, match_afwijkingen)
  - If status=dispute_open: show disputes table with resolution actions:
    - [Uren aanpassen in systeem] → link to UrenRegistratieFlex + re-approve flow
    - [Tariff-correctie afspreken] → email draft to bureau contact
    - [Handmatig goedkeuren] → override with note
    - [Afwijzen] → mark regel as rejected
  - If status=gematcht: show [Goedkeuren betaling] button
  - After approval: show G-rekening split breakdown + payment confirmation
- [ ] Implement CSV upload flow: [Upload factuur] → file picker → parse → trigger matching → display results
- [ ] Write component tests for matching + dispute workflows

### Task 4.6: TCO Dashboard tab (analytics & calculator)
- [ ] Pivot table component:
  - Rows: Cost center / Team (with manager name)
  - Columns: Aantal opdrachten, Totaal FTE, Gemiddeld uurtarief, Maandlast, Jaarlijkse kosten
  - Sorting: by jaarlijkse_kosten DESC
  - Drill-down: [Detail] → show list of underlying opdrachten
- [ ] Budget vs. realisatie column (if budget configured):
  - YTD gerealiseerd (EUR), % of budget, Prognose einde jaar
  - Cell color: green (<80%), orange (80-100%), red (>100%)
- [ ] Vs-vast calculator modal:
  - Inputs: FTE, Functie-titel, Huidige uurtarief, Salaris vast werknemer, Werkgeverslasten %, Verlof/ADV impact, Pensoen %
  - Outputs: Jaarlijkse inhuurkosten, Jaarlijkse vaste kosten, Break-even uren, Recommendation
  - Actions: [Opslaan] (save to dashboard state or export)
- [ ] Write component tests for pivot + calculator interactions

---

## Phase 5: Integration & Testing

### Task 5.1: Integrate with employee-master
- [ ] Verify FunctieProfiel lookup works (API call to employee-master)
- [ ] Verify Employee lookup for inhurende_manager works (with role validation)
- [ ] Implement listener for `cao-staffel-mutation` events from payroll-engine-nl
  - On event: find affected InhuurOpdracht records + mark onderbouwing as pending_review + create tasks
- [ ] Write integration tests for employee-master interaction

### Task 5.2: Integrate with task-management
- [ ] Implement task creation API calls for:
  - Uren approval tasks (post UrenRegistratieFlex creation)
  - Fase-progression notification tasks (post fase-update)
  - Inlenersbeloning-onderbouwing revision tasks (post CAO-mutation)
  - Factuur-dispute tasks (post matching with afwijkingen)
- [ ] Verify task links route back to correct details (Opdracht, Uren, etc.)
- [ ] Implement task-completion callbacks (e.g., mark uren as approved when task is done)
- [ ] Write integration tests for task workflows

### Task 5.3: Integrate with document-storage
- [ ] Implement file upload for `onderbouwing_document_url` (POST `/api/documents`)
- [ ] Implement file upload for `contract_raamovereenkomst_ref` (POST `/api/documents`)
- [ ] Verify document links work (download, preview)
- [ ] Write integration tests for document storage

### Task 5.4: Integrate with finance-export
- [ ] Implement FactuurFlex export to finance-export (on approval)
  - Create betaalopdracht with two lines (regular + g_rekening)
  - Include reference to FactuurFlex.id for audit trail
- [ ] Implement betaalopdracht status callback (on payment, update FactuurFlex.betaaldatum)
- [ ] Write integration tests for finance exports

### Task 5.5: Database migrations & seeds
- [ ] Create all 5 schema migrations (Bureau, InhuurOpdracht, InlenersBeloningOnderbouwing, UrenRegistratieFlex, FactuurFlex)
- [ ] Create seed data for local development (3 bureaus, 3 opdrachten, 3 onderbouwingen, 5 uren registrations, 1 factuur)
- [ ] Write migration tests (up/down rollback)
- [ ] Document migration sequence in DEVELOPMENT.md

### Task 5.6: Functional testing
- [ ] Test complete workflow for each requirement (REQ-UZI-001 through REQ-UZI-010):
  - REQ-001: Create opdracht → verify no Employee created → verify badge in organogram
  - REQ-002: Try to create opdracht with invalid bureau → verify blocked
  - REQ-003: Try to activate opdracht without onderbouwing → verify blocked
  - REQ-004: Trigger CAO-mutation → verify onderbouwing marked as pending_review + task created
  - REQ-005: Register 52 weeks of uren → verify fase changes to B + tasks created
  - REQ-006: Create payroll-opdracht with bepaalde/onbepaalde contract type → verify TCO impact
  - REQ-007: Register uren → verify manager gets approval task → approve → verify status changes
  - REQ-008: Import factuur → verify matching algorithm → check disputes → resolve → approve payment
  - REQ-009: Approve payment → verify G-rekening split calculated → verify betaalopdracht export
  - REQ-010: Open TCO Dashboard → verify metrics calculated → use vs-vast calculator
- [ ] Write automated functional tests (Cypress, Selenium, or similar)

### Task 5.7: Performance & security testing
- [ ] Load test: 100 concurrent users, 5000 opdrachten + 20000 uren + 500 facturen
  - Verify list views load in < 2s
  - Verify matching algorithm completes in < 30s for 500-regel factuur
- [ ] SQL query optimization: verify no N+1 queries in list/detail views
- [ ] Security: verify authorization checks for all endpoints (only managers can approve own-team uren, only finance can approve facturen, etc.)
- [ ] Test encrypted fields (kandidaat_bsn_optional): verify stored encrypted, never returned in API unless explicitly requested
- [ ] Write performance + security test suite

---

## Phase 6: Deployment & Documentation

### Task 6.1: Deployment checklist
- [ ] DB migrations applied + rollback tested
- [ ] All API endpoints tested in staging
- [ ] Frontend UI tested in browsers (Chrome, Firefox, Safari, mobile)
- [ ] Integration tests pass (employee-master, task-management, finance-export)
- [ ] Feature flag configured (if needed, enable for pilot group first)
- [ ] Monitoring + alerting set up for:
  - API error rates (threshold: > 1%)
  - Matching algorithm failures (threshold: > 5% of facturen)
  - Uren approval SLA violations (threshold: > 10% not approved within 7d)
  - G-rekening split calculation errors (threshold: 0 allowed)

### Task 6.2: User documentation
- [ ] Write guide: "Registratie van inhuur-opdrachten" (for coordinators)
  - Step-by-step bureau selection, candidate entry, onderbouwing linking, activation
  - Screenshots of each step
  - Common errors + solutions (e.g., "Bureau voldoet niet aan ketenaansprakelijkheid-eisen")
- [ ] Write guide: "Goedkeuring uren" (for managers)
  - Approve workflow, escalation process, dispute handling
- [ ] Write guide: "Factuur-matching & betaling" (for finance-admin)
  - Import workflow, dispute resolution, G-rekening split explanation, payment approval
- [ ] Write guide: "TCO-analyse" (for controllers)
  - Dashboard metrics, drill-down navigation, vs-vast calculator
- [ ] Create FAQ + troubleshooting guide

### Task 6.3: Compliance documentation
- [ ] Document WAADI compliance (inlenersbeloning 6-element check, SNA-keurmerk validation)
- [ ] Document WAB compliance (bepaalde vs. onbepaalde contract type, WW-premie impact)
- [ ] Document ABU/NBBU CAO compliance (fase-progression thresholds, contract-count limits)
- [ ] Document ketenaansprakelijkheid mitigation (G-rekening split, SNA-validation, audit trail)
- [ ] Document AVG compliance (BSN encryption, access logging, retention policy)
- [ ] Create Compliance Checklist for monthly internal audit

### Task 6.4: Training & rollout
- [ ] Conduct training sessions for:
  - Inhuur-coordinators (bureau management, opdracht registration, onderbouwing validation)
  - Managers (uren approval workflow, TCO view)
  - Finance-admin (factuur-matching, dispute resolution, payment approval, G-rekening-betaling)
  - Controllers (TCO dashboard, budget tracking, make-or-hire analytics)
- [ ] Record training videos for self-paced onboarding
- [ ] Schedule office hours (weekly) for first month post-launch
- [ ] Collect user feedback + iterate on UI/workflow

---

## Success Criteria

All tasks completed when:

- ✅ All 5 entities fully modeled with schema migrations
- ✅ All 17 API endpoints implemented and tested
- ✅ Uren approval scheduler runs nightly without errors
- ✅ Factuur-matching algorithm passes 100 edge-case tests
- ✅ Fase-progression automation detects all transitions (ABU A→B→C, NBBU 1→2→3→4)
- ✅ G-rekening split calculated correctly for all facturen (tolerance ± 0.01 EUR)
- ✅ Frontend UI complete with 5 tabs + detail panels
- ✅ All 10 REQ-UZI-* scenarios passing functional tests
- ✅ Integration tests with employee-master, task-management, finance-export, document-storage all passing
- ✅ Performance: list views load < 2s, matching < 30s for 500-regel factuur
- ✅ Security: all authorization checks in place, BSN encrypted, audit trail logged
- ✅ User documentation complete + training delivered
- ✅ Compliance documentation complete + audit checklist ready
- ✅ Monitoring + alerting configured

