---
status: proposed
app: hrmq
spec: zzp-dga-single-person-mode
owner: hrmq-core
depends_on: [hrmq-base, payroll-engine-nl]
target_users: [dga, zzp-with-bv, accountant]
---

# ZZP / DGA Single-Person Mode

## Placement & Information Architecture

**Placement type:** `SETTING` — Setting under the app's Beheer/Admin/Configuration surface. Lives in the existing settings UI; no top-level menu entry.

**Lives at:** Configuratie › Administraties

**Rationale:** ZZP/DGA mode-switch.  
_Source: /tmp/ia-procest-hrmq.md_

> **Implementation note for builders:** Respect the placement above. Do not promote this spec to a top-level menu item, sub-page, or new route unless the placement type explicitly says so. If the placement is `DETAIL_TAB`, `WIDGET`, `ACTION`, `SETTING`, or `INFRA`, the feature must NOT introduce a new entry in the app sidebar. When in doubt, ask before creating a new top-level surface.

## Purpose

The Dutch fiscal landscape has roughly 400.000 directeur-grootaandeelhouders (DGAs) running a single-person BV, plus a growing population of ZZPers who incorporate to a BV once turnover crosses ~EUR 80k. These users do not need the full HRMQ chrome: no team rosters, no leave approval queues, no manager dashboards, no employee-self-service portals for "others". They need a focused workspace that treats them as both the sole employee and the sole employer, with the fiscal artefacts the Belastingdienst expects from a DGA: a monthly loonstrook proving the gebruikelijk-loon, ZVW-bijdrage, ABP/PFZW or private pensioen (if any), an annual jaaropgaaf, and a clean handover dataset for the IB-aangifte (box 1 loon uit eigen BV, box 2 aanmerkelijk belang, FOR-afbouw, lijfrente-aftrek).

Today the typical DGA either pays an accountant EUR 1.500-3.000 per year to run the payroll-administration in a closed-source package (Loon Salaris Software, Nmbrs, Visma) en bewerkt op het einde van het jaar de IB-pakket-overdracht handmatig, of zij doet het zelf met een spreadsheet en hoopt dat de Belastingdienst geen vragen stelt. Beide opties zijn slecht: de eerste is duur voor een single-person setup, de tweede is foutgevoelig. De gebruikelijk-loon-discussie alleen al is een terugkerende bron van naheffingen — wie aan het einde van het jaar te weinig heeft opgenomen krijgt een naheffing loonheffing plus een correctieboete, plus rente.

The single-person mode is a UI shell and data-model toggle on top of the existing HRMQ payroll engine. It does not fork the engine — the same Loonheffingen calculations run — but it hides every multi-employee artefact and surfaces a DGA-specific dashboard: gebruikelijk-loon-status (this year's norm vs paid), ZVW running total, FOR-saldo (afbouwend per 2023+), lijfrente-jaarruimte, en een one-click "lever IB-pakket aan accountant" export. De ambition is dat een DGA met een rechte rug van januari tot december op autopiloot kan: de maandelijkse loonstrook genereert zichzelf op een vaste datum, de SEPA-overboeking gaat automatisch via bank-payment-batch-sepa (single-payment batch), en in januari ligt het IB-pakket klaar.

The mode is set at organisation level (`hrmq_organisation.mode = "dga_single_person"`) and locks the workforce to exactly one employee record (the DGA herself). The toggle is reversible — a growing BV that hires its first medewerker flips back to standard mode, and all DGA-specific dashboards remain available as a "mijn loon" tab on the DGA's own employee record. Dit garandeert dat de migration-pad voorwaarts is: een groeiende BV verliest niets door HRMQ in DGA-mode te starten en later naar standard te schakelen, en een krimpende BV (laatste werknemer vertrekt, alleen DGA blijft) kan terug naar DGA-mode zonder data-verlies.

Een tweede gebruikersgroep zijn externe accountants die 20-200 DGA-klanten beheren. Voor hen is consistentie cruciaal: zij willen één werkmethode over alle klanten, één IB-pakket-formaat richting hun belastingsoftware (Unit4, Cygnus, Twinfield), en de mogelijkheid om met één login tussen klant-organisaties te switchen. De `accountant_of_record` rol (REQ-010) ondersteunt dit met een delegatie-model dat de DGA controle geeft over wat de accountant ziet en kan.

## Data Model

Extends `hrmq_organisation` with `mode` enum (`standard`, `dga_single_person`) and `dga_employee_id` FK. Adds `hrmq_dga_profile` (1:1 with employee): `aanmerkelijk_belang_percentage`, `gebruikelijk_loon_norm_year`, `gebruikelijk_loon_norm_basis` (enum: `wettelijk_56000`, `meestverdienende_werknemer`, `vergelijkbare_dienstbetrekking`, `lager_aannemelijk_gemaakt`), `gebruikelijk_loon_norm_motivering` (vrije tekst, verplicht bij non-default basis), `for_saldo_opening` (legacy FOR, afbouw vanaf 2023), `for_onttrekkingen` (jsonb timeline), `lijfrente_polissen` (jsonb array van polis-objecten met verzekeraar, polisnummer, stortingen-per-jaar, factor-A), `box2_dividenduitkeringen` (jsonb timeline), `box2_verkrijgingsprijs` (decimal). Adds `hrmq_dga_ib_export` for annual IB-handover packages with status (`concept`, `definitief_aangeleverd`), `accountant_endpoint_id` FK to a delivery-target record, `pakket_blob_url`, `accountant_acknowledgement_at`. Adds `hrmq_accountant_delegation` voor het delegatie-model: `dga_employee_id`, `accountant_user_id`, `granted_at`, `revoked_at`, `permissions[]` (subset van read_payroll, write_payroll, read_jaaropgaaf, export_ib_pakket).

## Requirements

### REQ-001: Organisation mode toggle

**GIVEN** an HRMQ organisation with exactly one employee record marked as DGA
**WHEN** an admin sets `organisation.mode = "dga_single_person"` via settings UI
**THEN** the system validates that exactly one employee exists with `is_dga = true`, locks the organisation to that employee, hides all multi-employee navigation (teams, approvals, org chart, leave kalender voor anderen), and surfaces the DGA dashboard as the default landing page.

### REQ-002: Gebruikelijk-loon norm tracking

**GIVEN** a DGA in single-person mode for a given kalenderjaar
**WHEN** the system computes the gebruikelijk-loon norm (default EUR 56.000 for 2026, indexed annually)
**THEN** the dashboard shows: current norm, year-to-date paid loon, projected year-end loon at current run rate, and a status indicator (green if on track, amber if <90% projected, red if <80% projected with <3 months remaining). The norm basis (wettelijk vs meestverdienende werknemer vs lager-aannemelijk) is stored with an audit trail and any change requires an admin re-attestation.

### REQ-003: Monthly loonstrook generation (DGA flavour)

**GIVEN** a DGA with a contract specifying monthly bruto loon
**WHEN** the payroll engine runs the monthly cycle on the 25th
**THEN** a loonstrook PDF is generated showing bruto, loonheffing (groene tabel if AOW-gerechtigd, witte tabel otherwise), ZVW-bijdrage (werkgeversheffing 6,57% for 2026 over max EUR 75.864), netto, plus a DGA-specific section showing year-to-date totals against the gebruikelijk-loon norm. The loonstrook is filed in the DGA's own documents folder and emailed to the configured contact address.

### REQ-004: ZVW running total and werkgevers-deel reconciliation

**GIVEN** a DGA receiving monthly loon throughout the kalenderjaar
**WHEN** the dashboard renders the ZVW widget
**THEN** the widget shows: year-to-date ZVW werkgeversheffing paid by the BV, the maximum bijdrage-inkomen ceiling for the year, and a flag if the BV has paid >100% of the ceiling (overpayment to be reclaimed). For DGAs who also have additional inkomen (e.g. partial WW or freelance bijverdiensten), the widget shows an informational note that the IB-aangifte will reconcile across all bronnen.

### REQ-005: Jaaropgaaf generation

**GIVEN** a kalenderjaar has ended and all 12 loonstroken have been generated and finalised
**WHEN** the DGA (or accountant) clicks "Genereer jaaropgaaf"
**THEN** the system produces the standard Belastingdienst jaaropgaaf PDF (loon, loonheffing, ZVW, arbeidskorting, verrekende heffingskortingen) plus a machine-readable JSON copy for the IB-pakket. The jaaropgaaf is locked once generated — corrections require a formal correctieboeking met audit trail.

### REQ-006: FOR-afbouw tracking

**GIVEN** a DGA with a legacy Fiscale Oudedagsreserve saldo at 1 januari 2023 (the year FOR-opbouw stopped)
**WHEN** the DGA records annual onttrekkingen (omzetting in lijfrente of opname als belast inkomen)
**THEN** the system tracks the lopende FOR-saldo, shows it on the dashboard, and includes it in the IB-handover under "FOR-saldo per 31-12". No new FOR-dotaties are possible (the field is read-only for jaren ≥ 2023).

### REQ-007: Lijfrente jaarruimte berekening

**GIVEN** a DGA with persoonlijke jaargegevens (premiegrondslag, AOW-leeftijd, pensioenaangroei)
**WHEN** the system computes the lijfrente-jaarruimte for the lopende kalenderjaar
**THEN** the dashboard shows the available aftrekruimte (formule 2026: 30% * premiegrondslag − factor A * 6,27, max EUR 36.077), the reserveringsruimte (10 jaar terug), and a note linking to the relevant Belastingdienst-pagina. The actual lijfrente-storting is recorded by the DGA and feeds the IB-handover.

### REQ-008: IB-pakket export for accountant

**GIVEN** a finalised jaaropgaaf and complete FOR/lijfrente/box-2 data for a kalenderjaar
**WHEN** the DGA clicks "Lever IB-pakket aan accountant"
**THEN** the system bundles into a single ZIP: jaaropgaaf-PDF + JSON, FOR-overzicht, lijfrente-overzicht, aanmerkelijk-belang-overzicht (dividenduitkeringen + verkrijgingsprijs), and a manifest.json index. The bundle is uploaded to the accountant's configured deliverypath (SFTP, Nextcloud share, or email) and marked `definitief_aangeleverd`.

### REQ-009: Mode reversibility on first hire

**GIVEN** an organisation in `dga_single_person` mode
**WHEN** an admin attempts to add a second employee record (first hire)
**THEN** the system prompts to switch back to `standard` mode, preserves all DGA-specific data on the DGA's own employee record (which becomes "mijn loon" tab), unhides multi-employee navigation, and emits an `OrganisationModeChanged` event so downstream apps (pension provider integrations, payroll-tax filing) can re-evaluate their assumptions.

### REQ-010: Accountant-of-record delegation

**GIVEN** a DGA wishes to delegate payroll-administration to an external accountant
**WHEN** the DGA invites an accountant via the settings UI with role `accountant_of_record`
**THEN** the accountant gains read-write access to payroll, jaaropgaaf, and IB-pakket export, but no access to the DGA's persoonlijke postvak, lijfrente-polis details (read-only), or any inhoudelijke documenten outside payroll-scope. The delegation is logged in the audit trail and the DGA can revoke at any time.

## Standards

- Loonheffingen 2026: Wet op de loonbelasting 1964, Uitvoeringsregeling loonbelasting 2011
- Gebruikelijk-loon: Art. 12a Wet LB 1964
- ZVW werkgeversheffing 2026: 6,57% over bijdrage-inkomen, max EUR 75.864
- FOR-afbouw: Belastingplan 2023 (geen nieuwe dotaties vanaf 2023, bestaand saldo blijft)
- Lijfrente: Art. 3.124-3.129 Wet IB 2001, jaarruimte-formule
- Jaaropgaaf-formaat: Belastingdienst loonaangifte-XSD 2026

## Cross-app

- `payroll-engine-nl` for the underlying loonheffing-calculations (shared with standard mode)
- `docudesk` for jaaropgaaf and loonstrook PDF templating
- `bank-payment-batch-sepa` for the monthly salaris-overboeking (single-payment batch)
- `document-template-engine` for the IB-pakket manifest and accountant cover letter
- Optional `openconnector` adapter to the Belastingdienst loonaangifte-API (post-MVP)

## Target Users

Solo DGAs running a holding+werk-BV (consultants, IT-freelancers, interim-managers), ZZPers who recently incorporated, and external accountants who service 20-200 DGA clients and need a consistent IB-handover dataset across their klantenportefeuille. Secondary: bookkeeping-software vendors (Moneybird, Exact, e-Boekhouden) who want to embed the DGA-payroll module under a partner-label, en branche-organisaties (ONL voor Ondernemers, ZZP-Nederland) die hun leden een vertrouwd voorgeconfigureerd HRMQ-account willen aanbieden.

Tertiaire gebruikers zijn fiscaal-juristen die DGA-klanten begeleiden bij bedrijfsopvolging (waarbij box-2-verkrijgingsprijs en aanmerkelijk-belang-historie cruciaal zijn), en notarissen die bij oprichting van een BV de eerste loonadministratie willen meegeven aan de nieuwe DGA. Voor deze groepen is de export-vorm van het IB-pakket belangrijker dan de dagelijkse loonstrook-flow.

Out-of-scope voor dit spec: meer-DGA-situaties (twee broers samen een werk-BV, of een echtpaar-BV waar beide partners DGA zijn). Deze gevallen zijn zeldzaam en zullen worden afgevangen door standard mode met een toekomstige `is_dga` flag per employee. Ook out-of-scope: stamrecht-BV's met gerichte lijfrente-uitkeringen — de uitkering-zijde van pensioen wordt door een aparte spec gedekt.
