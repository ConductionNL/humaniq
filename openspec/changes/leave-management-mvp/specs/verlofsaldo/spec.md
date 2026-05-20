# Spec: Verlofsaldo en Opbouw

Capability: `leave-balance`

---

## REQ-LSA-001: Saldo raadplegen

Een medewerker kan zijn eigen verlofsaldo per verloftype per jaar inzien. HR en admins kunnen het saldo van alle medewerkers inzien.

### Scenario: Medewerker raadpleegt eigen saldo

- **GIVEN** medewerker Jan de Vries is ingelogd
- **WHEN** Jan een GET stuurt naar `/api/leave-balances?year=2025`
- **THEN** retourneert de API HTTP 200 met saldo-objecten voor alle verloftypes die op Jan van toepassing zijn
- **AND** bevat elk object `accruedHours`, `usedHours`, `carriedOverHours` en het berekende `remainingHours`

### Scenario: Medewerker mag saldo van ander niet inzien

- **GIVEN** medewerker Maria Janssen is ingelogd
- **WHEN** Maria een GET stuurt naar `/api/leave-balances?employee=jan-de-vries`
- **THEN** retourneert de API HTTP 403

### Scenario: HR raadpleegt saldo van medewerker

- **GIVEN** een HR-medewerker met `isAdmin=true` is ingelogd
- **WHEN** de HR-medewerker een GET stuurt naar `/api/leave-balances?employee=jan-de-vries&year=2025`
- **THEN** retourneert de API HTTP 200 met het saldo van Jan de Vries

---

## REQ-LSA-002: Resterend saldo berekend veld

Het veld `remainingHours` op een LeaveBalance-object wordt automatisch berekend als `accruedHours + carriedOverHours - usedHours`. Het is geen opgeslagen waarde maar een via `x-openregister-calculations` gedeclareerd afgeleid veld.

### Scenario: Resterende uren kloppen na goedkeuring aanvraag

- **GIVEN** Jan de Vries heeft saldo `accruedHours=80`, `carriedOverHours=16`, `usedHours=32`
- **WHEN** een aanvraag van 40 uren wordt goedgekeurd en `usedHours` wordt verhoogd naar 72
- **THEN** retourneert het API-object `remainingHours=24` (80 + 16 − 72)
- **AND** is `remainingHours` consistent ongeacht het tijdstip van opvragen

### Scenario: Saldo kan niet negatief worden via goedkeuring

- **GIVEN** Jan heeft `remainingHours=24`
- **WHEN** een leidinggevende een aanvraag van 40 uren probeert goed te keuren
- **THEN** blokkeert de `LeaveRequestGuard` de transitie met HTTP 422 "Onvoldoende saldo"
- **AND** blijft `remainingHours` ongewijzigd op 24

---

## REQ-LSA-003: Maandelijkse opbouw uitvoeren

Op de eerste werkdag van elke maand berekent de `LeaveAccrualJob` per actief dienstverband de opbouw op basis van het gekoppelde verlofbeleid en het contractuele weekurenaantal. Elke opbouwperiode wordt vastgelegd als `LeaveAccrualLog`-object.

### Scenario: Opbouw wordt correct berekend

- **GIVEN** medewerker Maria Janssen heeft een contractueel weekurenaantal van 40 uren
- **AND** haar verlofbeleid specificeert `annualHours=160` met `accrualPeriod="maandelijks"`
- **WHEN** de `LeaveAccrualJob` draait voor periode `2025-06`
- **THEN** wordt `accruedHours` op haar LeaveBalance verhoogd met 13.33 uren (160 / 12)
- **AND** wordt een `LeaveAccrualLog`-object aangemaakt voor `period="2025-06"` met `hoursAccrued=13.33`

### Scenario: Opbouw is idempotent per periode

- **GIVEN** de `LeaveAccrualJob` is reeds uitgevoerd voor periode `2025-06` voor medewerker Maria Janssen
- **WHEN** de taak opnieuw wordt gestart voor dezelfde periode
- **THEN** wordt het saldo van Maria NIET opnieuw verhoogd
- **AND** wordt er geen dubbele `LeaveAccrualLog`-entry aangemaakt

### Scenario: Medewerker zonder verlofbeleid overgeslagen

- **GIVEN** een medewerker heeft geen gekoppeld verlofbeleid
- **WHEN** de `LeaveAccrualJob` draait
- **THEN** wordt de medewerker overgeslagen en wordt een waarschuwing gelogd op serverniveau
- **AND** worden overige medewerkers correct verwerkt

---

## REQ-LSA-004: Jaarlijkse overdracht berekenen

Bij het begin van een nieuw kalenderjaar worden ongebruikte uren overgedragen naar het volgende jaar conform het `carryOverMaxHours`-veld van het gekoppelde verlofbeleid. Uren boven het maximum worden niet overgedragen.

### Scenario: Overdracht binnen maximum

- **GIVEN** Jan de Vries heeft op 31 december 2025 `remainingHours=24` voor `vakantie-wettelijk`
- **AND** het beleid heeft `carryOverMaxHours=40`
- **WHEN** de jaarwisseling-job draait voor het overgaan naar 2026
- **THEN** krijgt het LeaveBalance 2026 `carriedOverHours=24`
- **AND** vervallen er geen uren

### Scenario: Overdracht boven maximum afgekapt

- **GIVEN** medewerker heeft op 31 december 2025 `remainingHours=60` voor `vakantie-bovenwettelijk`
- **AND** het beleid heeft `carryOverMaxHours=40`
- **WHEN** de jaarwisseling-job draait
- **THEN** krijgt het LeaveBalance 2026 `carriedOverHours=40`
- **AND** worden 20 uren niet overgedragen (boven het maximum)

---

## REQ-LSA-005: Uitbetalingsberekening bij uitdiensttreding

Bij uitdiensttreding kan HR voor een medewerker een uitbetalingsberekening opvragen. Hierbij wordt het resterend saldo van verloftypes met `isPaidOutOnTermination=true` omgerekend naar een bruto-uitbetalingsbedrag op basis van het dagloon.

### Scenario: Uitbetalingsberekening opvragen

- **GIVEN** medewerker Jan de Vries treedt uit dienst per `2025-08-01`
- **AND** hij heeft een verlofsaldo van 64 uren `vakantie-wettelijk` en 60 uren `vakantie-bovenwettelijk` (beide `isPaidOutOnTermination=true`)
- **AND** zijn uurloon is EUR 28,50
- **WHEN** HR een GET stuurt naar `/api/leave-balances/termination-payout?employee=jan-de-vries&terminationDate=2025-08-01`
- **THEN** retourneert de API HTTP 200 met een overzicht van:
  - `vakantie-wettelijk`: 64 uren × EUR 28,50 = EUR 1.824,00
  - `vakantie-bovenwettelijk`: 60 uren × EUR 28,50 = EUR 1.710,00
  - totaal: EUR 3.534,00

### Scenario: Verloftypes zonder uitbetalingsrecht uitgesloten

- **GIVEN** Jan heeft ook bijzonder verlof van type `bijzonder-verlof-huwelijk` (`isPaidOutOnTermination=false`) staan
- **WHEN** de uitbetalingsberekening wordt opgevraagd
- **THEN** wordt `bijzonder-verlof-huwelijk` niet opgenomen in het berekende bedrag
