---
status: draft
---

# Payroll CAO MVP (10 most-common NL CAOs)

## Placement & Information Architecture

**Placement type:** `SUB_PAGE` — Sub-page beneath a top-level menu entry. Renders as a page inside the parent surface (usually reachable via a router child route or a tab on the parent index page).

**Lives at:** Salarissen › Loonruns

**Rationale:** Payroll-engine.  
_Source: /tmp/ia-procest-hrmq.md_

> **Implementation note for builders:** Respect the placement above. Do not promote this spec to a top-level menu item, sub-page, or new route unless the placement type explicitly says so. If the placement is `DETAIL_TAB`, `WIDGET`, `ACTION`, `SETTING`, or `INFRA`, the feature must NOT introduce a new entry in the app sidebar. When in doubt, ask before creating a new top-level surface.

## Purpose

Ship 10 most-common CAOs: Schoonmaak, Horeca, Kappersbedrijf, Detailhandel non-food, Metaal & Techniek, Bouw, ICT, Zorg VVT, Beveiliging, Algemeen (geen CAO). Each is JSON ruleset.

## Priority & Demand

- **Priority:** P0-must
- **Demand evidence:** 14 features tracked
- **Dependencies:** payroll-core-basic

## Competitor Evidence (from intelligence-db)

- adp-nl :: CAO ondersteuning 200+ :: Alle grote CAOs, sterk in metaal/industrie/retail
- afas-hrm :: CAO-bibliotheek 200+ :: Brede NL CAO-ondersteuning incl. Bouw, Metaal, Schoonmaak, Horeca
- centric-hrm :: CAO Bibliotheek overheid :: Volledige ambtelijke + zorg-CAOs: CAR-UWO, Rijk, NU-WO, VVT, GGZ
- cipal-schaubroeck :: Statutair NL ambtenaren :: Specifieke ambtelijke rechtspositie + CAR-UWO
- easy-loon :: CAO ondersteuning beperkt :: ~50 grote CAOs
- employes :: CAO-bibliotheek 80+ :: MKB-focus CAOs incl. retail, horeca, kappers, IT
- exact-online-hrm :: CAO-bibliotheek (beperkt) :: ~80 grote CAOs ondersteund, niet zo breed als Loket of Nmbrs
- hr2day :: CAO-bibliotheek :: Grote NL CAOs ondersteund
- loket :: CAO-bibliotheek 250+ :: Pre-configured CAOs voor alle grote NL branches incl. Bouw, Schoonmaak, Horeca, Metaal
- loonnext :: CAO-bibliotheek 100+ :: Grote NL CAOs ondersteund
- pivot-hr :: CAO basics :: ~30 grote CAOs, focus startup branches
- salure :: CAO-config consultancy :: Custom CAO-implementaties binnen AFAS
- visma-raet :: CAO-bibliotheek 400+ :: Industry leading, alle ambtelijke CAOs incl. CAR-UWO, Rijk, NU-WO
- visma-youserve :: CAO-bibliotheek 400+ :: Industry leading CAO-bibliotheek incl. overheid CAR-UWO, CAO Rijk

## Notes

This spec was triaged from market intelligence research dated 2026-05-20 covering 14 competitor implementations. See `/tmp/hrmq-research-gap-report.md` for full landscape, feature coverage matrix, and risk analysis.
