---
status: specs
date: 2026-05-23
---

# Onboarding workflow — Specifications

## REQ-OB-001: State Machine Integrity

**Requirement:** The system SHALL enforce a deterministic state machine for `Onboarding.status` with only valid transitions. Any attempt to set a status that is not reachable from the current status SHALL be rejected with HTTP 409 Conflict and a machine-readable `invalid_transition` error code.

**Valid transitions:**
- `aangenomen` → `contract_aangemaakt`, `geannuleerd`
- `contract_aangemaakt` → `contract_verzonden`, `geannuleerd`
- `contract_verzonden` → `contract_getekend`, `geannuleerd`
- `contract_getekend` → `id_geverifieerd`, `geannuleerd`
- `id_geverifieerd` → `bsn_gevalideerd`, `geannuleerd`
- `bsn_gevalideerd` → `iban_geverifieerd`, `geannuleerd`
- `iban_geverifieerd` → `pensioen_aangemeld`, `geannuleerd`
- `pensioen_aangemeld` → `zvw_gemeld`, `geannuleerd`
- `zvw_gemeld` → `it_provisioned`, `geannuleerd`
- `it_provisioned` → `eerste_werkdag`, `geannuleerd`
- `eerste_werkdag` → `proeftijd_lopend` (if proeftijd exists), `proeftijd_afgerond` (if no proeftijd), `geannuleerd`
- `proeftijd_lopend` → `proeftijd_afgerond`, `geannuleerd`
- `proeftijd_afgerond` → (terminal state)
- `geannuleerd` → (terminal state)

**Scenario 1.1: Invalid direct transition rejected**
- GIVEN an `Onboarding` case in status `aangenomen`
- WHEN an operator attempts to PATCH the case to status `it_provisioned`
- THEN the API responds 409 Conflict with:
  ```json
  {
    "error": {
      "code": "invalid_transition",
      "message": "Cannot transition from 'aangenomen' to 'it_provisioned'",
      "current_status": "aangenomen",
      "allowed_next_states": ["contract_aangemaakt", "geannuleerd"]
    }
  }
  ```

**Scenario 1.2: Valid transition succeeds**
- GIVEN an `Onboarding` case in status `contract_verzonden`
- WHEN docudesk delivers a signed-envelope webhook with matching `envelope_id`
- THEN the case automatically transitions to `contract_getekend`, a `ContractSigningEvent` row is created with signature metadata, the `contract_ondertekenen` step is marked `voltooid`, and an audit log entry records the transition with timestamp + triggering webhook ID

**Scenario 1.3: Operator-initiated transition with precondition check**
- GIVEN an `Onboarding` case in status `contract_getekend`
- WHEN an HR-officer attempts to manually transition to `id_geverifieerd` without any `WIDCheck` rows
- THEN the API responds 422 Unprocessable Entity with:
  ```json
  {
    "error": {
      "code": "precondition_failed",
      "message": "Cannot advance to 'id_geverifieerd': WIDCheck with gecontroleerd_door_user_id is required"
    }
  }
  ```

---

## REQ-OB-002: WID-Check Evidence

**Requirement:** The system SHALL require, before transitioning past `id_geverifieerd`, a `WIDCheck` row with:
- Non-null `gecontroleerd_door_user_id` (must be a user with `hr_admin` or `auditor` role)
- `fysiek_gezien = true` OR a documented `fysiek_gezien_reden` (e.g. "hybrid remote procedure")
- An attached document scan in docudesk with ACL restricted to `hr_admin` + `auditor` groups
- `kopie_bewaartermijn_einde` calculated as `start_date + 5 years` per Uitvoeringsregeling LB art. 28

**Scenario 2.1: HR officer uploads ID and marks WID check complete**
- GIVEN an `Onboarding` case with `employee_id` and `start_date = 2026-06-01`
- WHEN the HR-officer uploads a passport scan (JPEG, max 10MB) to the `wid_check` step, ticks the "fysiek gezien" checkbox, and clicks "Mark Complete"
- THEN:
  1. Document upload is stored in docudesk with ACL: `group:hr_admin`, `group:auditor`, user: current-user (read-only for all)
  2. A `WIDCheck` row is created with:
     - `gecontroleerd_door_user_id = <current-user-id>`
     - `gecontroleerd_op = <current-timestamp>`
     - `fiziek_gezien = true`
     - `document_nummer_hash = sha256(<document-number-extracted-from-OCR>)`
     - `kopie_opslag_document_id = <docudesk-id>`
     - `kopie_bewaartermijn_einde = 2031-06-01` (start_date + 5 years)
  3. The `wid_check` step is marked `voltooid`
  4. An audit log entry records: "WID-check completed for employee {employee_id}, document {document_type}, issuer {issuer}, valid until {expiry}"

**Scenario 2.2: Physical inspection waived (hybrid procedure)**
- GIVEN a candidate in a remote region and a documented pandemic/crisis protocol
- WHEN the HR-officer uploads ID scan, unchecks "fysiek gezien", and fills `fysiek_gezien_reden = "Hybrid remote onboarding per COVID-level 4 protocol, DocuSign video-call on 2026-05-17 at 14:30, verified via government ID database"`
- THEN a `WIDCheck` row is created with `fiziek_gezien = false` and `fysiek_gezien_reden` populated; audit log includes the exception reason

**Scenario 2.3: Expired ID document rejected**
- GIVEN a `WIDCheck` with `geldig_tot = 2025-12-31` (already expired)
- WHEN the HR-officer tries to mark the `wid_check` step as `voltooid`
- THEN the API responds 422 with: `"error": "Document has expired. Valid documents required."`

---

## REQ-OB-003: BSN Validation

**Requirement:** The system SHALL validate every BSN via:
1. **Format check:** exactly 9 digits, no leading zeros, not all-same (e.g. 111111111 is invalid)
2. **Elfproef (modulus-11):** weights are 9,8,7,6,5,4,3,2,-1; checksum must equal 0 mod 11

The raw BSN SHALL only be stored on the `Employee` record (encrypted at rest) and never logged in plaintext in audit trails, response bodies, or application logs.

**Scenario 3.1: Valid BSN passes validation**
- GIVEN an employee with BSN `123456782` (valid per format + elfproef)
- WHEN the HR-officer enters the BSN in the `bsn_validatie` step and clicks submit
- THEN:
  1. BSN is hashed (SHA-256 with salt) and stored in `BSNValidatie.bsn_hash`
  2. `elfproef_resultaat = "geldig"` and `format_resultaat = "geldig"`
  3. Raw BSN is stored encrypted on the `Employee` record only
  4. The `bsn_validatie` step is marked `voltooid`
  5. Audit log records: "BSN validated successfully, elfproef passed, format valid" (without revealing BSN)

**Scenario 3.2: Invalid BSN checksum rejected**
- GIVEN an employee with BSN `123456781` (invalid checksum; correct would be 123456782)
- WHEN the HR-officer enters it and submits
- THEN the API responds 422 with:
  ```json
  {
    "error": {
      "code": "bsn_validation_failed",
      "message": "BSN checksum (elfproef) failed. Please verify the last digit.",
      "details": {
        "format_resultaat": "geldig",
        "elfproef_resultaat": "ongeldig"
      }
    }
  }
  ```

**Scenario 3.3: BSN never logged in plaintext**
- GIVEN any BSN entry, validation, or storage operation
- WHEN audit logs, application logs, or API responses are examined
- THEN no raw BSN digits appear in any log entry; only the hash and validation result are logged

---

## REQ-OB-004: IBAN Validation

**Requirement:** The system SHALL validate every IBAN via:
1. **Country + length check:** NL (24 chars), BE (16 chars), DE (22 chars); reject others
2. **ISO-13616 modulus-97 check:** move first 4 chars to end, convert letters to digits (A=10, ..., Z=35), compute mod 97; must equal 1
3. **Optional SEPA name-check** (if configured via openconnector): verify name-on-account matches employee name

A `no_match` result from SEPA name-check SHALL block step completion unless HR-admin (`hr_admin` role) provides a free-text justification of at least 20 characters.

**Scenario 4.1: Valid NL IBAN passes modulus-97**
- GIVEN an employee with IBAN `NL91ABNA0417164300` (valid)
- WHEN the HR-officer enters it in the `iban_verificatie` step and clicks submit
- THEN:
  1. Country check passes (NL, 24 chars)
  2. Modulus-97 check passes
  3. If SEPA name-check is enabled: name-on-account is queried; assume result is `exact`
  4. `iban_verificatie` step is marked `voltooid` with payload:
     ```json
     {
       "iban": "NL91ABNA0417164300",
       "verificatie_methode": "iban_modulo97_plus_sepa_name_check",
       "validatie_resultaat": "geldig",
       "naam_op_rekening_match": "exact"
     }
     ```

**Scenario 4.2: SEPA name-check no_match blocks completion**
- GIVEN an IBAN with valid modulus-97 but SEPA name-check returns `no_match` (account name doesn't match employee name)
- WHEN the HR-officer submits
- THEN the API responds 422 with:
  ```json
  {
    "error": {
      "code": "iban_name_mismatch",
      "message": "Account name does not match employee. HR-admin override required.",
      "sepa_check_result": "no_match",
      "account_name_from_bank": "J. de Jager",
      "employee_name": "Jan de Jong"
    }
  }
  ```
- The step remains `openstaand`; only an `hr_admin` user can override by:
  - Re-submitting with `override_justification = "Account is in former married name; employee has not yet updated bank records. Verified by phone on 2026-05-19 at 14:30."` (min 20 chars)
  - Audit log records the override with full justification

**Scenario 4.3: Invalid IBAN format rejected**
- GIVEN an IBAN `DE91ABNA0417164300` (German IBAN, 22 chars, but format doesn't match German spec)
- WHEN submitted
- THEN API responds 422 with modulus-97 error message

---

## REQ-OB-005: Payroll Readiness Gate

**Requirement:** The system SHALL compute `payroll_ready = true` if and only if ALL of the following conditions are true on the reference date:
1. `contract_ondertekenen` step is `voltooid`
2. `BSNValidatie` row exists with `elfproef_resultaat = "geldig"`
3. `iban_verificatie` step is `voltooid` with no pending overrides
4. `zvw_melding` step is `voltooid` AND external ZVW service confirmed receipt (status `bevestigd`)
5. `pensioen_aanmelding` step is `voltooid` AND pensioenfonds confirmed (status `bevestigd`) OR explicit `niet_van_toepassing` with documented reason

The payroll-engine-nl integration SHALL refuse to include an employee in a salarisrun batch if `payroll_ready = false` on the run's reference date.

**Scenario 5.1: Employee with incomplete ZVW blocked from payroll**
- GIVEN an employee whose all steps are `voltooid` EXCEPT `zvw_melding.status = "in_behandeling"`
- WHEN the maandloon-batch runs for 2026-05
- THEN:
  1. The employee is excluded from batch processing
  2. Payroll-engine-nl logs: `"Employee emp_01HXYW987DEF excluded: payroll_ready = false (zvw_status = in_behandeling)"`
  3. Both HR and payroll roles receive notification:
     ```
     Subject: Onboarding blokkering: ZVW-melding niet bevestigd
     Body: Medewerker [Jan de Jong] kan niet in de salarisrun van mei worden opgenomen. 
           ZVW-aanvraag nog niet bevestigd door zorgverzekering.
           Link: /onboarding/ob_01HXYZ123ABC (redirects to the onboarding case)
     ```

**Scenario 5.2: Employee with all preconditions met becomes payroll-ready**
- GIVEN an employee where all preconditions are true
- WHEN the last precondition becomes true (e.g. pensioen-aanmelding bevestigd webhook received)
- THEN:
  1. System computes `payroll_ready = true`
  2. Sets `payroll_ready_at = <current-timestamp>`
  3. Records in audit: "payroll_ready = true (all preconditions met)"
  4. On next maandloon-batch run, employee is included automatically

**Scenario 5.3: Pension aanmelding marked niet_van_toepassing with reason**
- GIVEN a temporary agency worker (no pension clause)
- WHEN HR marks `pensioen_aanmelding` step as `niet_van_toepassing` with reason "Uitzendkracht via bureau, pension handled by bureau policy"
- THEN `payroll_ready` can become true (doesn't require pensioen bevestigd) if all other preconditions met; audit logs the exemption reason

---

## REQ-OB-006: Contract Signing via eIDAS QES or AES

**Requirement:** The system SHALL only accept contracts signed via docudesk envelopes that return either an eIDAS QES (qualified) or AES (advanced) signature; SES (simple) is rejected for arbeidscontracten. The system SHALL store the LTA (Long-Term Archive) identifier so the signature remains verifiable beyond certificate expiry.

**Scenario 6.1: Valid eIDAS QES signature accepted**
- GIVEN a contract sent to docudesk for e-signing
- WHEN the candidate e-signs via a qualified certificate (eIDAS QES, e.g. DigiD app)
- AND docudesk delivers webhook: `POST /api/onboarding/ob_01HXYZ123ABC/contract_signed` with:
  ```json
  {
    "envelope_id": "docudesk_env_447",
    "signature_type": "eidas_qes",
    "signed_at": "2026-05-16T14:08:00Z",
    "signer_email": "jan.dejong@voorbeeld.nl",
    "ip_address": "82.157.x.x",
    "certificate_serial": "00:A3:1F:...",
    "lta_archive_id": "lta_447"
  }
  ```
- THEN:
  1. A `ContractSigningEvent` row is created with all webhook data
  2. `Onboarding.status` transitions to `contract_getekend`
  3. `contract_ondertekenen` step is marked `voltooid`
  4. Audit log: "Contract signed via eIDAS QES by jan.dejong@voorbeeld.nl on 2026-05-16T14:08:00Z, LTA archive ID lta_447"

**Scenario 6.2: SES signature rejected**
- GIVEN a docudesk webhook with `signature_type = "eidas_ses"`
- WHEN received
- THEN the webhook is rejected with 422: `"error": "SES (simple) signatures are not accepted for employment contracts. QES or AES required."`; case remains in `contract_verzonden`

**Scenario 6.3: Expired certificate still verifiable via LTA**
- GIVEN a signature from 2026-05-16 with certificate expiry 2027-05-16
- WHEN the signature certificate expires in 2027
- AND an auditor needs to verify the signature in 2028
- THEN the system retrieves the signature via `lta_archive_id` from docudesk LTA service; the signature remains cryptographically valid (not time-of-verification dependent) because LTA preserves evidence of the original timestamp and signature at the moment of signing

---

## REQ-OB-007: Reminder + Escalation

**Requirement:** The system SHALL emit reminders on per-step configurable schedules:
- Default: `contract_ondertekenen` → T+3 working days to candidate (via email + Nextcloud)
- Default: `id_upload` → T+2 working days to candidate
- Default: Any blocking step not `voltooid` → T-5 calendar days before `start_date` to HR

After two unacknowledged reminders, the system SHALL automatically escalate to a configured `escalatie_naar_user_id` (typically a supervisor or HR-manager).

**Scenario 7.1: Level-1 reminder on T+3 working days**
- GIVEN a contract sent on Monday, 2026-06-02, at 09:00 (step `contract_versturen` marked voltooid)
- WHEN the reminder scheduler runs on Thursday, 2026-06-05, at 09:00 (T+3 working days)
- THEN:
  1. A `Reminder` row is created: `step_key = contract_ondertekenen`, `escalatie_niveau = 1`, `trigger_at = 2026-06-05T09:00+02:00`
  2. Reminder is sent to candidate via email (subject "Your employment contract is waiting for signature") + Nextcloud notification
  3. `Reminder.verzonden_op = 2026-06-05T09:10+02:00` (recorded after send)
  4. Audit log: "Reminder level-1 sent to candidate for contract_ondertekenen step"

**Scenario 7.2: Level-2 escalation on second unacknowledged reminder**
- GIVEN the candidate hasn't signed by Sunday, 2026-06-08 (T+6 working days)
- WHEN the scheduler runs and detects an unacknowledged level-1 reminder
- THEN:
  1. A second `Reminder` row is created with `escalatie_niveau = 2` and `escalatie_naar_user_id = <recruiter_user_id>`
  2. Email is sent to the recruiter (subject "Escalation: Candidate has not signed contract")
  3. Recruiter receives Nextcloud notification with action link to the onboarding case
  4. Audit log: "Reminder level-2 escalated to recruiter_user_id = u_42"

**Scenario 7.3: T-5 days before start_date for blocking steps**
- GIVEN an `Onboarding` with `start_date = 2026-06-01` and `wid_check` step is still `openstaand` (blocking)
- WHEN the scheduler runs on 2026-05-27, at 09:00 (T-5 calendar days)
- THEN:
  1. A `Reminder` row is created with `step_key = wid_check`, `escalatie_niveau = 1`
  2. Reminder email + Nextcloud notification sent to `hr_owner_user_id` and `hiring_manager_user_id`
  3. Subject: "Urgent: ID verification required before employee start date (5 days remaining)"

---

## REQ-OB-008: IT Provisioning Idempotency

**Requirement:** The system SHALL provision a Nextcloud user via OCS Users API with a deterministic `userid` pattern (configurable, default: `voornaam.achternaam` in lowercase; collision handling: `voornaam.achternaam2`, `voornaam.achternaam3`, etc.). The provisioned user SHALL be assigned to group memberships per `afdeling` + the global `medewerker` group. Provisioning SHALL be idempotent — re-running the step for an existing user updates groups and quota but does not error or duplicate the user.

**Scenario 8.1: New user provisioned with group assignment**
- GIVEN an `Onboarding` case with associated `Employee` (voornaam = "Jan", achternaam = "de Jong", afdeling = "HR")
- WHEN the HR-officer triggers the `it_provisioning` step
- THEN:
  1. System calls OCS API: `POST /ocs/v1.php/apps/admin_provisioning_api/api/v1/users` with:
     ```json
     {
       "userid": "jan.dejong",
       "password": "<generated-temporary-password>",
       "displayName": "Jan de Jong"
     }
     ```
  2. User is created successfully, `userid = jan.dejong`
  3. System immediately adds user to groups:
     - `hr` (afdeling)
     - `medewerker` (global)
  4. Sets quota: `1GB` (default)
  5. Disables login (via `POST /ocs/v1.php/apps/admin_provisioning_api/api/v1/users/jan.dejong/disable`) until after `start_date`
  6. Stores `userid = jan.dejong` on the `Employee` record
  7. Marks `it_provisioning` step `voltooid`
  8. Audit log: "Nextcloud user jan.dejong created and provisioned to groups [hr, medewerker]"

**Scenario 8.2: Idempotent re-provisioning on collision**
- GIVEN an `Onboarding` for a second "Jan de Jong" in the same org
- WHEN the `it_provisioning` step is triggered
- THEN:
  1. System attempts to create `jan.dejong` via OCS
  2. OCS returns 400: user already exists
  3. System falls back: attempts `jan.dejong2`, succeeds
  4. Groups are added: `hr`, `medewerker`
  5. Stores `userid = jan.dejong2` on `Employee`

**Scenario 8.3: Re-run of provisioning updates groups without error**
- GIVEN an `Onboarding` where `it_provisioning` was already completed for user `jan.dejong`
- AND the employee's `afdeling` has changed from "HR" to "IT"
- WHEN the HR-officer runs the `it_provisioning` step again
- THEN:
  1. System calls OCS to check if `jan.dejong` exists → yes
  2. System updates group membership: remove `hr`, add `it`
  3. No duplicate user is created
  4. Step remains `voltooid`
  5. Audit log: "IT provisioning re-run: groups updated for jan.dejong (removed [hr], added [it])"

---

## REQ-OB-009: Bewaartermijn Enforcement

**Requirement:** The system SHALL set retention timers per artefact per Dutch tax + employment law:
- WID-kopie: 5 years after `einde_dienstverband` (Uitvoeringsregeling LB art. 28)
- Sollicitatie-correspondentie: 4 weeks after rejection OR 1 year with employee consent
- Payroll-grondslagen: 7 years (fiscal requirement)

On retention expiry, the system SHALL cryptographically delete the artefact (key destruction) with an append-only audit log entry. Deletion is irreversible.

**Scenario 9.1: WID-kopie retention calculated and enforced**
- GIVEN a `WIDCheck` with `kopie_bewaartermijn_einde = 2031-06-01` (start_date 2026-06-01 + 5 years)
- WHEN the offboarding process is triggered (employee departs)
- AND the system's nightly retention-check job runs on 2031-06-02
- THEN:
  1. System identifies the expired WID document
  2. Calls docudesk API to destroy the document: `DELETE /documents/{doc_id}` with `reason = "bewaartermijn_expired"`
  3. Docudesk deletes the underlying file and marks key as destroyed
  4. Audit log records: "WID-kopie for employee emp_01HXYW987DEF cryptographically deleted on 2031-06-02, bewaartermijn_einde = 2031-06-01, key destruction by docudesk, irreversible"
  5. `WIDCheck` record is NOT deleted from hrmq DB (audit trail preserved); only the attached document is destroyed

**Scenario 9.2: Payroll-grondslagen retention for 7 years**
- GIVEN an employee's payroll records (salary, deductions, benefits) stored on the `Employee` record or linked via `Employee.payroll_audit_trail_id`
- WHEN the employee departs on 2026-06-15
- THEN the system sets a retention flag: `payroll_data_retention_until = 2033-06-15` (7 years)
- On 2033-06-16, the system flags the record for deletion and notifies payroll-admin for final review before destruction

**Scenario 9.3: Audit log entry on retention deletion**
- GIVEN any document destroyed due to bewaartermijn expiry
- WHEN the deletion occurs
- THEN an audit log entry is created (append-only, cannot be edited or deleted):
  ```json
  {
    "id": "audit_01HXZF123",
    "employee_id": "emp_01HXYW987DEF",
    "event_type": "retention_deletion",
    "artefact_type": "wid_kopie",
    "artefact_id": "doc_id_01HXZ",
    "deleted_at": "2031-06-02T02:15:00+02:00",
    "bewaartermijn_einde": "2031-06-01",
    "reason": "Legal retention period expired per Uitvoeringsregeling LB art. 28",
    "status": "irreversible"
  }
  ```

---

## REQ-OB-010: Audit Trail Completeness

**Requirement:** The system SHALL maintain an append-only, queryable audit log for:
- Every status transition (with reason + triggering event)
- Every step completion (with user + timestamp)
- Every reminder sent (with channel + recipient + escalation level)
- Every WID-check, BSN/IBAN validation
- Every override (with full justification text, min 20 chars)
- Every retention deletion

Audit log entries SHALL be queryable by `employee_id`, `onboarding_id`, and date range. Entries SHALL be exportable to a PDF for AVG-inzageverzoek responses within 60 seconds.

**Scenario 10.1: Audit trail queryable by onboarding_id**
- GIVEN an `Onboarding` case `ob_01HXYZ123ABC`
- WHEN an HR-officer queries the audit log via GET `/api/onboarding/ob_01HXYZ123ABC/audit-trail` with optional date range filter
- THEN the system returns all audit entries for this case in reverse chronological order (newest first):
  ```json
  [
    {
      "id": "audit_01HXZG001",
      "timestamp": "2026-05-20T11:02:00+02:00",
      "event_type": "step_completed",
      "step_key": "iban_verificatie",
      "user_id": "u_12",
      "details": "IBAN validated successfully, exact name match"
    },
    {
      "id": "audit_01HXZG002",
      "timestamp": "2026-05-19T16:45:00+02:00",
      "event_type": "reminder_sent",
      "step_key": "contract_ondertekenen",
      "recipient_id": "emp_01HXYW987DEF",
      "channel": "email_plus_nextcloud",
      "escalation_level": 1
    },
    ...
  ]
  ```

**Scenario 10.2: AVG-inzageverzoek export to PDF**
- GIVEN an employee (Jan de Jong) who submits an AVG-inzageverzoek (GDPR data-subject access request)
- WHEN the AVG-functionaris triggers the export from the onboarding case header (menu → "AVG Gegevensuitvraag")
- THEN:
  1. System queues an async PDF generation job
  2. Within 60 seconds, system generates a PDF containing:
     - **Personal data snapshot:** name, email, phone, address, BSN (last 4 digits only), IBAN (last 4 digits only), start_date, employment contract summary
     - **Full audit trail:** all events from case creation to present, including status changes, step completions, reminders, validation results, overrides
     - **Retention schedule:** which artefacts are retained, until when, deletion status
     - **eIDAS timestamp:** digital signature + timestamp from a third-party TSA (TimeStamp Authority) proving the PDF was generated on this date and is unaltered
  3. PDF is encrypted to HR + auditor roles only
  4. A download link is generated and sent to the AVG-functionaris
  5. Audit log records: "AVG-DSR export initiated by user u_12 on 2026-05-22 for employee emp_01HXYW987DEF, PDF exported with eIDAS timestamp"

**Scenario 10.3: Override justification logged in full**
- GIVEN an HR-admin who overrides an IBAN name-mismatch
- WHEN they submit the override with justification "Account is in former married name; employee verified by phone on 2026-05-19 at 14:30 that they have not yet updated the bank. Will handle after start date."
- THEN the audit log records:
  ```json
  {
    "id": "audit_01HXZG003",
    "timestamp": "2026-05-19T14:45:00+02:00",
    "event_type": "override",
    "step_key": "iban_verificatie",
    "override_reason": "iban_name_mismatch",
    "justification": "Account is in former married name; employee verified by phone on 2026-05-19 at 14:30 that they have not yet updated the bank. Will handle after start date.",
    "approved_by_user_id": "u_12",
    "approved_by_role": "hr_admin"
  }
  ```
  The full justification is never truncated or summarized.

---

## REQ-OB-011: Proeftijd-Watcher

**Requirement:** For contracts with a proeftijd-clause, the system SHALL automatically register a proeftijd-watcher that sends notifications T-7 werkdagen (working days) and T-2 werkdagen before `proeftijd_einddatum` to both HR-owner and lijnmanager. Notifications SHALL include three explicit action-buttons:
1. `proeftijd_geslaagd_afronden` — proeftijd ends successfully, contract becomes permanent
2. `proeftijd_beëindigen` — proeftijd ends due to unsuitability; system auto-creates a matching offboarding case
3. `verlenging_niet_mogelijk_in_nl` — selectable but disabled with legal explanation (Wet Arbeidsmarkt in Balans restricts extensions)

On reaching T-0 (the day of `proeftijd_einddatum`), if no explicit action is recorded, the system auto-closes the proeftijd as `proeftijd_geslaagd_afronden` with a warning in the case that the contract is now permanent.

**Scenario 11.1: T-7 notification sent to HR and manager**
- GIVEN an `Onboarding` with `proeftijd_einddatum = 2026-07-31` (7 working days = Tuesday, 2026-07-21 in Dutch calendar)
- WHEN the scheduler runs on Monday, 2026-07-20 at 09:00 (overnight job)
- THEN:
  1. System calculates working-day offset: T-7 werkdagen = 2026-07-21
  2. Creates a `Reminder` row: `step_key = proeftijd_monitor`, `escalatie_niveau = 1`, `trigger_at = 2026-07-21T09:00`
  3. On 2026-07-21 at 09:00, sends Nextcloud notifications to `hr_owner_user_id` (u_12) and `hiring_manager_user_id` (u_88):
     ```
     Proeftijd afloopt over 7 werkdagen voor Jan de Jong
     Contracteinddatum proeftijd: 31 juli 2026
     Kies één van de volgende opties:
     [Afronden - Geslaagd] [Beëindigen - Niet geschikt] [Verlenging? - Niet mogelijk in NL]
     ```
  4. Each action-button links to a backend endpoint: `/api/onboarding/ob_01HXYZ123ABC/proeftijd_action?action=<action-name>`

**Scenario 11.2: T-2 notification for final decision**
- GIVEN the same case, no action taken by T-7
- WHEN the scheduler runs on 2026-07-29 at 09:00 (T-2 working days before 2026-07-31)
- THEN:
  1. System sends a final Nextcloud notification (more urgent tone) to HR and manager
  2. Subject: "FINAL: Proeftijd decision required by 31 juli"
  3. If still no action by 2026-07-31 at 23:59, the system auto-closes (see Scenario 11.3)

**Scenario 11.3: T-0 auto-close if no action**
- GIVEN `proeftijd_einddatum = 2026-07-31` and no explicit action recorded
- WHEN the scheduler runs at 2026-08-01 00:30 (first job run after T-0)
- THEN:
  1. System sets `Proeftijd.status = proeftijd_afgerond_geslaagd_assumption`
  2. Adds a warning to the case: "⚠️ Proeftijd einddatum (31-07-2026) has passed. No explicit action was recorded. Per Dutch law, proeftijd automatically ended as SUCCESSFUL. Contract is now PERMANENT and cannot be terminated without legal process."
  3. Records in audit log: "Proeftijd auto-closed as geslaagd on 2026-08-01 (T-0 + 1 day, no action taken), warning issued to HR"
  4. Sends a notification to HR and manager: "Proeftijd afgerond (assumption: geslaagd)."

**Scenario 11.4: HR manually ends proeftijd with offboarding case**
- GIVEN the proeftijd T-7 notification is displayed
- WHEN the manager clicks `proeftijd_beëindigen`
- THEN:
  1. System creates a new offboarding case: `Offboarding` entity with `reason = "proeftijd_beëindigd"`, `onboarding_id = ob_01HXYZ123ABC` (backref)
  2. Sets offboarding `status = aangemeld` (not yet completed)
  3. Stores the manager's action in audit: "Proeftijd beëindigd via manager action on 2026-07-20, offboarding case offb_XXX created"
  4. Notifies HR and payroll: "Proeftijd has ended. Offboarding process started for Jan de Jong. Final salarisrun: Augustus 2026."

---

## REQ-OB-012: Self-Service Portal for Candidate

**Requirement:** The system SHALL provide a secure, expiry-bound self-service portal accessible via a unique link (delivered via email) that requires no account creation until `start_date`. The portal SHALL support:
- Persoonsgegevens (full name, email, phone, address)
- ID-document upload (with OCR for document number extraction)
- IBAN entry (validated via modulus-97)
- Voorkeur-aanspreking (formal/informal, pronouns)
- Noodcontactgegevens (emergency contact name, phone, relation)
- Voedingsvoorkeur (dietary preferences for team events)

The portal SHALL be TLS-only, expire after 30 calendar days of link creation (not inactivity), and audit-log every field change with IP address + user-agent.

**Scenario 12.1: Candidate receives secure portal link**
- GIVEN an `Onboarding` case in status `contract_verzonden`
- WHEN the HR-officer clicks "Send self-service link" in the wizard
- THEN:
  1. System generates a unique token: `portal_token_01HXZH123ABC` (ULID, 128-bit entropy)
  2. Sets token expiry: `expires_at = now + 30 calendar days`
  3. Email is sent to candidate email address:
     ```
     Subject: Welkom! Vul je gegevens in voor je start
     Body:
     Hallo Jan,
     
     Welkom! Klik op onderstaande link om je persoonlijke gegevens in te vullen
     voor je indiensttreding op 1 juni 2026.
     
     Link: https://hrmq.org/self-service/portal/portal_token_01HXZH123ABC
     
     Deze link vervalt op 19 juni 2026. Na je indiensttreding krijg je toegang
     tot het volledige Nextcloud-systeem.
     
     Vragen? Mail HR@bedrijf.nl
     ```
  4. Audit log: "Self-service portal link generated for employee emp_01HXYW987DEF, token expires 2026-06-19"

**Scenario 12.2: Candidate fills portal form with validation**
- GIVEN a candidate who clicks the portal link
- WHEN they enter:
  - IBAN: `NL91ABNA0417164300`
  - ID document: passport scan (JPEG, 2MB)
  - Voorkeur-aanspreking: "Formeel, hij/hem"
  - Noodcontactgegevens: "Maria de Jong (moeder), +31612345678"
  - Voedingsvoorkeur: "Vegetarian, no gluten"
- AND they click "Save & Continue"
- THEN:
  1. IBAN is validated via modulus-97 → passes
  2. ID document is OCR'd to extract document number + type
  3. Document is stored in docudesk (restricted ACL)
  4. All data is stored on the `Onboarding` case and linked `OnboardingStep` (self-service step, if desired)
  5. Each field-change is logged with:
     - `timestamp = 2026-05-20T14:30:00+02:00`
     - `field_name = "iban"`
     - `old_value = null` (first entry)
     - `new_value_hash = sha256(iban)` (never store raw IBAN in log)
     - `ip_address = "192.168.1.100"` (candidate's IP, if not masked)
     - `user_agent = "Mozilla/5.0 ... Safari 537.36"`
  6. Audit log records: "Self-service form update: 5 fields modified, IP: 192.168.1.100, user-agent: Safari"

**Scenario 12.3: Portal link expires after 30 days**
- GIVEN a portal link generated on 2026-05-20
- WHEN a candidate tries to access it on 2026-06-20 (31 days later)
- THEN the portal responds 401 Unauthorized:
  ```json
  {
    "error": {
      "code": "portal_link_expired",
      "message": "This portal link has expired. Please contact HR to request a new one.",
      "expired_at": "2026-06-19T23:59:59+02:00"
    }
  }
  ```

**Scenario 12.4: Post-start_date candidate transitions to Nextcloud account**
- GIVEN a candidate whose `start_date = 2026-06-01` and portal link is still valid (expires 2026-06-19)
- WHEN `start_date` is reached (2026-06-01)
- THEN:
  1. System enables the Nextcloud account (IT-provisioning step is marked voltooid)
  2. Candidate receives email: "Your Nextcloud account is now active: userid = jan.dejong, password reset via [link]"
  3. Portal link is disabled: future accesses return 403 Forbidden "Account is now active in Nextcloud"
  4. All self-service data is migrated to the `Employee` record and is now visible in the full HR dossier

---

## Acceptance Criteria Summary

| REQ | Acceptance Criteria |
|-----|-------------------|
| REQ-OB-001 | State transitions are gated; invalid transitions return 409 with allowed_next_states list |
| REQ-OB-002 | WID-check requires physical inspection or documented exception; bewaartermijn set to start_date + 5 years |
| REQ-OB-003 | BSN format + elfproef validated; raw BSN never logged plaintext |
| REQ-OB-004 | IBAN modulus-97 + optional SEPA name-check; no_match blocks unless hr_admin override (20+ char justification) |
| REQ-OB-005 | payroll_ready computed from 5 preconditions; payroll-batch excludes incomplete employees |
| REQ-OB-006 | eIDAS QES/AES signatures accepted; SES rejected; LTA archive ID stored |
| REQ-OB-007 | Reminders on configurable schedules (T+3, T+2, T-5); escalation after 2 unacknowledged |
| REQ-OB-008 | Nextcloud user provisioned with deterministic userid; idempotent re-runs update groups; collision handling with suffix |
| REQ-OB-009 | Bewaartermijn enforced per Dutch law; expired artefacts cryptographically deleted; audit-logged |
| REQ-OB-010 | Audit trail append-only, queryable by employee/case/date; exportable to eIDAS-timestamped PDF within 60s |
| REQ-OB-011 | Proeftijd notifications T-7 and T-2; three action-buttons; auto-close T-0 if no action with warning |
| REQ-OB-012 | Self-service portal via expiry-bound link; TLS-only; 30-day expiry; field-changes logged with IP+user-agent |
