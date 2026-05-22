---
status: draft
---

# Pension Admin MVP (PFZW, ABP, BPL, bpfBOUW)

## Placement & Information Architecture

**Placement type:** `SUB_PAGE` — Sub-page beneath a top-level menu entry. Renders as a page inside the parent surface (usually reachable via a router child route or a tab on the parent index page).

**Lives at:** Aangiftes & compliance › Pensioen-aanleveringen

**Rationale:** Pensioen-aanlevering.  
_Source: /tmp/ia-procest-hrmq.md_

> **Implementation note for builders:** Respect the placement above. Do not promote this spec to a top-level menu item, sub-page, or new route unless the placement type explicitly says so. If the placement is `DETAIL_TAB`, `WIDGET`, `ACTION`, `SETTING`, or `INFRA`, the feature must NOT introduce a new entry in the app sidebar. When in doubt, ask before creating a new top-level surface.

## Purpose

Aanlevering aan PFZW + ABP + BPL + bpfBOUW + StPVG (verplichte bedrijfstak-pensioenfondsen); UPA pensioenaangifte sectie.

## Priority & Demand

- **Priority:** P0-must
- **Demand evidence:** 13 features tracked
- **Dependencies:** payroll-core-basic

## Competitor Evidence (from intelligence-db)

- adp-nl :: Pensioenaanlevering :: Alle NL pensioenfondsen
- afas-hrm :: Pensioenkoppeling :: Alle grote NL pensioenfondsen
- centric-hrm :: ABP Pensioenkoppeling :: Native ABP koppeling, alle ambtelijke pensioenen
- cipal-schaubroeck :: Pensioenkoppeling :: ABP NL + RVP BE
- easy-loon :: Pensioenkoppeling :: Grote pensioenfondsen
- employes :: Pensioen-aanlevering :: Alle grote NL pensioenfondsen
- exact-online-hrm :: Pensioenkoppeling :: PFZW, ABP, BPL koppelingen
- hr2day :: Pensioenaanlevering :: NL pensioenfondsen
- loket :: Pensioenkoppeling :: Direct pensioenfonds aanlevering (PFZW, BpfBOUW, PMT, BPL, ABP)
- loonnext :: Pensioenkoppeling :: Grote pensioenfondsen NL
- pivot-hr :: Pensioen :: Grote pensioenfondsen
- visma-raet :: Pensioen-aanlevering :: ABP, PFZW, alle branche-fondsen
- visma-youserve :: Pensioenkoppeling :: PFZW, ABP, BPL, branche-pensioenfondsen

## Notes

This spec was triaged from market intelligence research dated 2026-05-20 covering 13 competitor implementations. See `/tmp/hrmq-research-gap-report.md` for full landscape, feature coverage matrix, and risk analysis.
