# Specs: Cross-app: Payroll Cost → shillinq GL Post

## Overzicht

Functionele eisen voor de hrmq → shillinq GL-postingintegratie. Alle eisen zijn in het GIVEN/WHEN/THEN-formaat en worden getoetst door browser-tests (Playwright) en integratietests (Newman).

---

## REQ-POST-001: Automatische posting na salarisrun

**Beschrijving:** Na het afsluiten van een salarisrun worden alle werknemers in die run automatisch als journaalpost naar shillinq verzonden.

**Prioriteit:** P0-must

### Scenario 1.1 — Succesvolle posting

```
GEGEVEN een afgesloten PayrollRun voor periode "2026-04"
  EN werknemer Bakker heeft loongegevens: bruto €3.850, sociale lasten €963, vakantiegeld €308, netto €5.121
  EN de shillinq API is bereikbaar en de API-sleutel is geldig
WANNEER de PayrollGLPostService.postRun(payrollRunId) wordt aangeroepen
DAN wordt shillinq.JournalEntry.post éénmalig aangeroepen voor werknemer Bakker
  EN de aanroep bevat vier journaalregels (debet 4000, debet 1700, debet 1800, credit 1400)
  EN een PayrollGLPost-record wordt aangemaakt met status "posted"
  EN het teruggegeven shillinqJournalEntryId wordt opgeslagen
  EN postedAt wordt gevuld met het huidige tijdstip
```

### Scenario 1.2 — Meerdere werknemers in één run

```
GEGEVEN een afgesloten PayrollRun met drie werknemers (Bakker, De Vries, Janssen)
  EN de shillinq API is bereikbaar
WANNEER postRun wordt aangeroepen
DAN worden voor elke werknemer drie afzonderlijke shillinq.JournalEntry.post-aanroepen gedaan
  EN voor elke werknemer een PayrollGLPost-record aangemaakt met status "posted"
```

---

## REQ-POST-002: Idempotentie per (employee_id, period)

**Beschrijving:** Een herpost voor een combinatie van werknemer en periode die al succesvol is geboekt, mag geen nieuwe journaalpost in shillinq aanmaken.

**Prioriteit:** P0-must

### Scenario 2.1 — Skip bij bestaande posted-record

```
GEGEVEN een bestaande PayrollGLPost met status "posted" voor (Bakker, "2026-04")
WANNEER postRun opnieuw wordt aangeroepen voor dezelfde run of een herpost voor dezelfde periode
DAN wordt shillinq.JournalEntry.post NIET aangeroepen voor werknemer Bakker
  EN de bestaande PayrollGLPost-record blijft ongewijzigd (status blijft "posted")
  EN de service retourneert status "skipped" voor dit record
```

### Scenario 2.2 — Herpost bij failed-record

```
GEGEVEN een bestaande PayrollGLPost met status "failed" voor (Janssen, "2026-04")
  EN de shillinq API is nu bereikbaar
WANNEER een herpost wordt getriggerd voor de gefaalde records
DAN wordt shillinq.JournalEntry.post WEL aangeroepen voor werknemer Janssen
  EN de bestaande PayrollGLPost-record wordt bijgewerkt naar status "posted"
  EN errorMessage wordt leeggemaakt
  EN shillinqJournalEntryId en postedAt worden ingevuld
```

### Scenario 2.3 — Idempotentie bij gelijktijdige aanroepen

```
GEGEVEN twee gelijktijdige aanroepen van postRun voor dezelfde (Bakker, "2026-04")
WANNEER beide aanroepen tegelijk worden verwerkt
DAN wordt precies één PayrollGLPost-record aangemaakt
  EN wordt shillinq.JournalEntry.post precies één keer aangeroepen
```

---

## REQ-POST-003: RGS 2026 rekeningnummer-mapping

**Beschrijving:** Journaalregels worden opgebouwd met de geconfigureerde RGS 2026 rekeningen per loonkostentype.

**Prioriteit:** P0-must

### Scenario 3.1 — Standaard RGS-mapping

```
GEGEVEN de standaard RGS-configuratie (4000, 1700, 1800, 1400)
  EN werknemer met bruto €4.200, sociale lasten €1.050, vakantiegeld €336, netto €5.586
WANNEER de journaalpost wordt opgebouwd
DAN bevat de shillinq.JournalEntry.post aanroep:
  - Debetboeking rekening "4000" voor €4.200 (bruto-loon)
  - Debetboeking rekening "1700" voor €1.050 (sociale lasten)
  - Debetboeking rekening "1800" voor €336 (vakantiegeld-reservering)
  - Creditboeking rekening "1400" voor €5.586 (netto-loonschuld)
```

### Scenario 3.2 — Aangepaste RGS-configuratie

```
GEGEVEN een aangepaste configuratie waarbij bruto-loon-rekening is ingesteld op "4010"
WANNEER de journaalpost wordt opgebouwd
DAN gebruikt de debetboeking voor bruto-loon rekening "4010"
  EN de overige rekeningen blijven ongewijzigd
```

### Scenario 3.3 — Balanceringscontrole

```
GEGEVEN loongegevens waarbij debet-totaal (bruto + sociale lasten + vakantiegeld) NIET gelijk is aan credit-totaal (netto-loonschuld) met meer dan €0,01 afwijking
WANNEER de journaalpost wordt opgebouwd
DAN wordt shillinq.JournalEntry.post NIET aangeroepen
  EN wordt de PayrollGLPost-record aangemaakt met status "failed"
  EN wordt een intern foutlogbericht geschreven (niet teruggegeven aan client)
```

---

## REQ-POST-004: Foutafhandeling shillinq API

**Beschrijving:** Fouten bij aanroep van de shillinq API worden geregistreerd; de integratie is robuust bij tijdelijke storingen.

**Prioriteit:** P0-must

### Scenario 4.1 — API niet bereikbaar

```
GEGEVEN de shillinq API geeft een HTTP 503 of een verbindingsfout
WANNEER shillinq.JournalEntry.post wordt aangeroepen voor werknemer Janssen
DAN wordt de PayrollGLPost-record aangemaakt of bijgewerkt met status "failed"
  EN wordt een statische foutmelding opgeslagen in errorMessage (NIET de ruwe API-response)
  EN wordt de echte foutmelding gelogd via $logger->error() op de server
  EN de service gaat door met de volgende werknemer in de run (geen totale abort)
```

### Scenario 4.2 — Ongeldige API-sleutel

```
GEGEVEN de shillinq API-sleutel is verlopen of ongeldig (HTTP 401)
WANNEER shillinq.JournalEntry.post wordt aangeroepen
DAN wordt de PayrollGLPost-record aangemaakt met status "failed"
  EN de foutmelding bevat GEEN API-sleutel of credential-details
  EN de beheerder kan in de admin-instellingen een nieuwe sleutel invoeren
```

### Scenario 4.3 — Partiële run-fout

```
GEGEVEN een PayrollRun met drie werknemers waarbij de API voor werknemer 2 faalt
WANNEER postRun wordt uitgevoerd
DAN worden werknemers 1 en 3 succesvol geboekt (status "posted")
  EN werknemer 2 krijgt status "failed"
  EN de service geeft een resultaat terug met aantallen per status
```

---

## REQ-CFG-001: Admin-configuratie shillinq-koppeling

**Beschrijving:** IT-beheerders kunnen de shillinq-koppeling configureren via de hrmq admin-instellingenpagina.

**Prioriteit:** P0-must

### Scenario 5.1 — API-sleutel opslaan

```
GEGEVEN de IT-beheerder is ingelogd als Nextcloud-admin
  EN de admin-instellingenpagina van hrmq is geopend
WANNEER de beheerder een shillinq API-sleutel invoert en opslaat
DAN wordt de sleutel opgeslagen via IAppConfig met sensitive=true
  EN wordt de sleutel NIET teruggegeven in de GET /api/settings response (alleen aanwezigheidsindicator)
  EN toont de pagina een succesbericht
```

### Scenario 5.2 — RGS-rekeningen configureren

```
GEGEVEN de admin-instellingenpagina
WANNEER de beheerder per loonkostentype een RGS-rekeningnummer invoert
DAN worden de rekeningen opgeslagen via IAppConfig
  EN worden de geconfigureerde rekeningen gebruikt bij de volgende salarisrun-posting
```

---

## REQ-OVZ-001: Posting-overzicht per periode

**Beschrijving:** Salarisadministrateurs kunnen een overzicht inzien van alle PayrollGLPost-records per periode.

**Prioriteit:** P1-should

### Scenario 6.1 — Overzicht met statusfilter

```
GEGEVEN de salarisadministrateur opent de PayrollGLPost-overzichtspagina voor periode "2026-04"
WANNEER de pagina laadt
DAN ziet de gebruiker een lijst met alle records voor die periode
  EN per record: werknemersnaam, periode, status, shillinqJournalEntryId (indien aanwezig)
  EN is er een filter op status beschikbaar (alle / pending / posted / failed / skipped)
```

### Scenario 6.2 — Herpost gefaalde records

```
GEGEVEN de overzichtspagina toont records met status "failed"
WANNEER de salarisadministrateur op "Herpost gefaald" klikt
DAN wordt de herpost-actie getriggerd voor alle "failed"-records van de geselecteerde periode
  EN de statussen worden bijgewerkt na afloop
  EN de gebruiker ziet het resultaat (hoeveel succesvol, hoeveel nog steeds failed)
```

---

## REQ-SEC-001: Autorisatie en beveiliging

**Beschrijving:** Alle endpoints zijn beveiligd conform ADR-005.

**Prioriteit:** P0-must

### Scenario 7.1 — Ongeautoriseerde posting-trigger

```
GEGEVEN een niet-ingelogde gebruiker of een gebruiker zonder toegang tot hrmq
WANNEER POST /api/payroll-gl-posts/post-run wordt aangeroepen
DAN retourneert de API HTTP 401 of HTTP 403
  EN wordt geen PayrollGLPost aangemaakt of bijgewerkt
```

### Scenario 7.2 — Admin-only configuratie

```
GEGEVEN een ingelogde gebruiker die GEEN Nextcloud-admin is
WANNEER POST /api/settings (shillinq-configuratie) wordt aangeroepen
DAN retourneert de API HTTP 403
  EN wordt de configuratie NIET gewijzigd
```

### Scenario 7.3 — Geen PII in API-responses

```
GEGEVEN een PayrollGLPost-record met een foutmelding die details over de API-call bevat
WANNEER GET /api/payroll-gl-posts/{id} wordt aangeroepen
DAN bevat de response GEEN ruwe API-response-body van shillinq
  EN bevat de response GEEN interne serverpaden of stacktraces
  EN is de errorMessage een statische, generieke tekst
```
