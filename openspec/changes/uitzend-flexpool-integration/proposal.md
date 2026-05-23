---
name: uitzend-flexpool-integration
title: Uitzendkrachten & Flexpool-integratie
version: 0.1.0
status: approved
owners: [hrmq-team]
stakeholders: [inhuur-coordinator, finance-admin, manager, hr-admin, controller]
placement: SUB_PAGE
parent-menu: Medewerkers › Uitzendkrachten
dependencies: [employee-master, payroll-engine-nl, finance-export, task-management, document-storage, organisations-master]
standards: [WAADI, ABU-CAO, NBBU-CAO, WAB, Inlenersbeloning, SNA-NEN-4400-1]
---

# Proposal: Uitzendkrachten & Flexpool-integratie

## Problem Statement

Nederlandse organisaties huren aanzienlijk deel van capaciteit in via uitzendbureaus, payroll-organisaties en detacheerders. De wettelijke en CAO-context (WAADI, WAB, ABU-CAO, NBBU-CAO) en inlenersbeloning-verplichtingen (sinds 30 maart 2015) vereisen significante administratieve compliance.

**Current pain:**
- Inhuurkrachten zijn niet zichtbaar in capaciteitsplanning en kostenrapportages
- Inlenersbeloning-onderbouwing gebeurt off-system (spreadsheets, email)
- Fase-overgangen (ABU A→B→C, NBBU 1-2-3-4) worden handmatig getrackt
- Factuur-matching tegen uren gebeurt manueel; geen automatische dispute-detectie
- G-rekening-betalingen niet ondersteund; ketenaansprakelijkheid-risico niet gemonitord
- SNA-keurmerk validatie ontbreekt; geen waarschuwing bij verlopen keurmerken

## Opportunity

Een dedicated inhuur-module integreert bureau-management, uren-registratie, factuur-matching, en fase-compliance in één werkstroom. Dit geeft:

- **Inhuur-coordinators:** volledig zicht op alle actieve opdrachten, bureau-relaties, fase-status, inlenersbeloning-revisies
- **Finance:** automatische factuur-matching, G-rekening-splits, dispute-escalatie
- **Managers:** approval-flow voor uren, TCO-vergelijking (inhuur vs vast), zicht op team-inhuurbudget
- **HR-admin:** totaal workforce-zicht (vast + flex), integratieve referent-functieprofielen voor inlenersbeloning
- **Controller:** make-or-hire analytics, flex-ratio monitoring, budget-realisatie

## Demand & Impact

| Feature | Demand | Effort | Impact |
|---------|--------|--------|--------|
| Inhuur-opdrachtregistratie (bureau, kandidaat, fase) | High | Medium | Core workflow |
| Bureau-validatie (SNA-keurmerk, G-rekening) | High | Low | Compliance, risk mitigation |
| Inlenersbeloning-onderbouwing & revisie | High | Medium | Legal compliance |
| Fase-progressie tracking (ABU A→B→C) | High | Medium | CAO compliance, cost escalation visibility |
| Urenregistratie met manager-approval | High | High | Factuur-validatie prerequisite |
| Maandelijkse factuur-matching | High | High | Finance automation, dispute detection |
| G-rekening-betaling & split-administratie | Medium | Medium | Tax risk mitigation |
| TCO-dashboard (inhuur vs vast) | Medium | Medium | Make-or-hire decision support |

## Success Metrics

- **Adoption:** ≥80% van actieve inhuur-opdrachten geregistreerd in system binnen 3 maanden
- **Compliance:** 100% van inlenersbeloning-onderbouwingen vastgelegd en jaarlijks gereviseerd
- **Cycle time:** Factuur-matching automatisch binnen 2 werkdagen (vs. 5-7 handmatig)
- **Risk:** 100% van bureaus SNA-validatie doormaken; 0 inhuur-opdrachten bij bureaus met verlopen keurmerk
- **Finance:** ≥95% van facturen automatisch gematcht zonder disputes

## Out of Scope

- **No payroll integration:** inhuurkrachten krijgen GEEN Employee-record, verlofbalans, of performance-cycles
- **No bureau portal:** uitzendbureaus kunnen uren NIET zelf inboeken (integratie via API/EDI alleen)
- **No detailed shift scheduling:** rooster/shift-planning wordt niet ondersteund (date-range + uren/week alleen)
- **No contractor invoicing:** freelance-ZZP-fakturering heeft eigen spec (niet hier)

## Placement & IA

- **Type:** SUB_PAGE
- **Lives at:** Medewerkers › Uitzendkrachten (under menu item 3 "Medewerkers")
- **Rationale:** Inhuurkrachten zijn workforce (als Stagiairs/BBL nu ook onder Medewerkers), dus dezelfde browse-list-detail-tab IA

## Risks & Mitigations

| Risk | Mitigation |
|------|-----------|
| Complex CAO logic (ABU vs NBBU fase-drempels) | Codify in schema; include ABU/NBBU drempel-tabellen in settings |
| Integration latency (factuur-matching vs payroll-engine CAO-mutations) | Async queue; human review loop for edge cases |
| SNA-keurmerk data freshness | Daily batch check against KvK registry; alert if unknown/expired |
| G-rekening regulatory change | Wrap percentage in setting; document risk in compliance notes |

## Success Criteria

- ✅ All 5 entities (InhuurOpdracht, Bureau, InlenersBeloningOnderbouwing, UrenRegistratieFlex, FactuurFlex) modeled and persisted
- ✅ Bureau-validatie gate (SNA + G-rekening) implemented and enforced on opdracht-create
- ✅ Inlenersbeloning-onderbouwing mandatory before `status=actief`
- ✅ Fase-progressie auto-detected on uren-registration threshold
- ✅ Manager approval flow for uren with auto-rappel (3d) and escalation (7d)
- ✅ Factuur-matching logic: amount, tariff, opdracht validation with dispute escalation
- ✅ G-rekening split calculated and exposed in betaalopdracht
- ✅ TCO dashboard: active count, FTE, avg tariff, monthly cost, vs-vast calculator per cost center
- ✅ All REQ-001 through REQ-010 scenarios passing

## Related Work

- **ADR-001: Information Architecture** — placement rules (this lands as SUB_PAGE under Medewerkers)
- **employee-master spec** — FunctieProfiel entity, manager references
- **payroll-engine-nl spec** — CAO-staffel mutations, phase-drempel codebook
- **finance-export spec** — factuur-import, betaalopdracht-export
- **task-management spec** — approval tasks, escalation workflow
