---
kind: code
depends_on: [employee-master]
---

# Leave Management MVP (vakantie, accrual, balance)

## Why

Nederlandse organisaties zijn wettelijk verplicht minimaal 4× het weekurenaantal aan vakantiedagen op te bouwen (BW art. 7:634). Bovenop dit wettelijk minimum geldt per CAO een bovenwettelijk deel met eigen opbouwregels. hrmq beschikt nog niet over verlofregistratie: medewerkers en HR-medewerkers werken nu met e-mail of losse spreadsheets, wat leidt tot foutieve saldo's, uitbetalingsgeschillen bij uitdiensttreding en ontbrekende audit-trails. Twintig marktoplossingen (ADP, AFAS, Loket, Employes, Exact, Gusto, e.a.) bieden dit als kernmodule aan; ontbreken ervan maakt hrmq onverkoopbaar als volledig HR-pakket.

## What Changes

- Verloftypes met categorie-indeling (wettelijk / bovenwettelijk / bijzonder verlof)
- Verlofbeleid per CAO: jaarlijkse uren, opbouwperiode, maximale overdracht en uitbetalingsregels
- Automatische maandelijkse opbouw op basis van het verlofbeleid van de medewerker
- Saldo per medewerker per verloftype per kalenderjaar, inclusief overdracht en uitbetalingsberekening
- Aanvraag/goedkeuring workflow: concept → ingediend → goedgekeurd/afgewezen/ingetrokken
- Dashboard-widgets: openstaande aanvragen, saldo-overzicht, maandelijkse opbouwtrend

## Capabilities

### New Capabilities

- `leave-types`: Verloftypes (wettelijk, bovenwettelijk, bijzonder) en CAO-beleidsregels aanmaken en beheren
- `leave-requests`: Verlofaanvraag indienen door medewerker en accorderen door leidinggevende of HR
- `leave-balance`: Verlofsaldo per medewerker en verloftype bijhouden, opbouw verwerken, uitbetaling bij uitdiensttreding berekenen

## Impact

- `lib/Settings/hrmq_register.json`: schemas LeaveType, LeavePolicy, LeaveBalance, LeaveRequest, LeaveAccrualLog met `x-openregister-lifecycle` (LeaveRequest), `x-openregister-calculations` (LeaveBalance.remainingHours), `x-openregister-aggregations` (openstaande aanvragen per leidinggevende), `x-openregister-notifications` (aanvraag ingediend, beslissing), seed data
- `src/manifest.json`: verlofpagina's toevoegen (verloftypes, aanvragen, saldo)
- `lib/Lifecycle/LeaveRequestGuard.php`: lifecycle guard — saldo-controle vóór goedkeuring
- `lib/Job/LeaveAccrualJob.php`: maandelijkse opbouw-achtergrondtaak (fallback wanneer n8n niet beschikbaar)
- `appinfo/routes.php`: routes voor LeaveType, LeavePolicy, LeaveBalance, LeaveRequest controllers
