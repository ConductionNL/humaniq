---
status: draft
---
# IKB Rijk & Gemeenten — Individueel Keuzebudget Engine

## Placement & Information Architecture

**Placement type:** `SUB_PAGE` — Sub-page beneath a top-level menu entry. Renders as a page inside the parent surface (usually reachable via a router child route or a tab on the parent index page).

**Lives at:** Salarissen › IKB

**Rationale:** IKB-budget.  
_Source: /tmp/ia-procest-hrmq.md_

> **Implementation note for builders:** Respect the placement above. Do not promote this spec to a top-level menu item, sub-page, or new route unless the placement type explicitly says so. If the placement is `DETAIL_TAB`, `WIDGET`, `ACTION`, `SETTING`, or `INFRA`, the feature must NOT introduce a new entry in the app sidebar. When in doubt, ask before creating a new top-level surface.

## Purpose

The `ikb-rijk-gemeenten` app implements the Individueel Keuzebudget (IKB) engine for Dutch public-sector employees, covering both the CAO Rijk (central government, 16.37% accrual) and the CAO Gemeenten (municipalities, 17.5% accrual). The IKB is a personal budget that every civil servant accrues monthly on top of their gross salary, which they may spend ("uitruilen") on a curated catalog of fiscal and non-fiscal benefits: extra take-home salary, additional leave days, accredited training, a bike-of-the-business scheme (fiets-van-de-zaak), fitness subscriptions, extended commuting allowance, union dues, or simply leave the balance to be paid out in December as a cash supplement.

Without dedicated tooling, HR teams currently track IKB in spreadsheets, leading to (a) miscalculated accruals when employees move between scales or have parental leave, (b) missed fiscal opportunities because employees do not understand the WKR (Werkkostenregeling) implications of each uitruil option, and (c) end-of-year payroll surprises when the cash-out is larger than budgeted. This app gives every medewerker a transparent, real-time IKB dashboard, lets them simulate uitruil choices before committing, and produces deterministic payroll mutations that flow into `payroll-engine-nl`.

The app must serve two CAO variants out of the box, support per-organisation overrides (e.g. a gemeente that adds a local fitness vendor to the catalog), and remain auditable for the AVG and tax authority for at least seven years. Critical design constraints: it must work for organisations from 50 to 200,000 medewerkers, support payroll runs at multiple cadences (Rijk centraal vs gemeentes met eigen P-Direkt-achtige providers), and gracefully handle CAO-overgangsregelingen wanneer medewerkers herindelen tussen Rijk en gemeenten of tussen gemeenten onderling. The architecture deliberately keeps IKB-administratie en payroll-uitvoering gescheiden: deze app is autoritair voor het IKB-saldo en de uitruil-keuzes, payroll-engine-nl voert de financiële afwikkeling deterministisch uit.

## Data Model

Five core schemas in the `hrmq` register:

- `IkbAccount` (one per employee per calendar year): `employeeId`, `cao` (rijk|gemeenten|custom), `year`, `openingBalance`, `currentBalance`, `accrualPercentage`, `pensionableBase`, `lastAccrualDate`, `status` (active|closed|frozen).
- `IkbAccrual` (monthly ledger entry): `accountId`, `period` (YYYY-MM), `pensionableSalary`, `bovenwettelijkeComponenten` (vakantietoeslag, eindejaarsuitkering, toelagen), `accrualAmount`, `runId`, `payrollMutationRef`, `lockedAt`.
- `IkbChoice` (an uitruil request): `accountId`, `catalogItemId`, `requestedAmount`, `requestedDate`, `effectiveDate`, `status` (concept|submitted|approved|rejected|reversed|settled), `fiscalImpact` (gross-to-net delta), `wkrCategory` (gericht_vrijgesteld|nihilwaardering|vrije_ruimte|belast), `supportingDocumentIds[]`, `approverId`, `decisionRationale`.
- `IkbCatalogItem`: `code`, `cao`, `category` (salaris|verlof|opleiding|fiets|fitness|woon-werk|vakbond|uitkering), `displayName_nl`, `displayName_en`, `minAmount`, `maxAmount`, `requiresApproval`, `requiresDocument`, `wkrCategory`, `fiscalRule` (reference to a calculation strategy), `validFrom`, `validUntil`, `vendorId` (optional).
- `IkbPayout` (year-end residual): `accountId`, `year`, `residualAmount`, `grossAmount`, `netAmount`, `loonheffing`, `payrollMutationRef`, `payoutDate`.

Relationships: `IkbAccount` is owned by an `Employee` (from `employee-master`); `IkbChoice` may reference an `Order` in `fiets-van-de-zaak` or a `TrainingEnrollment` in `training-opleidingen`. Every state transition writes an immutable `IkbAuditLog` entry (actor, before, after, IP, timestamp).

## Requirements

**REQ-001: Monthly accrual run**
- GIVEN an active `IkbAccount` for an employee with a pensionable salary on the first day of the month, WHEN the monthly accrual job runs on the 1st of the following month, THEN a new `IkbAccrual` is created with `accrualAmount = (pensionableSalary + bovenwettelijkeComponenten) * accrualPercentage / 12` and `currentBalance` is incremented atomically.
- GIVEN an employee who joined or left mid-month, WHEN accrual runs, THEN the amount is prorated by calendar days worked and the rationale is stored in `IkbAccrual.metadata.prorationBasis`.
- GIVEN an employee on unpaid leave for the full period, WHEN accrual runs, THEN a zero-amount `IkbAccrual` is created with `metadata.suppressionReason = "unpaid_leave"` so the gap is auditable.

**REQ-002: CAO-aware accrual percentage**
- GIVEN the CAO Rijk is selected, WHEN an `IkbAccount` is opened, THEN `accrualPercentage` defaults to 16.37 and the breakdown (8% vakantietoeslag, 6.4% eindejaarsuitkering, 1.97% levensloopbijdrage equivalent) is exposed for transparency.
- GIVEN the CAO Gemeenten is selected, WHEN an `IkbAccount` is opened, THEN the percentage defaults to 17.05% (8% vakantietoeslag, 6.75% eindejaarsuitkering, 1.5% bovenwettelijk verlof, 0.8% levensloop) and totals match the LOGA-publicatie.
- GIVEN a CAO renegotiation publishes a new percentage, WHEN an HR admin updates the `IkbCaoConfig` with a `validFrom`, THEN existing accruals are not retroactively recalculated; only future runs use the new value.

**REQ-003: Uitruil catalog & simulation**
- GIVEN an employee opens the IKB dashboard, WHEN they pick a catalog item and an amount, THEN a real-time simulation shows gross deduction, net effect on take-home pay, loonheffingstabel impact, and any WKR consequence for the employer, without persisting anything.
- GIVEN an employee submits an uitruil that exceeds their current balance, WHEN they click submit, THEN the request is rejected with a localized error citing the available balance and earliest date the balance would suffice based on projected accruals.
- GIVEN a catalog item with `requiresDocument = true` (e.g. opleidingsfactuur), WHEN no document is attached, THEN submission is blocked and the UI highlights the missing field.

**REQ-004: Approval workflow**
- GIVEN an `IkbChoice` is submitted for a catalog item with `requiresApproval = true`, WHEN it is persisted, THEN a `Task` is created for the configured approver (line manager by default, HR for opleidingen > €2500) and a notification is dispatched via the Nextcloud notifications app.
- GIVEN an approver rejects with a `decisionRationale`, WHEN the rejection is saved, THEN the employee receives a notification, the rationale is visible in their dashboard history, and no payroll mutation is generated.
- GIVEN an approval is not actioned within 14 calendar days, WHEN the daily reminder job runs, THEN the manager is reminded and the HR business partner is CC'd after day 21.

**REQ-005: Fiscal & WKR calculation**
- GIVEN an uitruil for "extra salaris", WHEN approved, THEN the gross amount is added to the next payroll run as `bijzonder tarief` with the applicable loonheffingspercentage looked up from the employee's `Loonheffingstabel`.
- GIVEN an uitruil for a fiets-van-de-zaak under €749, WHEN approved, THEN it is classified as `wkrCategory = nihilwaardering` and no employer WKR impact is logged; for amounts above the threshold the surplus is added to `vrije ruimte` consumption.
- GIVEN total WKR `vrije ruimte` consumption for the calendar year exceeds 1.92% of the wage sum, WHEN a new uitruil would push it further, THEN the HR controller receives a warning in the IKB admin dashboard before approval is allowed.

**REQ-006: Verlof uitruil (purchase of leave)**
- GIVEN an employee chooses to buy extra leave with IKB budget, WHEN the choice is approved, THEN their leave balance in `verlof-administratie` is incremented and the cost is `hourlyRate * hoursPurchased` with the hourly rate frozen at the moment of approval.
- GIVEN an employee tries to buy more than the CAO maximum (Rijk: 22 days, Gemeenten: per local regeling), WHEN they submit, THEN the request is blocked with a reference to the applicable article.
- GIVEN purchased leave is not consumed by 31 December, WHEN the year closes, THEN the unused portion is automatically converted back to IKB balance and rolled into the cash payout per REQ-008.

**REQ-007: Quarterly and annual uitruil moments**
- GIVEN the organisation has configured a quarterly uitruil model, WHEN an employee submits between Q-windows, THEN the choice is queued with `effectiveDate` set to the next window opening and is editable until that date.
- GIVEN an "always open" model is configured, WHEN a choice is submitted, THEN it is processed in the next available payroll run regardless of quarter.
- GIVEN a freeze period is configured (e.g. last week of December), WHEN an employee submits a choice within the freeze, THEN submission is blocked with a clear notice of the next available window.

**REQ-008: Year-end residual payout**
- GIVEN an `IkbAccount` has a positive `currentBalance` on the cut-off date (configurable, default 30 November), WHEN the year-end job runs, THEN an `IkbPayout` is created and a gross-to-net mutation is sent to `payroll-engine-nl` for the December run with bijzonder tarief.
- GIVEN an employee leaves the organisation mid-year, WHEN their offboarding is finalised in `employee-master`, THEN the residual IKB is paid out in the final payroll run and the `IkbAccount.status` is set to `closed`.
- GIVEN an employee transfers between two participating organisations using the same instance, WHEN the transfer is confirmed, THEN the residual balance is portable if both CAOs allow it; otherwise it is paid out and a new `IkbAccount` is opened.

**REQ-009: Employee self-service dashboard**
- GIVEN an employee opens the IKB page in `employee-self-service-mkb`, WHEN data loads, THEN they see current balance, projected end-of-year balance, accrual history (12 months), submitted choices, and a CTA to "simuleer een nieuwe keuze".
- GIVEN an employee uses a screen reader, WHEN they navigate the dashboard, THEN all amounts, tooltips, and CTAs are exposed with WCAG 2.2 AA-compliant labels in Dutch and English.
- GIVEN an employee downloads their IKB-jaaroverzicht, WHEN the PDF is generated, THEN it contains all accruals, all uitruil-keuzes with status, year-end payout, and is signed (PAdES-B-T) for archival.

**REQ-010: Audit & retention**
- GIVEN any IKB-related state change, WHEN it is persisted, THEN an `IkbAuditLog` entry is written with actor, IP, before-state, after-state, and is immutable for 7 calendar years per AWR retention.
- GIVEN an AVG-verzoek (data access request) is opened, WHEN the export is generated, THEN the response contains the full IKB history including audit logs, choices, accruals, and supporting documents in a machine-readable JSON bundle.
- GIVEN a tax inspection requests the IKB-administratie for a specific year, WHEN the export is generated, THEN it follows the SBR-format expected by the Belastingdienst and includes a manifest hash for tamper-evidence.

## Standards & Sources

- **CAO Rijk 2024-2025** (artikelen 4.4 t/m 4.7, IKB) — accrual base, percentage, uitruilcatalogus, Rijksbreed verplicht.
- **CAO Gemeenten** (hoofdstuk 3, paragraaf 3.28-3.32) — accrual, fiscale uitruil, lokale aanvullingen.
- **Wet op de loonbelasting 1964** + **Uitvoeringsregeling loonbelasting 2011** — bijzonder tarief, WKR.
- **Werkkostenregeling** (Belastingdienst handboek loonheffingen, hoofdstuk 8) — vrije ruimte, gerichte vrijstellingen, nihilwaarderingen.
- **AVG / UAVG** — grondslag art. 6 lid 1 sub b (uitvoering arbeidsovereenkomst).
- **AWR art. 52** — fiscale bewaarplicht 7 jaar.
- **NEN-EN 16931** — facturering voor opleidings-uitruil.
- **LOGA-circulaires** — actuele percentages voor gemeenten.

## Cross-app Integration

- **payroll-engine-nl**: receives `IkbAccrual` mutations (informational, no payment), `IkbChoice` settlements (gross-up or net-down) and `IkbPayout` year-end items. Bidirectional: payroll publishes the pensionable base each month.
- **employee-master**: source of truth for employment, scale, contract hours, CAO assignment; subscribes to lifecycle events (hire, terminate, scale-up).
- **employee-self-service-mkb**: hosts the employee dashboard widget and simulation UI; depends on this app for read-only API.
- **verlof-administratie** (planned): consumes "extra verlof" uitruil to credit leave balance.
- **training-opleidingen** (planned): links opleidings-uitruil to a course enrolment and invoice.
- **fiets-van-de-zaak** (planned): registers the bike order and supplier; the WKR classification flows back here.
- **openconnector**: Belastingdienst SBR submission, vendor catalog sync (Bike-shop providers, fitness chains).
- **docudesk**: stores supporting documents (facturen, akkoordverklaring) with retention policy linked to `IkbChoice.id`.

## Target Users

1. **Medewerker Rijk/gemeente** (10k–200k per instance) — checks balance monthly, simulates uitruil 2-4×/year, downloads jaaroverzicht in January. Needs a fast mobile-friendly dashboard with clear gross-to-net previews and no jargon; success criterion is that more than 80% of medewerkers actively use IKB rather than letting it default to year-end payout.
2. **Lijnmanager** — approves verlof-uitruil and opleidings-uitruil above thresholds; needs a clean inbox with batch approve, contextual policy hints (max 22 verlofdagen Rijk, opleidingsbudget-grens), and one-click rejection with template-rationale to keep approval load under 5 minutes per week.
3. **HR-medewerker** — configures catalog, monitors WKR-headroom, handles exceptions (parental leave proration, mid-year transfers, herindeling tussen Rijks-onderdelen); manages CAO-percentage-revisies and rolls out catalogue-updates per kwartaal.
4. **HR-controller / Loonadministrateur** — runs end-of-year jobs, reconciles with payroll, prepares Belastingdienst-export, owns the SBR-aangifte koppeling en valideert dat de WKR-vrije-ruimte niet wordt overschreden voor 31 december.
5. **OR-lid / Vakbondsvertegenwoordiger** — read-only access to anonymised dashboards to verify CAO-conform implementation, attendance van uitruil-keuzes, en gelijke behandeling tussen medewerkers met en zonder opleidingstrajecten.
6. **Auditor (intern of Auditdienst Rijk)** — accesses immutable audit logs and SBR-exports for fiscale en rechtmatigheidscontroles; gebruikt jaarcyclus rapporten voor rechtmatigheids-verklaring bij de gemeentelijke jaarrekening.
7. **CAO-onderhandelaar / werkgevers-secretariaat** — bekijkt geaggregeerde uitruil-statistieken om voorstellen voor catalogus-uitbreiding of percentage-aanpassing te onderbouwen tijdens CAO-rondes.
8. **Externe payroll-provider** (waar uitbesteed) — ontvangt deterministische mutatie-instructies via API, geen directe UI-toegang, met SLA op bevestigingstijd.
