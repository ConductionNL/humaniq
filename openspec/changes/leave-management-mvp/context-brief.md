---
status: draft
---

# Leave Management MVP (vakantie, accrual, balance)

## Placement & Information Architecture

**Placement type:** `SUB_PAGE` — Sub-page beneath a top-level menu entry. Renders as a page inside the parent surface (usually reachable via a router child route or a tab on the parent index page).

**Lives at:** Verlof & verzuim › Verlofverzoeken+Verlofsaldi

**Rationale:** Verlof-MVP.  
_Source: /tmp/ia-procest-hrmq.md_

> **Implementation note for builders:** Respect the placement above. Do not promote this spec to a top-level menu item, sub-page, or new route unless the placement type explicitly says so. If the placement is `DETAIL_TAB`, `WIDGET`, `ACTION`, `SETTING`, or `INFRA`, the feature must NOT introduce a new entry in the app sidebar. When in doubt, ask before creating a new top-level surface.

## Purpose

Vakantie-opbouw (wettelijk 4x weekuren + bovenwettelijk per CAO), aanvraag/goedkeuring workflow, saldo, uitbetaling bij uitdiensttreding.

## Priority & Demand

- **Priority:** P0-must
- **Demand evidence:** 20 features tracked
- **Dependencies:** employee-master

## Competitor Evidence (from intelligence-db)

- adp-nl :: Verlof- en verzuimmodule :: Volledig verlof, verzuim met WVP-cyclus
- afas-hrm :: Verlofregistratie + accrual :: Volledig verlof per CAO + bijzonder verlof
- cipal-schaubroeck :: Verlofregistratie :: Statutaire + extra-statutaire verloven
- easy-loon :: Verlofregistratie basic :: Vakantie-saldo
- employes :: Verlofregistratie :: Vakantie + verlof + accrual per CAO
- exact-online-hrm :: Verlofregistratie basic :: Vakantie + verlof + saldo opbouw
- frappe-hr :: Leave management :: Leave types + allocation + encashment
- gusto :: PTO management :: PTO accrual + approval
- hibob :: Time off management :: Configurable leave policies; geen NL CAO depth
- icehrm :: Leave management :: Leave types + balances + approval
- loket :: Verlofregistratie :: Vakantie, ouderschaps-, zorg-, geboorteverlof; saldo opbouw per CAO
- loonnext :: Verlofregistratie :: Vakantie + verlof saldo + accrual
- officient :: Verlofregistratie :: Vakantie, ziekte, jeugd, klein verlet, anciënniteit
- pivot-hr :: Verlofregistratie :: Vakantie + verlof + accrual
- rippling :: Time off :: PTO policies + accrual
- sage-hr :: Leave Management :: PTO policies + accrual + calendar
- sentrifugo :: Holiday calendar :: Multi-location holidays
- sentrifugo :: Leave management :: Leave types + accrual + approval
- workday-hcm :: Absence Management :: Configurable absence plans + EU compliance
- zoho-people :: Leave tracker :: Custom leave types + approval workflow

## Notes

This spec was triaged from market intelligence research dated 2026-05-20 covering 20 competitor implementations. See `/tmp/hrmq-research-gap-report.md` for full landscape, feature coverage matrix, and risk analysis.
