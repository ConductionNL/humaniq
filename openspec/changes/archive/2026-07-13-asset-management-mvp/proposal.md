---
kind: config
---

# Asset Management MVP (assets and effective-dated asset uitgiftes on the register)

## Why

The Spectr canon insight `hrmq-canon-asset-management` scores asset management at **2/9 competitive coverage** — almost nobody in the Dutch-SMB HR segment ships it, so a working asset register is differentiation, not parity — and the 2026-05-22 round-0 draft (`spec/asset-management`, Specter Intelligence) documents the pain verbatim: MKB employers track wie-heeft-welke-laptop across an HR system, Excel sheets and external tools (TOPdesk, Snipe-IT), lose offboarding visibility into asset return status, and reconcile by hand on every lifecycle event. hrmq already owns both natural integration anchors: the `Onboarding` case (hr-onboarding.json) whose checklist proves start-of-employment facts, and the parallel `offboarding-wizard-mvp` change whose `Offboarding` case carries an `assetsIngeleverd` checklist assertion — an assertion that is only auditable if the assets themselves are records. Today hrmq has **no asset surface at all**: no schema, no rules, no pages.

The draft proposed five entities (Asset, AssetAssignment, LeaseCarTaxRecord, AssetHistoryEntry, AssetCategory), SQL migrations, RabbitMQ payroll event coupling, bijtelling staffel automation, barcode scanning and GDPR anonymization batches — legacy-shaped for this codebase and mostly out of MVP reach. This change delivers the core data capability the modern hrmq way: two declarative schemas in a register fragment (one with an `x-openregister-lifecycle` on asset status), `$ref`-driven related widgets, two machine-checkable corpus rules, manifest pages under the existing expenses group, and seed data — no bespoke PHP models, no migrations, no event bus.

**Fleet note:** vehicles are ordinary Assets with a `kenteken` (category `voertuig`) — a fleet list, who drives what, since when. Fiscal **bijtelling is explicitly out of scope** (see Non-goals): the Spectr canon insight `hrmq-canon-fleet-bijtelling` marks bijtelling automation as engine-blocked — it needs staffel tables, DET + 60-month term tracking and a payroll-engine coupling hrmq does not have — so shipping the fleet *register* now without the fiscal calculation is the honest cut.

## What Changes

- **New register fragment `lib/Settings/register.d/hr-assets.json`** with two schemas:
  - **`Asset`** — a company-owned item issued to employees: `name`, `category` (enum `laptop`/`telefoon`/`voertuig`/`gereedschap`/`toegangspas`/`kleding`/`overig`), `serienummer` (nullable), `kenteken` (nullable — licence plate, meaningful for `voertuig`), `aanschafdatum` (nullable), `aanschafwaarde` (number, nullable), `status` with a declarative **`x-openregister-lifecycle`** (`beschikbaar` → `uitgeven` → `uitgegeven` → `innemen` → `ingenomen` → `vrijgeven` → back to `beschikbaar`, and `afschrijven` → `afgeschreven` terminal), `active` (boolean).
  - **`AssetAssignment`** — an effective-dated uitgifte of one asset to one employee (the OrgAssignment pattern from hr-org.json): `assetId` (`$ref: Asset`, required), `employeeId` (`$ref: Employee`, required), `uitgifteDatum` (required), `innameDatum` (nullable — null while the asset is out), `uitgifteBonSigned` (boolean), `notes`. No lifecycle — a plain effective-dated record.
- **Declarative relations**: both reference fields are canonical `$ref`s (ADR-062 rule 7 — Asset and Employee both exist in this register set), so the renderer's `related` widget resolves them by name and FK-scoped `object-list` widgets list a given asset's assignments — zero code.
- **Two new machine-checkable NL rules** in the labour corpus (`lib/Standards/rules/labour.json`) + a new check provider `lib/Standards/Checks/NlAssetChecks.php`:
  - `nl-asset-assignment-consistency` — an open AssetAssignment must reference an existing Asset in status `uitgegeven` (dangling refs fail closed); `innameDatum >= uitgifteDatum` whenever present;
  - `nl-asset-inname-bij-offboarding` — an open AssetAssignment whose employee has an Offboarding past its planned completion date is flagged (asset return is part of clean offboarding). Written **defensively** against the parallel `offboarding-wizard-mvp` change: it passes vacuously while no Offboarding objects exist, so the two changes land in either order.
- Both rules ride the established `RuleAuditService::buildRelatedContext()` mechanism, extended consistently with an `Asset` index and a defensive `Offboarding` index. `RuleCatalogue::VERSION` is bumped per SCHEMA.md's rule.
- **Manifest pages** as children of the **existing** `ExpensesGroup` ("Onkosten") menu — the group `hrmq-ia-navigation-alignment` re-homes to ADR-001 menu 7 "**Declaraties & assets** (— declaraties, **assets**, WKR-overzicht)", the frozen placement these pages are named for: `Assets` index + `AssetDetail` (data + lifecycleActions + related + assignments list + Files widget for signed uitgiftebonnen), `AssetAssignments` index + `AssetAssignmentDetail`; deep links for both schemas. No group renames here — IA renames are owned by the active `hrmq-ia-navigation-alignment` change.
- **Seed data**: 3 Assets (a laptop `uitgegeven`, a telefoon `beschikbaar`, a voertuig with kenteken `uitgegeven`) and 2 AssetAssignments for the slugged seed employees (one closed and consistent, one open and **deliberately inconsistent** — an open uitgifte on the `beschikbaar` telefoon) so the consistency rule visibly fires on seed data.

### Non-goals

- **No bijtelling / LeaseCarTaxRecord** — fiscal bijtelling (staffel lookup, cataloguswaarde brackets, DET + 60-month term boundary, payroll propagation) is **engine-blocked** per Spectr `hrmq-canon-fleet-bijtelling`: it requires maintained Belastingdienst staffel data and a payroll-engine coupling that do not exist yet. A voertuig Asset carries its `kenteken` and nothing fiscal. Follow-up spec `fleet-bijtelling` when the engine dependency lands.
- **No AssetHistoryEntry** — OpenRegister object audit trails already record every mutation; a bespoke append-only history entity duplicates the platform (same reasoning that dropped OrgChartSnapshot in org-chart-basic).
- **No payroll event coupling (RabbitMQ)**, no barcode/QR scanning, no CSV bulk import, no damage-report flow with photo upload, no GDPR anonymization batch, no leverancier/leasemaatschappij references (would need an Organisation schema this register set does not have — ADR-062 rule 7 forbids dangling `$ref`s), no depreciation schedules — all draft Phase-2-or-later material, deferred.
- **No offboarding blocking-gate** — the draft's "offboarding checklist blocks eind-afrekening until all assets returned" is a cross-schema lifecycle guard on Offboarding, which `offboarding-wizard-mvp` owns; this change contributes the audit-time rule (`nl-asset-inname-bij-offboarding`) that makes the gap visible either way.
- **No uniqueness enforcement** (serienummer uniqueness, one-open-assignment-per-asset) — audit-time consistency over write-time constraints, the org-chart-basic posture; the corpus rule flags the observable damage (open assignment on a non-uitgegeven asset).

## Capabilities

### New Capabilities

- `asset-management`: the Asset/AssetAssignment schemas + `hr-assets` fragment, the asset status lifecycle, `$ref`-driven related surfaces, the assignment-consistency and inname-bij-offboarding corpus rules and checks, the asset pages under the expenses group, and the seed data.

### Modified Capabilities

<!-- none — existing specs are untouched; the menu children are added to the existing ExpensesGroup without renaming it (IA renames stay owned by hrmq-ia-navigation-alignment) -->

## Impact

- `lib/Settings/register.d/hr-assets.json` — **new** fragment: `Asset` (with `x-openregister-lifecycle` on `status`) + `AssetAssignment` schemas.
- `lib/Standards/rules/labour.json` — 2 new NL rules (`nl-asset-assignment-consistency`, `nl-asset-inname-bij-offboarding`); `lib/Standards/rules/SCHEMA.md` framework examples gain `hr-assets-core`; `RuleCatalogue::VERSION` per SCHEMA.md's bump rule (at authoring time `2026-07.5` → `2026-07.6`; re-verify against HEAD at apply — parallel changes bump the same constant).
- `lib/Standards/Checks/NlAssetChecks.php` — **new** auto-discovered check provider.
- `lib/Service/RuleAuditService.php` — `buildRelatedContext()` gains an `Asset` index (`{id, status, active}` by id) and a defensive `Offboarding` index (planned-completion date by employeeId, empty until `offboarding-wizard-mvp` lands).
- `src/manifest.json` — `Assets`/`AssetDetail`/`AssetAssignments`/`AssetAssignmentDetail` pages, two `ExpensesGroup` menu children, two `deepLinks` entries.
- `lib/Settings/register.d/hr-seed.json` — 3 Asset + 2 AssetAssignment seeds (one open uitgifte deliberately inconsistent).
- `lib/Repair/InitializeRegister.php` — no change (fragment glob picks up the new file).
- Related active changes: `hrmq-ia-navigation-alignment` (owns the ExpensesGroup → "Declaraties & assets" re-homing; this change adds children to whatever group hosts Expenses at apply time), **`offboarding-wizard-mvp` (parallel)** — owns the Offboarding schema + `assetsIngeleverd` checklist field in hr-onboarding.json; `nl-asset-inname-bij-offboarding` is written to pass vacuously without it (either landing order works), `hrmq-employee-relations-widget` (owns generic related surfacing on EmployeeDetail — no EmployeeDetail change here).
