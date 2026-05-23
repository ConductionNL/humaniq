# Document Template Engine — Proposal

**Change:** document-template-engine  
**App:** hrmq  
**Owner:** hrmq-platform  
**Status:** proposed  
**Date:** 2026-05-23

## Executive Summary

HRMQ is documentwerk. Every employee lifecycle event requires templated paper: contracts, addenda, vaststellingsovereenkomsten, getuigschriften, brieven. Today most werkgevers copy-paste Word documents and hand-edit, risking legal non-compliance. This proposal defines a tenant-scoped, cross-cutting template engine that renders versioned, legally-reviewed documents with immutable audit trails, PDF/A archival, and approval workflows — the single rendering pipeline behind every brief HRMQ produces.

## Vision & Rationale

**The risk:** Outdated legal clauses (pre-WAB concurrentiebeding, non-WWZ-compliant proeftijd, missing WW-safe wording in vaststellingsovereenkomsten) in hand-edited Word templates cost thousands in lost disputes and compliance failures. This is not a nice-to-have — it is risicobeheersing for every Dutch werkgever.

**The solution:** Central, versioned, legally-reviewed templates with:
- Deterministic PDF rendering (same template + same data → byte-identical PDF for audit trails)
- Merge-field validation at save time (catch typos before production)
- Immutable data snapshots (render captures employee state at point-in-time)
- Approval workflows for high-risk documents (vaststellingsovereenkomsten, demoties)
- Tenant-scoped branding via partials (logo, briefpapier, ondertekening, juridische clausules)
- PDF/A archival with bewaartermijn-handling (7 jaar per Belastingdienst/AVG)
- Bulk rendering for mass-mailings (salarisronde, jaaropgaven)

**Why this engine, not a bought tool?** FlexHR and AFAS-template modules are closed-source, difficult to integrate, and don't understand Dutch legal requirements or CAO-modules. An in-house engine lets HRMQ's legal team own template compliance, other Conduction-apps embed the same engine (shillinq, decidesk, scholiq), and all documents flow through one audit-trail.

## User Needs & Stories

### Personas

1. **HR-Administrator** (Emma, 10+ years HR, daily contract-work): needs to draft arbeidsovereenkomsten, addenda, vaststellingsovereenkomsten with confidence that legal language is current. Fears: stale clauses, typos in names/bedragen, mis-filled conditionals.

2. **Payroll-Admin** (Piet, monthly loonronde): needs to bulk-render 240 loonstroken + jaaropgaven in one batch with a single approval sign-off. Fears: rendering failures mid-batch, lost audit-trail, re-renders don't match original.

3. **Legal-Counsel** (Mirjam, in-house): needs to maintain template library, review high-risk documents (vaststellingsovereenkomsten) before they reach employees, version clauses when Dutch law changes. Fears: HR team editing templates without review, outdated versioning, no ability to track which employee got which legal wording.

4. **Contract-Owner / Lijnmanager** (Rob): needs to initiate promotie-addenda through a workflow that ensures legal review before the employee sees it.

5. **Tenant-Admin**: needs to fork standard template library to their branding (logo, rechtspositie, ondertekening), override defaults per template.

### Key Stories

**As Emma, an HR-Administrator, I want to write a template with `{{employee.name}}` merge-fields so that I don't hand-edit every contract.**
- AC: The system provides field-picker autocomplete for employee, contract, payroll-run, tenant entities. Typos (`{{employee.bsnummer}}` instead of `bsn`) are blocked on save with the correct field-name suggested.

**As Piet, a Payroll-Admin, I want to bulk-render 240 loonstroken in a background job so that the monthly ronde completes without UI blocking.**
- AC: The system queues the render, produces per-employee PDF + CSV manifest, publishes to self-service-portaal, and marks failures for manual review without blocking successes.

**As Mirjam, Legal-Counsel, I want to version templates and plan activation dates so that new CAO clauses activate on 1 januari automatically.**
- AC: The system stores semver versions, allows `effective_from` / `effective_until` dates, and routes new renders to the active version. Old renders retain their version in the audit-trail.

**As Rob, Contract-Owner, I want to trigger a promotie-addendum workflow that routes through HR-Manager → Legal → CFO for approval before rendering the final document.**
- AC: The system routes the render-request through an approval chain, generates a draft PDF with "CONCEPT" watermerk, collects approvals, and publishes the final PDF only after all sign-offs.

## Scope & Constraints

### In Scope

- Template authoring UI with merge-field validation
- Conditional blocks (`{{#if}}...{{/if}}`)
- Tenant-scoped partials (briefhoofd, ondertekening, clausules)
- Versioning with effective-dates
- Render with immutable data snapshots
- Approval workflows (draft PDF → multistate approvals → final PDF)
- Standard template library (12 template types on provisioning)
- PDF/A-2b archival with docudesk integration
- Multi-language rendering (nl, en per template + locale-specific partials)
- Bulk rendering with CSV manifest + ZIP delivery
- Audit-export (all renders for employee X in period Y with version-history)

### Out of Scope (Post-MVP)

- Real-time collaborative template editing (Google Docs style)
- WYSIWYG editor (Markdown + live preview only in MVP)
- Electronic signing (post-MVP via Evidos/SignHero)
- Machine translation (manual per locale)
- Generic document-storage (use docudesk for that; this engine is HR-specific)

### Technical Constraints

- Templates written in constrained Markdown + Mustache (not Word XML, LaTeX, Twig)
- Merge-fields validated against hrmq data-model schema at save-time
- Rendering must be deterministic: same template + same data → byte-identical PDF
- PDF/A-2b compliance (ISO 19005-2) for archival-quality
- WCAG 2.2 AA for PDF accessibility (tagged PDF, alt-text)
- Dutch legal compliance: CAO-modules, Belastingdienst bewaarplicht (7 jaar), AVG/GDPR art. 5

## Stakeholders & Responsibilities

| Stakeholder | Responsibility |
|---|---|
| **hrmq-platform (owner)** | Engine design, template-library governance, docudesk integration, approval-workflow orchestration, audit-trail |
| **Legal-Counsel (HR team)** | Template authoring, legal review of standard library, CAO clause updates, approval workflow sign-off |
| **Payroll-team** | Bulk-render integration for loonronde, jaaropgaven |
| **Self-Service team** | Portal integration for document delivery to employees |
| **docudesk** | PDF storage, bewaartermijn-tracking, employee-dossier linking |
| **Compliance/Audit** | Template version-history queries, render-trail audits |

## Success Metrics

- **100% of employment documents** flow through the engine (contracts, addenda, vaststellingsovereenkomsten, getuigschriften, brieven)
- **0 legal-clause errors** in production templates (validated at save, legal-reviewed before publication)
- **Audit-trail coverage:** every render linked to template-version, merge-data snapshot, approver identity
- **Bulk-render speed:** 240 loonstroken in <5 minutes (parallel rendering, max 8 workers)
- **PDF/A compliance:** 100% of archives stored as PDF/A-2b
- **Bewaartermijn automation:** 0 manually-managed document retention schedules
- **Tenant adoption:** 100% of tenants fork standard library within week of provisioning

## Dependencies & Cross-App Integration

- **docudesk:** PDF storage, dossier-linking, bewaartermijn-enforcement
- **payroll-engine-nl:** loonstrook + jaaropgaven rendering
- **cao-onderwijs-vo** (+ other CAO-modules): CAO-specific contract-addenda, clause versioning
- **bank-payment-batch-sepa:** pre-notification mailings to werknemers
- **openconnector:** post-MVP signing-provider adapters (Evidos, SignHero)
- **self-service-portaal:** document-delivery to employees
- **hrmq-base:** employee, contract, payroll-run, tenant data models

## Demand & Prioritization

| Feature | Demand | Rationale |
|---|---|---|
| Template authoring with merge-field validation | 🔴 P0 | Core: without this, no legal compliance |
| Conditionals & versioning | 🔴 P0 | Core: CAO clauses, legal updates must be versioned |
| Tenant branding via partials | 🟠 P1 | Important: every tenant needs custom letterhead + T&Cs |
| Approval workflow | 🟠 P1 | Important: legal-review before high-risk docs (vaststellings) |
| Bulk rendering | 🟠 P1 | Important: loonronde, jaaropgaven |
| PDF/A + bewaartermijn | 🔴 P0 | Mandatory: Dutch compliance (7 jaar, audit) |
| Multi-language | 🟡 P2 | Nice: English contracts for expat employees, post-MVP |
| Electronic signing | ⚪ Post-MVP | Out-of-scope: external provider integration |
| Real-time collaboration | ⚪ Post-MVP | Out-of-scope: 95% of templates are <1KB, hand-edited |

## Open Questions & Risks

- **Risk:** Template complexity explosion. Mitigation: constrained Markdown + Mustache, max 5 nesting levels, static analysis on save.
- **Risk:** Docudesk capacity for bulk-storage. Mitigation: validate with docudesk team on archive-strategy.
- **Risk:** Performance on 240-render bulk. Mitigation: parallel workers (max 8), background job, streaming ZIP delivery.
- **Risk:** Multi-language translation effort. Mitigation: 12 templates × 2 languages (nl + en) = 24 source-docs; post-MVP.
- **Question:** Should templates be tenant-private or shareable across tenants? Decision: tenant-scoped forks of a master library (like WordPress themes).
