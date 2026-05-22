---
status: draft
---

# Cross-app: Payroll Cost → shillinq GL Post

## Placement & Information Architecture

**Placement type:** `ACTION` — Action button or menu item on an existing surface. Implemented as a single button / context-menu entry that opens a modal/wizard or runs a backend operation — NOT a page.

**Lives at:** Salarissen › Loonruns (Post-knop) + Configuratie › Integraties

**Rationale:** Cross-app GL-post.  
_Source: /tmp/ia-procest-hrmq.md_

> **Implementation note for builders:** Respect the placement above. Do not promote this spec to a top-level menu item, sub-page, or new route unless the placement type explicitly says so. If the placement is `DETAIL_TAB`, `WIDGET`, `ACTION`, `SETTING`, or `INFRA`, the feature must NOT introduce a new entry in the app sidebar. When in doubt, ask before creating a new top-level surface.

## Purpose

Maandelijkse salariskosten-post naar shillinq grootboek: bruto-loon → 4xxx, sociale lasten → 17xx, vakantiegeld-reservering → 18xx, netto-loonschuld → 14xx. RGS 2026.

## Priority & Demand

- **Priority:** P0-must
- **Demand evidence:** 11 features (cross-app)
- **Dependencies:** payroll-core-basic

## Cross-app integration

shillinq.JournalEntry.post; idempotent per (employee_id, period).

## Competitor Evidence (from intelligence-db)

- afas-hrm :: AFAS Profit GL integratie :: Native koppeling AFAS Profit grootboek voor salariskosten
- easy-loon :: Bookhoudkoppeling :: Export naar grote pakketten
- employes :: Bookhoudkoppelingen :: Exact, Moneybird, Snelstart, Twinfield
- exact-online-hrm :: Bookkeeping native integratie :: Auto-post salariskosten in Exact Online grootboek; sociale lasten; vakantiegeld-reservering
- frappe-hr :: GL integration :: Native ERPNext GL posting
- loket :: Bookhouding-koppelingen :: Native exports naar Exact, Twinfield, Snelstart, AccountView, Reeleezee, MUIS
- loonnext :: Bookhoudkoppelingen :: Exact, Twinfield, Snelstart
- pivot-hr :: Boekhoudkoppeling :: Exact, Moneybird, Snelstart
- rippling :: Bill pay :: AP automation; NL only via partner
- sage-hr :: Sage Accounting integration :: Tight Sage 50/200 integration; no NL native
- visma-youserve :: Boekhoudkoppeling SAP/Oracle :: Native exports naar SAP, Oracle, Exact, AFAS Profit

## Notes

This spec was triaged from market intelligence research dated 2026-05-20 covering 11 competitor implementations. See `/tmp/hrmq-research-gap-report.md` for full landscape, feature coverage matrix, and risk analysis.
