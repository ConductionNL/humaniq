---
kind: code
depends_on: []
status: draft
---

# Employee Master (NAW, BSN, IBAN, AVG)

## Why

Dutch employers are legally required to maintain accurate, GDPR-compliant (AVG) personnel records
for every employee. Without a structured employee master, HR teams rely on spreadsheets and
disconnected tools — creating AVG audit risk, BSN data-leak exposure, and manual rekeying errors
across payroll, contracts, and onboarding.

Market intelligence across 30 competitors (ADP NL, AFAS, HiBob, Centric, Frappe HR, Employes)
confirms that an employee personal-record module is the universal table-stakes starting point:
46 discrete features tracked, all competitors implement some form of it before any other module.

The hrmq app cannot support payroll, onboarding, or leave management without this foundation.

## What

A structured employee master record covering:

- **NAW** — naam, adres, woonplaats (name, address, locality)
- **BSN** — encrypted at rest with AES-256; never exposed in logs or API responses
- **IBAN** — bank account number for salary transfer
- **Contact** — email, telephone, geboortedatum, nationaliteit
- **Emergency contact** — naam, telefoon, relatie
- **Employment dates** — indienst / uitdienst
- **AVG compliance** — employee status lifecycle with 7-year retention timer starting at uitdienst

All data stored as OpenRegister objects. Platform handles CRUD, audit trail, field-level RBAC,
GDPR data-subject access requests, and full-text search.

Custom code is limited to BSN encryption/decryption (AES-256, no OR extension exists for this).

## Capabilities

### New Capabilities

- **`employee`** — personal record management: NAW, BSN (encrypted), IBAN, contact details,
  emergency contact, employment dates, AVG-lifecycle with 7-year retention

### Modified Capabilities

_(none — first change on this app)_

## Stakeholders

| Role | Name | Responsibility |
|------|------|----------------|
| HR Administrator | HR medewerker | Creates and maintains employee records, handles BSN/IBAN data entry |
| HR Manager | HR manager | Reviews records, approves lifecycle transitions, AVG oversight |
| Employee (self-service, future) | Werknemer | Read-only access to own NAW data (out of scope this change) |
| Privacy Officer | FG / DPO | Monitors AVG compliance, triggers data-subject requests |
| System Administrator | Beheerder | Manages encryption key, configures retention policies |

## Out of Scope

- Employee self-service portal (own data editing)
- BRP/GBA validation of NAW data (separate integration change)
- Document management / personeelsdossier (separate change)
- Payroll and salary information (separate change)
- Leave management and absence tracking (separate change)
- BSN-Koppeling / DigiD authentication (separate change)
