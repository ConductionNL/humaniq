---
status: draft
---

# Payroll Core (bruto-netto, loonheffing 2026)

## Placement & Information Architecture

**Placement type:** `SUB_PAGE` — Sub-page beneath a top-level menu entry. Renders as a page inside the parent surface (usually reachable via a router child route or a tab on the parent index page).

**Lives at:** Loon / Loonruns

**Rationale:** De Loonruns sub-page is de werkruimte waar payroll-core wordt aangeroepen + uitkomsten zichtbaar zijn. Engine zelf is backend (INFRA), maar de run-UI is een eigen page.  
_Source: manual tag 2026-05-24_

> **Implementation note for builders:** Respect the placement above. Do not promote this spec to a top-level menu item, sub-page, or new route unless the placement type explicitly says so. If the placement is `DETAIL_TAB`, `WIDGET`, `ACTION`, `SETTING`, or `INFRA`, the feature must NOT introduce a new entry in the app sidebar. When in doubt, ask before creating a new top-level surface.

## Purpose

Bruto-netto berekening: loonheffing tabellen 2026 (wit/groen, bijzondere beloningen), ZVW premie, WW/WIA/WAO, AOW, heffingskorting, arbeidskorting, jonggehandicaptenkorting.

## Priority & Demand

- **Priority:** P0-must
- **Demand evidence:** 54 features tracked
- **Dependencies:** employee-master, contract-management

## Competitor Evidence (from intelligence-db)

- adp-nl :: 30%-regeling :: Expat 30%-regeling beheer
- adp-nl :: Global Payroll Streamline :: Multi-country payroll vanuit een platform; NL + 140+ landen
- adp-nl :: NL Payroll Service Bureau :: Service-bureau model met ADP payroll experts; full UPA
- afas-hrm :: 13e maand + vakantiegeld :: Automatische reservering + uitbetaling
- afas-hrm :: 30%-regeling :: Expat 30%-regeling
- afas-hrm :: DGA-administratie :: Gebruikelijk-loon DGA + holdingstructuur
- afas-hrm :: Loonbeslag automatisering :: Beslag-berekening + beslagvrije voet
- centric-hrm :: Centric Payroll :: NL payroll engine, sterk in CAR-UWO, CAO Rijk, zorg-CAOs
- centric-hrm :: Loonbeslag automatisering :: Beslag + beslagvrije voet + deurwaarder
- cipal-schaubroeck :: BE sociale zekerheid :: RSZ-aanlevering BE
- cipal-schaubroeck :: BE/NL payroll :: Native BE + NL payroll engines
- cipal-schaubroeck :: Loonbeslag :: Beslag-procedures
- easy-loon :: 30%-regeling :: Expat regeling
- easy-loon :: DGA salarisrun :: Specifiek DGA gebruikelijk-loon + spaarloon
- easy-loon :: MKB salarisrun :: Bruto-netto NL met loonheffing + ZVW
- employes :: 30%-regeling :: Expat 30%-regeling
- employes :: DGA-flow :: Gebruikelijk-loon DGA
- employes :: Loonbeslag :: Beslag + beslagvrije voet
- employes :: Salarisrun automatisch :: NL payroll engine, maandelijkse run
- exact-online-hrm :: 13e maand en vakantiegeld :: Automatische reservering en uitbetaling
- exact-online-hrm :: 30%-regeling expat :: 30%-regeling administratie voor expats
- exact-online-hrm :: Loonheffingskorting beheer :: Heffingskorting toepassing met werknemer-verklaring
- exact-online-hrm :: Salarisrun maandelijks :: Bruto-netto, loonheffing, ZVW, WW, WIA
- frappe-hr :: Loans + advances :: Employee loans with repayment via payroll
- frappe-hr :: Payroll Entry :: Periodic payroll run; India/UAE focus

## Notes

This spec was triaged from market intelligence research dated 2026-05-20 covering 30 competitor implementations. See `/tmp/hrmq-research-gap-report.md` for full landscape, feature coverage matrix, and risk analysis.
