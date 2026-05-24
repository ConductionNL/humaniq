---
status: draft
---

# Contract Management (vast/tijdelijk/oproep/freelance)

## Placement & Information Architecture

**Placement type:** `SUB_PAGE` — Sub-page beneath a top-level menu entry. Renders as a page inside the parent surface (usually reachable via a router child route or a tab on the parent index page).

**Lives at:** Personeel / Contracten

**Rationale:** Contracten zijn first-class entities met eigen lijst + detail + einddatum-alerts. Nesten onder Personeel (medewerker-centric module), niet aparte top-level.  
_Source: manual tag 2026-05-24_

> **Implementation note for builders:** Respect the placement above. Do not promote this spec to a top-level menu item, sub-page, or new route unless the placement type explicitly says so. If the placement is `DETAIL_TAB`, `WIDGET`, `ACTION`, `SETTING`, or `INFRA`, the feature must NOT introduce a new entry in the app sidebar. When in doubt, ask before creating a new top-level surface.

## Purpose

Contract types: onbepaalde tijd / bepaalde tijd / oproep / nul-uren / freelance; start/eind/proeftijd/concurrentie-beding fields; einddatum-alert workflow.

## Priority & Demand

- **Priority:** P0-must
- **Demand evidence:** 6 features (universal)
- **Dependencies:** employee-master

## Competitor Evidence (from intelligence-db)

- easy-loon :: Contractbeheer :: Vast/tijdelijk contracten
- employes :: Contractbeheer :: Vast/tijdelijk/oproep + addenda
- exact-online-hrm :: Contractbeheer :: Vast/tijdelijk contracten met einddatum-alerts
- loket :: Contractbeheer :: Vast/tijdelijk/oproep contracten met automatische einddata-alerts en addenda
- loonnext :: Contractbeheer :: Vast/tijdelijk/oproep met einddata-alerts
- officient :: Contractbeheer :: Alle contract types incl. flex/oproep BE+NL

## Notes

This spec was triaged from market intelligence research dated 2026-05-20 covering 6 competitor implementations. See `/tmp/hrmq-research-gap-report.md` for full landscape, feature coverage matrix, and risk analysis.
