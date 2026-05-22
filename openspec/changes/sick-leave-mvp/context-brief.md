---
status: draft
---

# Sick Leave / Verzuim MVP (70%/70%, UWV)

## Placement & Information Architecture

**Placement type:** `SUB_PAGE` — Sub-page beneath a top-level menu entry. Renders as a page inside the parent surface (usually reachable via a router child route or a tab on the parent index page).

**Lives at:** Verlof & verzuim › Ziekmeldingen

**Rationale:** Verzuim-MVP.  
_Source: /tmp/ia-procest-hrmq.md_

> **Implementation note for builders:** Respect the placement above. Do not promote this spec to a top-level menu item, sub-page, or new route unless the placement type explicitly says so. If the placement is `DETAIL_TAB`, `WIDGET`, `ACTION`, `SETTING`, or `INFRA`, the feature must NOT introduce a new entry in the app sidebar. When in doubt, ask before creating a new top-level surface.

## Purpose

Ziekmelding/hersteldmelding, wachtdag, 70%/70% doorbetalingsplicht 1e/2e jaar, UWV-koppeling voor 42e-weeks-melding.

## Priority & Demand

- **Priority:** P0-must
- **Demand evidence:** 12 features tracked
- **Dependencies:** employee-master

## Competitor Evidence (from intelligence-db)

- afas-hrm :: Verzuim + WVP-dashboard :: Wet Verbetering Poortwachter tijdlijn + arbo-koppeling
- centric-hrm :: Verzuim + Arbo :: Volledig verzuim, WVP, arbo-koppeling
- cipal-schaubroeck :: Verzuim + arbo :: WVP + arbeidsongeschiktheid + re-integratie
- easy-loon :: Verzuim basic :: Ziek/hersteld registratie
- employes :: Verzuim :: Ziekmelding + hersteld + UWV-rapportage
- exact-online-hrm :: Verzuim registratie basic :: Ziekmelding + hersteldmelding; geen volledige WVP module
- loket :: Verzuimregistratie :: Ziekmelding, hersteldmelding, koppeling Arbo + UWV; Wet Verbetering Poortwachter dashboard
- loonnext :: Verzuimregistratie basic :: Ziek/hersteld; geen volledige WVP cyclus
- pivot-hr :: Verzuim :: Ziek/hersteld + WVP basic
- salure :: Verzuimcoaching :: Salure consultants begeleiden WVP-traject
- visma-raet :: Verzuimmanager :: WVP-cyclus, arbo-koppeling, UWV
- visma-youserve :: Verzuim Vandaag :: Native verzuim module met arbo-portal integratie, WVP timeline

## Notes

This spec was triaged from market intelligence research dated 2026-05-20 covering 12 competitor implementations. See `/tmp/hrmq-research-gap-report.md` for full landscape, feature coverage matrix, and risk analysis.
