---
status: draft
---

# Payslip / Loonstrook Generation

## Placement & Information Architecture

**Placement type:** `SUB_PAGE` — Sub-page beneath a top-level menu entry. Renders as a page inside the parent surface (usually reachable via a router child route or a tab on the parent index page).

**Lives at:** Salarissen › Loonstroken

**Rationale:** Payslip-output.  
_Source: /tmp/ia-procest-hrmq.md_

> **Implementation note for builders:** Respect the placement above. Do not promote this spec to a top-level menu item, sub-page, or new route unless the placement type explicitly says so. If the placement is `DETAIL_TAB`, `WIDGET`, `ACTION`, `SETTING`, or `INFRA`, the feature must NOT introduce a new entry in the app sidebar. When in doubt, ask before creating a new top-level surface.

## Purpose

PDF loonstrook (standaard NL-formaat: bruto/netto/inhoudingen/cumulatieven), digitaal portaal voor werknemer, jaaropgaaf jaarlijks.

## Priority & Demand

- **Priority:** P0-must
- **Demand evidence:** 6 features (universal)
- **Dependencies:** payroll-core-basic

## Competitor Evidence (from intelligence-db)

- easy-loon :: Loonstrook PDF :: PDF loonstrook + jaaropgaaf
- employes :: Loonstrook PDF :: Digitale loonstrook met portal
- exact-online-hrm :: Loonstrook PDF + portaal :: Digitale loonstrook, jaaropgaaf, eigen werknemers-portaal
- loket :: Loonstrook generatie :: PDF loonstrook + digitaal portaal voor werknemer, jaaropgaaf, loonbeslag-brieven
- loonnext :: Loonstrook PDF + portaal :: Digitale loonstrook en jaaropgaaf
- pivot-hr :: Loonstrook digitaal :: Mobile-first loonstrook

## Notes

This spec was triaged from market intelligence research dated 2026-05-20 covering 6 competitor implementations. See `/tmp/hrmq-research-gap-report.md` for full landscape, feature coverage matrix, and risk analysis.
