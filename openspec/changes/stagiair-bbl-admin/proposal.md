---
status: draft
app: hrmq
spec: stagiair-bbl-admin
version: 0.1.0
owners: [hrmq-team]
---

# Stagiair & BBL-leerling Administratie — Proposal

## Problem

Nederlandse organisaties die opleidingsplaatsen bieden onderscheiden juridisch en administratief twee fundamenteel verschillende leerwerkrelaties:

1. **Stagiair (HBO/WO/MBO-BOL)** — zonder dienstverband, geen loonheffing, geen CAO-toepassing
2. **BBL-leerling (MBO-BBL)** — met regulier dienstverband, loon, CAO-toepassing en recht op Subsidieregeling Praktijkleren

Beide vereisen:
- Erkenning van het leerbedrijf via SBB
- Ondertekende praktijkleerovereenkomst (POK) van alle drie partijen
- Aparte fiscale, verzekerings- en subsidie-administratie

**Current gap:** hrmq's `Employee` master is ontworpen voor reguliere werknemers. Toepassing op stagiairs veroorzaakt:
- Foutieve payroll-entries voor onbelaste stagevergoeding
- Ongewenste CAO-staffels en verlofadministratie
- Risico op misclassificatie als dienstbetrekking (BW-7 titel 10)
- Geen tracking van SBB-erkenning, POK-ondertekening of Subsidie Praktijkleren-aanvragen

## Solution

Dedicated module voor stagiairs en BBL-leerlingen als distinct entity-types, gescheiden van `Employee`, met volledige lifecycle-support:

- **Entity-scheiding:** `Stagiair` en `BBLLeerling` als aparte records
- **SBB-integratie:** Erkenning-check op CREBO-niveau voordat registratie mogelijk is
- **POK-lifecycle:** Opstellen, driepartijehandtekening, archivering
- **Financieel:** Onbelaste stagevergoeding, BBL-payroll-koppeling, Subsidie Praktijkleren-aanvraag
- **Voortgang:** Evaluatiegesprekken op 25%/50%/75% van looptijd
- **Uitstroom:** Diploma-registratie, archivering, optionele doorstroom naar vast contract

## Stakeholder Value

| Rol | Voordeel |
|-----|----------|
| **HR-admin** | Één formulier per type (stagiair/BBL), geen risico op payroll-misclassificatie, geautomatiseerde instroom-blockers |
| **Stagebegeleider** | Evaluatiepunten automatisch gepland, POK-tracking, audit-trail van voortgang |
| **Opleidingscoordinator** | Dashboard van actieve leerwerkplekken per SBB-erkenning, capaciteitsplanning |
| **Finance-admin** | Automatische subsidie-aanvraag (RVO), stagevergoeding zonder loonheffing, BBL-salaris-mutaties per leerjaar |
| **Directie** | KPI-dashboard: aantallen, subsidie-opbrengsten (€2.700/leerling/jaar), talent-pipeline van BBL/stagiair naar vast |

## Demand Scoring

| Feature | User | Freq | Impact | Score |
|---------|------|------|--------|-------|
| Entity-scheiding stagiair vs werknemer | HR-admin, Finance | Per instroom | Prevent payroll-misclassification | 95 |
| SBB-erkenning-check op CREBO | HR-admin, Opl.coord | Per registratie | Ensure compliance, prevent invalid placements | 90 |
| POK-driepartijehandtekening | Stagebegeleider, HR-admin | Per plaatsing | Contractual binding, audit trail | 85 |
| BBL-staffel-progressie per leerjaar | Finance, HR-admin | Jaarlijks (1/leerling) | Automate salary step, avoid manual error | 80 |
| Subsidie Praktijkleren-aanvraag | Finance, Directie | Per leerling per jaar | €2.700/leerling revenue, audit trail | 85 |
| Voortgangsgesprekken-tracking | Stagebegeleider, HR-admin | 3× per plaatsing | Ensure evaluation, unlock outflow | 75 |
| Verzekering-status validatie | HR-admin, Opl.coord | Per instroom | Prevent uninsured placements | 80 |
| Uitstroom met diploma-registratie | Stagebegeleider, HR-admin | Per afgestudeerd | Close record cleanly, archiving compliance | 70 |

## Scope

**In scope:**
- Entity types: `Stagiair`, `BBLLeerling`, `PraktijkLeerOvereenkomst`, `SBBErkenning`, `SubsidieAanvraagPraktijkleren`
- Core lifecycle: registratie → POK-ondertekening → voortgang → uitstroom
- Integrations: SBB register (API), RVO subsidie-API, payroll-engine-nl (BBL-staffel), employee-master (refs)
- Dashboard: actieve leerwerkplekken, subsidie-opbrengsten, instroom-blockers
- Compliance: Archiefwet (7j retentie), BW-7 titel 10, AVG-BSN, CAO Beroepsonderwijs

**Out of scope:**
- Training/competence-management (learning-record separate spec)
- Manager performance-reviews (part of broader performance-management-advanced)
- Duplicate stagiair-self-service portal (roles in `Mijn HR` where needed)
- Multi-tenancy admin (part of general multi-administratie spec)

## Placement & IA

**Type:** `SUB_PAGE` — under top-level `Medewerkers`  
**Lives at:** Medewerkers › Stagiairs & BBL  
**Rationale:** Separate filter/view from regular employees, dedicated admin surface, high compliance risk if mixed with Employee workflows.

---

## Related

- **ADR-001:** Information Architecture (placement, nav rules)
- **Dependency specs:** employee-master, contract-management, payroll-engine-nl, document-storage, task-management, finance-export
- **Standards:** SBB, Subsidieregeling Praktijkleren (RVO), BW-7 titel 10, CAO Beroepsonderwijs, AVG, Archiefwet
