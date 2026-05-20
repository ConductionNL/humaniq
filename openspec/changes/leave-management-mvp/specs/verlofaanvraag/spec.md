# Spec: Verlofaanvraag en Goedkeuringsworkflow

Capability: `leave-requests`

---

## REQ-LAA-001: Verlofaanvraag indienen

Een medewerker kan een verlofaanvraag indienen voor een gewenste periode en verloftype. Het systeem controleert of het saldo toereikend is voordat de aanvraag kan worden ingediend.

### Scenario: Aanvraag succesvol ingediend

- **GIVEN** medewerker Jan de Vries heeft een verlofsaldo van 64 uren voor `vakantie-wettelijk` in 2025
- **WHEN** Jan een aanvraag indient voor 40 uren (`startDate="2025-09-01"`, `endDate="2025-09-05"`)
- **THEN** retourneert de API HTTP 201 met de aanvraag in status `ingediend`
- **AND** ontvangt de leidinggevende een Nextcloud-notificatie "Nieuwe verlofaanvraag van Jan de Vries"
- **AND** is de aanvraag zichtbaar in de aanvragenlijst van de leidinggevende

### Scenario: Aanvraag geblokkeerd wegens onvoldoende saldo

- **GIVEN** medewerker Pieter van den Berg heeft een saldo van 24 uren voor `vakantie-wettelijk`
- **WHEN** Pieter een aanvraag probeert in te dienen voor 40 uren
- **THEN** retourneert de API HTTP 422 met melding "Onvoldoende verlofsaldo. Beschikbaar: 24 uren, aangevraagd: 40 uren."
- **AND** wordt geen aanvraag aangemaakt

### Scenario: Aanvraag met einddatum vóór startdatum

- **GIVEN** een medewerker is ingelogd
- **WHEN** de medewerker een aanvraag indient met `startDate="2025-09-05"` en `endDate="2025-09-01"`
- **THEN** retourneert de API HTTP 400 met validatiefout "Einddatum mag niet vóór startdatum liggen"

---

## REQ-LAA-002: Verlofaanvraag goedkeuren

Een leidinggevende of HR-medewerker kan een ingediende aanvraag goedkeuren. Bij goedkeuring wordt het verlofsaldo van de medewerker verlaagd met het aangevraagde aantal uren.

### Scenario: Aanvraag goedgekeurd

- **GIVEN** aanvraag `aanvraag-janssen-pinksteren` heeft status `ingediend`
- **AND** de leidinggevende Anne Bakker is ingelogd
- **WHEN** Anne de transitie `ingediend → goedgekeurd` uitvoert via POST `/api/leave-requests/{id}/transition` met `{"transition": "goedkeuren"}`
- **THEN** retourneert de API HTTP 200 met de aanvraag in status `goedgekeurd`
- **AND** wordt `approvedBy` ingesteld op het UID van Anne Bakker en `approvedAt` op het huidige tijdstip
- **AND** wordt `usedHours` in het LeaveBalance van Maria Janssen verhoogd met 8 uren
- **AND** ontvangt Maria Janssen een Nextcloud-notificatie "Uw verlofaanvraag is goedgekeurd"

### Scenario: Goedkeuring geblokkeerd door onvoldoende saldo (race condition)

- **GIVEN** het saldo van de medewerker is tussen indiening en goedkeuring gedaald tot onder de aangevraagde uren (bijv. door een eerder goedgekeurde overlappende aanvraag)
- **WHEN** de leidinggevende de goedkeuring probeert uit te voeren
- **THEN** retourneert de API HTTP 422 met melding "Onvoldoende saldo op moment van goedkeuring"
- **AND** blijft de aanvraag in status `ingediend`

### Scenario: Goedkeuring door onbevoegde gebruiker geblokkeerd

- **GIVEN** medewerker Jan de Vries is ingelogd (geen leidinggevende of HR)
- **WHEN** Jan probeert een aanvraag van een andere medewerker goed te keuren
- **THEN** retourneert de API HTTP 403

---

## REQ-LAA-003: Verlofaanvraag afwijzen

Een leidinggevende of HR-medewerker kan een ingediende aanvraag afwijzen met opgave van reden.

### Scenario: Aanvraag afgewezen met reden

- **GIVEN** aanvraag `aanvraag-devries-kerst` heeft status `ingediend`
- **WHEN** de leidinggevende de transitie `afwijzen` uitvoert met `{"transition": "afwijzen", "rejectionReason": "Onvoldoende bezetting in de kerstperiode"}`
- **THEN** retourneert de API HTTP 200 met status `afgewezen` en het opgegeven `rejectionReason`
- **AND** wordt het verlofsaldo van de medewerker NIET aangepast
- **AND** ontvangt de medewerker een notificatie met de afwijzingsreden

### Scenario: Afwijzen zonder reden geblokkeerd

- **GIVEN** een leidinggevende wil een aanvraag afwijzen
- **WHEN** de transitie `afwijzen` wordt uitgevoerd zonder `rejectionReason`
- **THEN** retourneert de API HTTP 400 met validatiefout "Afwijzingsreden is verplicht"

---

## REQ-LAA-004: Verlofaanvraag intrekken door medewerker

Een medewerker kan een concept of goedgekeurde aanvraag intrekken. Bij intrekking van een goedgekeurde aanvraag worden de uren teruggestort op het saldo.

### Scenario: Concept intrekken

- **GIVEN** aanvraag `aanvraag-vandenberg-september` heeft status `concept`
- **WHEN** Pieter van den Berg de transitie `intrekken` uitvoert
- **THEN** retourneert de API HTTP 200 met status `ingetrokken`
- **AND** wordt het saldo niet gewijzigd (uren waren nog niet verbruikt)

### Scenario: Goedgekeurde aanvraag intrekken — saldo terugstorten

- **GIVEN** aanvraag `aanvraag-devries-zomervakantie` heeft status `goedgekeurd` voor 80 uren
- **WHEN** Jan de Vries de transitie `intrekken` uitvoert vóór de startdatum
- **THEN** retourneert de API HTTP 200 met status `ingetrokken`
- **AND** worden 80 uren teruggestort op het verlofsaldo van Jan
- **AND** ontvangt de leidinggevende een notificatie "Verlofaanvraag ingetrokken door Jan de Vries"

### Scenario: Intrekken na startdatum geblokkeerd

- **GIVEN** de startdatum van een goedgekeurde aanvraag ligt in het verleden
- **WHEN** de medewerker de transitie `intrekken` probeert uit te voeren
- **THEN** retourneert de API HTTP 422 met melding "Verlof is reeds ingegaan en kan niet meer worden ingetrokken"

---

## REQ-LAA-005: Aanvragenlijst raadplegen

Medewerkers zien hun eigen aanvragen. Leidinggevenden en HR zien aanvragen van directe rapportages. Admins zien alle aanvragen. Filtering op status en periode is mogelijk.

### Scenario: Medewerker ziet alleen eigen aanvragen

- **GIVEN** medewerker Jan de Vries is ingelogd
- **WHEN** Jan een GET stuurt naar `/api/leave-requests`
- **THEN** retourneert de API HTTP 200 met uitsluitend aanvragen waarvan `employee` het UID van Jan is

### Scenario: Admin ziet alle aanvragen

- **GIVEN** een admin is ingelogd
- **WHEN** de admin een GET stuurt naar `/api/leave-requests`
- **THEN** retourneert de API HTTP 200 met alle aanvragen, ongeacht medewerker

### Scenario: Filteren op status

- **GIVEN** een leidinggevende is ingelogd
- **WHEN** de leidinggevende een GET stuurt naar `/api/leave-requests?status=ingediend`
- **THEN** retourneert de API HTTP 200 met uitsluitend aanvragen met `status="ingediend"` van directe rapportages

---

## REQ-LAA-006: Verlofaanvragen zichtbaar in dashboard

Openstaande verlofaanvragen (status `ingediend`) zijn als widget zichtbaar op het dashboard van leidinggevenden en HR.

### Scenario: Dashboard-widget toont openstaande aanvragen

- **GIVEN** er zijn 3 verlofaanvragen met `status=ingediend` voor de afdeling van de ingelogde leidinggevende
- **WHEN** de leidinggevende het dashboard opent
- **THEN** toont de widget "Openstaande verlofaanvragen" het getal 3
- **AND** bevat de widget een link naar de gefilterde aanvragenlijst
