---
status: approved
---

# Proposal: Offboarding workflow (Offboarding case entity)

## Problem Statement

Dutch MKB offboarding is operationally fractured across multiple systems (HRMQ, payroll, IT, audit) with no unified workflow. The single biggest source of downstream errors and compliance violations stems from:

- **Administrative errors** (belastingnaheffingen, pensioenfonds-correcties surfacing months after departure)
- **AVG violations** (unscheduled data destruction, unrevoked system access weeks post-departure)
- **Financial disputes** (incorrectly calculated eindafrekeningen involving verlof, vakantiegeld, transitievergoeding, inhoudingen)
- **Security incidents** (leaver retains Nextcloud, e-mail, VPN access)

Each absence is manually detected (in retrospect) and corrected at 5-10x the cost of proactive handling. The hardest compute problem — the **eindafrekening** — combines statutory leave, vacation accrual, 13th-month pro rata, transitievergoeding (Wet WWZ 2026), and withholdings across 4-5 different legal frameworks, with per-component audit trails required. The second hard problem is **AVG retention scheduling** — 7 år fiscaal, 5 år arbeid, 2 år sollicitatie timers must auto-trigger with cryptographic destruction.

## Value Proposition

- **Legal risk → zero**: Every departure governed by a deterministic stateful case object. Hard gates where Dutch law requires them (eindafrekening freeze, UWV WW-meldingen, pensioenfonds afmelding).
- **Compute correctness**: Eindafrekening is ~400 lines of determistic math (1/3 maandsalaris × dienstjaren × pro-rata) fully auditable. Transitievergoeding auto-indexed per 2026 WAB. No manual override without immutable audit entry.
- **Knowledge continuity**: Structured manager-handover-checklist captures tacit knowledge (projects, klantcontacten, externe systeem-toegangen, sleutel-vergaderingen) per vertrekker. Continuity of operations protected.
- **Compliance automation**: Exit interview, getuigschrift-drafting, data-export provisioning, goodbye-communicatie, retention-timer scheduling all wired into the case lifecycle. Auditors can prove all 12 steps were taken.
- **Operational visibility**: Single case dashboard shows step completion, blockers, owner assignments, dates. Reduces "who was supposed to do X?" friction.

## Key Features

1. **Offboarding case lifecycle** — Single case entity from departure announcement through final artefact handover + data destruction. Status progression: `opzegging_geregistreerd` → `it_accounts_deactiveren` → `retentie_timers_starten` → `afgerond_op`.

2. **Eindafrekening computation** — Deterministic calculation of severance pay combining: statutory leave hours (× uurloon_op_einddatum), accrued vacation money (8%), 13th-month pro rata, transitievergoeding (1/3 × maandsalaris × dienstjaren, pro-rata days, 2026-indexed max), minus withholdings (loans, unreturned equipment). Every component auditable.

3. **Exit interview capture** — Structured form (satisfaction 1-10, feedback, rehire likelihood, reason categories). Anonymizable per 90-day rule for aggregate reporting.

4. **Equipment tracking** — Per-item return checklist (laptop, phone, keys, access cards, etc.) with condition assessment and inhouding-if-unreturned amounts.

5. **IT account deactivation with data-export** — Full Nextcloud export (files, calendar, contacts, Talk history) offered to leaver on secure channel (14-day download link or USB). Account disabled, mail-forwarded for 90d, then deleted day 30+.

6. **Knowledge-transfer checklist** — Manager-driven handover of active projects (receiver assignment), klantcontacten (relatie-status), external système-toegangen, sleutel-vergaderingen, tacit-knowledge memos. Blocks case completion if open.

7. **Getuigschrift (work certificate)** — Auto-generated per art. 7:656 BW (activity type, duration, manner). Manager signature via eIDAS. Rendered via docudesk.

8. **UWV WW-melding** — Auto-drafted unemployment notice (reden, einddatum, last-earned salary, termination-agreement PDF). Submitted via openconnector within statutory deadline.

9. **Pensioenfonds + ZVW afmeldingen** — Auto-submitted via openconnector with confirmation tracking. Escalation at 14d no-confirmation.

10. **AVG retention timers** — On case completion, spawn 4-7 `RetentionTimer` objects per artefact category (7y fiscal, 5y labour, 2y recruitment, other statutory). At timer expiry, cryptographic destruction with immutable audit log.

11. **Goodbye communicatie** — Structured team announcement template (departure date, role, successor intro if applicable) with optional external contact notification and successor assignment.

12. **GDPR data subject access** — Export full offboarding dossier (all steps, all changes, all calculations, all data-exports, all retention-actions) as searchable PDF within 4w. Auto-pseudonymise third-party mentions.

## Success Criteria

- [ ] Zero manual eindafrekening overrides without audit-log
- [ ] 100% of statutory terminations complete via the wizard (zero cases bypassing workflow)
- [ ] UWV WW-meldingen submitted within statutory deadline in 95%+ of cases
- [ ] Equipment return tracking → 90%+ of leavers accounted for within 14d of departure
- [ ] Manager-handover checklist blocks case closure until 100% of projects/contacts have receiver assignment
- [ ] Getuigschrift issued within 5d of request (template → signature → delivery)
- [ ] Data-export provisioned within 2d of request; download link expires 14d
- [ ] AVG retention timers start on case completion; zero timers missed or manually created
- [ ] Audit trail covers every step, actor, timestamp, before/after values
- [ ] GDPR data subject access requests fulfilled within 4w; pseudonymisation working

## Scope (In)

**In:**
- Offboarding case entity and state machine
- Eindafrekening computation (verlof, vakantiegeld, 13e-maand, transitievergoeding, withholdings)
- Exit interview capture
- Equipment return tracking
- IT account deactivation + data-export
- Manager handover checklist
- Getuigschrift generation (template + signature)
- UWV WW-melding auto-draft + submission
- Pensioenfonds + ZVW afmelding auto-draft + submission
- AVG retention timers + cryptographic destruction
- Goodbye communicatie (team announcement + external notification)
- GDPR data subject access export

**Explicitly Out:**
- Arbeidsconflict-bemiddeling (refer to external)
- Juridische beschikking bij ontslag (administrative processing only, not legal reasoning)
- Pensioenkapitaal-overdracht (pension fund's job, not hrmq)
- Outplacement (referral + check tracking, not service delivery)
- Actual payroll loonstrook computation (payroll-engine-nl's job; hrmq computes input only)

## Stakeholders

- **HR-officer** — Primary case owner, exit-interview conductor, certificate requestor
- **HR-admin** — Eindafrekening reviewer + approval gate, correction handler
- **Line manager** — Handover-checklist driver, equipment receiver, goodbye-announcement approver
- **IT-beheerder** — Account deactivation + data-export executor
- **Leaver (employee)** — Receives data-export, certificate, final payslip, annual statement
- **Payroll officer** — Consumes frozen eindafrekening; reports final strook + jaaropgaaf back
- **Auditor / AVG-functionaris** — Read-only dossier access, retention-timer verification
- **Boekhouder / controller** — Receives AP-bookings via shillinq; uses reporting for year-end

## Open Questions / Decisions Pending

- Should second-level approval (HR-admin) be optional if first-level (HR-officer) review covers all red-flag scenarios? **→ Defer to design phase**
- Correctie-naheffing flow after payment: how many iterations allowed before requiring legal escalation? **→ Defer to specs**
- Should tacit-knowledge memos be encrypted/redacted from auditor view? **→ Defer to design; affects audit-trail scoping**
