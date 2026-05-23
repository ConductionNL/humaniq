---
status: proposed
app: hrmq
spec: document-template-engine
owner: hrmq-platform
depends_on: [hrmq-base, docudesk]
target_users: [hr-admin, payroll-admin, contract-owner, tenant-admin]
---

# Document Template Engine

## Placement & Information Architecture

**Placement type:** `SUB_PAGE` — Sub-page beneath a top-level menu entry. Renders as a page inside the parent surface (usually reachable via a router child route or a tab on the parent index page).

**Lives at:** Configuratie › Sjablonen

**Rationale:** Template-engine.  
_Source: /tmp/ia-procest-hrmq.md_

> **Implementation note for builders:** Respect the placement above. Do not promote this spec to a top-level menu item, sub-page, or new route unless the placement type explicitly says so. If the placement is `DETAIL_TAB`, `WIDGET`, `ACTION`, `SETTING`, or `INFRA`, the feature must NOT introduce a new entry in the app sidebar. When in doubt, ask before creating a new top-level surface.

## Purpose

HR-administratie is documentwerk. Every employee event produces paper: contract, addendum bij promotie, vaststellingsovereenkomst bij uit-diensttreding, getuigschrift, salarisverhoging-brief, BAPO-bevestiging, demotie-akkoord, concurrentiebeding-opheffing. Every Dutch werkgever has the same dozen template-types but each tenant wants its own tone of voice, briefpapier, ondertekening-blok, en juridische clausules. Today most kleine werkgevers do this in Word: copy-paste het vorige contract, hand-edit naam en bedrag, hopen dat geen verouderde clausule blijft staan. Larger werkgevers buy point-tools (FlexHR, AFAS-template-module) that are closed-source en zelden goed integreren.

Het risico van de Word-aanpak is groter dan veel HR-medewerkers beseffen. Een verouderde concurrentiebeding-clausule die nog uitgaat van pre-WAB-recht is in een ontslag-procedure waardeloos. Een proeftijd-clausule die niet voldoet aan de WWZ-eisen (max 1 maand bij contract <2 jaar) maakt het hele beding nietig. Een vaststellingsovereenkomst zonder de juiste WW-veilige formulering kost de werknemer (en uiteindelijk de werkgever) duizenden euro's aan uitkering. Centrale, juridisch-getoetste templates die automatisch de juiste versie pakken op basis van de contract-datum is geen luxe — het is risicobeheersing.

This spec defines a cross-cutting template engine for HRMQ: a tenant-scoped library of versioned templates with merge-fields drawn from the employee+contract data-model, deterministic PDF rendering via docudesk, and an approval-workflow for legal-review van significant uitgaven (vaststellings-overeenkomsten, demoties). The engine is the single rendering pipeline behind every brief HRMQ produces — contracts (REQ-VO from cao-onderwijs-vo), loonstroken (payroll-engine-nl), jaaropgaven (zzp-dga + standard), en interne HR-brieven. Door alle document-rendering door één engine te laten lopen krijgen we één plek voor audit-trails, één plek voor tenant-branding, één plek voor PDF/A-conformance, en één plek voor multi-language ondersteuning.

Templates are written in a constrained Markdown dialect with `{{employee.name}}`-style merge-fields, conditional blocks (`{{#if contract.proeftijd}}...{{/if}}`), and named partials (`{{> handtekening_blok}}`) for the tenant-branding chrome. Merge-fields are validated at template-save time against a schema — typos in field-names are caught before they reach production. Rendering is deterministic: same template + same data → byte-identical PDF, which matters for audit-trails. We bewust kiezen voor Markdown + Mustache boven complexere alternatieven (Word XML, LaTeX, Twig): Markdown is begrijpelijk voor HR-medewerkers zonder technische achtergrond, en de constrained mustache-syntax is veilig (geen arbitrary code-execution in templates).

De engine is expliciet niet bedoeld als generiek document-systeem — voor algemene document-storage gebruiken we docudesk. Deze engine is HR-specifiek in de zin dat zij weet over employees, contracts, payroll-runs, en CAO-modules. Voor cross-app re-use bieden we wel een library-package (`@hrmq/template-engine`) zodat andere Conduction-apps de engine kunnen embedden voor hun eigen document-rendering — shillinq voor facturen, decidesk voor besluitenformulieren, scholiq voor diploma's.

## Data Model

`hrmq_document_template`: `id`, `tenant_id`, `slug` (e.g. `arbeidsovereenkomst-onbepaalde-tijd`), `name`, `version` (semver), `status` (`draft`, `active`, `archived`), `effective_from`, `effective_until`, `markdown_source`, `merge_field_schema` (jsonb, derived from source on save), `partials_used[]`, `locales[]`, `requires_approval` (bool), `approval_workflow_id`, `rechtsgebied` (enum nl/be/de — default nl). `hrmq_document_partial`: tenant-scoped reusable blocks (briefpapier-header, ondertekening, AVG-clausule) met dezelfde versioning als templates. `hrmq_document_approval_workflow`: ordered chain van approver-roles, met optioneel `min_amount_threshold` om approval alleen boven een drempel te eisen. `hrmq_document_render`: `id`, `template_id`, `template_version`, `target_entity_type` (`employee`, `contract`, `payroll_run`, `tenant`), `target_entity_id`, `merge_data_snapshot` (jsonb, immutable), `locale`, `pdf_blob_url`, `pdf_hash_sha256`, `pdf_a_compliant` (bool), `rendered_at`, `rendered_by`, `superseded_by_render_id` (voor re-renders). `hrmq_document_approval` for the workflow when `requires_approval = true`: `render_id`, `approver_role`, `approver_user_id`, `decision` (`approved`, `rejected`), `comment`, `decided_at`. `hrmq_document_bulk_run` voor mass-mailings: `id`, `template_id`, `target_set` (jsonb employee-ids), `status`, `manifest_url`.

## Requirements

### REQ-001: Template authoring met merge-field validatie

**GIVEN** an HR-admin schrijft een nieuw template in de UI-editor
**WHEN** zij `{{employee.bsn}}` typt en de field-picker autocompletes
**THEN** the system valideert tegen het employee-schema op save, blokkeert non-bestaande velden (`{{employee.bsnummer}}` → error), en toont een live preview met sample-data uit een randomly-selected employee record uit de tenant. Save met onbekende fields wordt geweigerd met een concrete foutmelding ("regel 42: employee.bsnummer bestaat niet, bedoelde je employee.bsn?").

### REQ-002: Conditionele blokken

**GIVEN** een arbeidsovereenkomst-template met `{{#if contract.proeftijd_maanden > 0}}...{{/if}}` rond een proeftijd-paragraaf
**WHEN** the template renders voor een contract zonder proeftijd
**THEN** het hele blok wordt weggelaten zonder lege regels, en bij rendering met proeftijd = 2 wordt de tekst "een proeftijd van 2 maanden" ingevoegd. Nested conditionals worden ondersteund (max 5 niveaus diep), en een conditie-fout (`contract.foo` bestaat niet) faalt fail-fast met regelnummer.

### REQ-003: Tenant-branding via partials

**GIVEN** een tenant heeft een eigen logo, briefpapier-header, en ondertekening-blok
**WHEN** een template `{{> briefhoofd}}` en `{{> ondertekening}}` includes
**THEN** de partials worden tenant-scoped opgezocht (geen cross-tenant lookup), met fallback naar een HRMQ-default. Een tenant kan de defaults overriden door een eigen partial met dezelfde slug op te slaan. Partials kunnen zelf merge-fields gebruiken (`{{tenant.kvk_nummer}}`).

### REQ-004: Versioning en effective-date

**GIVEN** een actief template `arbeidsovereenkomst-onbepaalde-tijd` versie 3.1.0
**WHEN** legal-counsel update de WAB-clausule en publiceert versie 3.2.0
**THEN** alle nieuwe renders gebruiken automatisch 3.2.0, eerdere renders behouden hun versie in de audit-trail, en de oude versie blijft beschikbaar voor re-render (b.v. bij correctie van een typo in een naam). Templates kunnen `effective_from` en `effective_until` velden hebben — een nieuwe versie kan gepland worden voor activatie op een toekomstige datum (b.v. CAO-wijziging per 1 januari).

### REQ-005: Render met immutable data-snapshot

**GIVEN** een template wordt gerenderd voor employee X op datum D
**WHEN** the render-call wordt uitgevoerd
**THEN** the system maakt een snapshot van alle merge-data op moment D (employee, contract, tenant), slaat deze immutable op in `hrmq_document_render.merge_data_snapshot`, rendert de PDF, en berekent de SHA256-hash van de output. Een latere wijziging van employee-data heeft geen invloed op het reeds gerenderde document — re-render met dezelfde snapshot geeft byte-identieke output.

### REQ-006: Approval-workflow voor risico-volle templates

**GIVEN** een template `vaststellingsovereenkomst` is gemarkeerd `requires_approval = true` met workflow [HR-Manager → Legal-Counsel → CFO]
**WHEN** een HR-medewerker een render-request indient
**THEN** the system genereert een draft-PDF (watermerk "CONCEPT - niet juridisch bindend"), routeert deze naar HR-Manager voor goedkeuring, vervolgens Legal, vervolgens CFO. Pas na de derde goedkeuring wordt de definitieve PDF gerenderd (zonder watermerk) en gepubliceerd. Elke goedkeurder kan terugsturen met comments; de cycle restart bij rejection.

### REQ-007: Standaard template-library

**GIVEN** een nieuwe HRMQ-tenant wordt aangemaakt
**WHEN** de tenant wordt geprovisioneerd
**THEN** the system installeert een standaard-set van 12 templates (arbeidsovereenkomst-onbepaalde-tijd, arbeidsovereenkomst-bepaalde-tijd, oproepovereenkomst, stageovereenkomst, addendum-promotie, addendum-salarisverhoging, vaststellingsovereenkomst, getuigschrift, ontslag-aanzegging, BAPO-bevestiging, concurrentiebeding-opheffing, geheimhoudingsverklaring) op basis van de tenant's geselecteerde rechtsgebied (NL-default, BE optioneel) en CAO. Templates worden geforked naar de tenant — updates aan de master-library propageren niet automatisch.

### REQ-008: PDF/A-archivering voor wettelijke bewaarplicht

**GIVEN** een gerenderd document met `target_entity_type = contract`
**WHEN** the render compleet is
**THEN** the PDF wordt opgeslagen als PDF/A-2b (archief-kwaliteit, embedded fonts, geen externe verwijzingen), gekoppeld aan het employee-dossier in docudesk met de juiste bewaarter mijn (contracten = 7 jaar na uitdiensttreding per AVG art. 5 + Belastingdienst-eisen), en geïndexeerd voor zoeken op employee-naam, contractnummer, en render-datum.

### REQ-009: Multi-language rendering

**GIVEN** een template heeft varianten voor `nl` en `en`
**WHEN** een render-request expliciet `locale = "en"` specificeert (of de employee `preferred_locale = "en"` heeft)
**THEN** the engine kiest de juiste variant, valideert dat alle merge-fields in beide locales aanwezig zijn (geen ontbrekende vertalingen), en valt terug op de tenant-default-locale met een waarschuwing als de gevraagde variant ontbreekt. Locale-specifieke partials (`> ondertekening:en`) worden ondersteund.

### REQ-010: Bulk-render voor mass-mailings

**GIVEN** een salaris-ronde waarbij alle 240 medewerkers een salarisverhoging-brief ontvangen
**WHEN** HR een bulk-render initieert met template + employee-set
**THEN** the system rendert alle 240 documenten in een achtergrond-job (parallel met max 8 workers), produceert per medewerker een audit-record, levert bij voltooiing een ZIP met alle PDFs en een CSV-manifest, en publiceert per medewerker in hun self-service-portaal. Failures op individuele renders blokkeren de batch niet — de manifest markeert failures voor manual review.

## Standards

- PDF/A-2b (ISO 19005-2) voor archief-kwaliteit
- Markdown CommonMark + Mustache-style merge-syntax
- AVG / GDPR art. 5 (bewaartermijnen)
- Belastingdienst bewaarplicht 7 jaar voor administratie
- eIDAS-compatibele digital signatures (post-MVP, via integration met external signing-provider)
- WCAG 2.2 AA voor PDF accessibility (tagged PDF, alt-text)

## Cross-app

- `docudesk` voor PDF-storage, dossier-koppeling, en bewaartermijn-handhaving
- `payroll-engine-nl` als consumer voor loonstrook + jaaropgaaf rendering
- `cao-onderwijs-vo` (en andere CAO-modules) als consumer voor CAO-specifieke contract-addenda
- `bank-payment-batch-sepa` voor het renderen van pre-notification mailings naar werknemers
- `openconnector` adapter voor signing-providers (Evidos, SignHero) post-MVP

## Target Users

HR-administrateurs die dagelijks contracten en addenda opmaken, payroll-admins die maandelijks loonstroken en jaarlijks jaaropgaven uitsturen, contract-eigenaren (lijnmanagers) die promoties initiëren, legal-counsel voor template-onderhoud en approval-workflow, tenant-admins voor branding en partial-management. Secondary: arbeidsrechtadvocaten die hun klanten een set juridisch-getoetste templates willen leveren, en bookkeeping-software-vendors die de template-engine als embedded-module willen aanbieden.

Tertiaire gebruikers: brancheorganisaties (FNV, CNV, AWVN) die hun leden voor-getoetste template-sets willen leveren als ledenvoordeel, en compliance-auditors (Inspectie SZW, externe accountantskantoren) die de versionering en render-trail van employment-documents controleren. Voor deze laatste groep is de audit-export functie (alle renders voor employee X in kalenderjaar Y met versie-historie van de gebruikte templates) van grote waarde.

Out-of-scope voor dit spec: real-time samenwerking op template-bewerking (zoals Google Docs), WYSIWYG-editor (we starten met een Markdown-source-editor + live preview), elektronische ondertekening (post-MVP via integratie met Evidos/SignHero), en automatic translation (templates worden handmatig in elke locale onderhouden, geen machine-translation in productie).
