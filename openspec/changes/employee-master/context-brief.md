---
status: draft
---

# Employee Master (NAW, BSN, IBAN, AVG)

## Placement & Information Architecture

**Placement type:** `SUB_PAGE` — Sub-page beneath a top-level menu entry. Renders as a page inside the parent surface (usually reachable via a router child route or a tab on the parent index page).

**Lives at:** Medewerkers › Alle medewerkers

**Rationale:** Kern register.  
_Source: /tmp/ia-procest-hrmq.md_

> **Implementation note for builders:** Respect the placement above. Do not promote this spec to a top-level menu item, sub-page, or new route unless the placement type explicitly says so. If the placement is `DETAIL_TAB`, `WIDGET`, `ACTION`, `SETTING`, or `INFRA`, the feature must NOT introduce a new entry in the app sidebar. When in doubt, ask before creating a new top-level surface.

## Purpose

Personal record (NAW, BSN, IBAN, email, phone, geboortedatum, nationaliteit, emergency contact), AVG-by-default with 7-year retention timer post-uitdiensttreding. BSN encrypted at rest (AES-256).

## Priority & Demand

- **Priority:** P0-must
- **Demand evidence:** 46 features tracked
- **Dependencies:** none

## Competitor Evidence (from intelligence-db)

- adp-nl :: ADP Workforce Now :: Global HRIS adapted voor NL incl. NL payroll engine
- adp-nl :: Compliance Library :: Auto-updated NL fiscale tabellen, WAB, WAB-correcties
- afas-hrm :: AFAS Personal (gratis <10 wn) :: Vrije starter-versie t/m 10 werknemers
- afas-hrm :: AVG-dataverzoeken :: GDPR retentie + werknemer data export
- afas-hrm :: Document management :: Personeelsdossier + e-sign
- centric-hrm :: AVG/GDPR + BIO compliance :: BIO-baseline informatiebeveiliging, AVG-dataverzoeken
- centric-hrm :: Centric HR Suite :: Modulair HR-platform overheid/zorg, BRP-koppeling
- centric-hrm :: Documentbeheer dossier :: Personeelsdossier met versies + AVG-retentie 50 jaar overheid
- centric-hrm :: GBA/BRP koppeling :: Direct BRP voor identiteit + adresvalidatie
- cipal-schaubroeck :: BIO/AVG compliance :: Baseline Informatiebeveiliging Overheid
- cipal-schaubroeck :: Documentbeheer :: Personeelsdossier 50-jaar overheid retentie
- cipal-schaubroeck :: GBA/BRP koppeling :: BRP-validatie identiteit
- cipal-schaubroeck :: Persicope HRM :: Klassieke HR-suite voor BE+NL overheid
- easy-loon :: AVG-compliance :: GDPR retentie
- employes :: AVG/GDPR :: GDPR retentie + dataverzoeken
- exact-online-hrm :: AVG/GDPR rapportages :: Data export per werknemer, retentie-instellingen
- frappe-hr :: Employee master :: Full employee record + custom fields
- frappe-hr :: GPL-3.0 fully OSS :: Self-hostable, no per-user fees
- gusto :: Benefits administration US :: Health + 401k + commuter; US-only
- gusto :: Compliance reminders :: US federal + state labor compliance
- gusto :: Workers comp :: Pay-as-you-go workers comp; US-only
- hibob :: Bob HRIS Core :: Modern HRIS database, social-network style UI
- hibob :: Documents + e-sign :: Document storage met versies
- hibob :: GDPR/SOC2/ISO27001 :: EU DPA + sub-processors
- hibob :: Org chart dynamic :: Drag-and-drop org chart

## Notes

This spec was triaged from market intelligence research dated 2026-05-20 covering 30 competitor implementations. See `/tmp/hrmq-research-gap-report.md` for full landscape, feature coverage matrix, and risk analysis.
