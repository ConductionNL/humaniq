---
status: draft
---
# Audit Trail Payroll (Immutable Payroll Audit Log)

## Placement & Information Architecture

**Placement type:** `SUB_PAGE` — Sub-page beneath a top-level menu entry. Renders as a page inside the parent surface (usually reachable via a router child route or a tab on the parent index page).

**Lives at:** Salarissen › Audit-trail

**Rationale:** Immutable log.  
_Source: /tmp/ia-procest-hrmq.md_

> **Implementation note for builders:** Respect the placement above. Do not promote this spec to a top-level menu item, sub-page, or new route unless the placement type explicitly says so. If the placement is `DETAIL_TAB`, `WIDGET`, `ACTION`, `SETTING`, or `INFRA`, the feature must NOT introduce a new entry in the app sidebar. When in doubt, ask before creating a new top-level surface.

## Purpose

`audit-trail-payroll` is een aparte, doelgerichte audit-laag voor hrmq die elke payroll-relevante mutatie onveranderlijk vastlegt — los van en aanvullend op de generieke applicatie-audit-trail die OpenRegister out-of-the-box biedt. Het bestaansrecht zit in vier dingen die de generieke audit-laag niet kan of niet zou moeten doen.

Ten eerste: payroll heeft een wettelijk afdwingbare bewijswaarde. Bij een loonheffingen-boekenonderzoek, een UWV-controle, een AP-onderzoek of een arbeidsgeschil moet aantoonbaar zijn wie wat wanneer wijzigde, vanuit welke UI/API, met welke onderbouwing — en dat de log niet achteraf is bijgewerkt. Dat vereist append-only opslag met cryptografische hash chain, niet "soft delete" of "version table".

Ten tweede: de fiscale bewaartermijn voor loonadministratie is 7 jaar (art. 52 AWR), maar voor pensioenadministratie 10 jaar en voor 30%-regeling-onderbouwing minstens zolang de regeling loopt plus 7 jaar. De audit-trail moet dus de langste van de samenhangende retenties hanteren — pragmatisch gezegd: 10 jaar voor alles, met optie tot langer (bijvoorbeeld voor pensioenfondsen met levenslange verplichtingen).

Ten derde: de granulariteit en semantiek moeten payroll-specifiek zijn. "Veld X gewijzigd van A naar B" is voor generieke audits genoeg, maar payroll wil "loonperiode 2026-06 berekend met deze CAO-versie, deze 30%-status, dit beslagvrije-voet-bedrag, deze pensioenpremiestaffel — door payroll-engine versie 1.4.2". Reproduceerbaarheid van een historische loonrun is een eerste-orde-vereiste.

Ten vierde: AVG. Het generieke audit-log kan persoonsgegevens bevatten die onder een ander verwerkingsregister vallen dan de payroll-data. De payroll-audit-trail heeft een eigen wettelijke verwerkingsgrondslag (art. 6(1)(c) AVG + fiscale wetgeving) en moet daarom organisatorisch en technisch gescheiden zijn van marketing-, gebruiks- en troubleshooting-audits.

De capability levert daarom een dedicated, append-only audit-store met hash chain, payroll-specifieke event-semantiek, 10-jaar default retentie met configureerbare extensie, exportformaten voor accountant/Belastingdienst/UWV, en een SDK waarmee elke andere hrmq-capability genoemd ook zelf events kan schrijven zonder eigen audit-implementatie te bouwen.

## Data Model

`PayrollAuditEvent` (de centrale append-only entiteit — geen UPDATE, geen DELETE, alleen INSERT): `id` (UUID v7 — tijd-geordend), `vorige_event_id` (per-administratie hash-chain pointer naar voorgaand event), `vorige_event_hash` (SHA-256 hash van het voorgaande event-record), `eigen_hash` (SHA-256 over alle eigen velden incl. `vorige_event_hash`, berekend bij insert), `administratie_id`, `tijdstip_utc` (microsecondprecisie), `tijdstip_lokaal_amsterdam` (Europe/Amsterdam, met DST-info), `actor_type` (gebruiker/systeem/api_token/job_scheduler/migratie/import), `actor_id` (gebruiker-id, systeem-naam, token-id, job-naam), `actor_label` (mens-leesbaar bij vastleg), `actor_ip` (waar bekend), `actor_user_agent` (waar van toepassing), `sessie_id`, `oorzaak_keten_id` (correlation-id om events binnen één gebruikersactie te bundelen — bijv. een loonrun heeft één keten-id en duizenden events), `entiteit_type` (Medewerker/Contract/Loonrun/Loonpost/Beschikking30/Beslag/Pensioenaanspraak/JournaalpostExport/LoonaangifteIngediend), `entiteit_id`, `event_type` (aangemaakt/gewijzigd/verwijderd_logisch/berekend/ingediend/herzien/teruggedraaid/goedgekeurd/afgewezen/gepubliceerd), `event_naam` (kort label zoals `medewerker.salaris_gewijzigd`, `loonrun.uitgevoerd`, `beschikking30.ingetrokken`), `payload_voor` (JSON snapshot van relevante velden vóór de mutatie), `payload_na` (JSON snapshot na), `payload_diff` (JSON-patch RFC 6902 representatie), `motivering` (vrije tekst — verplicht bij sommige event-types), `ui_pad` (URL of menupad waar de actie werd gestart), `api_endpoint` (indien via API: methode + pad), `engine_versie` (semver van de payroll-engine of berekenmodule die het event veroorzaakte), `wet_versie` (verwijzing naar de loonbelasting-versie/CAO-versie die op het moment van uitvoering gold — essentieel voor reproduceerbaarheid).

`PayrollAuditChainAnchor` (periodiek ankerpunt voor extra integriteitsbewijs): `id`, `administratie_id`, `tot_event_id` (het laatste event-id dat in dit anker is opgenomen), `cumulatieve_root_hash` (Merkle-root over alle event-hashes tot en met `tot_event_id`), `anker_tijdstip`, `anker_methode` (interne_ondertekening_met_systeemsleutel/rfc3161_tsa/blockchain_ots — start met interne sleutel, optie tot upgrade), `anker_bewijs` (signature of TSA-token), `vorige_anker_id`. Wekelijks gepland; bij export ook on-demand.

`PayrollAuditRetentionPolicy`: `id`, `administratie_id` (of `null` voor fleet-default), `entiteit_type` (kan generiek of specifiek zijn), `retentie_jaren` (default 10, minimaal 7), `vernietigingsmethode` (volledige_verwijdering/pseudonimisering_met_behoud_metadata), `wetgrondslag` (vrije tekst — bijv. "art. 52 AWR + art. 7:611 BW + 30%-regeling looptijd"), `actief_vanaf`.

`PayrollAuditExport`: `id`, `administratie_id`, `aangevraagd_door`, `aangevraagd_op`, `periode_van`, `periode_tot`, `filter` (JSON — bijv. alleen Loonrun-events of alleen één medewerker), `export_formaat` (pdf_bewijspakket/csv/json_signed/xbrl_payroll), `export_bestand_uri`, `export_hash`, `inclusief_chain_proof` (bool), `status` (in_uitvoering/gereed/mislukt/verlopen), `download_verloopt_op` (auto-cleanup na 30 dagen).

`PayrollAuditAccessLog` (audit-on-audit — wie keek wanneer naar de audit-trail): `id`, `gebruiker_id`, `tijdstip`, `query_filter`, `aantal_events_geraadpleegd`, `rechtvaardiging` (verplicht — bijv. "voorbereiding Belastingdienst-controle 2026-Q2"). Bewaring conform dezelfde retentiepolicy.

## Requirements

**REQ-001: Append-only opslag — geen UPDATE, geen DELETE op `PayrollAuditEvent`.** De database-schema-laag, de ORM-laag en de service-laag dwingen elk onafhankelijk af dat events nooit kunnen worden gewijzigd of verwijderd.
- GIVEN een audit-event is geschreven, WHEN een ontwikkelaar via de ORM een `update()` of `delete()` probeert, THEN gooit de repository een `ImmutabilityViolationException` en wordt het verzoek geweigerd voordat het de database raakt.
- GIVEN een database-administrator probeert via een direct SQL `UPDATE payroll_audit_events SET ...`, WHEN dit gebeurt, THEN weigert een database-trigger (PostgreSQL `BEFORE UPDATE OR DELETE`) de operatie met een expliciete fout.
- GIVEN een corrigerende actie is gewenst (bijv. foutieve invoer), WHEN dit speelt, THEN wordt een nieuw "correctie"-event toegevoegd dat het oorspronkelijke event semantisch tegenspreekt, maar het origineel blijft staan.

**REQ-002: Hash-chain integriteit per administratie.** Elk event-record bevat de hash van het voorgaande event in dezelfde administratie; manipulatie tussen events wordt direct gedetecteerd door chain-verificatie.
- GIVEN tien events in administratie A, WHEN het verificatie-job draait, THEN herberekent het de hash van elk event in volgorde en concludeert "chain valide" of bij mismatch "chain gebroken bij event {id}".
- GIVEN iemand muteert (ondanks REQ-001) een event-record buitenom, WHEN de eerstvolgende verificatie draait, THEN faalt deze met expliciete aanwijzing van het eerste corrupte event en wordt een hoog-prioriteit security-alert verzonden.

**REQ-003: Wekelijkse cumulatieve-root-anchor met sterke ondertekening.** Per administratie wordt wekelijks een Merkle-root over alle events sinds vorige anchor berekend en ondertekend; bij externe controle is dit het bewijs dat de hele historie niet is hersteld.
- GIVEN het is zondag 02:00 UTC, WHEN de anchor-job draait, THEN wordt voor elke administratie een nieuw `PayrollAuditChainAnchor`-record gemaakt met de Merkle-root over alle events sinds het vorige anker.
- GIVEN een administratie heeft een week lang geen events, WHEN de anchor-job draait, THEN wordt nog steeds een anker geschreven (met identieke root als vorige anker) om de doorlopende keten te garanderen.
- GIVEN de instelling op `anker_methode = rfc3161_tsa`, WHEN de anker-job draait, THEN wordt de root naar een externe Time Stamping Authority gestuurd en de TSA-respons in `anker_bewijs` opgeslagen.

**REQ-004: Verplichte motivering bij high-impact events.** Bestaalde event-types (handmatige overrule beslagvrije voet, intrekking 30%-regeling, handmatige correctie loonrun, soft-delete medewerker, wijziging IBAN voor loonbetaling) vereisen een niet-leeg `motivering`-veld.
- GIVEN payroll_admin trekt een 30%-beschikking handmatig in zonder motivering, WHEN het event probeert opgeslagen te worden, THEN weigert de service-laag met `validation_error: motivering verplicht voor event_type beschikking30.handmatig_ingetrokken`.
- GIVEN een motivering wordt opgegeven met minder dan 20 tekens, WHEN het event probeert opgeslagen te worden, THEN weigert de service met `validation_error: motivering te kort — minstens 20 tekens vereist`.

**REQ-005: Volledige reproduceerbaarheid van een historische loonrun.** Met `engine_versie` en `wet_versie` op elk Loonrun-event kan een berekening jaren later opnieuw worden uitgevoerd met exact dezelfde regels en uitkomsten.
- GIVEN een loonrun is in 2026-06 uitgevoerd met `engine_versie = 1.4.2` en `wet_versie = 2026-loonheffing-tabel-rev3`, WHEN een accountant in 2030 vraagt om reproductie, THEN kan het systeem de oorspronkelijke engine-versie en wettentabel laden uit een versie-archief en de exacte berekening herhalen met identieke uitkomst.
- GIVEN de payroll-engine is geüpgraded sinds de oorspronkelijke loonrun, WHEN reproductie plaatsvindt, THEN wordt expliciet de oude engine-versie gestart (containerized of via versie-archief) en niet de huidige.

**REQ-006: Bewijspakket-export voor Belastingdienst, UWV en AP.** Met één klik (of API-call) kan een gefilterde, ondertekende audit-export worden gegenereerd in een formaat dat door controleurs direct verwerkbaar is.
- GIVEN de Belastingdienst kondigt een boekenonderzoek aan voor administratie A over 2025, WHEN payroll_admin de export draait met filter `periode = 2025-01-01..2025-12-31`, THEN wordt een ZIP gegenereerd met (a) alle relevante events als JSON met hash-chain, (b) PDF samenvattingen per loonperiode, (c) de chain-anchors die over de periode lopen, (d) een verifieer-script waarmee de controleur de integriteit zelfstandig kan controleren, (e) een leeswijzer in het Nederlands.
- GIVEN de export is gegenereerd, WHEN deze wordt gedownload, THEN wordt een `PayrollAuditAccessLog`-event geschreven met `query_filter` en motivatie verplicht ingevoerd door de aanvrager.

**REQ-007: Retentie van minimaal 10 jaar; vernietigings-procedure met overrule-mogelijkheid.** Events worden minimaal 10 jaar bewaard en daarna conform `vernietigingsmethode` van de policy verwerkt; bij actieve geschillen of fiscale onderzoeken kan een legal-hold de vernietiging uitstellen.
- GIVEN een event is geschreven in 2017-03 en de standaardretentie is 10 jaar, WHEN het 2027-03-01 wordt en geen legal-hold actief is, THEN wordt het event volgens beleid verwerkt (pseudonimisering of volledige verwijdering — bij volledige verwijdering wordt de hash-chain met een speciaal "tombstone"-event gerespecteerd zodat de chain valide blijft).
- GIVEN een legal-hold is actief voor administratie A vanwege een lopende rechtszaak, WHEN de cleanup-job draait, THEN worden geen events van A verwijderd zolang de hold actief is en wordt dit per skipped event gelogd.

**REQ-008: Strikte AVG-grondslag-scheiding van generieke audit-laag.** Toegang tot `PayrollAuditEvent` vereist een dedicated rol `payroll_audit_lezer` die organisatorisch los staat van applicatie-audit-rechten; toegang wordt zelf weer geaudit in `PayrollAuditAccessLog`.
- GIVEN een ontwikkelaar heeft applicatie-admin-rechten in OpenRegister maar geen `payroll_audit_lezer`, WHEN hij `GET /api/payroll-audit/events` aanroept, THEN antwoordt de API met `403 Forbidden` en wordt een security-event geschreven.
- GIVEN een payroll_audit_lezer raadpleegt 250 events met filter "medewerker X", WHEN dit gebeurt, THEN wordt één `PayrollAuditAccessLog`-record geschreven met `aantal_events_geraadpleegd = 250` en `query_filter` opgeslagen.

**REQ-009: Performance-doelen bij grote volumes.** Een grote administratie (10.000 medewerkers, 12 loonrunsenkele per maand) produceert mogelijk miljoenen events per jaar; lees-query's mogen niet boven 2s gaan voor reguliere filters.
- GIVEN een administratie heeft 5 miljoen events totaal, WHEN payroll_admin een filter draait op "alle events voor medewerker X tussen 2024-01-01 en 2026-06-30", THEN reageert de API binnen 2 seconden voor P95.
- GIVEN een schrijfpiek tijdens een loonrun van 50.000 events binnen 30 seconden, WHEN dit gebeurt, THEN verwerkt het systeem alle events zonder verlies en blijft de hash-chain consistent (per-administratie serialisatie via lock of single-writer-queue).

**REQ-010: SDK voor andere hrmq-capabilities.** Elke capability die payroll-relevante mutaties uitvoert importeert de audit-SDK en hoeft geen eigen audit-implementatie te bouwen; SDK garandeert correcte chain-invoeging en validatie.
- GIVEN `loonbeslag-admin` registreert een nieuwe beslag-mutatie, WHEN deze capability `auditLogger.log(event)` aanroept, THEN voegt de SDK het event correct in de chain in, vult `vorige_event_id`/`vorige_event_hash`/`eigen_hash` automatisch en valideert dat verplichte velden (motivering bij high-impact events) zijn ingevuld.
- GIVEN een capability vergeet `wet_versie` mee te geven bij een Loonrun-event, WHEN de SDK het event valideert, THEN faalt de log-call met `audit_log_validation_error: wet_versie verplicht voor entiteit_type Loonrun`.

## Standards & Sources

- **Art. 52 Algemene Wet inzake Rijksbelastingen (AWR)** — fiscale bewaarplicht van 7 jaar (basis), 10 jaar voor onroerend goed; payroll volgt 7 jaar maar `audit-trail-payroll` neemt 10 jaar als veiligheidsmarge.
- **Art. 7:655 t/m 7:667 BW (arbeidsovereenkomst-administratie)** — onderbouwing dat ook arbeidsrechtelijk relevante mutaties verifieerbaar moeten zijn.
- **AVG art. 5(2) (verantwoordingsplicht / accountability)** en **art. 30 (verwerkingsregister)** — eis dat verwerkingsverantwoordelijke kan aantonen dat de verwerking conform AVG plaatsvond; immutable audit-trail is hier het bewijsmiddel.
- **AVG art. 32 (passende technische maatregelen)** — hash-chain + ondertekende anchors als state-of-the-art integriteitsmaatregel.
- **NEN 7510 / ISO 27001 A.12.4 (Logging and monitoring)** — eisen aan log-bescherming, log-administrator-segregation-of-duties, tijdsync.
- **NIST SP 800-92 (Guide to Computer Security Log Management)** — best practices voor log-integriteit en anti-tampering.
- **RFC 6962 (Certificate Transparency — Merkle Tree)** — referentiemodel voor de cumulatieve-root-anchors.
- **RFC 3161 (Time-Stamp Protocol)** — optionele externe TSA voor sterkere notarisatie van anchors.
- **RFC 6902 (JSON Patch)** — formaat voor `payload_diff`.
- **UUID v7 (RFC 9562)** — tijd-geordende UUID's voor efficiënte natuurlijke sortering van events.
- Referentie-implementaties: **AWS QLDB** (immutable ledger met hash-chain), **Datomic** (time-as-first-class), **Permit.io audit logs**, **OpenZeppelin Defender Sentinel**, **Apache Kafka log-compaction met read-only consumer**.

## Cross-app integration

- **Cross-cutting onder alle hrmq-capabilities** — elke schrijfactie in `employee-master`, `payroll-engine-nl`, `30-procent-regeling`, `loonbeslag-admin`, `pensioen-aangifte`, `loonaangifte-digipoort`, `journaalpost-export`, `verzuim-uwv`, `declaraties-fcab` schrijft naar `audit-trail-payroll` via de SDK.
- **multi-administratie** — alle events zijn administratie-scoped en de hash-chain is per administratie geserialiseerd; retentie-policies kunnen per administratie afwijken.
- **Generieke OpenRegister audit-laag** — bewust complementair, niet vervangend. De OR-audit-laag blijft draaien voor algemene troubleshooting en niet-payroll-mutaties (gebruikersconfiguratie, schermlay-outs, etc.). `audit-trail-payroll` heeft een eigen verwerkingsgrondslag in het AVG-register.
- **openconnector** — externe systemen (Digipoort-aangifte, SEPA-batches, pensioenaanlevering) loggen hun verzend-events naar audit-trail-payroll met `oorzaak_keten_id` voor traceerbaarheid.
- **document-vault** — exporten worden hier opgeslagen met retentie.
- **notification-engine** — chain-integriteits-alerts en legal-hold-conflicten gaan via notificaties.
- **observability-stack** — metrics op event-volume, write-latency, chain-verificatie-tijd, retentie-cleanup-doorvoer.

## Target users

- **Functionaris Gegevensbescherming / DPO** — verifieert dat AVG-accountability is geborgd; reviewt periodiek de `PayrollAuditAccessLog`.
- **Compliance officer** — verantwoordelijk voor NEN 7510 / ISO 27001-controles; gebruikt chain-verificatie-rapporten als bewijsmiddel.
- **Accountant / EDP-auditor** — voert jaarrekeningcontrole en specifieke werkzaamheden uit; ontvangt bewijspakket-exports met verifieerbare hash-chain.
- **Belastingdienst-controleur** — bij boekenonderzoek loonheffingen; ontvangt het bewijspakket en kan zelfstandig integriteit verifiëren.
- **UWV-inspecteur** — bij controle ziekmeldingen, WAZO-aanvragen of WIA-dossiers.
- **Autoriteit Persoonsgegevens** — bij meldingsplichtig datalek of klacht; krijgt op verzoek bewijs van wie wanneer welke persoonsgegevens raakte.
- **Payroll-administrateur** — primair als schrijver (impliciet via dagelijkse acties), incidenteel als lezer bij reconstructie van een verkeerd uitgevoerde loonrun.
- **HR-manager / DGA** — bij arbeidsgeschillen die de salarisadministratie raken; gebruikt audit-trail als bewijsmiddel.
- **Hrmq-platformteam** — gebruikt de audit-trail bij incident-postmortems voor payroll-bugs en bij upgrade-validatie (vergelijken oude versus nieuwe engine-uitkomsten).
- **Bewindvoerder / WSNP-curator** (indirect, via medewerker) — kan via medewerker-portaal volledige financiële historie inzien voor schuldsanering.
