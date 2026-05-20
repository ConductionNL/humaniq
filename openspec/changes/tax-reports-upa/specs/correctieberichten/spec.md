# Spec: Correctieberichten

Capability: `correctieberichten` — Correctiebericht aanmaken voor een eerder ingediend tijdvak; verschil berekenen ten opzichte van originele aangifte; indienen via relay; status bewaken.

---

## ADDED Requirements

### REQ-COR-001: Correctiebericht aanmaken op bestaande aangifte

De payroll-beheerder kan een correctiebericht aanmaken voor een eerder ingediend en verwerkt tijdvak wanneer loongegevens zijn gewijzigd (bijv. nabetaling, correctie arbeidsduur).

#### Scenario: Correctiebericht aanmaken op verwerkte aangifte

- **GIVEN** er bestaat een `LoonaangifteRun` met status `verwerkt` voor tijdvak `2026-02`
- **WHEN** de beheerder een correctiebericht aanmaakt via `CorrectieBerichtModal`
- **THEN** wordt een nieuwe `LoonaangifteRun` aangemaakt met `aangifte_type: correctie`
- **AND** worden `origineelTijdvak` en `origineelRun` ingevuld met verwijzing naar de originele aangifte
- **AND** heeft de nieuwe run status `concept`

#### Scenario: Correctie geblokkeerd op niet-verwerkte aangifte

- **GIVEN** een `LoonaangifteRun` met status `concept` of `fout` voor tijdvak 2026-02
- **WHEN** de beheerder een correctiebericht probeert aan te maken
- **THEN** toont het systeem: `Een correctiebericht kan alleen worden aangemaakt op een verwerkte aangifte`
- **AND** wordt geen correctie-run aangemaakt

---

### REQ-COR-002: Verschil berekenen ten opzichte van originele aangifte

Het systeem berekent automatisch het verschil tussen de gecorrigeerde loongegevens en de originele ingediende aangifte. Alleen gewijzigde dienstverbanden worden opgenomen in het correctiebericht.

#### Scenario: Verschil berekenen voor nabetaling

- **GIVEN** een correctie-`LoonaangifteRun` verwijst naar een originele verwerkte aangifte voor tijdvak 2026-02
- **AND** voor werknemer WL-10042 is de loonheffing gecorrigeerd van €1.420 naar €1.580 (nabetaling vakantietoeslag)
- **WHEN** het systeem het verschil berekent
- **THEN** bevat de correctie-XML alleen de gewijzigde dienstverbanden
- **AND** worden delta-bedragen correct berekend: loonheffing-delta = €160
- **AND** worden `totaalLoonheffing` en `totaalSvLoon` bijgewerkt met de delta-waarden

#### Scenario: Geen wijzigingen aanwezig

- **GIVEN** een correctie-run is aangemaakt maar geen loongegevens zijn gewijzigd ten opzichte van origineel
- **WHEN** het systeem het verschil berekent
- **THEN** toont het systeem: `Geen verschillen gevonden ten opzichte van de originele aangifte`
- **AND** adviseert een nul-aangifte als het tijdvak daadwerkelijk geen correctie behoeft

---

### REQ-COR-003: Correctiebericht indienen via relay

Het correctiebericht volgt dezelfde indienprocedure als een initiële aangifte, maar met het UPA correctiebericht XML-profiel.

#### Scenario: Correctie succesvol ingediend

- **GIVEN** een correctie-`LoonaangifteRun` met status `gereed` en geldige correctie-XML
- **WHEN** de beheerder indiening bevestigt
- **THEN** wordt `UpaIndienenJob` aangemaakt voor het correctiebericht
- **AND** gebruikt de relay-API het UPA correctiebericht XML-profiel (niet het initieel-profiel)
- **AND** wijzigt de status naar `ingediend` en vervolgens `verwerkt` bij responscode `0000`

#### Scenario: Correctie-indiening op gesloten tijdvak

- **GIVEN** het tijdvak 2025-12 ligt buiten de Belastingdienst correctiewindow (meer dan 5 jaar geleden)
- **WHEN** de beheerder een correctie probeert in te dienen
- **THEN** toont het systeem een waarschuwing: `Tijdvak 2025-12 valt mogelijk buiten het correctiewindow van de Belastingdienst. Raadpleeg uw fiscaal adviseur.`
- **AND** kan de beheerder de indiening alsnog forceren na bevestiging van de waarschuwing
