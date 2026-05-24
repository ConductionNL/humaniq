---
status: draft
---

# Werknemer Self-service Portal (SSO)

## Placement & Information Architecture

**Placement type:** `TOP_MENU` — Top-level menu entry — this functionality earns its own item in the app's left-nav.

**Lives at:** Mijn HR (role-filtered top-level menu)

**Rationale:** Per ADR-001 rule 2: Self-service is rol-gefilterde wrapper, geen aparte app. Mijn HR IS de self-service top-level menu — medewerker ziet loonstrook/verlof/NAW; manager ziet team-widgets.  
_Source: manual tag 2026-05-24_

> **Implementation note for builders:** Respect the placement above. Do not promote this spec to a top-level menu item, sub-page, or new route unless the placement type explicitly says so. If the placement is `DETAIL_TAB`, `WIDGET`, `ACTION`, `SETTING`, or `INFRA`, the feature must NOT introduce a new entry in the app sidebar. When in doubt, ask before creating a new top-level surface.

## Purpose

Werknemer-login (Nextcloud SSO): loonstrook download, verlof aanvragen, NAW-mutaties (met manager-approval voor IBAN/BSN), jaaropgaaf.

## Priority & Demand

- **Priority:** P0-must
- **Demand evidence:** 52 features tracked
- **Dependencies:** employee-master, payslip-generation, leave-management-mvp

## Competitor Evidence (from intelligence-db)

- adp-nl :: Mobile app :: ADP Mobile app iOS/Android
- adp-nl :: Werknemer portaal NL :: Loonstrook, jaaropgaaf, verlof, NAW-mutaties
- afas-hrm :: AFAS InSite :: Werknemer + manager web portal
- afas-hrm :: AFAS Pocket app :: Mobile app voor werknemers, gratis voor klanten
- afas-hrm :: Workflow management :: Configureerbare workflows zonder code
- centric-hrm :: Centric Self-Service :: Werknemer + manager ESS/MSS portal
- centric-hrm :: WMV (Workflow Management) :: Configureerbare workflows + approvals; sterk feature
- cipal-schaubroeck :: Self-service portal :: Werknemer + manager
- easy-loon :: Werknemer portaal :: Loonstrook downloaden
- employes :: Mobile app :: iOS/Android werknemer
- employes :: Werknemer self-service :: Web + mobiel
- exact-online-hrm :: Exact mobile app :: iOS/Android; declaraties + loonstrook
- exact-online-hrm :: Werknemer self-service :: Loonstrook download, verlof aanvragen
- frappe-hr :: Mobile app :: Frappe HR mobile app iOS/Android
- frappe-hr :: Self-service :: Employee portal with leave/expense
- frappe-hr :: Workflow builder :: Approval workflows in low-code
- gusto :: Employee self-service :: Paystubs + W-2 + benefits
- hibob :: Mobile app :: iOS/Android
- hibob :: Workflows/automations :: No-code workflow builder
- hr2day :: Self-service portaal :: Werknemer + manager
- icehrm :: Employee self-service :: Web + mobile
- icehrm :: Mobile app :: iOS/Android
- loket :: Accountant-portaal :: Multi-klant overzicht voor accountants/administratiekantoren met centrale rechten
- loket :: Mobiele app :: iOS/Android voor werknemer + manager
- loket :: Werknemer self-service portaal :: Werknemer kan loonstrook, verlofsaldo, NAW-mutaties zelf doen

## Notes

This spec was triaged from market intelligence research dated 2026-05-20 covering 30 competitor implementations. See `/tmp/hrmq-research-gap-report.md` for full landscape, feature coverage matrix, and risk analysis.
