---
status: draft
---
# Onboarding workflow (multi-step Onboarding case entity)

## Placement & Information Architecture

**Placement type:** `SUB_PAGE` — Sub-page beneath a top-level menu entry. Renders as a page inside the parent surface (usually reachable via a router child route or a tab on the parent index page).

**Lives at:** Onboarding & ATS › Onboardings

**Rationale:** Wizard-lijst.  
_Source: /tmp/ia-procest-hrmq.md_

> **Implementation note for builders:** Respect the placement above. Do not promote this spec to a top-level menu item, sub-page, or new route unless the placement type explicitly says so. If the placement is `DETAIL_TAB`, `WIDGET`, `ACTION`, `SETTING`, or `INFRA`, the feature must NOT introduce a new entry in the app sidebar. When in doubt, ask before creating a new top-level surface.

## Purpose

The `onboarding-wizard` capability turns the historically chaotic "new hire first week" into a single coordinated case that spans recruiting, HR, IT, the line manager, and external parties (pension fund, UWV, payroll, e-signing provider). In most Dutch MKB organisations onboarding is a tangle of spreadsheets, ad-hoc emails, paper folders, and verbal handovers; payroll only finds out a new employee exists when the manager forwards a contract scan, IT provisions the laptop a week too late, the pensioenfonds receives the aanmelding two months after start date (triggering retroactive corrections), and nobody can prove later whether the WID-check actually happened.

hrmq treats every new hire as an `Onboarding` case object that owns a deterministic state machine: from `aangenomen` through `contract_verzonden`, `contract_getekend`, `id_geverifieerd`, `bsn_gevalideerd`, `iban_geverifieerd`, `it_provisioned`, `eerste_werkdag`, `proeftijd_lopend`, to `proeftijd_afgerond`. Each transition is gated by concrete artefacts — a signed PDF with eIDAS signature blob, a WID-check log entry, a BSN-modulus-11 pass, an `oc_users` row, a pensioenfonds aanmeldbericht confirmation — and a transition can only fire when its preconditions are met. The same case carries a checklist of side-tasks (bedrijfskleding bestellen, laptop ophalen, mentor toewijzen, VOG aanvragen, sleutels uitgeven) which are not themselves blocking for payroll but must be tracked, escalated when overdue, and reportable.

The wizard is the operator UI on top of the case: a stepper that recruits and HR-officers actually fill in, with each step being a thin form bound to a single sub-aspect of the case. Behind the wizard sits a reminder + escalation engine that nudges the right person when a step stalls (employee hasn't signed contract after 3 working days → HR notified; ID-document still missing 2 days before start date → manager + HR escalated; pensioenfonds aanmelding rejected → payroll team escalated).

The hard load-bearing requirement is the **payroll gate**: an employee record may not flow into the next `loonberekening` run until `onboarding.payroll_ready === true`, which is computed from the combination of (signed contract on file, BSN validated, IBAN validated, ZVW-melding done, pensioenfonds aanmelding confirmed, and start_date in the past or current pay period). This eliminates the entire class of bug where HR forgets to tell payroll that a new employee exists; the moment all preconditions are satisfied, the next salarisrun automatically picks the employee up.

Scope explicitly excludes: applicant-tracking / werving (a separate future `recruitment` spec), generic case management for non-HR processes (lives in core procest), the actual e-signing transport (delegated to docudesk), and the actual user provisioning protocol (delegated to nextcloud's OCS user API via openconnector). hrmq holds the orchestration and the audit trail.

A third load-bearing requirement is the **proeftijd-watcher**: the wizard registers the einddatum of the proeftijd (legally bound to 1 maand voor contracten tot 2 jaar, 2 maanden voor langere of onbepaalde tijd contracten, en geheel verboden bij contracten van 6 maanden of korter sinds de Wet Arbeidsmarkt in Balans). On reaching T-7 and T-2 days before einddatum, both HR and the lijnmanager receive an actionable notification with three buttons: `proeftijd_geslaagd_afronden`, `proeftijd_verlengen_niet_mogelijk_in_NL` (geblokkeerd met juridische uitleg), of `proeftijd_beëindigen` (waarbij automatisch een offboarding-case wordt aangemaakt en aan elkaar gekoppeld). Dit voorkomt de klassieke MKB-bug waar een proeftijd stilzwijgend voorbij gaat en de werkgever vervolgens een vol arbeidscontract niet meer kan beëindigen zonder UWV of kantonrechter.

Een vierde aspect dat hrmq expliciet niet aan andere apps overlaat is de **dossier-integriteit**: het Onboarding-case object is de "single pane of glass" waar alle artefacten naar verwijzen. Een HR-officer mag nooit hoeven schakelen tussen docudesk (voor de contract-PDF), Nextcloud (voor de user-account), Talk (voor het welkomstbericht), een externe payroll-portal (voor de aanmeldgegevens), en het personeelsdossier in shillinq — alles wordt vanuit dit case-object benaderbaar gemaakt via deep-links en geïntegreerde inline-weergaven (PDF-preview, status-badge op de Nextcloud-user, recente Talk-berichten van mentor naar nieuwe medewerker). Dit is een UX-eis maar heeft data-modellaire consequenties: elke externe referentie wordt expliciet getypeerd opgeslagen met genoeg metadata om de externe artefact zonder extra round-trip te kunnen tonen.

## Data Model (entities + Dutch JSON examples)

### Onboarding (case)

Top-level case object. One per `employee_id` (uniqueness enforced; rehires get a new case with `is_rehire: true` and a reference back to the previous offboarding case).

```json
{
  "id": "ob_01HXYZ123ABC",
  "employee_id": "emp_01HXYW987DEF",
  "status": "contract_getekend",
  "start_date": "2026-06-01",
  "is_rehire": false,
  "previous_offboarding_id": null,
  "recruiter_user_id": "u_42",
  "hiring_manager_user_id": "u_88",
  "hr_owner_user_id": "u_12",
  "it_owner_user_id": "u_99",
  "mentor_user_id": "u_55",
  "payroll_ready": false,
  "payroll_ready_at": null,
  "checklist_completion_pct": 62,
  "vog_required": true,
  "vog_status": "aangevraagd",
  "proeftijd_einddatum": "2026-07-31",
  "created_at": "2026-05-08T09:14:00+02:00",
  "updated_at": "2026-05-20T11:02:00+02:00"
}
```

### OnboardingStep

Each row is one of the fixed wizard steps; created lazily the first time a step is touched.

```json
{
  "id": "obs_01HXZ456",
  "onboarding_id": "ob_01HXYZ123ABC",
  "step_key": "iban_verificatie",
  "status": "voltooid",
  "voltooid_door_user_id": "u_12",
  "voltooid_op": "2026-05-18T15:22:00+02:00",
  "blocking": true,
  "payload": {
    "iban": "NL91ABNA0417164300",
    "verificatie_methode": "iban_modulo97",
    "validatie_resultaat": "geldig",
    "naam_op_rekening_match": "exact"
  },
  "audit_evidence_id": "doc_iban_01HXZ"
}
```

Fixed `step_key` enum: `contract_aanmaken`, `contract_versturen`, `contract_ondertekenen`, `id_upload`, `wid_check`, `bsn_validatie`, `iban_verificatie`, `pensioen_aanmelding`, `zvw_melding`, `it_provisioning`, `bedrijfskleding`, `laptop_uitgifte`, `mentor_toewijzing`, `vog_aanvraag`, `eerste_werkdag_checklist`.

### WIDCheck (Wet op de Identificatieplicht)

```json
{
  "id": "wid_01HXZ789",
  "onboarding_id": "ob_01HXYZ123ABC",
  "document_type": "paspoort",
  "document_nummer_hash": "sha256:7f3a...",
  "uitgegeven_door": "Burgemeester van Amsterdam",
  "geldig_tot": "2031-04-12",
  "gecontroleerd_door_user_id": "u_12",
  "gecontroleerd_op": "2026-05-15T10:30:00+02:00",
  "fysiek_gezien": true,
  "kopie_versie": "wid_kopie_2024",
  "kopie_opslag_document_id": "doc_id_01HXZ",
  "kopie_bewaartermijn_einde": "2031-12-31"
}
```

The raw document number is never stored; only a salted SHA-256 hash for matching. The document scan itself is stored as a docudesk file with restricted ACL. Bewaartermijn aligns with art. 28 Uitvoeringsregeling LB: 5 jaar na einde dienstverband.

### BSNValidatie

```json
{
  "id": "bsn_01HXZA12",
  "onboarding_id": "ob_01HXYZ123ABC",
  "bsn_hash": "sha256:b91e...",
  "elfproef_resultaat": "geldig",
  "format_resultaat": "geldig",
  "gevalideerd_op": "2026-05-15T10:32:00+02:00",
  "bron": "wid_check"
}
```

Bare BSN lives only on the `Employee` record (encrypted at rest) — `BSNValidatie` only stores hash + elfproef result so it can be re-shown in audit without exposing the BSN.

### ContractSigningEvent

```json
{
  "id": "sign_01HXZB34",
  "onboarding_id": "ob_01HXYZ123ABC",
  "contract_document_id": "doc_contract_01HXZ",
  "envelope_id": "docudesk_env_447",
  "ondertekenmethode": "eidas_qes",
  "ondertekend_door": "jan.dejong@voorbeeld.nl",
  "ondertekend_op": "2026-05-16T14:08:00+02:00",
  "ip_adres": "82.157.x.x",
  "certificaat_serienummer": "00:A3:1F:...",
  "lta_archive_id": "lta_447"
}
```

### Reminder

```json
{
  "id": "rem_01HXZC56",
  "onboarding_id": "ob_01HXYZ123ABC",
  "step_key": "contract_ondertekenen",
  "geadresseerde_user_id": "u_42",
  "kanaal": "email_plus_nextcloud_notification",
  "trigger_at": "2026-05-19T09:00:00+02:00",
  "verzonden_op": null,
  "escalatie_niveau": 1,
  "escalatie_naar_user_id": "u_12"
}
```

### OnboardingChecklistItem

Used for the loose "first day" tasks that don't gate payroll but must be tracked.

```json
{
  "id": "cli_01HXZD78",
  "onboarding_id": "ob_01HXYZ123ABC",
  "categorie": "eerste_werkdag",
  "titel": "Sleutelpas activeren",
  "verantwoordelijke_user_id": "u_99",
  "due_op": "2026-06-01",
  "status": "open",
  "opmerking": ""
}
```

## Requirements

### REQ-OB-001 — State machine integrity

The system SHALL enforce a deterministic state machine for `Onboarding.status` with the transitions defined in the spec; any attempt to set a status that is not reachable from the current status SHALL be rejected with a 409 and a machine-readable `invalid_transition` error.

- GIVEN an onboarding case in status `aangenomen`
  WHEN an operator attempts to transition directly to `it_provisioned`
  THEN the API responds 409 with `error.code = "invalid_transition"` and `allowed_next_states` listing only `contract_aangemaakt` and `geannuleerd`.

- GIVEN an onboarding case in status `contract_verzonden`
  WHEN docudesk delivers a signed-envelope webhook with a matching `envelope_id`
  THEN the case transitions to `contract_getekend`, a `ContractSigningEvent` row is created, and the `contract_ondertekenen` step is marked `voltooid`.

### REQ-OB-002 — WID-check evidence

The system SHALL require, before transitioning past `id_geverifieerd`, a `WIDCheck` row with a non-null `gecontroleerd_door_user_id`, `fysiek_gezien = true` (or a documented hybride-procedure exception with `fysiek_gezien_reden`), and an attached document scan whose `bewaartermijn_einde` is set to start_date + 5 years.

- GIVEN an HR-officer who uploads a passport scan and ticks "fysiek gezien"
  WHEN they confirm the WID-check
  THEN a `WIDCheck` row is created, the document is stored with ACL restricted to HR + auditor groups, and the `wid_check` step transitions to `voltooid`.

### REQ-OB-003 — BSN validation

The system SHALL validate every BSN via format check (9 digits, no leading zeros forbidden, no all-same) AND elfproef (modulus-11 with weights 9,8,7,6,5,4,3,2,-1) before allowing the case to leave `id_geverifieerd`. The raw BSN SHALL only be stored on `Employee` (encrypted) and never logged in plaintext.

### REQ-OB-004 — IBAN validation

The system SHALL validate every IBAN via (a) country-prefix + length check for NL/BE/DE, (b) ISO-13616 modulus-97 check, and (c) where a SEPA name-check service is configured via openconnector, a name-on-account match check whose result is one of `exact`, `close_match`, `no_match`, `not_available`. A `no_match` SHALL block the `iban_verificatie` step from completion until manually overridden by an HR-officer with role `hr_admin` and a free-text justification of at least 20 characters.

### REQ-OB-005 — Payroll readiness gate

The system SHALL compute `payroll_ready = true` if and only if all of the following are true: contract step voltooid AND BSN validated AND IBAN validated AND ZVW-melding `bevestigd` AND pensioen-aanmelding `bevestigd` (or `niet_van_toepassing` with documented reason). The payroll-engine-nl integration SHALL refuse to include an employee in a salarisrun whose `payroll_ready` is false on the run's reference date.

- GIVEN an employee whose ZVW-melding is still `in_behandeling`
  WHEN the maandloon-batch runs for that pay period
  THEN the employee is excluded from the batch, an `excluded_employees` line is added to the run summary, and a notification is sent to both HR and payroll roles.

### REQ-OB-006 — Contract signing via eIDAS QES or AES

The system SHALL only accept contracts signed via docudesk envelopes that return either an eIDAS QES (qualified) or AES (advanced) signature blob; SES (simple) is rejected for arbeidscontracten. The system SHALL store the LTA archive identifier so the signature remains verifiable beyond certificate expiry.

### REQ-OB-007 — Reminder + escalation

The system SHALL emit reminders on per-step configurable schedules (default: T+3 working days for `contract_ondertekenen` to candidate, T+2 working days for `id_upload` to candidate, T-5 calendar days before `start_date` for any outstanding blocking step to HR). After two unacknowledged reminders the system SHALL escalate to the configured `escalatie_naar_user_id`.

- GIVEN a contract sent on Monday with no signature by Thursday 09:00
  WHEN the reminder scheduler runs
  THEN a level-1 reminder email is sent to the candidate and a Nextcloud notification is sent to the recruiter, and a `Reminder` row is logged with `escalatie_niveau = 1`.

### REQ-OB-008 — IT provisioning idempotency

The system SHALL provision a Nextcloud user via OCS Users API with `userid` derived from a deterministic pattern (configurable, default `voornaam.achternaam`, with collision-suffix `voornaam.achternaam2`), assign group memberships per the employee's `afdeling` + global `medewerker` group, and SHALL be idempotent — re-running the provisioning step for an existing user updates groups but does not error.

### REQ-OB-009 — Bewaartermijn enforcement

The system SHALL set retention timers per artefact per Dutch tax + employment law: WID-kopie 5 years after einde dienstverband, sollicitatie-correspondentie 4 weeks after rejection (or up to 1 year with consent), payroll-grondslagen 7 years (fiscaal). On retention expiry the artefact SHALL be cryptographically deleted (key destruction) with an audit log entry.

### REQ-OB-010 — Audit trail completeness

The system SHALL maintain an append-only audit log for every status transition, every step completion, every reminder sent, every WID-check, every override (with justification text), every retention deletion. Audit log entries SHALL be queryable by `employee_id`, `onboarding_id`, and date range, and SHALL be exportable to PDF for AVG-inzageverzoek responses within 4 weeks (art. 12.3 AVG).

- GIVEN een werknemer die een AVG-inzageverzoek indient via de organisatie
  WHEN de AVG-functionaris de export aanvraagt vanuit het Onboarding-dossier
  THEN binnen 60 seconden is een PDF beschikbaar met alle persoonsgegevens, alle wijzigingen, alle overrides + justificaties, en de retentie-status per artefact; de PDF heeft een eIDAS-tijdstempel voor onweerlegbaarheid.

### REQ-OB-011 — Proeftijd-watcher

The system SHALL bij contracten met een proeftijd-clausule automatisch een `Proeftijd`-watcher activeren die T-7 en T-2 werkdagen vóór `proeftijd_einddatum` notificaties stuurt aan HR-owner én lijnmanager, met drie expliciete vervolgkeuzes (afronden, beëindigen, niet-mogelijk-verlengen). Bij keuze `proeftijd_beëindigen` SHALL het systeem automatisch een offboarding-case openen met `reden = proeftijd_beëindigd` en deze koppelen aan het onboarding-dossier.

- GIVEN een onboarding met `proeftijd_einddatum = 2026-07-31` en geen actie op T-7
  WHEN de scheduler de T-2 notificatie verstuurt
  THEN HR en de lijnmanager ontvangen een Nextcloud-notificatie met drie actie-knoppen, en op T-0 sluit de proeftijd automatisch als `proeftijd_geslaagd_afronden` niet expliciet is bevestigd én er ook geen offboarding is gestart — met een waarschuwing in het dossier dat het contract daarmee onvoorwaardelijk geworden is.

### REQ-OB-012 — Self-service portal voor de nieuwe medewerker

The system SHALL de kandidaat een veilige self-service-portal aanbieden (via een unieke, expiry-bound link verstuurd per e-mail, geen account-creatie vereist tot indiensttreding) voor het invullen van persoonsgegevens, uploaden van ID-bewijs, IBAN-invoer, voorkeur-aanspreking, noodcontactgegevens, en eventuele bijzondere voedingseisen voor team-lunches. De portal SHALL alleen via TLS bereikbaar zijn, SHALL na 30 dagen inactiviteit de link invalideren, en SHALL elke veld-wijziging in de audit-log vastleggen met IP-adres en user-agent.

## Standards & Sources

- **AVG/GDPR** art. 5, 6, 9, 12, 15, 17, 25, 28, 32 — lawful basis, special category (BSN is in NL treated as special; ID-document numbers always), retention, data subject rights, security.
- **Wet bescherming persoonsgegevens BSN (Wbp-BSN)** — beperking gebruik BSN tot wettelijke grondslag (werkgever heeft grondslag via Wet LB art. 28).
- **Wet op de Identificatieplicht (WID)** — werkgever moet identiteit vaststellen aan de hand van origineel document vóór indiensttreding; kopie bewaren tot 5 jaar na einde dienstverband (art. 28 Uitvoeringsregeling LB).
- **Uitvoeringsregeling Loonbelasting 1965 art. 28** — bewaarplicht identiteitsdocument kopie.
- **Wet op de Loonbelasting 1964** — verplichte gegevens loonadministratie.
- **eIDAS Verordening (EU) 910/2014** — QES/AES/SES classificatie; QES heeft gelijke rechtskracht als handgeschreven handtekening; AES is voldoende voor arbeidscontracten mits identificatie aantoonbaar.
- **Pensioenwet art. 7 + art. 23** — meldingsplicht werkgever bij pensioenuitvoerder binnen wettelijke termijn.
- **Zorgverzekeringswet (ZVW)** — werkgeversbijdrage en melding loonheffingen.
- **ISO/IEC 27001** + **NEN 7510** (sectorale informatiebeveiliging) — beheersmaatregelen voor toegangsbeheer, retentie, logging.
- **ISO 20022** — pain.001 betaalformaat voor salarisbetalingen (relevant voor downstream payroll).
- **ISO 13616** — IBAN modulus-97 validatie.
- **ELV-rapport "Veilig omgaan met BSN"** (Autoriteit Persoonsgegevens) — beste praktijken hash/encryptie BSN.
- **Belastingdienst Handboek Loonheffingen 2026** — administratieve verplichtingen werkgever.
- **VNG / UBV onboarding-procesmodellen** — referentieproces voor publieke werkgevers (toepasbaar als baseline ook voor private MKB).

## Cross-app integration

- **docudesk** — owns contract-template rendering, e-signing envelope creation, signed-PDF storage with LTA, OCR of identiteitsbewijzen. hrmq calls `POST /api/envelopes` with the rendered contract and a webhook URL; docudesk calls back on signing events.
- **openconnector** — owns the outbound calls to (a) pensioenfonds aanmelding (per-fund mapping: PFZW, ABP, BPL, branche-pensioenfondsen), (b) UWV / Belastingdienst loonheffingen-aangifte trigger, (c) optional SEPA name-check service (Surepay or equivalent via API), (d) BKR / VOG-portaal voor VOG-aanvraag waar van toepassing.
- **Nextcloud user management** — IT-provisioning calls `OCS Users API` to create user, set quota, assign groups; hrmq tracks the resulting `userid` on the `Employee`.
- **payroll-engine-nl** (peer hrmq spec) — consumes `Employee.payroll_ready` to gate inclusion in maandloon-batches.
- **employee-master** (peer hrmq spec) — the canonical `Employee` record; onboarding writes BSN (encrypted), IBAN, contract reference, start_date, function, salaris-grondslagen onto it.
- **contract-management** (peer hrmq spec) — owns the `Contract` template + clauses; onboarding renders an instance and feeds it to docudesk.
- **shillinq** (peer Conduction app) — receives notification on onboarding completion to set up cost-center allocation; AP-feed not used here.
- **procest** — case-model substrate; `Onboarding` is a procest case kind with hrmq-specific schema overlay.

## Target users

- **HR-officer / personeelszaken** — primary wizard operator; fills steps, runs WID-check, monitors checklists. Typical Dutch MKB profile: HR-generalist in een organisatie van 20-250 medewerkers, doet ook offboarding, contractwijzigingen, en is eerste aanspreekpunt loonadministratie. Verwacht een UI die het hele dossier in één scherm laat zien zonder tab-springen.
- **Recruiter** — opens new cases from a closed vacancy / hire decision; hands off to HR after contract is signed. Vaak een externe (uitzendbureau of werving-en-selectiebureau) — heeft beperkte toegang tot alleen de eigen kandidaten.
- **Hiring manager / lijnmanager** — owns the eerste-werkdag-checklist, mentor-toewijzing, equipment-uitgifte. Geen HR-expertise, wil een korte takenlijst en duidelijke deadlines.
- **IT-beheerder** — owns IT-provisioning step; usually a single person of een externe MSP in een MKB. Wil het liefst geautomatiseerd, met een dashboard van net-aangenomen medewerkers en hun status.
- **Werknemer (kandidaat / nieuwe medewerker)** — self-service portal voor contract-ondertekening, ID-upload, IBAN-invoer, persoonsgegevens. Geen account in hrmq zelf in de eerste fase; ontvangt secure links per e-mail of via docudesk-portal.
- **Auditor / AVG-functionaris** — read-only toegang tot het volledige audit-spoor, bewaartermijn-rapportages, en kan inzage-verzoeken servicen.
- **Payroll-officer** — niet zelf wizard-operator maar wel directe stakeholder; consumeert `payroll_ready` en krijgt notificaties bij ontbrekende grondslagen vóór een salarisrun.
- **Pensioenadministrateur** (intern of extern) — ontvangt vanuit hrmq de aanmeldgegevens; krijgt foutmeldingen retour gerapporteerd in de case.
