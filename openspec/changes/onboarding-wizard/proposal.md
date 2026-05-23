---
status: proposal
date: 2026-05-23
---

# Onboarding workflow (multi-step Onboarding case entity)

## Summary

Transform the historically chaotic "new hire first week" into a single coordinated case spanning recruiting, HR, IT, line management, and external parties. The system enforces a deterministic state machine with transitions gated by concrete artifacts (signed contracts, identity verification, BSN validation, IBAN validation) and provides an operator UI (wizard) for HR-officers and recruiters, plus a reminder/escalation engine for task oversight.

## Problem Statement

Dutch MKB organisations currently manage onboarding as a tangle of spreadsheets, ad-hoc emails, paper folders, and verbal handovers:
- Payroll discovers new employees only when managers forward contract scans
- IT provisions laptops a week too late
- Pensioenfonds receives aanmelding two months after start date, triggering retroactive corrections
- Nobody can prove whether required WID-checks actually happened
- Payroll cannot safely include new employees in salary runs because preconditions are unclear
- Proeftijd-expiry is often missed, locking the employer into unbreakable contracts

## Features

### 1. Onboarding Case Management (demand: 8.5/10)

**Description:** Create a deterministic state machine for `Onboarding` case objects, progressing from `aangenomen` through `contract_verzonden`, `contract_getekend`, `id_geverifieerd`, `bsn_gevalideerd`, `iban_geverifieerd`, `it_provisioned`, `eerste_werkdag`, `proeftijd_lopend`, to `proeftijd_afgerond`. Each transition is gated by concrete preconditions (signed PDF, WID-check, BSN-modulus-11 pass, `oc_users` row, pensioenfonds confirmation).

### 2. Wizard Operator UI (demand: 8.0/10)

**Description:** HR-officers and recruiters fill in a stepper UI where each step binds to a single sub-aspect of the case (contract-aanmaken, contract-versturen, contract-ondertekenen, id-upload, wid_check, bsn_validatie, iban_verificatie, pensioen_aanmelding, zvw_melding, it_provisioning, bedrijfskleding, laptop_uitgifte, mentor_toewijzing, vog_aanvraag, eerste_werkdag_checklist). Steps are marked `voltooid` only when backing artefacts are attached.

### 3. Payroll Readiness Gate (demand: 9.0/10)

**Description:** Compute `payroll_ready = true` if and only if contract step `voltooid` AND BSN validated AND IBAN validated AND ZVW-melding `bevestigd` AND pensioen-aanmelding `bevestigd` (or `niet_van_toepassing` with reason). The payroll-engine-nl integration refuses to include an employee in a salarisrun whose `payroll_ready` is false on the run's reference date. This eliminates the entire class of bug where HR forgets to tell payroll that a new employee exists.

### 4. Reminder + Escalation Engine (demand: 8.0/10)

**Description:** Emit reminders on per-step configurable schedules (default: T+3 working days for `contract_ondertekenen`, T+2 working days for `id_upload`, T-5 calendar days before `start_date` for blocking steps). After two unacknowledged reminders, escalate to the configured `escalatie_naar_user_id`. Notifications deliver via email + Nextcloud.

### 5. Proeftijd-Watcher (demand: 7.5/10)

**Description:** For contracts with proeftijd-clauses, register an automatic watcher that sends notifications T-7 and T-2 werkdagen before `proeftijd_einddatum` to HR-owner and lijnmanager with three explicit choices: afronden, beëindigen (auto-creates matching offboarding case), or verlengen (rejected with legal explanation). On T-0, proeftijd auto-closes as `geslaagd_afrond` unless explicitly overridden.

### 6. WID-Check Evidence Management (demand: 8.5/10)

**Description:** Require a `WIDCheck` row with non-null `gecontroleerd_door_user_id`, `fysiek_gezien = true` (or documented hybride-procedure exception), and an attached document scan with `bewaartermijn_einde` set to start_date + 5 years. Document hash is stored (SHA-256) for matching; raw scan lives in docudesk with restricted ACL.

### 7. BSN + IBAN Validation (demand: 8.5/10)

**Description:** Validate BSN via format check (9 digits, no leading zeros, no all-same) AND elfproef (modulus-11). Validate IBAN via country-prefix+length check for NL/BE/DE, ISO-13616 modulus-97, and optional SEPA name-check. Raw BSN stored encrypted on `Employee` only; `BSNValidatie` stores hash+result. IBAN `no_match` blocks step unless HR-admin overrides with 20+ character justification.

### 8. Contract Signing via eIDAS QES/AES (demand: 8.0/10)

**Description:** Only accept contracts signed via docudesk envelopes returning eIDAS QES (qualified) or AES (advanced) signature blobs; SES (simple) is rejected. Store LTA archive identifier so signature remains verifiable beyond certificate expiry. Auto-transition case to `contract_getekend` on docudesk webhook delivery.

### 9. IT Provisioning Idempotency (demand: 7.5/10)

**Description:** Provision a Nextcloud user via OCS Users API with `userid` derived from a deterministic pattern (configurable, default `voornaam.achternaam` with collision-suffix `voornaam.achternaam2`). Assign group memberships per `afdeling` + global `medewerker` group. Idempotent re-runs update groups without error.

### 10. Self-Service Portal for Candidate (demand: 7.0/10)

**Description:** Offer candidate a secure self-service portal (via expiry-bound unique link, no account creation until start_date) for persoonsgegevens, ID-upload, IBAN-entry, voorkeur-aanspreking, noodcontactgegevens, dietary preferences. TLS-only, 30-day inactivity expiry on link, audit-log every field change with IP + user-agent.

### 11. Audit Trail Completeness (demand: 9.0/10)

**Description:** Maintain append-only audit log for every status transition, step completion, reminder sent, WID-check, override+justification, retention deletion. Queryable by `employee_id`, `onboarding_id`, date range. Exportable to eIDAS-timestamped PDF for AVG-inzageverzoek within 60 seconds.

### 12. Bewaartermijn Enforcement (demand: 7.5/10)

**Description:** Set retention timers per Dutch tax + employment law: WID-kopie 5 years after offboarding, sollicitatie-correspondentie 4 weeks after rejection (or 1 year with consent), payroll-grondslagen 7 years. On retention expiry, cryptographically delete artefact (key destruction) with audit log entry.

## Stakeholders

| Role | Responsibilities | Goals | Constraints |
|------|------------------|-------|-------------|
| **HR-officer / personeelszaken** | Primary wizard operator; fills steps, runs WID-check, monitors checklists. | See entire dossier in one screen without tab-spilling. Handle 20-50 onboardings in various states simultaneously. | Limited technical skill; expects drag-drop UX. Dual-roles: offboarding, contracten, loonadministratie. |
| **Recruiter** | Opens new cases from closed vacancy; hands off to HR post-signature. Often external (uitzendbureau). | Quick candidate experience; handoff clarity. Limited system access. | View only own candidates. Limited access to system. |
| **Hiring manager / lijnmanager** | Owns eerste-werkdag-checklist, mentor assignment, equipment. Geen HR-expertise. | Short task list, clear deadlines. | No payroll/contract knowledge expected. |
| **IT-beheerder** | Owns IT-provisioning step. Often single person or external MSP. | Preferably automated with status dashboard for new hires. | Familiar with OCS protocol. |
| **Werknemer (candidate / new hire)** | Self-service: contract e-signing, ID upload, IBAN, persoonsgegevens. | Smooth onboarding, secure link, no account-creation burden. | Keine account in hrmq initially; link-based access. |
| **Auditor / AVG-functionaris** | Read-only access to full audit trail, retention reports, DSR servicing. | Complete defensibility. | Full traceability. |
| **Payroll-officer** | Not wizard operator but direct stakeholder; consumes `payroll_ready`. | Certainty that employee data is complete before salarisrun. | Must block incomplete employees. |
| **Pensioenadministrateur** | Receives aanmelding from hrmq; reports errors back. | Auto-receipt, error visibility. | Error reporting via case. |

## User Stories

### Story 1: HR-officer completes onboarding wizard

**GIVEN** an `Onboarding` case in status `aangenomen` with associated `Employee` and `Contract`  
**WHEN** the HR-officer navigates to the wizard and fills `contract_aanmaken` step (template selection), `contract_versturen` step (preview & send to docudesk), then waits for candidate signature  
**THEN** the case remains in `contract_verzonden` until docudesk webhook delivers signed envelope; case auto-transitions to `contract_getekend` and step is marked `voltooid`

**Acceptance criteria:**
- Wizard renders all 15 fixed steps in correct order
- Each step shows blocking vs. non-blocking distinction
- Completed steps show lock-icon + timestamp + completed-by user
- Future steps are greyed out until preconditions met
- Status transitions logged in audit trail

### Story 2: WID-check and identity verification

**GIVEN** a candidate whose `contract_getekend` is `voltooid`  
**WHEN** the HR-officer uploads a passport scan, ticks "fysiek gezien", and confirms the WID-check step  
**THEN** a `WIDCheck` row is created with `gecontroleerd_door_user_id = <current-user>`, document stored in docudesk with HR+auditor ACL, `bewaartermijn_einde = start_date + 5 years`, and step marked `voltooid`

**Acceptance criteria:**
- Document upload enforces JPEG/PDF formats, max 10MB
- SHA-256 hash of document stored (not raw scan)
- Bewaartermijn calculated from start_date (pulled from `Onboarding`)
- ACL restricted to hr_admin + auditor groups
- "fysiek gezien" checkbox unlocks step completion

### Story 3: BSN validation blocks payroll

**GIVEN** an onboarding case where BSN has been entered but is invalid (e.g. wrong checksum)  
**WHEN** the HR-officer fills the `bsn_validatie` step and submits  
**THEN** the API responds with validation error (`elfproef_failed`) and the step is NOT marked `voltooid`; `payroll_ready` remains false

**Acceptance criteria:**
- Both format (9 digits) and elfproef checked
- Raw BSN never logged or echoed in response
- Error message is user-friendly (e.g. "Checksum failed; verify the 9-digit code on the ID")
- Retry is allowed with new value

### Story 4: Reminder escalation when contract unsigned

**GIVEN** a contract sent on Monday (2026-06-02) at 09:00  
**WHEN** no signature arrives by Thursday 09:00  
**THEN** a level-1 reminder is emitted to the candidate via email + Nextcloud, and an escalation reminder to the recruiter; a `Reminder` row logged with `escalatie_niveau = 1`

**Acceptance criteria:**
- Reminder scheduler runs on configurable schedule (T+3 working days, skipping weekends)
- If still unsigned at T+6, level-2 escalation sent to HR-owner
- Escalation recipient list is configurable per step
- All reminders logged with timestamp + delivery status

### Story 5: Proeftijd auto-close or escalation

**GIVEN** an `Onboarding` with `proeftijd_einddatum = 2026-07-31` and no action on T-7  
**WHEN** the scheduler runs on T-2 (2026-07-29)  
**THEN** HR and lijnmanager receive Nextcloud notification with three action-buttons (afronden, beëindigen, verlengen-niet-mogelijk); on T-0, proeftijd auto-closes as `proeftijd_geslaagd_afronden` unless explicitly overridden, with warning in dossier that contract is now permanent

**Acceptance criteria:**
- Proeftijd duration calculated per Wet Arbeidsmarkt in Balans (1 month for <2yr contracts, 2 months for longer, forbidden for <6mo)
- Notification shown in Nextcloud + email (HR only)
- Three buttons are explicitly actionable (not just read-only state)
- Auto-close on T-0 sets case to `proeftijd_afgerond`, locks further proeftijd changes
- Manual selection of afronden/beëindigen records choice in audit trail

### Story 6: Payroll-engine checks payroll_ready

**GIVEN** an employee whose ZVW-melding is still `in_behandeling`  
**WHEN** the maandloon-batch runs for that pay period  
**THEN** the employee is excluded from the batch, an `excluded_employees` line appears in the run summary, and both HR and payroll are notified

**Acceptance criteria:**
- `payroll_ready` computed from: contract `voltooid` AND BSN validated AND IBAN validated AND ZVW `bevestigd` AND pensioen `bevestigd` (or `niet_van_toepassing` with reason)
- Payroll-engine-nl integration checks `payroll_ready` before batch inclusion
- Exclusion is logged with reason (which component failed)
- HR receives actionable notification with a link to the incomplete case

### Story 7: Self-service portal for candidate identity + IBAN

**GIVEN** a candidate who receives a secure link (via email) to the onboarding self-service portal  
**WHEN** they click the link and fill ID-upload (passport/ID card with document scan), IBAN (validated via modulus-97), voorkeur-aanspreking, noodcontactgegevens, and dietary preferences  
**THEN** all data is stored on the `Onboarding` case, every field-change is logged in audit trail with IP + user-agent, and the portal link automatically expires after 30 days of inactivity

**Acceptance criteria:**
- Portal accessible via unique, expiry-bound token (no login required)
- TLS-only delivery (HSTS header set)
- Document upload enforces JPEG/PDF, max 10MB
- IBAN validated before submission
- All changes logged to audit trail
- Link expiration after 30 calendar days (not inactivity)
- Post-start_date, candidate can access full Nextcloud account

### Story 8: AVG-inzageverzoek export

**GIVEN** an employee who submits an AVG-inzageverzoek (data-subject access request)  
**WHEN** the AVG-functionaris requests the export from the Onboarding case  
**THEN** within 60 seconds, a PDF is generated with all persoonsgegevens, all changes/overrides + justifications, retention status per artefact, and signed with an eIDAS timestamp for auditability

**Acceptance criteria:**
- PDF includes: personal data snapshot, full audit trail, retention schedule, signature blob
- eIDAS timestamp embedded (via docudesk or inline cert)
- Export queryable by `employee_id`, date-range
- PDF is encrypted to HR+auditor roles only
- Export logged in audit trail (AVG-DSR export initiated by user X on date Y)

## Information Architecture

**Placement type:** `SUB_PAGE`  
**Lives at:** Onboarding & ATS › Onboardings  
**Rationale:** Wizard-list for HR-officers and recruiters to manage active cases.

## Out of Scope

- Applicant-tracking / werving (separate `recruitment` spec in future)
- Generic case management for non-HR processes (procest core)
- E-signing transport protocol (delegated to docudesk)
- User-provisioning protocol (delegated to Nextcloud OCS API via openconnector)
- Offboarding case workflows (separate `offboarding-wizard` spec)
- Compensation/benefits cycles (separate `comp-planning-cycle` spec)

## Standards & Legal References

- **AVG/GDPR** art. 5, 6, 9, 12, 15, 17, 25, 28, 32
- **Wbp-BSN** — BSN usage restriction to lawful basis
- **Wet op de Identificatieplicht (WID)** — identity verification + 5-year retention
- **Uitvoeringsregeling Loonbelasting 1965 art. 28** — ID-document copy retention
- **Wet op de Loonbelasting 1964** — payroll-administration requirements
- **eIDAS Verordening (EU) 910/2014** — QES/AES/SES signature classification
- **Pensioenwet art. 7, 23** — pension fund notification requirement
- **Zorgverzekeringswet (ZVW)** — health-insurance declaration
- **ISO/IEC 27001 + NEN 7510** — information security controls
- **ISO 13616** — IBAN modulus-97 validation
- **Wet Arbeidsmarkt in Balans** — proeftijd duration rules

## Cross-app Integration Points

- **docudesk:** contract template, e-signing envelope, signed-PDF storage, OCR of ID
- **openconnector:** pensioenfonds aanmelding, UWV/Belastingdienst aangifte, SEPA name-check, BKR/VOG
- **Nextcloud OCS API:** user creation, group assignment, quota
- **payroll-engine-nl:** consumes `payroll_ready` gate
- **employee-master:** canonical `Employee` record (BSN encrypted, IBAN, contract-ref, start_date)
- **contract-management:** `Contract` template + clauses rendering
- **shillinq:** notification on onboarding completion for cost-center setup
- **procest:** case-model substrate (Onboarding is a procest case kind)
