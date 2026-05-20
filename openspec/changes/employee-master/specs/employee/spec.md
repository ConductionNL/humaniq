# Spec: employee

**Capability:** employee — personal record management (NAW, BSN, IBAN, AVG)
**OpenSpec changes:**
- employee-master (in-progress)

**Status:** in-progress

---

## Requirements

### REQ-EMP-001: Employee Record Creation

An HR administrator can create a complete employee personal record containing NAW, contact
details, IBAN, geboortedatum, nationaliteit, and employment start date.

**GIVEN** an HR administrator is authenticated and navigates to the Employees section  
**WHEN** they submit the new-employee form with voornamen, achternaam, geboortedatum,
straatnaam, huisnummer, postcode, woonplaats, email, telefoon, iban, and indatumInDienst  
**THEN** the system creates an Employee object with status `actief`, assigns it a unique ID,
and displays it in the employee list

**GIVEN** the form is submitted with a missing required field (achternaam, geboortedatum,
or indatumInDienst)  
**WHEN** the user clicks Save  
**THEN** the form displays an inline validation error on the missing field and does not save

---

### REQ-EMP-002: BSN Storage with AES-256 Encryption

BSN (burgerservicenummer) is encrypted at rest using AES-256. The plaintext BSN is never
written to the database, logs, API responses, or audit trail.

**GIVEN** an HR administrator enters a BSN in the employee form  
**WHEN** the record is saved  
**THEN** the BSN is encrypted by BsnEncryptionService before persistence; the stored value
is the AES-256 ciphertext; the plaintext BSN is not present anywhere in the OpenRegister
object, the audit trail entry, or the HTTP response body

**GIVEN** an authenticated HR administrator views an employee detail page  
**WHEN** the employee record is loaded  
**THEN** the BSN field is displayed as a masked value (e.g. `••••••782`) with a reveal
button visible only to users with the `bsn:read` permission

**GIVEN** the encryption key is unavailable (misconfigured or missing)  
**WHEN** the system attempts to save a BSN  
**THEN** the save fails with a server-side error; no plaintext BSN is stored; the error
message returned to the client does not include the BSN value

---

### REQ-EMP-003: Employee Status Lifecycle

An employee record transitions through defined statuses: `actief` → `inactief` → `uitdienst`.
Transitions are recorded in the audit trail with timestamp and acting user UID.

**GIVEN** an employee record with status `actief`  
**WHEN** an HR administrator sets status to `inactief`  
**THEN** the transition is saved, the audit trail records the old and new status with
`$user->getUID()` (not display name), and the employee list reflects the new status

**GIVEN** an employee record with status `uitdienst`  
**WHEN** a user attempts to set status back to `actief`  
**THEN** the system rejects the transition with a validation error; the status remains `uitdienst`
(uitdienst is a terminal state)

**GIVEN** an employee transitions to `uitdienst` with an uitdienst date set  
**WHEN** the transition completes  
**THEN** the `retentionExpiresAt` calculated field is set to `uitdienst date + 7 years`,
marking the end of the AVG retention window

---

### REQ-EMP-004: AVG 7-Year Retention Policy

Employee records are subject to a 7-year data retention period starting from the date of
uitdienst (employment end). The system automatically calculates and surfaces the retention
expiry date.

**GIVEN** an employee has transitioned to `uitdienst` and has an `endDate` set  
**WHEN** the employee detail page is viewed  
**THEN** the field `retentionExpiresAt` shows the date 7 years after `endDate`; the field
is read-only and derived automatically

**GIVEN** the current date is past `retentionExpiresAt`  
**WHEN** a Privacy Officer views the employee record  
**THEN** the record is visually flagged as "retention expired" and available for destruction
review; no automatic deletion occurs without explicit human approval

**GIVEN** an employee record is within the retention window (status `uitdienst`, date < retentionExpiresAt)  
**WHEN** a user attempts to delete the record  
**THEN** the system displays a warning: "This record is within the 7-year AVG retention
window (expires [date]). Deletion requires Privacy Officer approval."

---

### REQ-EMP-005: Emergency Contact

An employee record optionally includes a single emergency contact with naam, telefoon, and relatie.

**GIVEN** an HR administrator edits an employee record  
**WHEN** they enter an emergency contact name and telephone number  
**THEN** the data is saved as part of the Employee object; the contact is displayed in the
employee detail view under "Noodcontact"

**GIVEN** an employee record has no emergency contact  
**WHEN** the employee detail view is displayed  
**THEN** the "Noodcontact" section shows an empty state with a prompt to add one; no
validation error is raised (emergency contact is optional)

---

### REQ-EMP-006: Field-Level Access Control for Sensitive Fields

Sensitive fields (BSN, IBAN) are restricted to users with explicit permissions. Unauthorised
users cannot read or modify these fields.

**GIVEN** a user with role `hr:read` (no `bsn:read` permission) loads an employee detail page  
**WHEN** the HTTP response is returned  
**THEN** the `bsnEncrypted` field is absent from the response payload; no masked value
is shown (field is omitted entirely, not masked)

**GIVEN** a user with role `hr:admin` (has `bsn:read` and `iban:read`) loads an employee detail  
**WHEN** the response is returned  
**THEN** both `bsnEncrypted` (decrypted, masked) and `iban` are present in the response
with appropriate display formatting
