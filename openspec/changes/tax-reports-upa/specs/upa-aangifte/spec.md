# Spec: UPA Aangifte

Capability: `upa-aangifte` — UPA loonaangifte XML genereren en indienen voor een loonheffingen-tijdvak via relay-service (Loonnext of Nmbrs); indienstatus bewaken; Belastingdienst-response verwerken.

---

## ADDED Requirements

### REQ-UPA-001: Loonaangifte aanmaken per tijdvak

De payroll-beheerder kan een nieuwe loonaangifte aanmaken voor een salarisperiode (tijdvak). De aangifte aggregeert loongegevens uit `payroll-core-basic` voor alle actieve dienstverbanden in dat tijdvak.

#### Scenario: Aangifte aanmaken voor een afrondend tijdvak

- **GIVEN** de payroll-beheerder is ingelogd en `payroll-core-basic` heeft loonberekeningen beschikbaar voor tijdvak 2026-03
- **WHEN** de beheerder een nieuwe aangifte aanmaakt voor tijdvak `2026-03` met type `initieel`
- **THEN** wordt een `LoonaangifteRun`-object aangemaakt met status `concept`
- **AND** zijn `aantalDienstverbanden`, `totaalLoonheffing` en `totaalSvLoon` berekend op basis van de loonrun
- **AND** kan de beheerder de aangifte reviewen vóór indiening

#### Scenario: Aanmaken geblokkeerd zonder afhankelijkheid

- **GIVEN** `payroll-core-basic` heeft geen afgeronde loonrun voor tijdvak 2026-03
- **WHEN** de beheerder een aangifte probeert aan te maken voor dat tijdvak
- **THEN** toont het systeem een foutmelding: `Geen afgeronde loonrun beschikbaar voor tijdvak 2026-03`
- **AND** wordt geen `LoonaangifteRun`-object aangemaakt

---

### REQ-UPA-002: UPA XML genereren conform XSD 2026

Het systeem genereert een geldig UPA XML-bestand conform Belastingdienst XSD versie 2026 op basis van de `LoonaangifteRun`.

#### Scenario: Succesvolle XML-generatie

- **GIVEN** een `LoonaangifteRun` met status `concept` en volledig ingevulde loongegevens
- **WHEN** de beheerder XML genereren triggert
- **THEN** genereert `UpaAangifteService` een UPA XML-document
- **AND** wordt de XML gevalideerd tegen XSD versie 2026 vóór opslaan
- **AND** wordt de XML als base64 opgeslagen in `xmlPayload` van de `LoonaangifteRun`
- **AND** wijzigt de status naar `gereed`

#### Scenario: XSD-validatie mislukt

- **GIVEN** een `LoonaangifteRun` waarbij een verplicht UPA-veld ontbreekt (bijv. `loonheffingennummer`)
- **WHEN** XML-generatie wordt gestart
- **THEN** faalt XSD-validatie met een beschrijving van het ontbrekende veld
- **AND** blijft de status `concept`
- **AND** wordt de foutmelding getoond aan de beheerder (geen stack trace, generieke weergave)

---

### REQ-UPA-003: Aangifte indienen via relay-service

De beheerder kan een XML-gereed aangifte indienen bij de Belastingdienst via de geconfigureerde relay-provider (Loonnext of Nmbrs).

#### Scenario: Succesvolle indiening via relay

- **GIVEN** een `LoonaangifteRun` met status `gereed` en geconfigureerde relay-provider `loonnext`
- **WHEN** de beheerder indiening bevestigt via `IndienAangifteModal`
- **THEN** wordt `UpaIndienenJob` aangemaakt als `QueuedJob`
- **AND** wijzigt de status direct naar `ingediend` voor gebruikersfeedback
- **AND** roept de job de Loonnext relay-API aan met de XML-payload
- **AND** slaat de `relayReferentie` op na succesvolle ontvangst

#### Scenario: Relay-service tijdelijk onbereikbaar

- **GIVEN** de Loonnext relay-API retourneert een HTTP 503 fout
- **WHEN** `UpaIndienenJob` de aangifte probeert in te dienen
- **THEN** wacht de job 60 seconden en herprobeert (maximaal 3 pogingen)
- **AND** na 3 mislukte pogingen wijzigt de status naar `fout`
- **AND** ontvangt de payroll-beheerder een Nextcloud-notificatie met de foutdetails

#### Scenario: Relay vereist geldige API-credentials

- **GIVEN** de relay-provider API-credentials zijn niet geconfigureerd in de app-instellingen
- **WHEN** de beheerder indiening probeert te starten
- **THEN** toont het systeem: `Relay-provider is niet geconfigureerd. Ga naar Instellingen om de API-credentials in te stellen.`
- **AND** wordt geen `UpaIndienenJob` aangemaakt

---

### REQ-UPA-004: Belastingdienst-response verwerken en status bewaken

Na indiening verwerkt het systeem de response van de Belastingdienst (via relay) en werkt de aangifte-status bij.

#### Scenario: Aangifte goedgekeurd door Belastingdienst

- **GIVEN** de relay-service heeft de aangifte doorgestuurd en een responscode ontvangen
- **WHEN** de relay retourneert responscode `0000` (aangifte verwerkt)
- **THEN** wijzigt de status van de `LoonaangifteRun` naar `verwerkt`
- **AND** worden `responsecode`, `responseomschrijving` en `ingediendOp` opgeslagen
- **AND** ontvangt de payroll-beheerder een Nextcloud-notificatie: `Aangifte 2026-03 is verwerkt door de Belastingdienst`

#### Scenario: Aangifte afgewezen door Belastingdienst

- **GIVEN** de relay retourneert een foutcode (bijv. `0141`: onbekend aanleveraarsnummer)
- **WHEN** `UpaIndienenJob` de response verwerkt
- **THEN** wijzigt de status naar `fout`
- **AND** worden `responsecode` en `responseomschrijving` opgeslagen op de `LoonaangifteRun`
- **AND** ontvangt de beheerder een notificatie met de foutcode en omschrijving
- **AND** kan de beheerder de aangifte corrigeren en opnieuw indienen

#### Scenario: Aangifte-overzicht toont actuele statussen

- **GIVEN** er zijn meerdere `LoonaangifteRun`-objecten met verschillende statussen
- **WHEN** de beheerder het aangifte-overzicht (`LoonaangifteView`) opent
- **THEN** worden alle aangiften weergegeven met tijdvak, type, status en indiendatum
- **AND** kan de beheerder filteren op status en tijdvak
- **AND** zijn statussen kleurgecodeerd: concept (grijs), gereed (blauw), ingediend (oranje), verwerkt (groen), fout (rood)
