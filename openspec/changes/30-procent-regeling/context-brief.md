---
status: draft
---
# 30%-regeling Administratie (Expatregeling)

## Purpose

De 30%-regeling — formeel de "extraterritoriale-kostenregeling" uit art. 31a Wet LB 1964 jo. art. 10ea Uitvoeringsbesluit LB — is voor Nederlandse werkgevers met internationaal personeel een zware administratieve last met aanzienlijke fiscale risico's. Werkgevers mogen 30% van het bruto-loon onbelast uitkeren als forfaitaire vergoeding voor extraterritoriale kosten (huisvesting, dubbele-huishouding, repatriëringskosten, taalcursussen) zonder bonnetjes te hoeven overleggen. Daar staan strenge voorwaarden tegenover: geldige Belastingdienst-beschikking, salarisdrempel (€46.660 bruto fiscaal loon excl. de 30%-vergoeding voor 2026; €35.468 voor jong-onderzoekers <30 jaar met master-diploma), 150-km-criterium (>16 van de 24 maanden vóór indiensttreding op >150 km van de Nederlandse grens woonachtig), maximaal 5 jaar looptijd (sinds 2024 afgebouwd: 30/20/10% in jaar 1-2, 2-3, 4-5 — definitief afgeschaft voor nieuwe gevallen vanaf 2027 in Belastingplan 2026 onder voorbehoud), maximale grondslag = WNT-norm (€246.000 voor 2026; alleen 30% over het deel onder de norm), en jaarlijkse her-toetsing van het loonniveau.

Praktijkfouten zijn duur. Een werkgever die de regeling onterecht toepast, krijgt naheffingsaanslagen loonheffing over de afgelopen jaren plus belastingrente plus boete. De Belastingdienst is gericht actief: bij elke loonaangifte-controle wordt de 30%-vergoeding gescreend op beschikkings-existentie, looptijd, drempeloverschrijding en WNT-aftopping. Voor de werknemer is een fout net zo erg — de partial-non-resident-keuze voor box 2 en box 3 (tot 2026 nog mogelijk; afgeschaft per 2027 onder voorbehoud) vervalt mét de regeling, en dan volgt een navorderingsaanslag inkomstenbelasting.

`30-procent-regeling` automatiseert de volledige levenscyclus van een 30%-beschikking binnen hrmq: aanvraag-voorbereiding, beschikking-administratie, maandelijkse loon-impact-berekening, jaarlijkse her-toetsing, automatische alerts bij drempel-onderschrijding, looptijd-eindes en aftopping bij WNT-norm. De capability draait bovenop `employee-master` en `payroll-engine-nl` en is essentieel voor elk Nederlands bedrijf met meer dan een handvol expats — scale-ups, R&D-afdelingen, internationale consultancy en universiteiten.

## Data Model

`Beschikking30` (per medewerker, kan in theorie meerdere per leven hebben — bij wissel werkgever opnieuw aanvragen): `id`, `medewerker_id`, `administratie_id`, `beschikkingsnummer` (zoals afgegeven door Belastingdienst Heerlen), `beschikkingsdatum`, `vanaf` (eerste werkdag in NL waarvoor de regeling geldt), `tot` (vanaf 2024: max 5 jaar vanaf `vanaf`; vóór 2019 was dit 8 jaar, overgangsrecht relevant), `oorspronkelijke_looptijd_jaren` (5 voor nieuwe gevallen, 8 voor overgangsrecht), `categorie` (regulier/jonge_onderzoeker/wetenschappelijk_onderzoeker), `partial_non_resident_gekozen` (bool; alleen 2024-2026), `salarisdrempel_van_toepassing` (norm voor het beschikkingsjaar — kan jaarlijks anders zijn), `bron_document_uri` (gescande beschikking), `aanvrager_intern` (HR-medewerker die de aanvraag deed), `status` (aangevraagd/toegekend/afgewezen/actief/verlopen/ingetrokken).

`Beschikking30Periode` (per kalenderjaar binnen de looptijd — modelleert de afbouw): `id`, `beschikking_id`, `jaar`, `percentage` (30/20/10), `salarisdrempel_jaar` (geïndexeerd bedrag voor dat jaar), `wnt_norm_jaar` (eveneens geïndexeerd), `actief` (kan tussentijds op false komen door intrekking).

`Beschikking30Toetsing` (jaarlijkse en periodieke toetsingen): `id`, `beschikking_id`, `toetsingsdatum`, `toetsingsperiode` (jaar of YTD), `bruto_loon_excl_30` (fiscaal loon over de periode), `bruto_loon_geannualiseerd` (voor parttime-correctie), `drempel_gehaald` (bool), `wnt_grens_overschreden` (bool), `aftopping_bedrag` (deel van het loon boven WNT-norm waarop geen 30% mag worden toegepast), `conclusie` (continueren/intrekken/aanpassen), `door_gebruiker_id`, `automatisch` (bool — door scheduler of handmatig).

`Beschikking30Intrekking`: `id`, `beschikking_id`, `intrekkingsdatum`, `effectieve_datum` (terugwerkende kracht mogelijk tot start van het kalenderjaar), `reden` (drempel_niet_gehaald/dienstverband_eindigt/expat_verhuist_terug/wijziging_functie/wettelijke_wijziging/handmatig), `correctieaangifte_vereist` (bool), `terugbetaling_door_werknemer_bedrag` (indien onterecht ontvangen), `boeking_journaalpost_id`.

`Beschikking30LoonImpact` (per loonperiode, gegenereerd door de loonrun): `id`, `loonrun_id`, `beschikking_id`, `medewerker_id`, `bruto_loon_periode`, `percentage_toegepast`, `vergoeding_30_bedrag`, `vergoeding_30_grondslag_excl_wnt_aftopping`, `wnt_aftopping_bedrag`, `effectief_belastingvoordeel_werknemer` (informatief — schatting), `bron` (gewone_loonrun/13e_maand/vakantiegeld/bonus).

`Beschikking30AlertConfig` (per administratie of fleet-default): `looptijd_einde_waarschuwing_dagen_vooraf` (default 180 — half jaar van tevoren waarschuwen zodat HR contractgesprek kan plannen), `drempel_marge_percentage` (default 5 — alert als YTD-loon binnen 5% boven of onder de drempel zit), `wnt_marge_percentage` (default 10), `actief` (bool).

## Requirements

**REQ-001: Beschikking-registratie en validatie.** HR kan een afgegeven 30%-beschikking vastleggen; het systeem valideert basisconsistentie (looptijd ≤5 jaar voor nieuwe gevallen, beschikkingsdatum ≥ vanaf-datum binnen 4 maanden wettelijke aanvraagtermijn).
- GIVEN HR vult een beschikking in met `vanaf = 2026-01-01` en `tot = 2032-01-01` (6 jaar), WHEN het formulier wordt ingediend, THEN weigert het systeem met `validation_error: looptijd overschrijdt wettelijk maximum van 5 jaar (overgangsrecht 8 jaar alleen bij vanaf-datum vóór 2019-01-01)`.
- GIVEN een geldige beschikking wordt opgeslagen, WHEN dit gebeurt, THEN worden automatisch 5 (of resterende) `Beschikking30Periode`-records aangemaakt met de juiste percentages (30/30/20/20/10 voor 2024+, 30/30/30/30/30 voor pre-2024 overgangsrecht).

**REQ-002: Maandelijkse loon-impact-berekening tijdens loonrun.** Bij elke loonrun berekent het systeem automatisch de 30%-vergoeding per medewerker met actieve beschikking, inclusief WNT-aftopping en parttime-correctie.
- GIVEN een medewerker met actieve beschikking en bruto-maandloon €5.000, WHEN de loonrun draait, THEN wordt €1.500 (30%) als onbelaste vergoeding geboekt en €3.500 als belastbaar loon.
- GIVEN een medewerker met bruto-jaarloon €300.000 (boven WNT-norm €246.000 voor 2026), WHEN de loonrun draait, THEN wordt 30% berekend over €246.000 (max €73.800/jaar; €6.150/maand) en op het surplus van €54.000 geen 30% toegepast — registratie in `wnt_aftopping_bedrag`.
- GIVEN een medewerker in afbouwjaar 3 (jaar 3 = 20%), WHEN de loonrun draait, THEN wordt 20% in plaats van 30% toegepast.

**REQ-003: Jaarlijkse her-toetsing op salarisdrempel.** Elk jaar in januari (of bij dienstverband-mutatie tussentijds) controleert het systeem of het loon over het afgesloten jaar boven de drempel uitkwam; zo niet, dan automatische intrekking met terugwerkende kracht.
- GIVEN een medewerker eindigde 2026 met fiscaal loon excl. 30% van €44.000 (drempel €46.660), WHEN de jaartoetsing draait, THEN wordt een `Beschikking30Toetsing`-record geschreven met `drempel_gehaald = false` en `conclusie = intrekken`; HR krijgt een actie-item.
- GIVEN de jaartoetsing concludeert intrekking, WHEN HR de intrekking bevestigt, THEN wordt een `Beschikking30Intrekking` aangemaakt met `effectieve_datum = 2026-01-01` en wordt een correctieaangifte loonheffingen voor 2026 voorbereid.

**REQ-004: Parttime-correctie bij drempeltoetsing.** Voor parttimers wordt de drempel niet pro-rata verlaagd — de wet eist dat het werkelijke fiscaal loon de fulltime-drempel haalt; alleen bij ouderschapsverlof, geboorteverlof en langdurig ziekteverzuim is er een uitzondering.
- GIVEN een medewerker werkt 24 uur/week (60% van 40), WHEN de drempel-toetsing draait, THEN wordt de drempel niet aangepast — het werkelijke jaarloon van €30.000 voldoet niet aan de drempel van €46.660 en de regeling vervalt.
- GIVEN een medewerker had 4 maanden ouderschapsverlof, WHEN de drempel-toetsing draait, THEN wordt het loon over de verlofperiode buiten beschouwing gelaten en wordt het loon over de gewerkte 8 maanden geannualiseerd (× 12/8) voor de toetsing.

**REQ-005: Alert 6 maanden voor looptijd-einde.** HR wordt automatisch gealarmeerd ruim voor het einde van de beschikking zodat contractgesprek of nieuwe arbeidsvoorwaarden-onderhandeling kan plaatsvinden.
- GIVEN een beschikking eindigt op 2027-06-30, WHEN het 2027-01-01 wordt (180 dagen vooraf), THEN wordt een actie-item aangemaakt voor de hr_manager van de administratie met de boodschap "30%-beschikking van {medewerker} verloopt op 2027-06-30 — voorbereid CAO-conversie of nieuw aanbod".
- GIVEN het is 30 dagen voor einde, WHEN er nog geen actie is ondernomen, THEN wordt een escalatie naar de eigenaar van de administratie gestuurd.

**REQ-006: WNT-aftopping conform Wet op de Loonbelasting art. 31a lid 8.** De grondslag voor de 30%-vergoeding wordt afgetopt op de WNT-norm; over het surplus mag geen onbelaste vergoeding worden toegepast.
- GIVEN de WNT-norm voor 2026 is €246.000 en een medewerker heeft bruto-jaarloon €400.000, WHEN de loonrun draait, THEN wordt de maximale 30%-vergoeding €73.800/jaar (€6.150/maand) en blijft €154.000 volledig belast bovenop het reguliere belastbare deel.
- GIVEN de WNT-norm wijzigt jaarlijks per ministerieel besluit, WHEN het nieuwe jaar begint, THEN gebruikt het systeem automatisch de nieuwe norm uit `Beschikking30Periode.wnt_norm_jaar`.

**REQ-007: Intrekking met correctieaangifte.** Bij intrekking met terugwerkende kracht wordt automatisch een correctieaangifte loonheffing voorbereid voor de getroffen periodes; het bedrag dat de medewerker moet terugbetalen wordt berekend.
- GIVEN een beschikking wordt ingetrokken per 2026-01-01 en de medewerker heeft over jan-aug €12.000 aan onbelaste 30%-vergoeding ontvangen, WHEN de intrekking wordt bevestigd, THEN wordt een correctieaangifte voor jan-aug gegenereerd waarin €12.000 alsnog wordt aangemerkt als belastbaar loon; werkgever moet alsnog loonheffing afdragen, werknemer wordt geconfronteerd met loonkorting of vordering.
- GIVEN HR voert een handmatige intrekking door, WHEN dit gebeurt, THEN wordt het besluit met motivatie vastgelegd in `Beschikking30Intrekking.reden` en gekoppeld aan de audit-trail.

**REQ-008: Partial-non-resident keuze (tijdelijk 2024-2026, vervallen 2027).** Het systeem registreert of de medewerker in box 2 en box 3 kiest voor partial-non-resident-status; flag wordt aan IB-aangifte-export meegegeven.
- GIVEN een medewerker tekende voor partial-non-resident in 2026, WHEN HR dit registreert, THEN wordt `partial_non_resident_gekozen = true` opgeslagen en zichtbaar in de jaaropgave-bijlage.
- GIVEN het wordt 2027, WHEN het systeem deze flag evalueert, THEN wordt een waarschuwing getoond: "Partial-non-resident keuze is per 2027 vervallen op basis van wetswijziging — vergewis u van de actuele wetgeving."

**REQ-009: Salarisdrempel-marge alert YTD.** Tijdens het lopende jaar waarschuwt het systeem als de YTD-loonbestelling binnen 5% van de drempel zit — zodat HR tijdig kan ingrijpen (bonusbeleid, salarisverhoging) om alsnog boven de drempel uit te komen.
- GIVEN een medewerker heeft per september een YTD-loon van €33.000 (geannualiseerd €44.000 — binnen 5% onder drempel €46.660), WHEN de maandelijkse drempelcheck draait, THEN wordt een alert gegenereerd: "drempel-risico — €2.660 tekort op basis van huidige loonprojectie".

**REQ-010: Documentatie en bewijslast voor Belastingdienst-controle.** Per medewerker zijn alle beschikkingen, toetsingen, periodes en loon-impacts opvraagbaar als één PDF-bewijspakket — dit is de standaard-aanvraag bij een loonheffingen-boekenonderzoek.
- GIVEN de Belastingdienst kondigt een boekenonderzoek aan voor administratie A, WHEN HR de export draait, THEN wordt een PDF gegenereerd met per relevante medewerker: gescande beschikking, alle Beschikking30Toetsing-records, alle Beschikking30LoonImpact-records, intrekkingen (indien van toepassing) en de bijbehorende correctieaangiftes.

## Standards & Sources

- **Art. 31a Wet op de loonbelasting 1964** — wettelijke grondslag van de 30%-regeling.
- **Art. 10ea t/m 10ej Uitvoeringsbesluit loonbelasting 1965** — uitvoeringsbepalingen, incl. salarisdrempel, looptijd, 150-km-criterium.
- **Belastingplan 2024** (Wet fiscale maatregelen 2024) — afbouw 30/20/10% en max-grondslag op WNT-norm.
- **Belastingplan 2026** — voorgenomen afschaffing van de partial-non-resident-keuze en mogelijke verdere afbouw (definitieve teksten volgen).
- **Wet normering topinkomens (WNT)** — bron voor de jaarlijks vast te stellen WNT-norm; in 2026 €246.000.
- **Belastingdienst-brochure "30%-regeling — voor werkgevers en werknemers"** — toetsingsdrempels, parttime-uitzonderingen, partial-non-resident-keuze.
- **Loonheffingen Handboek 2026** — hoofdstuk 17 Vrijgestelde vergoedingen en verstrekkingen.
- **Centraal aanspreekpunt 30%-regeling van de Belastingdienst** (vestiging Heerlen) — uitvoeringspraktijk, beschikkingsnummers.
- Referentie-implementaties: **Loket.nl 30%-module**, **Nmbrs Expat-module**, **Visma Talenta**, **Workday Tax** (multi-country expat-engine).

## Cross-app integration

- **employee-master** — levert geboortedatum, master-diploma (voor jong-onderzoeker-categorie), nationaliteit, eerdere woonadres (voor 150-km-toetsing), eerste werkdag in Nederland.
- **payroll-engine-nl** — consumeert `Beschikking30LoonImpact` per loonperiode; de payroll-engine roept de 30%-rule-evaluator aan tijdens elke loonrun voor elke medewerker met een actieve beschikking.
- **multi-administratie** — beschikkingen zijn altijd administratie-scoped; bij intercompany-detachering blijft de beschikking gekoppeld aan de "payroll-blijft-bij"-administratie.
- **audit-trail-payroll** — elke beschikkingsmutatie, toetsing en intrekking wordt onveranderlijk gelogd; vereist voor Belastingdienst-verantwoording.
- **journaalpost-export** — bij intrekking met terugwerkende kracht wordt automatisch een correctieboeking gegenereerd richting boekhoudpakket.
- **loonaangifte-digipoort** — bij intrekking wordt een correctieaangifte loonheffingen gegenereerd en via Digipoort ingediend.
- **document-vault** — gescande beschikkingen worden opgeslagen met retentie van 7 jaar conform fiscale bewaarplicht.
- **notification-engine** — looptijd-einde- en drempel-alerts worden via de standaard hrmq-notificatie-stack (mail/in-app/n8n) verstuurd.

## Target users

- **HR-medewerker bij scale-up met internationale knowledge workers** — primaire dagelijkse gebruiker; registreert beschikkingen, monitort drempels, voert intrekkingen door.
- **Payroll-administrateur bij accountantskantoor** — beheert tientallen klant-BV's met expats; gebruikt de bewijspakket-export bij Belastingdienst-controles.
- **HR-business-partner bij universiteit / R&D-instelling** — beheert wetenschappelijk-onderzoeker-categorie met aparte (lagere) drempel.
- **Compliance officer bij multinational** — controleert dat het bedrijf geen risico loopt op naheffing; gebruikt drempel-marge-alerts.
- **DGA van internationaal opererende consultancy** — wil real-time inzicht in de fiscale impact van expat-aanwervingen.
- **Expat-medewerker zelf** — ziet in zijn self-service-portaal de status van zijn beschikking, einddatum en jaarlijkse toetsings-uitkomst.
- **Belastingdienst (indirect, via controleur)** — ontvangt bewijspakket bij boekenonderzoek; consistent en compleet formaat verlaagt onderzoeksduur en risico op correcties.
