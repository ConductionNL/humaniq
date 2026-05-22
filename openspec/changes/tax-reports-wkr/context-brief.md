---
status: draft
---

# Werkkostenregeling (WKR) calc + eindheffing

## Placement & Information Architecture

**Placement type:** `SUB_PAGE` — Sub-page beneath a top-level menu entry. Renders as a page inside the parent surface (usually reachable via a router child route or a tab on the parent index page).

**Lives at:** Declaraties & assets › WKR-overzicht + Aangiftes (eindheffing)

**Rationale:** WKR-engine.  
_Source: /tmp/ia-procest-hrmq.md_

> **Implementation note for builders:** Respect the placement above. Do not promote this spec to a top-level menu item, sub-page, or new route unless the placement type explicitly says so. If the placement is `DETAIL_TAB`, `WIDGET`, `ACTION`, `SETTING`, or `INFRA`, the feature must NOT introduce a new entry in the app sidebar. When in doubt, ask before creating a new top-level surface.

## Purpose

Werkkostenregeling: vrije ruimte berekening 3% over eerste 400k loonsom + 1.18% boven; eindheffing 80% over overschrijding.

## Priority & Demand

- **Priority:** P0-must
- **Demand evidence:** 28 features tracked
- **Dependencies:** payroll-core-basic

## Competitor Evidence (from intelligence-db)

- adp-nl :: Loonaangifte UPA :: Belastingdienst UPA + correcties; jaaropgaven
- adp-nl :: WKR-administratie :: Werkkostenregeling
- afas-hrm :: UPA Loonaangifte :: Belastingdienst UPA + correctieberichten
- afas-hrm :: WKR-administratie :: Werkkostenregeling + vrije ruimte
- centric-hrm :: Loonaangifte UPA :: Belastingdienst UPA + correcties
- centric-hrm :: WKR + Vergoedingen :: Werkkostenregeling + secundaire arbeidsvoorwaarden
- cipal-schaubroeck :: UPA Loonaangifte NL :: UPA aanlevering NL
- easy-loon :: UPA Loonaangifte :: Belastingdienst UPA
- easy-loon :: WKR :: Werkkostenregeling
- employes :: UPA Loonaangifte :: Volledige UPA aanlevering
- employes :: WKR :: Werkkostenregeling
- exact-online-hrm :: UPA Loonaangifte :: Volledige UPA aanlevering naar Belastingdienst
- exact-online-hrm :: WKR-administratie :: Werkkostenregeling met grootboek-koppeling
- frappe-hr :: Income tax (IN/UAE/etc) :: TDS + Form-16; no NL UPA
- gusto :: Multi-state taxes :: US multi-state
- hr2day :: UPA Loonaangifte :: UPA aanlevering Belastingdienst
- hr2day :: WKR + vergoedingen :: Werkkostenregeling
- loket :: Loonaangifte UPA :: Volledige Belastingdienst UPA loonaangifte met automatische indiening en correctieberichten
- loket :: Werkkostenregeling WKR :: WKR vrije ruimte berekening, eindheffing, OR-toets
- loonnext :: UPA Loonaangifte :: Volledige UPA aanlevering inbegrepen
- loonnext :: WKR :: Werkkostenregeling administratie
- pivot-hr :: UPA Loonaangifte :: UPA aanlevering
- pivot-hr :: WKR :: Werkkostenregeling
- salure :: Loonaangifte controle :: Pre-submission UPA-validatie door payroll experts
- visma-raet :: UPA Loonaangifte :: Belastingdienst UPA aanlevering met correcties

## Notes

This spec was triaged from market intelligence research dated 2026-05-20 covering 28 competitor implementations. See `/tmp/hrmq-research-gap-report.md` for full landscape, feature coverage matrix, and risk analysis.
