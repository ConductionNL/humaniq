---
status: approved
---

# Specs: Offboarding workflow (Offboarding case entity)

## REQ-OFF-001: Reden and legal basis determinism

The system SHALL require selection of `reden` from a fixed enum and SHALL compute downstream toepasselijkheid (transitievergoeding applicable yes/no, UWV WW-melding required yes/no, bijzondere fiscale behandeling) deterministically from reden alone, with no manual override.

**Enum values:**
- `opzegging_werknemer` — employee-initiated termination
- `opzegging_werkgever_met_vergunning` — employer termination with statutory permission
- `opzegging_werkgever_ontbinding_kantonrechter` — employer termination via court dissolution
- `vaststellingsovereenkomst` — settlement agreement (both parties)
- `einde_tijdelijk_contract` — fixed-term contract natural expiry
- `ontslag_op_staande_voet` — immediate dismissal (just cause)
- `wederzijds_goedvinden` — mutual consent termination
- `pensionering` — retirement (age or request)
- `overlijden` — death
- `proeftijd_beëindigd` — probation period termination

**Acceptance Criteria:**

- **AC-001.1**: GIVEN `reden = opzegging_werknemer`, WHEN Offboarding is created, THEN `transitievergoeding_van_toepassing = true`, `ww_melding_uwv_vereist = false`, and `transitievergoeding_grondslag = "wet_wwz_2026"`.

- **AC-001.2**: GIVEN `reden = ontslag_op_staande_voet`, WHEN Offboarding is created, THEN `transitievergoeding_van_toepassing = false` with grondslag `art_7:673_lid_7_bw` (just-cause exemption), and `ww_melding_uwv_vereist = false`.

- **AC-001.3**: GIVEN `reden ∈ [opzegging_werkgever_met_vergunning, opzegging_werkgever_ontbinding_kantonrechter, vaststellingsovereenkomst, einde_tijdelijk_contract]`, WHEN Offboarding is created, THEN `ww_melding_uwv_vereist = true` and UWV WW-drafting is auto-triggered.

- **AC-001.4**: GIVEN `reden = pensionering`, WHEN Offboarding is created, THEN `transitievergoeding_van_toepassing = false` with grondslag `pensioenwet_art_2b`, and `ww_melding_uwv_vereist = false`.

- **AC-001.5**: A user attempting to edit `reden` after case reaches status `eindafrekening_berekenen` SHALL be rejected with error "Reden kan niet gewijzigd worden na berekening eindafrekening".

---

## REQ-OFF-002: Eindafrekening computation

The system SHALL compute transitievergoeding per Wet WWZ 2026 formula: `(dienstjaren × 1/3 × maandsalaris) × (dagen_gewerkt_dit_jaar / 365)`, with all components of maandsalaris as defined by BW 7:673 + Besluit loonbegrip (vast brutoloon, vakantiegeld, vaste ploegentoeslag, vaste eindejaarsuitkering, structured overtime, structured bonus). Every component SHALL be auditable (all inputs + exact formula per month visible in audit-table).

**Acceptance Criteria:**

- **AC-002.1**: GIVEN employee with 4 years 7 months service and monthly salary (incl. components) €3950, WHEN eindafrekening is computed, THEN `dienstjaren = 4.583`, `transitievergoeding = (4.583 × 1/3 × 3950) ≈ 6033.33 EUR`, rounded to cent.

- **AC-002.2**: GIVEN a 55-month employment period, WHEN eindafrekening is computed, THEN all 55 months with their grondslag per month are visible in an audit-table (exportable as CSV/PDF).

- **AC-002.3**: GIVEN an employee with bonussen averaged over 36 months, WHEN eindafrekening is computed, THEN the system derives the 36-month average and includes it in maandsalaris calculation per Besluit loonbegrip.

- **AC-002.4**: GIVEN 2026 transitievergoeding max indexed per WAB + inflatiecorrectie, WHEN eindafrekening is computed, THEN the gross amount is capped at the 2026 statutory max (approx. €202,000 for 2026) and the cap rationale is logged.

- **AC-002.5**: Verlof uren (statutory + extra-statutory) SHALL be recalculated from the employee-master leave-balance API at computation time, not cached.

- **AC-002.6**: Vakantiegeld over the current period (1 juni to einddatum) SHALL be pro-rata calculated: `(grondslag × 8%) × (days_in_period / 365)`, minus already-paid portions.

---

## REQ-OFF-003: Verlof and vakantiegeld settlement

The system SHALL calculate leave balance at einddatum with clear distinction between statutory leave (expires 6 months post accrual) and extra-statutory leave (expires 5 years post accrual), and SHALL pay out the balance at gross hourly rate on einddatum. Vacation money over the current period (1 juni to einddatum) SHALL be pro-rata paid, minus previously paid amounts in that period.

**Acceptance Criteria:**

- **AC-003.1**: GIVEN employee with 38 uren statutory leave and 12.5 uren extra-statutory leave on einddatum, gross hourly rate €28.45, WHEN eindafrekening is computed, THEN statutory amount is €1081.10 and extra-statutory amount is €355.63.

- **AC-003.2**: GIVEN statutory leave expired more than 6 months ago, WHEN eindafrekening is computed, THEN expired saldo is zero (not paid).

- **AC-003.3**: GIVEN extra-statutory leave expiring more than 5 years ago, WHEN eindafrekening is computed, THEN expired saldo is zero (not paid).

- **AC-003.4**: GIVEN employee has taken leave in the current vacation period (1 juni – einddatum), WHEN eindafrekening is computed, THEN the vacation money grondslag is adjusted backwards to exclude paid periods.

---

## REQ-OFF-004: Eindafrekening freeze and payroll handoff

The system SHALL freeze every Eindafrekening after approval by role `hr_admin`, making it immutable. A frozen eindafrekening SHALL only be modifiable by retraction (with `ingetrokken_op` + reason); retracting post-payment is only permitted with explicit `correctie_naheffing` flag.

**Acceptance Criteria:**

- **AC-004.1**: GIVEN an approved (bevroren=true) eindafrekening, WHEN a user attempts direct field edit, THEN the system rejects with "Bevroren eindafrekening kan niet worden gewijzigd. Intrekken en opnieuw aanmaken".

- **AC-004.2**: GIVEN an approved eindafrekening, WHEN HR-admin selects "Intrekken" with a reason (e.g., "verlof saldo correctie"), THEN the old eindafrekening is marked `ingetrokken_op`, a new version is auto-created, and payroll receives a correction message.

- **AC-004.3**: GIVEN an approved eindafrekening that was already paid (payroll_run_id is set), WHEN HR-admin attempts retraction WITHOUT `correctie_naheffing = true`, THEN the system rejects with "Correctie na uitbetaling vereist expliciete vlag correctie_naheffing".

- **AC-004.4**: GIVEN a retracted eindafrekening post-payment, WHEN the replacement is approved, THEN the delta amount is scheduled for the next payroll run (or back-pay via standalone betaling if inter-period).

---

## REQ-OFF-005: IT account deactivation with data-export

The system SHALL offer full Nextcloud data-export (all personal files, calendar, contacts, Talk history) to departing employee on employee-designated channel (14-day download link OR USB-stick mailing). Only after export is provisioned SHALL the account be marked `disabled` (no login, mail-forward to manager active). Account SHALL be fully deleted 30 calendar days post-last-workday.

**Acceptance Criteria:**

- **AC-005.1**: GIVEN a leaver entering `data_export_werknemer` step, WHEN system prompts for export channel, THEN employee selects between "Download link (14 days)" or "USB-stick by mail"; system records choice.

- **AC-005.2**: GIVEN employee selecting download-link, WHEN data-export is prepared, THEN a secure link with 14-day expiry is generated and sent via secure email channel.

- **AC-005.3**: GIVEN employee selecting USB-stick, WHEN data-export is prepared, THEN system creates a postal form (employee's address auto-filled) and routes to admin for USB-stick creation + mailing.

- **AC-005.4**: GIVEN download-link or USB-stick provisioned, WHEN `it_accounts_deactiveren` step is entered, THEN Nextcloud user is set to `disabled` via OCS API, and mail-forwarding to manager is configured.

- **AC-005.5**: GIVEN last_werkdag = 2026-07-29, WHEN 30 calendar days elapse (2026-08-28), THEN Nextcloud user is fully deleted via OCS API with audit-log entry.

- **AC-005.6**: Mail-forwarding during the 30-day window SHALL process arriving mail to manager or functional mailbox, with external senders unaware of the disable (no bounce-back exposure of personal data).

---

## REQ-OFF-006: UWV WW-melding

The system SHALL auto-draft an UWV WW-aanmeldbericht for all terminations where `ww_melding_uwv_vereist = true`, including: reden, einddatum, last-earned salary, termination-agreement PDF (if applicable). The message SHALL be submitted via openconnector within the statutory deadline (day of departure or next business day).

**Acceptance Criteria:**

- **AC-006.1**: GIVEN `reden = opzegging_werkgever_met_vergunning` and einddatum = 2026-07-31, WHEN `uwv_ww_melding` step is reached, THEN system auto-drafts message with reason code, final salary, and sends via openconnector by 2026-07-31 EOD.

- **AC-006.2**: GIVEN a termination-agreement PDF in the dossier (stored in FileService), WHEN UWV WW-melding is drafted, THEN the PDF is attached to the outbound message.

- **AC-006.3**: GIVEN UWV submission succeeds, WHEN response is received, THEN `ww_melding_uwv_status` is set to `bevestigd` and confirmation details are logged.

- **AC-006.4**: GIVEN UWV submission fails (network error, validation error), WHEN retry-window expires (24 hours), THEN system escalates to HR-owner with error details and manual submission option.

---

## REQ-OFF-007: Pensioenfonds + ZVW-afmelding

The system SHALL auto-submit pensioenfonds-afmelding (per-fund mapping in settings) and ZVW-afmelding via openconnector within statutory deadlines. Confirmation SHALL be tracked in dossier; absence of confirmation after 14 days SHALL escalate to HR-owner.

**Acceptance Criteria:**

- **AC-007.1**: GIVEN `reden ≠ pensionering` (active pension arrangement), WHEN `pensioenfonds_afmelding` step is entered, THEN system identifies employee's fund(s) from employee-master, drafts per-fund termination message, and submits via openconnector.

- **AC-007.2**: GIVEN pensioenfonds submission, WHEN confirmation is received within 7 days, THEN `pensioenfonds_afmelding` step status is set to `completed` and reference logged.

- **AC-007.3**: GIVEN no pensioenfonds confirmation after 14 days, WHEN escalation-check runs (daily at 08:00 CET), THEN system creates a task for HR-owner to follow up manually.

- **AC-007.4**: GIVEN `reden` is any (all employment types), WHEN `zvw_afmelding` step is entered, THEN system submits ZVW-afmelding (health insurance termination) via openconnector with end-date = einddatum.

---

## REQ-OFF-008: Getuigschrift (work certificate)

The system SHALL generate a getuigschrift per art. 7:656 BW on employee request, including minimum: type of work, duration of service, manner of work performance, and departure date/reason (only if requested by employee). Qualitative assessment only if explicitly requested. Manager signature via eIDAS. Rendered by docudesk.

**Acceptance Criteria:**

- **AC-008.1**: GIVEN an employee requesting getuigschrift at any offboarding stage, WHEN HR-officer selects "Getuigschrift aanvragen", THEN system opens a form with pre-filled fields (employee name, position, start date, end date) and options: (a) feitelijk only, (b) feitelijk + kwalitatief assessment (if consented).

- **AC-008.2**: GIVEN feitelijk-only request, WHEN docudesk-template is rendered, THEN output includes: aard werkzaamheden (job description), duur dienstverband (duration), wijze van werkuitvoering (manner), and departure date. NO qualitative judgment.

- **AC-008.3**: GIVEN kwalitatief-request with manager consent, WHEN docudesk-template is rendered, THEN output includes above PLUS manager's brief (1-3 sentences) assessment of performance/conduct. Assessment must be factual, not subjective.

- **AC-008.4**: GIVEN rendered certificate, WHEN manager is prompted for eIDAS signature, THEN system opens secure signature envelope via docudesk. After signing, certificate is locked and marked `verstrekt_op` with delivery date.

- **AC-008.5**: GIVEN certificate is signed, WHEN 1 business day passes, THEN system delivers signed PDF to employee (via secure email link or print-postal) and archives in FileService.

---

## REQ-OFF-009: Retention timers and cryptographic destruction

The system SHALL create RetentionTimer objects on case completion (afgerond_op) for every artefact category with the correct statutory timer (7-year fiscal, 5-year labour, 2-year recruitment, other). At timer expiry, artefacts SHALL be cryptographically destroyed with immutable audit entry. All timers SHALL be queryable by auditor + AVG-functionaris.

**Acceptance Criteria:**

- **AC-009.1**: GIVEN Offboarding.afgerond_op = 2026-07-31 with a WID-Kopie document, WHEN case is marked complete, THEN system auto-creates `RetentionTimer` with `artefact_type = wid_kopie`, `gestart_op = 2026-07-31`, `vervalt_op = 2031-07-31` (5y per Uitvoeringsregeling Loonbelasting).

- **AC-009.2**: GIVEN 5-year timer for Salarisstrook starting 2026-07-31, WHEN calendar reaches 2031-07-31, THEN destruction-job runs, FileService document is cryptographically erased (key deletion if encrypted), and `vernietigd_op = 2031-07-31` is logged.

- **AC-009.3**: GIVEN a 90-day exit-interview anonymization window, WHEN 90 days pass from case completion, THEN `ExitInterview.antwoorden` is re-hashed (identifiable fields replaced) and PersonalData references removed. All destruction is logged.

- **AC-009.4**: GIVEN auditor querying retention-timers, WHEN auditor filters by `artefact_type` and `grondslag`, THEN system returns all matching timers (active + destroyed) with full audit-chain.

- **AC-009.5**: GIVEN destroyed artefact, WHEN auditor requests the original document, THEN system returns "Document vernietigd op [date] per grondslag [basis] met methode [key_destruction | overwrite_7pass]" without exposing content.

---

## REQ-OFF-010: Audit and GDPR data subject access

The system SHALL export the entire offboarding dossier (all steps, all changes, all calculations, all data-exports, all retention-actions) as a searchable PDF within 4 weeks of request (art. 12.3 AVG), with auto-pseudonymization of third-party references (colleague names, external contact names).

**Acceptance Criteria:**

- **AC-010.1**: GIVEN employee's GDPR data-subject-access request received 2026-06-01, WHEN HR-officer selects "GDPR-inzage exporteren", THEN system generates a PDF containing: all Offboarding fields, all OffboardingStep records, full Eindafrekening (with component audit-table), all ExitInterview answers (anonymized per policy), all EquipmentReturn records, all correspondence, all audit-log entries (before/after for every field).

- **AC-010.2**: GIVEN dossier-PDF generation, WHEN external party names appear (manager, IT-owner, colleague in handover), THEN names are replaced with pseudonyms "Manager [M01]", "IT-Owner [T01]" with a separate pseudonym-key (not provided to data-subject, retained for internal audit).

- **AC-010.3**: GIVEN dossier request on 2026-06-01, WHEN PDF is generated, THEN system notifies data-subject of availability by 2026-06-29 (within 4-week deadline per AVG).

- **AC-010.4**: GIVEN sensitive financial data in dossier (Eindafrekening components, bank routing), WHEN PDF is generated, THEN file is password-protected and delivery is via secure channel only (no unencrypted email).

---

## REQ-OFF-011: Manager-handover checklist

The system SHALL activate a mandatory manager-handover checklist at every offboarding with categories: active projects (assignment of receiver), active client contacts (relationship-transfer status), external system access (re-assignment), key meetings (successor introduction schedule), tacit-knowledge memos. Every open position SHALL be explicitly closed with a receiver assignment or justified "no-transfer-needed" reason. The checklist SHALL be exportable to successor without leaking personal offboarding data.

**Acceptance Criteria:**

- **AC-011.1**: GIVEN Offboarding enters `manager_handover` step, WHEN manager logs in, THEN system presents a structured form with sections: (1) Lopende projecten, (2) Actieve klantcontacten, (3) Externe systeem-toegangen, (4) Sleutel-vergaderingen, (5) Tacit-knowledge memos.

- **AC-011.2**: GIVEN manager enters 8 lopende projecten in handover, WHEN manager attempts to mark step complete without assigning a receiver per project, THEN system blocks completion with: "8 projecten wachten op toewijzing ontvanger: [list]".

- **AC-011.3**: GIVEN project "Client X onboarding" with no clear successor, WHEN manager selects "Geen overdracht nodig — project afgerond", THEN system logs reason and the project is considered closed (not open).

- **AC-011.4**: GIVEN completed handover-checklist, WHEN "Exporteer voor opvolger" is selected, THEN system generates PDF with project/contact/access details ONLY — no personal offboarding data (no salary, no reason, no exit-interview feedback).

- **AC-011.5**: GIVEN handover-checklist export, WHEN successor receives PDF, THEN successor can use it as operational runbook without learning employee's departure reason or financial settlement.

---

## REQ-OFF-012: Goodbye communication

The system SHALL support drafting a team announcement (template + free-text) with optional external contact notification and successor assignment. Announcement SHALL be configurable (Dutch + English) and distribute via Nextcloud Talk or email. External contacts SHALL receive information on new point-of-contact to prevent silent forwarding of personal data.

**Acceptance Criteria:**

- **AC-012.1**: GIVEN Offboarding enters `goodbye_message` step, WHEN HR-officer selects "Goodbye-bericht opstellen", THEN system opens template editor with pre-filled sections: (1) Departure date, (2) Role, (3) Successor (if assigned), (4) Free-text custom message, (5) Distribution channel (Nextcloud Talk / Email), (6) Recipient list (team / organization-wide).

- **AC-012.2**: GIVEN template with English + Dutch options, WHEN HR-officer generates message, THEN both language versions are created side-by-side (Nextcloud Talk thread shows both; email includes both).

- **AC-012.3**: GIVEN message includes external contact notification, WHEN HR-officer selects "Inform external contacts", THEN system prompts for external contact list (from CRM / manual entry) and generates personalized message: "Dear [contact], [Employee] is departing [date]. Your new point of contact is [successor]. Contact info: [successor phone/email]."

- **AC-012.4**: GIVEN goodbye message with external contacts, WHEN message is sent, THEN external contacts receive email with clear action-item (update your records) so no mail is silently forwarded to departing employee's address.

---

## REQ-OFF-013: Cross-app data consistency

The system SHALL write back to employee-master on case completion: `uit_dienst_per` (einddatum), `reden_uit_dienst` (reden enum), `laatste_werkdag`, and `status = inactive`. These writes SHALL be idempotent (duplicate writes produce no side-effects). Errors during write-back SHALL escalate to HR-owner and block case archival.

**Acceptance Criteria:**

- **AC-013.1**: GIVEN Offboarding.afgerond_op is set, WHEN case-completion-job runs, THEN system invokes employee-master API with: `employee_id`, `uit_dienst_per = einddatum`, `reden_uit_dienst = reden enum`, `laatste_werkdag`, `status = inactive`.

- **AC-013.2**: GIVEN write-back succeeds, WHEN employee-master returns 200 OK, THEN case is marked `Offboarding.updated_at` and employee-master's `Employee.uit_dienst_per` field is immutable (no further changes allowed).

- **AC-013.3**: GIVEN write-back fails (network error, employee_id mismatch), WHEN retry-window expires (3 attempts over 24 hours), THEN system creates escalation task for HR-owner with API error details. Case remains in `retentie_timers_starten` status (not marked complete).

---

## REQ-OFF-014: Step enforcement and workflow guards

The system SHALL enforce strict sequential step completion (some steps may be skipped based on reden, but cannot be re-ordered). A step can only be marked complete if all predecessor dependencies are met.

**Acceptance Criteria:**

- **AC-014.1**: GIVEN Offboarding with `reden = pensionering`, WHEN workflow is initialized, THEN `uwv_ww_melding` step is marked `skipped` (not applicable), and workflow progresses to next required step without user interaction.

- **AC-014.2**: GIVEN `it_accounts_deactiveren` step, WHEN manager attempts to mark step complete WITHOUT first completing `equipment_geretourneerd`, THEN system blocks with "Equipment geretourneerd moet afgerond zijn alvorens IT-deactivering".

- **AC-014.3**: GIVEN `data_export_werknemer` step with status = `aanvraag_ontvangen`, WHEN 14 days elapse without download/USB-receipt, THEN system sends reminder to IT-owner and HR-owner.

---

## REQ-OFF-015: Audit trail completeness

Every field change in Offboarding, Eindafrekening, EquipmentReturn, ExitInterview, Getuigschrift, and RetentionTimer SHALL be tracked with: (actor, timestamp, before-value, after-value). Audit entries SHALL be immutable and exportable.

**Acceptance Criteria:**

- **AC-015.1**: GIVEN Eindafrekening.totaal_bruto changed from €9000 to €10037.16 by user u_12 at 2026-07-20T14:11:00, WHEN auditor views audit-tab, THEN entry shows: `Field: totaal_bruto | Before: 9000.00 | After: 10037.16 | Actor: u_12 | Timestamp: 2026-07-20T14:11:00 | Reason: (no reason provided)`.

- **AC-015.2**: GIVEN critical field changes (e.g., Eindafrekening.bevroren = true), WHEN change occurs, THEN notification is sent to HR-admin and manager (per RBAC rules).

- **AC-015.3**: GIVEN auditor exporting audit-trail as CSV/PDF, WHEN export is created, THEN file includes all entries, is sorted by timestamp, and is cryptographically signed (for regulatory compliance).

---

## REQ-OFF-016: Error handling and validation

The system SHALL validate all required fields at step-transition points and provide clear, actionable error messages in Dutch. No form submission succeeds with missing critical data.

**Acceptance Criteria:**

- **AC-016.1**: GIVEN Offboarding creation form, WHEN user submits WITHOUT selecting reden or einddatum, THEN system displays: "Reden van vertrek is verplicht" + "Einddatum is verplicht" in inline validation.

- **AC-016.2**: GIVEN eindafrekening computation, WHEN employee's leave-balance API returns error (employee-master unavailable), THEN system logs error and displays: "Verlofsaldo kon niet opgehaald worden van employee-master. Probeer over 5 minuten opnieuw of vul handmatig in." with manual-override option.

- **AC-016.3**: GIVEN UWV WW-melding submission failure, WHEN openconnector returns 400 validation error, THEN system displays: "UWV-bericht kon niet verstuurd worden: [specific error]. Controleer hier..." with link to validation checklist.

---

## REQ-OFF-017: Performance and scalability

The system SHALL support 500+ concurrent offboarding cases per organization without degradation. Eindafrekening computation (including 55-month audit-table generation) SHALL complete in <2 seconds. Data-export generation (full dossier PDF) SHALL complete in <10 seconds.

**Acceptance Criteria:**

- **AC-017.1**: GIVEN 500 active offboarding cases, WHEN list-view is loaded with pagination (50 per page), THEN page load completes in <500ms with sorting/filtering responsive (<200ms per sort).

- **AC-017.2**: GIVEN employee with 55-month service record, WHEN eindafrekening is computed (all 55 months with components), THEN computation completes in <2 seconds and audit-table is fully rendered.

- **AC-017.3**: GIVEN dossier-export request with 200+ audit-entries, WHEN PDF is generated, THEN generation completes in <10 seconds and PDF is <10MB uncompressed.
