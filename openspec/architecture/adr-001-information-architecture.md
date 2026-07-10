# ADR-001: Information Architecture

**Status**: accepted

**Date**: 2026-05-23

## Context

hrmq is the Dutch HRM/payroll suite covering ~48 specs across employee-master, contract, payroll-engine + CAOs, payslip/UPA, leave/sick/WVP, self-service, ATS, pension, multi-administratie, ZZP/DGA modes, and public-sector add-ons (AOR, WNT, IRMA/DigiD). Without a top-down information-architecture (IA) discipline a register of this size naturally drifts into:

- a top-level menu per CAO-sector (gemeenten, Rijk, PO, VO, ziekenhuizen, VVT) — 6+ menu items that are really configuration rulesets;
- duplicate "Manager portaal" / "Medewerker portaal" sibling apps that re-implement the same screens with different role filters;
- multi-administratie surfaced as a menu prefix per tenant, exploding navigation when a concern runs 5+ payroll-administraties;
- ZZP/DGA and eenmanszaak as separate apps or top-level toggles instead of mode-switches;
- compliance output (UPA, pensioen, WNT, AVG-DSR, audit) scattered across Salarissen/Medewerkers/Compliance instead of one external-reporting hub;
- performance-management and comp-cyclus as a 10th top-level "Performance" menu instead of detail-tabs on the personnel record.

A cross-app IA design exercise (covering both procest and hrmq) was completed and recorded in `/tmp/ia-procest-hrmq.md`. The hrmq section of that document fixes 8 top-level menu items + 1 Configuratie drawer (9 total), assigns every one of the 48 specs to a single placement (TOP_MENU / SUB_PAGE / DETAIL_TAB / WIDGET / ACTION / SETTING), and codifies six design rules. This ADR lifts those rules into the per-app architecture record so they bind future spec proposals.

## Decision

**hrmq adheres to the top-level navigation (8 menus + Configuratie drawer) and the six IA design rules below.** New specs MUST map their UI surface onto an existing placement under this IA. Adding a new top-level menu requires an ADR amendment.

### Top-level navigation (frozen)

1. **Dashboard** (`view-dashboard`) — widget grid, role-default layouts.
2. **Mijn HR** (`account`) — self-service wrapper (profiel, loonstroken, verlof, ziek, declaraties, rooster, uren).
3. **Medewerkers** (`account-multiple`) — register, organogram, functiehuis, stagiairs/BBL, uitzend, offboarding.
4. **Salarissen** (`cash-multiple`) — loonruns, loonstroken, SEPA, loonbeslagen, 30%-regeling, IKB, audit-trail.
5. **Verlof & verzuim** (`calendar-clock`) — verlof, saldi, ziek, WVP, rooster, tijdregistratie, BHV.
6. **Onboarding & ATS** (`account-plus`) — vacatures, kandidaten, offers, onboardings.
7. **Declaraties & assets** (`wallet`) — declaraties, assets, WKR-overzicht.
8. **Aangiftes & compliance** (`file-document-check`) — UPA, pensioen, WNT, AVG-DSR, audit-rapporten.
9. **Configuratie** (`cog`) — CAO's & regelingen, Administraties, Sjablonen, Integraties, Rollen & rechten, Admin (drawer).

### Design rules

#### Rule 1 — CAO's zijn rulesets, geen menu's

All seven CAO-modules (cao-gemeenten, cao-rijk, cao-onderwijs-po, cao-onderwijs-vo, cao-ziekenhuizen, cao-zorg-vvt, payroll-cao-mvp) plus AOR ambtenarenrecht and ABP-aansluiting-verplicht live as SETTING entries under one `Configuratie › CAO's & regelingen` sub-page.

**Rationale:** a CAO is configuration data (rate tables, regelingen, eindejaar-uitkering rules) consumed by the payroll-engine, not a workflow. A top-level "CAO Rijk" menu would imply a distinct workflow per sector — that is exactly the duplication the engine exists to prevent.

**How to apply:** when proposing a new CAO or sector add-on, place it as a `SETTING` row under `Configuratie › CAO's & regelingen` in the spec's IA mapping table. Never propose a new top-level menu for a sector.

#### Rule 2 — Self-service is rol-gefilterde wrapper, niet aparte app

`Mijn HR` is the medewerker self-service top-level. Manager self-service lives as widgets on `Dashboard` plus scoped acties inside existing menus (Verlof & verzuim, Salarissen, Declaraties). There is no "Manager portaal" top-level menu and no separate self-service app.

**Rationale:** spinning up a dedicated portal app would duplicate every approval/list screen with different role filters and create a permanent UX-drift tax. A role-filtered wrapper keeps a single screen owner per surface.

**How to apply:** route any new self-service spec into either `Mijn HR` (medewerker scope) or as widgets+scoped acties on the relevant module (manager scope). Never propose a sibling self-service app.

#### Rule 3 — Multi-administratie is tenant-switch, geen menu-prefix

Multi-administratie surfaces as an active-administratie indicator in the topbar plus a switch. Every page in the app is implicitly scoped to the active administratie. Menus are NOT duplicated per administratie.

**Rationale:** a concern with 5 administraties would produce a 5× menu explosion; tenant-context is a single cross-cutting filter, not a navigation axis.

**How to apply:** specs that touch multi-administratie must respect the active-tenant context as the implicit scope; the multi-administratie spec itself lives as a `SETTING` under `Configuratie › Administraties` and never adds menu items.

#### Rule 4 — ZZP/DGA en eenmanszaak zijn modes, geen aparte app

`zzp-dga-single-person-mode` and `zzp-eenmanszaak-no-payroll-mode` are mode-switches under `Configuratie › Administraties`. They modify menu visibility (e.g. `Salarissen` is hidden under eenmanszaak-mode) but do not create a parallel app or a separate top-level toggle.

**Rationale:** small-business single-person variants share 80% of the data model (employee-master, declaraties, assets); forking the app would duplicate that surface. Mode-switching gives the same UX without code duplication.

**How to apply:** spec proposals targeting single-person scenarios must place their behaviour as `SETTING` mode-flags and document which existing menus are hidden/altered. Never propose a new app or top-level entry for a mode.

#### Rule 5 — Aangiftes en compliance leven samen

UPA-loonaangifte, pensioen-aanleveringen, WNT-publicatie, AVG-DSR-rights-engine and audit-rapporten all live under one `Aangiftes & compliance` top-level menu — not scattered across Salarissen, Medewerkers, or a separate Compliance app.

**Rationale:** all five are external verantwoording surfaces with shared mental model (periode, bestand, verzending-status, bevestiging, foutverwerking). Splitting them across modules forces users to remember which obligation lives where.

**How to apply:** any new external-reporting/disclosure/compliance spec lands under `Aangiftes & compliance` as a `SUB_PAGE`. WNT-publicatie remains hidden for non-public-sector tenants.

#### Rule 6 — Performance & comp zijn detail-tabs, geen module

`performance-management-advanced` (OKR/9-box/kalibratie) and `comp-planning-cycle` (jaarlijkse comp-cyclus) live as `DETAIL_TAB` rows on `Medewerkers › Functie & comp`. There is no 10th top-level "Performance" menu.

**Rationale:** the personnel-dossier is the natural anchor for performance and comp data; a separate module would duplicate the medewerker-context (manager, functie, salaris) on every screen.

**How to apply:** specs that add performance/comp/talent features land as detail-tabs on the personnel-dossier. The 9-item top-level cap is enforced — any 10th proposal must demote an existing item via ADR amendment.

## Consequences

**Positive:**
- Spec authors have a deterministic placement rule — every new spec maps to TOP_MENU / SUB_PAGE / DETAIL_TAB / WIDGET / ACTION / SETTING with the parent already chosen.
- The 9-item top-level cap is explicit; growth pressure routes into Configuratie or detail-tabs instead of menu sprawl.
- CAOs, modes, multi-tenant, and self-service stop generating proposals for new apps or sibling portals.
- Compliance output is discoverable in one place, which matches how HR-admins actually work (deadline-driven, not module-driven).

**Negative / trade-offs:**
- Manager self-service is split across widgets + scoped acties, which is harder to "demo as a portal" than a dedicated app would be. Mitigation: the dashboard role-default-layout for managers is the demo surface.
- The compliance hub mixes Belastingdienst, pensioenfondsen and AVG into one menu, which span very different stakeholders. Mitigation: the sub-pages within the hub keep the audiences separated visually.
- Mode-switching means menu visibility changes per administratie — onboarding documentation must explain this clearly to avoid "the Salarissen menu disappeared" tickets.

## Alternatives considered

- **Per-CAO top-level menus** — rejected: rule 1. Configuration ≠ navigation.
- **Separate manager-portaal app** — rejected: rule 2. Duplicates screens for a role filter that fits inside existing menus.
- **Multi-administratie as menu prefix** — rejected: rule 3. n×menu explosion for concerns.
- **ZZP/DGA as a forked single-person app** — rejected: rule 4. 80% data-model overlap.
- **Compliance spread per module (UPA under Salarissen, WNT under Medewerkers, AVG under Configuratie)** — rejected: rule 5. Breaks the deadline-driven mental model of HR-admins.
- **10th top-level "Performance" menu** — rejected: rule 6. Performance data anchors on the personnel-dossier.

## Related

- Source IA design doc: `/tmp/ia-procest-hrmq.md` (procest + hrmq, hrmq section).
- Spec mapping table (all 48 hrmq specs → IA placement): see the source doc, section 2.D.
- Implementation phasing (Q3 2026 payroll-MVP → Q4 sector-CAO + compliance → Q1 2027 advanced HR + planning): source doc section 2.E.
- procest sibling ADR for the cross-app IA pattern.
