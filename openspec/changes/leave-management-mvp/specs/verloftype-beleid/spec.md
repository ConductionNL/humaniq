# Spec: Verloftype en Verlofbeleid

Capability: `leave-types`

---

## REQ-LVT-001: Verloftype aanmaken

Een admin kan een verloftype aanmaken met naam, categorie (wettelijk / bovenwettelijk / bijzonder), opbouwmethode en uitbetalingsregel bij uitdiensttreding.

### Scenario: Succesvol wettelijk verloftype aanmaken

- **GIVEN** een gebruiker met admin-rechten is ingelogd
- **WHEN** de gebruiker een verloftype aanmaakt met `name="Vakantie (wettelijk)"`, `category="wettelijk"`, `isStatutory=true`, `defaultHoursPerYear=160`, `accrualMethod="proportioneel"`, `isPaidOutOnTermination=true`, `requiresApproval=true`
- **THEN** retourneert de API HTTP 201 met het aangemaakte object inclusief UUID
- **AND** is het verloftype zichtbaar in de verloftypes-lijst

### Scenario: Aanmaken geblokkeerd zonder admin-rechten

- **GIVEN** een gebruiker zonder admin-rechten is ingelogd
- **WHEN** de gebruiker een POST stuurt naar `/api/leave-types`
- **THEN** retourneert de API HTTP 403
- **AND** wordt er geen verloftype aangemaakt

---

## REQ-LVT-002: Verloftype bijwerken

Een admin kan naam, beschrijving, opbouwmethode en uitbetalingsregels van een bestaand verloftype aanpassen.

### Scenario: Categorie aanpassen

- **GIVEN** een verloftype met `slug="vakantie-bovenwettelijk"` bestaat
- **WHEN** de admin een PUT stuurt met `carryOverMaxHours=80`
- **THEN** retourneert de API HTTP 200 met de bijgewerkte waarden
- **AND** worden bestaande LeaveBalance-objecten niet automatisch herberekend (herberekening is een aparte actie)

### Scenario: Verloftype verwijderen geblokkeerd indien in gebruik

- **GIVEN** er bestaan LeaveRequest-objecten die naar verloftype `vakantie-wettelijk` verwijzen
- **WHEN** de admin een DELETE stuurt voor dat verloftype
- **THEN** retourneert de API HTTP 409 met melding "Verloftype is in gebruik en kan niet worden verwijderd"

---

## REQ-LVT-003: Verloftypes raadplegen

Alle ingelogde gebruikers kunnen de lijst van actieve verloftypes opvragen.

### Scenario: Lijst verloftypes ophalen

- **GIVEN** een ingelogde gebruiker (niet noodzakelijk admin)
- **WHEN** de gebruiker een GET stuurt naar `/api/leave-types`
- **THEN** retourneert de API HTTP 200 met een gepagineerde lijst van verloftypes
- **AND** bevat elk object minimaal `id`, `name`, `category`, `isStatutory`, `requiresApproval`

---

## REQ-LVP-001: Verlofbeleid aanmaken

Een admin kan een verlofbeleid aanmaken dat een verloftype koppelt aan jaarlijkse opbouwuren, opbouwfrequentie en geldigheidsperiode.

### Scenario: CAO-beleid aanmaken

- **GIVEN** verloftype `vakantie-wettelijk` bestaat
- **WHEN** de admin een beleid aanmaakt met `leaveType="vakantie-wettelijk"`, `annualHours=160`, `accrualPeriod="maandelijks"`, `validFrom="2025-01-01"`, `caoReference="CAO Gemeenten 2025 art. 6.1"`
- **THEN** retourneert de API HTTP 201
- **AND** is het beleid beschikbaar voor koppeling aan medewerkers

### Scenario: Beleid met ongeldige opbouwperiode

- **GIVEN** een admin is ingelogd
- **WHEN** de admin een beleid aanmaakt met `accrualPeriod="kwartaal"` (niet in de enum)
- **THEN** retourneert de API HTTP 400 met een validatiefout op het veld `accrualPeriod`

---

## REQ-LVP-002: Overlappende beleidsperiodes detecteren

Het systeem mag niet twee actieve beleidsregels voor hetzelfde verloftype met overlappende geldigheidsperiodes accepteren.

### Scenario: Overlap geblokkeerd

- **GIVEN** beleid `cao-gemeenten-wettelijk-2025` geldig van `2025-01-01` tot `2025-12-31` bestaat voor verloftype `vakantie-wettelijk`
- **WHEN** de admin een nieuw beleid aanmaakt voor hetzelfde verloftype met `validFrom="2025-06-01"` en geen `validTo`
- **THEN** retourneert de API HTTP 409 met melding "Er bestaat al een actief beleid voor dit verloftype in de opgegeven periode"

### Scenario: Aansluitende periodes toegestaan

- **GIVEN** beleid `cao-gemeenten-wettelijk-2025` geldig t/m `2025-12-31` bestaat
- **WHEN** de admin een nieuw beleid aanmaakt met `validFrom="2026-01-01"`
- **THEN** retourneert de API HTTP 201 (aansluitend, geen overlap)
