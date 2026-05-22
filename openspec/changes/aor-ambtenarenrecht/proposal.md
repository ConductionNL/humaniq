---
status: proposal
---

# AOR Ambtenarenrecht — Public-Sector Employment Workflows

## Summary

The `aor-ambtenarenrecht` app codifies the complex procedural workflows governing public-sector HR events across Dutch Wnra-context organisations (post-2020 private employment law). It eliminates ad-hoc Word templates, tribal knowledge, and missed legal termijnen by delivering structured, auditable workflows for six critical HR procedures: ontslagprocedure, integriteitsmelding, tuchtbesluit, disciplinaire maatregel, escalatie naar college, and beroep bij de Centrale Raad van Beroep.

Current state: 30-40% of ontslagdossiers contain proceduregebreken that result in beroep-vernietiging, primarily due to missed termijnen and misplaced bevoegd-gezag attribution. The app enforces termijnbewaking, pre-fills required decision templates, generates compliant administrative besluiten with bezwaar/beroep clauses, and maintains legally-mandated audit trails and retentie-klasses.

## Features

### F-001: Ontslagprocedure Orchestration (Demand: 10)
Guided workflow for employment termination covering statutory grounds (a-i per BW art. 7:669), injection of kantonrechter/UWV routes when required, pre-filling of verzoekschriften, and generation of compliant Besluit with correct termijn per legal context (Wnra or legacy Ambtenarenwet). Termijn auto-calculation and dashboard alerts at T-7 and T-2.

### F-002: Transitievergoeding Calculation (Demand: 9)
Snapshot-based calculation of statutory severance (1/3 maandsalaris per dienstjaar per BW 7:673) with organisational CAO overlays (bovenwettelijke components), service-years-frozen semantics, and overgangsregelingen (kleine-werkgever, AOW-grens). Multi-component breakdown for transparency. Manual override gated behind four-eyes approval.

### F-003: Integriteitsmelding & Klokkenluider Protection (Demand: 9)
Isolated, protected-tier workflow for employee integrity disclosures via designated channels (vertrouwenspersoon, HvK, toezichthouder). Automatic 7-year protection period per Wet bescherming klokkenluiders. Pseudonymised melder identity (visible only to 2 named persons), automatic retaliation-check escalation within 24 months, external escalation export (Huis voor Klokkenluiders / sectorale toezichthouders).

### F-004: Tuchtbesluit (Non-Normalised Sectors) (Demand: 7)
Workflow for disciplinary decisions in politie, defensie, rechterlijke macht (Barp/AMAR/judiciary regulations). Mandatory hoor-en-wederhoor with 14+ day reactietermijn for sanctions heavier than schriftelijke berisping. Auto-anonimisering after zwaarte-dependent expiry (3/5/10 jaar).

### F-005: Disciplinaire Maatregelen (Genormaliseerd) (Demand: 9)
Formalised warning/suspension/pay-suspension procedures under Wnra+CAO context, with enforced juridische motivering per BW 7:611. Distinct routing for loonopschorting (art. 7:629 lid 6, no meldplicht) vs loonstop (art. 7:629 lid 3, arbo-arts meldplicht). Payroll mutation proposal and reversal-with-rente on invalidation.

### F-006: Escalatie naar College B&W / Dagelijks Bestuur (Demand: 6)
Auto-routing of high-impact cases (C-level termination, €50k+ financial impact, integrity issues touching bestuur) to collegevoorstel generation with local template library and B&W/DB agenda integration. Besluiten-registratie with explicit verwijzing to collegebesluitnummer. Terugverwijzing handling with revision termijn.

### F-007: Beroep bij Centrale Raad van Beroep (Demand: 6)
Procesdossier bundling per CRvB instructies (chronological, geanonimiseerde derden-stukken). Automatic internal deadline calculation (verweerschrift, nadere stukken, getuigenlijst) on zittingsdatum registration. Post-uitspraak workflow for heroverwegen/financiële afwikkeling/cassatie.

### F-008: Termijnbewakinag & SLA Dashboard (Demand: 8)
Dailyjob rekening rest-days per bezwaartermijn, dashboard widget per casehandler, automated reminders (T-7, T-2), escalation to teamlead at T-1, immutable logging on expiry with recorded legal consequence.

### F-009: Vertrouwelijkheid & Toegangsbeheer (Demand: 8)
Case confidentiality tiers (standaard | vertrouwelijk | geheim) with explicit ACL grant enforcement. Access denial with logging/handler notification. Melder-identity pseudonymisatie in default views. Extern-advies export with AVG-compliant pseudonymisation + wachtwoord-beveiligde levering.

### F-010: Bewaartermijnen & Archivering (Demand: 7)
Automatic retentieklasse assignment on case afronding per Selectielijst (ontslagdossiers: 75j post-geboorte; integriteitsmelding: 7j; tuchtbesluiten: 10j post-verwijdering). Auto-anonimisering/vernietiging on termijn-expiry per klasse with immutable destruction log. RiC-format export for Nationaal Archief overbrenging.

## Stakeholders

- **HR-jurist / Arbeidsjurist** (1-10 per organisation): Primary case handler, daily dossier work, needs template proposals, termijn calculations, jurisprudence links
- **Vertrouwenspersoon Integriteit**: Exclusive access to klokkenluider dossiers, yearly anonieme rapportage to bestuur
- **Integriteitscoördinator**: Overview of all integrity cases, retaliation-checks, trend reporting
- **Lijnmanager**: Escalation recipient, signer of light disciplinary measures, hoorzitting witness
- **HR-directeur / Hoofd P&O**: Bestuurlijke alignment, escalation routing to college/DB
- **Bestuurssecretaris / Collegegriffier**: Collegevoorstel receipt, agenda-cycle oversight
- **Auditdienst Rijk / Gemeentelijke Accountantsdienst**: Read-only audit access for rechtmatigheids-onderzoek
- **Externe Advocaat / Juridisch Adviseur**: Temporary ACL grant per dossier with pseudonymisation where needed

## Customer Journeys

### Journey-1: Standard Termination (Wnra Context)
**Trigger**: HR-jurist opens new dossier with ontslaggrond (a-f, most common)
**Pain points**: Manual termijn tracking, template hunting, bezwaartermijn-clause copy-paste
**Outcome**: System calculates opzegtermijn, pre-fills Besluit draft, auto-notifies employee via Digitale Akte, reminds handler at T-7/T-2 bezwaar-expiry
**Duration**: 2-6 weeks

### Journey-2: Complex Termination (h/i-grond + UWV Route)
**Trigger**: HR-jurist selects h-grond (overige omstandigheden) or i-grond (cumulatie)
**Pain points**: UWV-formuliereenset coordination, checklist tracking, bedrijfseconomisch onderbouwing
**Outcome**: System auto-routes to UWV-procedure, pre-generates UWV forms A+B with employee data, creates checklist, links to kantonrechter-verzoekschrift template
**Duration**: 4-12 weeks

### Journey-3: Integrity Disclosure (Melder-Vertrouwenspersoon)
**Trigger**: Employee submits melding via protected channel to vertrouwenspersoon
**Pain points**: Identity confidentiality, retaliation risk, external escalation complexity
**Outcome**: System registers Klokkenluidermelding with pseudonymised identity, sets 7-year protection, flags any HR action on melder within 24mo, enables Huis voor Klokkenluiders export
**Duration**: Ongoing (7-year protection)

### Journey-4: Retaliation Check on HR Action
**Trigger**: Manager initiates HR action on melder within 24 months of melding
**Pain points**: Unknown retaliation risk, no systematic checks, compliance gaps
**Outcome**: System auto-creates retaliationCheckLog entry, notifies integriteitscoördinator, gates action until assessment complete
**Duration**: 1-3 days (assessment window)

### Journey-5: Disciplinary Sanction (Wnra Context)
**Trigger**: Lijnmanager or HR-jurist proposes formele waarschuwing or schorsing
**Pain points**: BW 7:611 motivering enforcement, hoor-en-wederhoor compliance, payroll coordination
**Outcome**: System pre-fills juridische motivering template, generates hoor-en-wederhoor uitnodiging (14+ day reactietermijn), proposes payroll mutation (loonopschorting vs loonstop distinction)
**Duration**: 3-8 weeks

### Journey-6: Escalation to College B&W
**Trigger**: Case impacts bestuur level (C-title termination, €50k+ severance, integrity-bestuur link)
**Pain points**: Local template variation, B&W/DB agenda coordination, decision traceability
**Outcome**: System generates collegevoorstel per local template library, schedules B&W/DB meeting slot, registers besluit with explicit collegebesluitnummer
**Duration**: 2-6 weeks (next meeting cycle)

### Journey-7: CRvB Beroep Bundling
**Trigger**: Employee announces beroep at CRvB post-bezwaar rejection
**Pain points**: Procesdossier compliance, deadline orchestration, derden-stukken anonimisering
**Outcome**: System bundles dossier per CRvB instructies, auto-calculates verweerschrift/nadere-stukken deadlines, generates getuigenlijst template
**Duration**: 3-6 months (CRvB zitting)

## Dependencies

- **employee-master**: persoons- en dienstverbandsgegevens, writes eind-dienstverband on termination
- **contract-management**: links to vigerend arbeidscontract, registers contractmutaties (loonopschorting, schorsing)
- **payroll-engine-nl**: receives salarismutaties (loonopschorting, loonstop, transitievergoeding, terugbetaling-met-rente)
- **docudesk**: stores dossier-stukken, besluiten, verweerschriften, CRvB-bundels met retentieklasse+ACL
- **openconnector**: integrates UWV-portal, Huis voor Klokkenluiders, gemeentelijke besluitvormings-systemen (iBabs, Notubiz), Berichtenbox/MijnOverheid
- **mydash**: bestuurlijke dashboards with geanonimiseerde KPIs (case counts, throughput times, bezwaar-statistics)
- **opencatalogi**: WOO-compliant besluiten-publicatie with anonimisering
- **irma-digid-auth**: Yivi-pseudoniem for integrity meldingen, eHerkenning niveau 4 for bestuurlijke signing
- **payroll-engine-nl**: instructie-processing for schorsing met loon, loonopschorting, loonstop, transitievergoeding, terugbetaling-met-rente

## Legal / Compliance Basis

- Wet Normalisering Rechtspositie Ambtenaren (Wnra, 2020) — private employment law baseline
- Burgerlijk Wetboek boek 7, titel 10 (arbeidsovereenkomst) — termination, severance, pay suspension
- Wet bescherming Klokkenluiders (2023, EU 2019/1937) — whistleblower protection
- Algemene Wet Bestuursrecht (Awb) — bezwaar/beroep, hoorplicht, motivering
- Beroepswet — CRvB competence
- Selectielijsten Rijk/Gemeenten — retention schedules
- AVG/UAVG + DPIA — data protection for integrity/disciplinary cases
- Ambtenarenwet 2017 — for politie/defensie/rechterlijke ambtenaren (legacy-path support)

## Notes

- App is future-proof for organisations re-classified under Ambtenarenwet 2017 (politie, defensie, rechterlijke macht) by supporting both Wnra-context (default) and Ambtenarenwet-context workflows side-by-side
- Procedurele scheiding enforced: integrity/whistleblower dossiers live in separate access tier, invisible to regular HR roles — critical for melder-vertrouwen
- All templates assume Dutch context but can be extended per CAO (Rijk, Gemeenten, SGO, Provincies, Waterschappen) variations
