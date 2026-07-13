---
capability: asset-management
status: in-progress
built_by: openspec/changes/asset-management-mvp
---

# asset-management Specification

**Status**: in-progress
**Scope**: hrmq
**OpenSpec changes**:
- [asset-management-mvp](../../changes/asset-management-mvp/) _(active)_ — new `Asset` (category/serienummer/kenteken, declarative `beschikbaar`→`uitgegeven`→`ingenomen`→`beschikbaar`/`afgeschreven` custody lifecycle) and effective-dated `AssetAssignment` schemas in a new `hr-assets` fragment, `$ref`-driven related surfaces, 2 new machine-checkable asset-custody rules (framework `hr-assets-core`, defensive against the parallel offboarding-wizard-mvp), asset pages under the expenses group and seed data (kind: config)

## Purpose

Give hrmq its first asset register: company property (laptops, phones,
vehicles, tools, access passes, clothing) as `Asset` records with a
declarative custody lifecycle, and effective-dated `AssetAssignment`
uitgiftes linking assets to employees — in a new `hr-assets` register
fragment, with `$ref`-driven related surfaces, two machine-checkable
custody-integrity rules in the labour corpus (open uitgifte ⇒ asset
`uitgegeven` with fail-closed reference resolution; open uitgiftes past an
employee's offboarding planned completion), asset pages under the expenses
menu group (the frozen ADR-001 menu 7 "Declaraties & assets" placement,
re-homed by `hrmq-ia-navigation-alignment`), and seeded examples including
one deliberate violation. Vehicles are Assets with a `kenteken`; fiscal
bijtelling is an explicit non-goal (engine-blocked per Spectr
`hrmq-canon-fleet-bijtelling` — follow-up spec `fleet-bijtelling`). The
round-0 draft's LeaseCarTaxRecord, AssetHistoryEntry, RabbitMQ payroll
coupling, barcode/CSV tooling and GDPR batches are out of scope (see the
proposal's Non-goals).

## Requirements

Detailed requirements (REQ-AST-001 … REQ-AST-007) are defined in the
active change's delta spec —
[`openspec/changes/asset-management-mvp/specs/asset-management/spec.md`](../../changes/asset-management-mvp/specs/asset-management/spec.md)
— and are merged here when the change is archived. The umbrella requirement
below anchors the capability until then.

### Requirement: Asset custody lives on the register as declarative schemas with audit-time integrity rules (REQ-AST-000)

The app MUST model company assets and their uitgiftes as OpenRegister
objects (`Asset` with a declarative `x-openregister-lifecycle` custody state
machine; `AssetAssignment` as a plain effective-dated record with canonical
`$ref`s to Asset and Employee), surfaced through declarative manifest pages
— no bespoke PHP models, migrations, endpoints or Vue views — and MUST
enforce cross-record custody integrity as versioned, machine-checkable
corpus rules evaluated at audit time via the established
`RuleAuditService::buildRelatedContext()` mechanism, written defensively so
they pass vacuously while the parallel `offboarding-wizard-mvp` change's
Offboarding schema is absent.

#### Scenario: Capability surface is declarative

- GIVEN the hrmq codebase with this change applied
- WHEN the asset surface is inspected
- THEN the data model lives in `lib/Settings/register.d/hr-assets.json`, the UI in `src/manifest.json` page/menu/deepLink entries, and the only imperative code is the `NlAssetChecks` corpus predicates plus the `buildRelatedContext()` index pre-pass (the established ADR-031 exception)
- @e2e exclude structural code-layout assertion with no UI runtime surface; enforced by the change's quality-gate task (manifest check, PHPUnit, rules audit) and hydra gates
