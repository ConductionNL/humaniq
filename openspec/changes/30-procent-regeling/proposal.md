---
status: approved
---

# 30%-regeling Administratie (Expatregeling) — Proposal

## Why

Dutch employers with international talent face severe administrative and fiscal risks under the "30%-regeling" (art. 31a Wet LB 1964). A single compliance error triggers back-taxes, interest, and penalties for both employer and employee. Current manual workflows (spreadsheets, email tracking, PDF storage) create blind spots — employers miss salary thresholds, WNT-norm overages, and expiry dates until the Belastingdienst audit reveals the damage.

Without system-wide automation, this remains a high-risk, labor-intensive process: 30+ data points per employee, annual re-validation, drastic penalties for false-positive applications (up to 5+ years of back-payments), and a regulatory landscape shifting under pressure (phaseout rules changing in 2024, partial-non-resident changes in 2027, further changes pending in 2026 Belastingplan).

## What Changes

- **Unified 30%-registration & lifecycle** — HR captures geldige Belastingdienst-beschikkingen; system auto-validates looptijd, salarisdrempel, WNT-norm, and partial-non-resident eligibility per applicable fiscal year.
- **Automated monthly loon-impact** — Payroll engine applies correct 30%-percentage (30/20/10 afbouw per 2024+ rules), WNT-aftopping, and parttime-correctie without manual calculation.
- **Annual drempel-toetsing** — System auto-checks if FTE-corrected fiscal loon met €46.660+ threshold (or €35.468 for jong-onderzoekers); triggers intrekking + correctieaangifte if threshold missed.
- **Compliance alerts & escalation** — HR warned 180 days before expiry, daily YTD-drempel risk tracking, WNT-marge alerts; overdue actions escalate to administratie-owner.
- **Bewijspakket-export** — On-demand PDF combining gescande beschikking, toetsings-records, loon-impacts, and intrekkingen voor Belastingdienst-controles.
- **Journaalpost & correctieaangifte** — Automatic accounting entries and loonheffingen-correction filings for intrekking scenarios.

## Capabilities

### New Capabilities

- `Beschikking30-registration`: HR registers and stores Belastingdienst-beschikkingen with validation (looptijd ≤5 jaar voor nieuwe gevallen, beschikkingsdatum ≤ 4 maanden sinds aanvraag per wettelijke termijn).
- `Beschikking30-loon-impact`: Payroll engine calculates 30%-vergoeding per medewerker per loonperiode, applying juiste percentage (30/20/10 per afbouwregel), WNT-aftopping, and parttime-correctie.
- `Beschikking30-drempel-toetsing`: Annual (or interim) check that fiscal loon excl. 30% met threshold; auto-intrekking if failed with correctieaangifte voorbereiding.
- `Beschikking30-expiry-alerts`: 180-day and 30-day escalation sequence before looptijd-einde.
- `Beschikking30-ytd-risk-tracking`: Real-time YTD-loon projection against drempel; warns if within 5% onder.
- `Beschikking30-wnt-aftopping`: Max 30% over WNT-norm portion only; registers aftopping-bedrag per loonperiode.
- `Beschikking30-partial-non-resident`: Tracks PNR-keuze (2024-2026 only); flags deprecation warning per 2027.
- `Beschikking30-intrekking-workflow`: Handmatige intrekking met terugwerkende kracht; auto-generates correctieaangifte loonheffingen.
- `Beschikking30-bewijspakket`: On-demand export (PDF) of all related records per administratie/medewerker voor Belastingdienst-controles.

### Modified Capabilities

- `payroll-engine-nl`: Integrated loon-impact calculation; calls Beschikking30-loon-impact-rule during each loonrun.
- `employee-master`: Contributes geboortedatum, master-diploma (jong-onderzoeker-category), eerdere woonadressen (150-km-toetsing).
- `multi-administratie`: Scoped beschikkingen per administratie; inter-company detachering remains administratie-locked.

## Impact

**Data layer** (`lib/Data/`):
- `Beschikking30Entity`, `Beschikking30PeriodeEntity`, `Beschikking30ToetsingEntity`, `Beschikking30IntrekkingEntity`, `Beschikking30LoonImpactEntity`, `Beschikking30AlertConfigEntity`
- Relations: medewerker → beschikking (1:N), beschikking → periode/toetsing/intrekking/loon-impact (1:N)

**Service layer** (`lib/Service/`):
- `Beschikking30Service`: registration, validation, lifecycle
- `Beschikking30ToetsingService`: annual drempel-check, intrekking-trigger
- `Beschikking30LoonImpactService`: calculate monthly 30%-vergoeding
- `Beschikking30AlertService`: expiry/drempel/wnt alerts
- `Beschikking30ExportService`: bewijspakket PDF generation
- `Beschikking30IntrekkingService`: intrekking + correctieaangifte voorbereiding

**Backend API** (`lib/Controller/`, `lib/Api/`):
- REST routes for CRUD beschikking, toetsing, intrekking
- Background job for jaarlijkse toetsing (January + mutatie-trigger)
- Scheduled job for expiry-alerts (monthly on first business day)
- Webhook integration: loopaangifte-digipoort (intrekking-filing), journaalpost-export (boekhoudpakket)

**Frontend** (`src/components/`, `src/views/`):
- Beschikking-registration form (ajax-driven validator for looptijd/threshold)
- Medewerker-detail sidebar panel showing actieve beschikking, expiry-date, YTD-loon-progress
- Administratie-dashboard widget: 30%-medewerkers count, upcoming-expiry list, at-risk list
- Self-service: expat-medewerker sees PNR-keuze, toetsings-uitkomsten, jaarlijkse drempel-status

**Integrations**:
- `payroll-engine-nl`: Rule evaluator called per loonrun per medewerker
- `employee-master`: Lookups for geboortedatum, diplomainfo, eerdere woonadressen
- `journaalpost-export`: Sends correctieboeking on intrekking
- `loonaangifte-digipoort`: Files correctieaangifte loonheffingen
- `notification-engine`: Mail/in-app alerts for HR/compliance
- `audit-trail-payroll`: Immutable logging of beschikking-mutations, toetsingen, intrekkingen
- `document-vault`: 7-year retention of gescande beschikkingen

---

**Ready to design and spec.**
