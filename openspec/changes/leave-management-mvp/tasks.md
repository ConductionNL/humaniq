# Tasks: Leave Management MVP

---

## 0. Deduplication Check

- [ ] 0.1 Zoek naar bestaande verlof-gerelateerde services of schema's in `openspec/specs/` en `lib/Service/` — documenteer bevindingen (verwacht: geen overlap, dit is een net-nieuwe module)
- [ ] 0.2 Verifieer dat de entiteiten `LeaveType`, `LeavePolicy`, `LeaveBalance`, `LeaveRequest`, `LeaveAccrualLog` nog niet bestaan als OpenRegister-schema in `lib/Settings/hrmq_register.json`
- [ ] 0.3 Controleer of OpenRegister al een `x-openregister-lifecycle`-engine biedt die de workflow `concept → ingediend → goedgekeurd/afgewezen/ingetrokken` kan afhandelen — zo ja, gebruik die; zo nee, documenteer de engine-gap in `design.md`

---

## 1. Schema-register: entiteiten en declaratieve logica

- [ ] 1.1 Voeg schema `LeaveType` toe aan `lib/Settings/hrmq_register.json` met alle properties uit `design.md` (name, description, category, isStatutory, defaultHoursPerYear, accrualMethod, carryOverMaxHours, isPaidOutOnTermination, requiresApproval)
- [ ] 1.2 Voeg schema `LeavePolicy` toe met relations naar `LeaveType` (OpenRegister-relatie, geen foreign key conform ADR-001)
- [ ] 1.3 Voeg schema `LeaveBalance` toe met `x-openregister-calculations` voor `remainingHours = accruedHours + carriedOverHours - usedHours`
- [ ] 1.4 Voeg schema `LeaveRequest` toe met `x-openregister-lifecycle`:
  - Toestane transities: `concept→ingediend`, `ingediend→goedgekeurd` (requires: `LeaveRequestGuard`), `ingediend→afgewezen`, `ingediend→concept`, `goedgekeurd→ingetrokken`, `afgewezen→concept`
  - `rejectionReason` verplicht op transitie `afwijzen`
- [ ] 1.5 Voeg schema `LeaveAccrualLog` toe als append-only log (geen lifecycle, geen update/delete-rechten)
- [ ] 1.6 Declareer `x-openregister-aggregations` voor "openstaande aanvragen per leidinggevende" (count van `LeaveRequest` per `status=ingediend`, gegroepeerd op manager/afdeling)
- [ ] 1.7 Declareer `x-openregister-notifications`:
  - `aanvraag-ingediend`: `LeaveRequest` status → `ingediend` → notificeer leidinggevende
  - `aanvraag-goedgekeurd`: status → `goedgekeurd` → notificeer medewerker
  - `aanvraag-afgewezen`: status → `afgewezen` → notificeer medewerker (include `rejectionReason`)
- [ ] 1.8 Declareer `x-openregister-widgets` voor het dashboard: `CnStatsBlock` "Openstaande aanvragen", "Mijn verlofsaldo", "Opbouw dit jaar"

---

## 2. Seed data

- [ ] 2.1 Voeg 5 `LeaveType`-seed objecten toe aan `lib/Settings/hrmq_register.json` via `@self`-envelope conform `design.md` (vakantie-wettelijk, vakantie-bovenwettelijk, bijzonder-huwelijk, bijzonder-overlijden, ouderschapsverlof)
- [ ] 2.2 Voeg 3 `LeavePolicy`-seed objecten toe (cao-gemeenten-wettelijk-2025, cao-gemeenten-bovenwettelijk-2025, cao-zorg-wettelijk-2025)
- [ ] 2.3 Voeg 4 `LeaveBalance`-seed objecten toe voor fictieve medewerkers (jan-de-vries, maria-janssen, pieter-van-den-berg)
- [ ] 2.4 Voeg 4 `LeaveRequest`-seed objecten toe met variatie in status (goedgekeurd, ingediend, concept, afgewezen)
- [ ] 2.5 Voeg 3 `LeaveAccrualLog`-seed objecten toe (periode 2025-01 voor drie medewerkers)
- [ ] 2.6 Verifieer idempotentie: importeer `hrmq_register.json` twee keer via `ConfigurationService::importFromApp()` — geen duplicaten aangemaakt

---

## 3. Backend: Lifecycle Guard

- [ ] 3.1 Maak `lib/Lifecycle/LeaveRequestGuard.php` aan — implementeert de `requires`-interface van `x-openregister-lifecycle`
- [ ] 3.2 Guard controleert bij transitie `ingediend→goedgekeurd`: haal LeaveBalance op voor de combinatie (employee, leaveType, year) en vergelijk `remainingHours` met `totalHours` van de aanvraag
- [ ] 3.3 Guard gooit exception bij onvoldoende saldo; lifecycle-engine vertaalt dit naar HTTP 422
- [ ] 3.4 Voeg `@spec openspec/changes/leave-management-mvp/tasks.md#task-3` PHPDoc-tag toe aan de guardklasse (ADR-003)
- [ ] 3.5 Schrijf PHPUnit-tests voor guard (`tests/Unit/Lifecycle/LeaveRequestGuardTest.php`): voldoende saldo (doorgaan), onvoldoende saldo (blokkeren), saldo exact gelijk (doorlaten)

---

## 4. Backend: Controllers

- [ ] 4.1 Maak `lib/Controller/LeaveTypeController.php` aan — CRUD met `#[AuthorizedAdminSetting]` op mutatie-methoden, `#[NoAdminRequired]` op GET
- [ ] 4.2 Maak `lib/Controller/LeavePolicyController.php` aan — CRUD admin-only + overlaps-validatie (REQ-LVP-002)
- [ ] 4.3 Maak `lib/Controller/LeaveBalanceController.php` aan — GET gefilterd op `IUserSession` (medewerker ziet alleen eigen saldo; admin ziet alles); GET `/termination-payout` endpoint (REQ-LSA-005)
- [ ] 4.4 Maak `lib/Controller/LeaveRequestController.php` aan — POST (medewerker), GET gefilterd, POST `/transition` voor lifecycle-overgangen
- [ ] 4.5 Implementeer per-object autorisatie in alle mutatie-endpoints: fetch object → check `employee === $currentUser->getUID()` OF manager-relatie OF `isAdmin` → throw `OCSForbiddenException` indien geen match (ADR-005)
- [ ] 4.6 Registreer alle routes in `appinfo/routes.php` conform patroon `/api/{resource}` (ADR-002); specifieke routes vóór wildcard `{slug}`
- [ ] 4.7 Voeg `@spec` PHPDoc-tags toe aan alle controllers
- [ ] 4.8 Schrijf PHPUnit-tests voor elke controller: happy path, 403-pad (verkeerde gebruiker), 400-pad (invalid input)

---

## 5. Backend: Opbouw-achtergrondtaak

- [ ] 5.1 Maak `lib/Job/LeaveAccrualJob.php` aan als `QueuedJob`
- [ ] 5.2 Taak doorloopt actieve medewerkers met een verlofbeleid, berekent de maandelijkse opbouw (annualHours / 12), verhoogt `accruedHours` op LeaveBalance en schrijft een `LeaveAccrualLog`-entry
- [ ] 5.3 Idempotentie-check: controleer op bestaand `LeaveAccrualLog`-object voor dezelfde (employee, leaveType, period) combinatie — sla over als al bestaat (REQ-LSA-003)
- [ ] 5.4 Registreer de taak in `lib/AppInfo/Application.php` als maandelijkse job
- [ ] 5.5 Schrijf PHPUnit-test: idempotent re-run, medewerker zonder beleid overslaan, correcte berekening 40u/week × 1/12

---

## 6. Backend: Jaarwisseling en Uitbetaling

- [ ] 6.1 Maak `lib/Job/LeaveCarryOverJob.php` aan — draait op 1 januari, berekent overdracht per medewerker per verloftype conform `carryOverMaxHours`, maakt LeaveBalance voor het nieuwe jaar aan met `carriedOverHours` (REQ-LSA-004)
- [ ] 6.2 Implementeer `/api/leave-balances/termination-payout` in `LeaveBalanceController` — berekent uitbetaling voor verloftypes met `isPaidOutOnTermination=true` op basis van uurloon uit Employee-entiteit (REQ-LSA-005)
- [ ] 6.3 Schrijf PHPUnit-tests: overdracht binnen maximum, overdracht afgekapt, uitbetalingsberekening inclusief uitsluiting niet-uitbetaalbare types

---

## 7. Frontend: Manifest en routing

- [ ] 7.1 Voeg drie pagina-entries toe aan `src/manifest.json`: `leave-requests` (type: `index`), `leave-balances` (type: `index`), `leave-types` (type: `index`) conform ADR-024
- [ ] 7.2 Voeg bijbehorende named routes toe aan de Vue-router: `/verlofaanvragen`, `/verlofsaldo`, `/verloftypes` (history-mode, props via arrow function)
- [ ] 7.3 Voeg navigatie-items toe aan `MainMenu.vue` voor de drie verlofpagina's
- [ ] 7.4 Verifieer manifest-schema via `npm run check:manifest`

---

## 8. Frontend: Verlofaanvragen pagina

- [ ] 8.1 Maak `src/views/LeaveRequestsView.vue` aan via `CnIndexPage` + `useListView`
- [ ] 8.2 Implementeer status-filter via `CnFilterBar` (alle, concept, ingediend, goedgekeurd, afgewezen, ingetrokken)
- [ ] 8.3 Registreer `leave-request` objectStore via `createObjectStore` in `src/store/store.js` met `lifecyclePlugin` en `auditTrailsPlugin`
- [ ] 8.4 Detail-view `src/views/LeaveRequestDetailView.vue`: toon aanvraagdetails, gekoppeld verlofsaldo, transitie-knoppen (afhankelijk van status en rol) en `CnObjectSidebar` (audit-trail, notities)
- [ ] 8.5 Maak `src/modals/LeaveRequestFormModal.vue` aan voor aanmaken/bewerken (eigen modal file conform ADR-004)
- [ ] 8.6 Transitie-knoppen: "Indienen" (medewerker), "Goedkeuren" / "Afwijzen" (leidinggevende/HR — toon afwijzingsreden-dialog), "Intrekken" (medewerker)
- [ ] 8.7 Alle `await store.action()` calls gewrapped in `try/catch` met gebruikersfeedback (ADR-015)
- [ ] 8.8 Alle user-visible strings via `this.t('hrmq', '...')`, Dutch translations in `l10n/nl.json`

---

## 9. Frontend: Verlofsaldo pagina

- [ ] 9.1 Maak `src/views/LeaveBalanceView.vue` aan via `CnIndexPage` — filtert op huidige gebruiker (tenzij admin/HR)
- [ ] 9.2 Toon per saldo-rij: verloftype, opgebouwd, opgenomen, overgedragen, resterend
- [ ] 9.3 Voeg jaar-filter toe (dropdown, default huidig jaar)
- [ ] 9.4 Admin/HR-view: toon filter op medewerker

---

## 10. Frontend: Verloftypes pagina (admin)

- [ ] 10.1 Maak `src/views/LeaveTypesView.vue` aan via `CnIndexPage` — toon naam, categorie, opbouwmethode, uitbetalingsrecht
- [ ] 10.2 Maak `src/views/LeavePoliciesView.vue` aan — toon gekoppeld verloftype, jaaruren, CAO-referentie, geldigheidsperiode
- [ ] 10.3 Maak `src/modals/LeaveTypeFormModal.vue` en `src/modals/LeavePolicyFormModal.vue` aan als eigen modal-bestanden
- [ ] 10.4 Pagina's uitsluitend zichtbaar voor admins — verberg navigatie-items voor niet-admins (frontend), valideer op backend

---

## 11. Dashboard-widgets

- [ ] 11.1 Verifieer dat de `x-openregister-widgets` declaraties uit Task 1.8 correct worden geladen door `CnDashboardPage`
- [ ] 11.2 Test de widgets op het dashboard: "Openstaande aanvragen" toont correcte count, "Mijn verlofsaldo" toont saldo huidige gebruiker

---

## 12. Kwaliteitscontroles

- [ ] 12.1 Voeg SPDX-headers toe aan alle nieuwe PHP-bestanden (`// SPDX-License-Identifier: EUPL-1.2`) en Vue-bestanden (`<!-- SPDX-License-Identifier: EUPL-1.2 -->`)
- [ ] 12.2 Voer `composer check:strict` uit — alle tests groen
- [ ] 12.3 Voer `npm run lint` uit — nul fouten
- [ ] 12.4 Voer `npm run check:manifest` uit — manifest geldig
- [ ] 12.5 Smoke-test: roep elk nieuw API-endpoint aan met `curl`, verifieer HTTP-statuscode en response-shape
- [ ] 12.6 Test foutpaden: 403 (verkeerde gebruiker), 422 (onvoldoende saldo), 400 (invalid input), 409 (overlap verlofbeleid)
- [ ] 12.7 Controleer dat `$e->getMessage()` nergens in een JSONResponse terecht komt (statische foutmeldingen conform ADR-015)
