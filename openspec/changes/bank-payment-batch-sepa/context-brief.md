---
status: proposed
app: hrmq
spec: bank-payment-batch-sepa
owner: hrmq-payroll
depends_on: [payroll-engine-nl, expense-reimbursement, shillinq]
target_users: [payroll-admin, finance-controller, cfo, werknemer]
---

# SEPA Payment Batch (Salaris-Uitbetaling)

## Placement & Information Architecture

**Placement type:** `SUB_PAGE` — Sub-page beneath a top-level menu entry. Renders as a page inside the parent surface (usually reachable via a router child route or a tab on the parent index page).

**Lives at:** Salarissen › SEPA-batches

**Rationale:** SEPA-pain.001 batch.  
_Source: /tmp/ia-procest-hrmq.md_

> **Implementation note for builders:** Respect the placement above. Do not promote this spec to a top-level menu item, sub-page, or new route unless the placement type explicitly says so. If the placement is `DETAIL_TAB`, `WIDGET`, `ACTION`, `SETTING`, or `INFRA`, the feature must NOT introduce a new entry in the app sidebar. When in doubt, ask before creating a new top-level surface.

## Purpose

Every maandelijkse payroll-cycle ends in money leaving the werkgever's bankrekening and landing on werknemer-rekeningen. In Nederland is dit traditioneel verschillende uploads richting de bank: een SEPA-bulk voor netto-loon, een aparte run voor onkostendeclaraties, een derde voor bonussen of eenmalige uitkeringen, en handmatige boekingen voor uitschieters. Veel kleine werkgevers doen dit nog steeds via internet-bankieren met een CSV-upload of - erger - per werknemer een losse overboeking. Fouten zijn duur: een verkeerd bedrag naar de verkeerde IBAN is administratief 2-3 dagen werk om terug te draaien, en het ondermijnt vertrouwen.

De juridische lat ligt hoog. Salaris moet uiterlijk op de afgesproken loon-datum (meestal de 25e van de maand) op de werknemer-rekening staan — bij latere betaling kan een werknemer aanspraak maken op wettelijke verhoging (art. 7:625 BW, oplopend tot 50%). Bij grote werkgevers gaat het om miljoenen-betalingen per maand: ABN AMRO, ING en Rabo eisen voor corporate-clients SCA (Strong Customer Authentication) op submission, een audit-trail van wie de batch heeft goedgekeurd, en soms een dual-control hard requirement. SOX-compliance voor multinational-tenants vereist een end-to-end traceerbare goedkeuringsketen — wie heeft welk bedrag wanneer naar wie gestuurd, en wie heeft dat geapproveerd.

This spec definieert de SEPA Credit Transfer (SCT) batch-generatie voor HRMQ: per payroll-run wordt één pain.001.001.09 XML-bestand gegenereerd dat netto-loon + onkostendeclaraties + bonussen aggregeert per werknemer in één betaling per persoon (geen drie losse overboekingen voor dezelfde maand). Het XML wordt geupload naar de bank via een keuze uit: direct API (PSD2 corporate-API met SCA), SFTP-batch (klassiek), of handmatige download. Voor banken die pain.002 status-rapportages teruggeven leest HRMQ deze in en reconcilieert per betaling — gefaalde betalingen (rekening opgeheven, IBAN onjuist) worden in een werklijst geplaatst voor HR. Eén-bedrag-per-werknemer is bewust: voor de werknemer is dit overzichtelijker (één regel op het bankafschrift, "salaris en onkosten maart 2026") en voor de werkgever vermindert het transactiekosten bij banken die per-transactie afrekenen.

De batch is approval-gated: HR produceert de batch, finance reviewt totaalbedragen tegen het payroll-overzicht, CFO geeft formele approval boven een tenant-configurabele drempel (default EUR 500.000). Pre-notification mailings naar werknemers (verwachte ontvangst-datum + bedrag) gaan automatisch uit twee werkdagen voor de execution-datum — niet uit een fiscale verplichting maar uit een vertrouwens-perspectief: een werknemer die weet wanneer en hoeveel hij ontvangt is een tevreden werknemer, en discrepanties tussen verwacht en ontvangen worden direct gesignaleerd (de werknemer mailt HR meteen, in plaats van pas drie weken later bij de loonstrook-check).

Er is bewust gekozen voor een tight-coupled aanpak met shillinq's SEPA-module: facturatie-batches (uitgaande betalingen aan leveranciers) en salaris-batches delen 90% van de XML-generatie, IBAN-validatie, bank-submissie en pain.002-reconciliatie code. Code-duplicatie hier zou betekenen dat een SEPA-protocol-update (van pain.001.001.09 naar .10) twee keer geimplementeerd moet worden, met inherente drift-risico. De shillinq-module wordt naar een gedeelde library opgewerkt; HRMQ levert de payroll-specifieke aggregatie en approval-logic erbovenop.

## Data Model

`hrmq_payment_batch`: `id`, `tenant_id`, `payroll_run_id` (unique constraint voor idempotentie), `batch_reference` (unique per bank-acceptatie, format `HRMQ-{tenant}-{yyyymm}-{seq}`), `status` (`concept`, `pending_approval_finance`, `pending_approval_cfo`, `approved`, `submitted`, `partially_executed`, `executed`, `failed`), `total_amount`, `payment_count`, `execution_date`, `pain001_xml_blob_url`, `pain001_xml_hash_sha256`, `pain001_message_id`, `bank_endpoint_id` FK, `previous_batch_hash` (voor hash-chain integrity). `hrmq_payment_batch_item`: per werknemer-betaling, `batch_id`, `employee_id`, `iban`, `bic`, `amount`, `currency` (default EUR), `omschrijving` (max 140 chars per SEPA), `end_to_end_id` (unique per item), `composition_breakdown` (jsonb: netto_loon, onkosten, bonus, eenmalige_uitkering, terugvordering), `status` (`pending`, `accepted`, `accepted_settlement_completed`, `rejected_with_reason`, `held_invalid_iban`), `rejection_reason_code`, `rejection_reason_description`. `hrmq_payment_batch_status_update` voor pain.002 incremental updates per item met `received_at`, `pain002_message_id`. `hrmq_payment_approval` voor de approval-trail met user, role, decision, timestamp, comment, en optioneel `sca_token_reference` voor banken die SCA op approval-niveau vragen. `hrmq_payment_bank_endpoint` voor tenant-bank-configuraties: `bank_name`, `connection_type` (`psd2_api`, `sftp`, `manual_download`), `connection_credentials_ref`, `pain_version`, `iban_debtor`.

## Requirements

### REQ-001: Batch-aggregatie per werknemer

**GIVEN** een payroll-run met 240 medewerkers, 187 onkostendeclaraties (over 95 medewerkers), en 12 bonussen
**WHEN** de batch-generator draait
**THEN** the system produceert exactly 240 batch-items (één per werknemer) waar elke item het som-bedrag is van netto-loon + onkosten + bonus voor die werknemer. De composition_breakdown bewaart per-component bedragen voor audit. Een werknemer zonder onkosten of bonus krijgt alleen het netto-loon-bedrag. Werknemers zonder enig saldo (netto = 0, geen onkosten) worden niet als batch-item opgenomen.

### REQ-002: pain.001 XML-generatie

**GIVEN** een approved batch met N items en execution-date D
**WHEN** the XML-generator wordt aangeroepen
**THEN** the system produceert een valide pain.001.001.09 XML met de juiste GroupHeader (MsgId = batch_reference, CreDtTm = nu, NbOfTxs = N, CtrlSum = totaal-bedrag), één PaymentInformation-block (alle items vallen onder dezelfde debtor), per item een CreditTransferTransactionInformation met EndToEndId (unieke string per item, employee_id + batch_reference), InstrumentAmount, Cdtr (naam werknemer), CdtrAcct (IBAN), en RmtInf/Ustrd (omschrijving). Het XML valideert tegen de officiële pain.001.001.09 XSD voordat het wordt opgeslagen.

### REQ-003: IBAN- en BIC-validatie

**GIVEN** een werknemer-record met een ingevulde IBAN
**WHEN** een batch-item wordt opgebouwd
**THEN** the system valideert de IBAN-checksum (ISO 13616 mod-97), valideert het land-prefix (NL, BE, DE, etc. — werkgevers in NL betalen meestal alleen SEPA-EU), en derived de BIC uit een lokale lookup-tabel als de werknemer geen expliciete BIC heeft opgegeven. Ongeldige IBAN's blokkeren de batch-creation niet maar leveren het item op met status `held_invalid_iban` voor manual review.

### REQ-004: Approval-workflow met drempel

**GIVEN** een tenant met approval-drempel CFO = EUR 500.000 en finance = EUR 100.000
**WHEN** een batch met totaal-bedrag EUR 1.250.000 wordt aangeboden voor approval
**THEN** the system routeert eerst naar een finance-controller (verplicht voor alle batches >100k), vervolgens naar CFO (verplicht voor >500k). Per goedkeurder wordt een approval-record geschreven met user, timestamp, en een verplicht comment. Een approver kan terug-sturen met reason, waarna de batch terug naar `concept` gaat. Beneden de finance-drempel volstaat payroll-admin self-approval.

### REQ-005: Pre-notification e-mail naar werknemer

**GIVEN** een approved batch met execution-date D
**WHEN** D-2 (twee werkdagen voor uitvoering) wordt bereikt
**THEN** the system stuurt elke werknemer met `pre_notification_enabled = true` een e-mail met: verwachte ontvangst-datum, bedrag, IBAN-laatste-4-cijfers (privacy), en een per-component breakdown (netto loon EUR X, onkosten EUR Y, bonus EUR Z). E-mails worden gerenderd via `document-template-engine` met de tenant-branding. Werknemers kunnen pre-notification uitschakelen in hun self-service-instellingen.

### REQ-006: Bank-submissie via PSD2 corporate-API

**GIVEN** een tenant met een geconfigureerde PSD2 corporate-API (Rabobank Direct Connect, ING Sandbox, ABN Corporate Banking)
**WHEN** een approved batch wordt ingediend
**THEN** the system roept de bank-API met het pain.001 XML, vangt de submission-acknowledgement (transactie-ID), update batch status naar `submitted`, en plant een polling-job voor pain.002 status-updates. SCA (Strong Customer Authentication) wordt afgehandeld door de approver in het approval-stadium — de batch carries een SCA-token dat de API-call autoriseert.

### REQ-007: pain.002 reconciliatie

**GIVEN** een submitted batch waar de bank een pain.002 status-rapport teruglevert (via dezelfde API, SFTP, of e-mail)
**WHEN** the pain.002 wordt ingelezen
**THEN** the system matched OrgnlEndToEndId tegen batch-items, update per-item status (`accepted`, `accepted_settlement_completed`, `rejected_with_reason`), en bij volledige settlement update batch-status naar `executed`. Rejected items (verkeerde IBAN, blocked rekening, insufficient funds at debtor) worden in een werklijst voor HR geplaatst met de bank-reason-code en suggested remediation.

### REQ-008: Idempotentie en duplicate-prevention

**GIVEN** een payroll-admin per ongeluk twee keer op "Genereer batch" klikt voor dezelfde payroll-run
**WHEN** de tweede request binnenkomt
**THEN** the system detecteert via `payroll_run_id` uniqueness dat er al een batch bestaat in non-failed status, retourneert de bestaande batch in plaats van een tweede te maken. Een failed batch kan expliciet worden herzien met een nieuw batch-reference (zonder bank-side duplicate-risk).

### REQ-009: Onkostendeclaraties met BTW-uitsplitsing

**GIVEN** een werknemer dient een onkostendeclaratie in van EUR 121,00 inclusief 21% BTW
**WHEN** the batch-item wordt opgebouwd
**THEN** the system voegt de declaratie netto bij het uit te betalen bedrag (de BTW komt terug via de aangifte omzetbelasting van de werkgever, niet via de werknemer), schrijft de BTW-component op de juiste grootboekrekening voor de boekhouding, en includeert de declaratie-omschrijving in de composition_breakdown voor de pre-notification e-mail.

### REQ-010: Audit-trail en SOX-compliance

**GIVEN** een SOX-rapporterende moeder-organisatie eist dat alle salaris-betalingen een complete audit-trail hebben
**WHEN** een audit-rapport wordt opgevraagd voor een kalenderjaar
**THEN** the system produceert een rapport met per batch: payroll_run reference, totaal-bedrag, payment-count, alle approvals (wie, wanneer, comment), submission-tijdstip, executed-tijdstip, en eventuele rejections met reden. Het rapport bevat hash-chain integrity (elke batch refereert de SHA256 van de voorgaande approved batch) zodat manipulatie achteraf detecteerbaar is. Export als CSV en PDF (via document-template-engine).

## Standards

- ISO 20022 pain.001.001.09 (SEPA Credit Transfer Customer Credit Transfer Initiation)
- ISO 20022 pain.002.001.10 (Customer Payment Status Report)
- ISO 13616 (IBAN)
- ISO 9362 (BIC)
- PSD2 (EU 2015/2366) corporate-API en SCA-flow
- AVG / GDPR voor pre-notification-e-mail (rechtmatige grondslag: gerechtvaardigd belang werkgever + dienstovereenkomst)
- Belastingdienst loonadministratie bewaarplicht 7 jaar
- SOX section 404 (voor multinational tenants)

## Cross-app

- `payroll-engine-nl` als bron voor netto-loon-bedragen
- `expense-reimbursement` als bron voor onkostendeclaraties
- `shillinq` SEPA-module als shared library voor pain.001 / pain.002 XML-handling (DRY met facturatie-batches)
- `document-template-engine` voor pre-notification e-mails, approval-aanvragen, audit-rapporten
- `openconnector` adapters voor bank PSD2-API's (Rabo, ING, ABN, Bunq, KNAB)
- `docudesk` voor archivering van XML-bestanden en audit-rapporten

## Target Users

Payroll-admins die maandelijks de batch produceren en submitten, finance-controllers die approval-stappen uitvoeren voor batches boven drempel, CFOs voor de hoogste-drempel approvals, werknemers als ontvangers van pre-notification-mails en self-service IBAN-beheer, finance-auditors (intern en extern) voor compliance-rapportages. Secondary: payroll-service-providers die 50-500 klanten bedienen en een consistent batch-proces willen, en banken die HRMQ als ecosystem-partner willen aansluiten op hun corporate-API.

Tertiair: tenant-administrators die bank-koppelingen en approval-workflows configureren, security-teams die de PSD2-credential-rotation en SCA-flow monitoren, en branche-organisaties (NVB, ABN-corporate-banking-product-team, etc) als implementation-partners voor nieuwe bank-API's. Voor multinationals is de Group Treasury de impliciete stakeholder — zij willen één geconsolideerde view op alle salaris-betalingen over alle BV's heen, met cross-entity reconciliation tegen het ERP-systeem (SAP, Oracle).

Out-of-scope voor dit spec: SEPA Direct Debit voor incasso-betalingen (werknemer-naar-werkgever, b.v. terugvordering teveel betaald salaris), niet-EUR-betalingen (US payroll, UK payroll), bitcoin/crypto-salaris (geen Nederlandse loonbelasting-grondslag), en cross-border non-SEPA betalingen (gedeeltelijk gedekt door losse internationale wire-transfer flow). Deze gevallen zullen door volgende specs worden afgevangen.
