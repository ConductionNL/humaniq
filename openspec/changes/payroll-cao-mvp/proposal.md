# Proposal: payroll-cao-mvp

## Why

Dutch employers must apply sector-specific CAO (Collectieve Arbeidsovereenkomst) rules to payroll: minimum salaries, working hours, leave entitlements, and overtime thresholds vary per sector. Without CAO support, HRMQ cannot produce legally compliant payroll for the majority of Dutch employees. Competitors ship 80–400+ pre-built CAO libraries (Loket: 250+, AFAS: 200+, Visma Raet: 400+); shipping the 10 most-common CAOs makes HRMQ viable for MKB onboarding and closes the most critical competitive gap identified across 14 competitor implementations.

## What Changes

- Pre-built JSON rulesets for 10 NL CAOs: Schoonmaak, Horeca, Kappersbedrijf, Detailhandel non-food, Metaal & Techniek, Bouw, ICT, Zorg VVT, Beveiliging, and Algemeen (geen CAO)
- OpenRegister schema and register for CAO objects
- REST API for CAO listing, detail, and per-organisation activation
- Admin UI to browse and activate/deactivate CAOs
- Employee-contract to CAO assignment so that payroll-core-basic can enforce CAO salary minimums

## Capabilities

### New Capabilities

- `cao-library`: Store, version, and serve pre-built CAO JSON rulesets covering salary scales, working hours, leave entitlements, and overtime rules for 10 NL sectors
- `cao-admin`: Nextcloud admin UI for browsing, inspecting, and activating CAOs per organisation
- `cao-employee-link`: Assign an active CAO to an employee contract; expose linked CAO rules to payroll-core-basic for minimum salary enforcement

## Impact

- `lib/Db/Cao.php` + `lib/Db/CaoMapper.php`: CAO entity and OpenRegister-backed mapper
- `lib/Service/CaoService.php`: CAO lookup, activation, and rule-retrieval business logic
- `lib/Controller/CaoController.php`: REST controller — `GET /api/caos`, `GET /api/caos/{id}`, `PUT /api/caos/{id}/activate`
- `appinfo/routes.php`: New CAO API routes
- `src/views/CaoView.vue` + `src/components/cao/`: Admin list and detail UI
- `appinfo/openregister.json`: CAO schema and register registration
- `resources/cao-rulesets/`: 10 seed JSON files loaded via `IRepairStep` on install/upgrade
- `lib/Migration/InstallCaoRulesets.php`: Repair step that loads seed CAO data into OpenRegister
