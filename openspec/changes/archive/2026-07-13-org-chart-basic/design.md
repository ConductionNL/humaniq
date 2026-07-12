# Design — org-chart-basic

## Context

hrmq models people (`Employee`), their contracts, hours, expenses, leave, payroll and filings — but not the organisation they sit in. The only organisational trace is a free-text `costCenter` string on Timesheet seeds (`CC-100`/`CC-200`). Everything this change needs already exists as an established pattern:

- register fragments in `lib/Settings/register.d/` are glob-imported by the Repair step (no code change for a new file);
- ADR-062 rule 7 made `employeeId` a canonical `$ref` on EmploymentContract/Payslip/Timesheet/Expense, and the detail pages' `related` widget resolves those refs by name (see EmployeeDetail's `_note`: "the record every other HR/payroll schema points AT");
- FK-scoped `object-list` widgets on EmployeeDetail (Contracts/Timesheets/Payslips/Expenses filtered on `employeeId: @objectId`) are the declared-relation child-list pattern to mirror;
- the versioned rule corpus + auto-discovered `CheckProvider`s enforce 90+ rules, and `RuleAuditService::buildRelatedContext()` (pension-filing-upa-mvp) is the established cross-type index predicates read from `$context['related']`.

Source material: the round-0 draft on `spec/org-chart-basic` (Specter Intelligence, 2026-05-23) and the Spectr ranked build-list insight `hrmq-insight-ranked-buildlist`. The draft's *why* is adopted (manager self-service team scope, cost-center owners for shillinq, onboarding/offboarding placements, effective-dated history); its *how* (SQL migrations, bespoke models/endpoints, D3.js tree, snapshot caching) predates the declarative architecture and is re-scoped below.

## Goals / Non-Goals

**Goals:** an `OrgUnit` hierarchy (self-referencing parent) with type, cost-center and manager; effective-dated `OrgAssignment` placements linking employees to units; `$ref`-driven related surfaces so OrgUnit ↔ OrgAssignment ↔ Employee resolve on every detail page; assignment-consistency and cycle-freedom as versioned machine-checkable corpus rules; index/detail pages under Personeel; a seeded example hierarchy exercising the rules.

**Non-Goals:** OrgChartSnapshot materialisation (no query volume justifies it — scale follow-up), D3.js interactive tree + PNG/PDF/SVG/JSON export (custom visual widget belongs in nc-vue first — follow-up `org-chart-visualization`), reporting-line/cost-center REST wrappers (ADR-022: consume OpenRegister's object API), bulk reorg, matrix reporting, primary-assignment uniqueness, Employee.managerId compatibility (no such field exists).

## Decisions

### D1 — Two effective-dated schemas, no snapshot entity

The draft's OrgUnit/OrgAssignment split is kept: OrgUnit is the structure (a department/team/cost-center node), OrgAssignment is the time-bounded placement of one employee in one unit. Effective-dating lives on the *assignment* (`startDate` required, `endDate` nullable = current), matching the draft; OrgUnit versioning-by-validity-range is dropped for MVP — a unit is toggled with `active` instead, and OpenRegister's audit trail answers "when did this change". `OrgChartSnapshot` is dropped entirely: it existed to make historical tree queries fast, and hrmq has no read volume that justifies a materialisation pipeline. If it ever does, snapshots are a purely additive follow-up schema.

### D2 — Relations are canonical `$ref`s; the UI is the existing related/object-list machinery

All four reference fields are `$ref`s per ADR-062 rule 7 (every target schema — Employee, OrgUnit — exists in this register set): `OrgUnit.parentUnitId` → OrgUnit (self), `OrgUnit.managerId` → Employee, `OrgAssignment.employeeId` → Employee, `OrgAssignment.orgUnitId` → OrgUnit. Consequences, all zero-code:

- **outbound**: the `related` widget on OrgUnitDetail resolves the parent unit and the manager by name; on OrgAssignmentDetail it resolves the employee and the unit — exactly how TimesheetDetail's related panel resolves `employeeId` today;
- **inbound**: child units and a unit's assignments are FK-scoped `object-list` widgets (`filter: { parentUnitId: "@objectId" }` / `{ orgUnitId: "@objectId" }`), the EmployeeDetail Contracts/Timesheets pattern;
- **hub growth**: EmployeeDetail (the hub archetype) gains an Assignments `object-list` mirroring its existing lists — the page structure supports this cleanly (it is one more widget + one more layout row, exactly how emp-contracts/emp-timesheets are declared).

References are stored as UUIDs (relations widget contract); seeds reference by slug, resolved at import like every existing seed FK.

### D3 — Consistency and acyclicity are corpus rules riding the related-context

Both rules are deterministic over structured fields, so they are `machineCheckable: true` corpus entries enforced by a new `NlOrgChecks` provider — the app's established ADR-031 exception for domain-rule evaluation:

- **`nl-org-assignment-consistency`** (on `OrgAssignment`): violates when `endDate` is present and `endDate < startDate` (incoherent dates), or when the assignment is *active* (no `endDate`, or `endDate` on/after the audit date) and `orgUnitId` does not resolve in the OrgUnit index or resolves to a unit with `active: false`. Fail-closed on dangling references, like `nl-upa-payrollrun-approved`.
- **`nl-org-unit-cycle`** (on `OrgUnit`): walks the `parentUnitId` chain through the OrgUnit index carrying a **visited set**; violates when the walk re-enters a visited id (including self-parenting). A dangling parent simply ends the walk — that is a missing-node problem, not a cycle, and inventing a violation for it here would double-report what the register's own referential surface shows.

Cross-object data comes from the existing mechanism: `RuleAuditService::buildRelatedContext()` is extended **consistently** with a third index, `context['related']['OrgUnit']` = `byId` map of `{id, parentUnitId, active}` — same shape, same degrade-to-empty behaviour when the schema is not yet imported, same single pre-pass load. `OrgAssignment` needs no index (no predicate reads assignment siblings). The predicate contract stays `fn(array $o, array $context): bool`; no RuleEngine change.

Corpus placement: both rules go in `lib/Standards/rules/labour.json` (`domain: labour` — the organisational placement of employees is labour-relationship data, and the file-per-domain rule in SCHEMA.md keeps them there rather than in a new file). They are administration-integrity controls, not statute transcriptions, so they follow the `xc-payroll-gl-reconciliation` precedent of a control-style `source` with a new opaque `framework: hr-org-core` slug (added to SCHEMA.md's examples, which is the only place frameworks are enumerated). Severity `mandatory`: a cyclic hierarchy breaks every consumer that walks it, and an inconsistent active assignment silently corrupts team scope and cost-center routing — the two integrations this feature exists for.

### D4 — Schema.org: OrgUnit is `schema:Organization`, OrgAssignment is `schema:EmployeeRole`

Per the schema.org marker convention: an OrgUnit is a (sub)organisation — `schema:Organization` (whose `parentOrganization`/`subOrganization` mirror `parentUnitId` exactly). An OrgAssignment is a person-in-role-at-organisation with a start and end date — `schema:EmployeeRole`, the same marker EmploymentContract carries; both are Role records and schema.org intends Role reuse (the contract is the legal agreement, the assignment is the structural placement — distinct records, same shape of annotation). `type` uses Dutch domain values (`afdeling`/`team`/`kostenplaats`) consistent with the corpus/lifecycle vocabulary precedent (Dutch domain terms in data); the draft's six-value enum (`company`/`division`/…) is collapsed to the three the seeds and integrations actually need — enum append later is non-breaking.

### Declarative-vs-imperative decision (ADR-031)

| behaviour | path | rationale |
|---|---|---|
| OrgUnit / OrgAssignment data model + relations | **declarative** schemas with `$ref`s in the fragment | ADR-031 default; renderer resolves related/object-list widgets |
| Detail/index/child-list UI | declarative manifest pages | existing page archetypes; no custom Vue |
| Assignment consistency, unit-cycle detection | imperative **CheckProvider** methods (`NlOrgChecks`) | domain-rule evaluation over the versioned corpus — the established ADR-031 exception all 90+ rules use; a JSON-schema cannot express cross-object or graph predicates |
| OrgUnit sibling index for the checks | imperative pre-pass in `RuleAuditService::buildRelatedContext()` | the established related-context mechanism (pension-filing-upa-mvp), extended with one more index |
| Lifecycle / guards | **none** | plain records — no workflow states, so no `x-openregister-lifecycle`, no lifecycleActions, no guard exception needed |

## Schemas (new fragment `lib/Settings/register.d/hr-org.json`)

OpenAPI 3.0.0 `components.schemas` fragment shape (like `hr-leave.json`), `x-hrmq-fragment: hr-org`, two schemas:

**`OrgUnit`** (slug `OrgUnit`, icon `SitemapOutline`, version `0.1.0`, `x-schema-org: schema:Organization`):

- `name` — string, **required**. Unit name ("Directie", "Consultancy").
- `type` — string enum `afdeling|team|kostenplaats`, **required** (D4).
- `parentUnitId` — string, format uuid, `$ref: OrgUnit`, nullable. Self-referencing parent; null for root units. Chains must be acyclic (`nl-org-unit-cycle`).
- `costCenter` — string, nullable. Cost-center code (`CC-100` style, matching the Timesheet seed vocabulary) — the key shillinq-side consumers resolve owners through.
- `managerId` — string, format uuid, `$ref: Employee`, nullable. The unit's manager/cost-center owner.
- `active` — boolean, default `true`. Inactive units must not carry active assignments (`nl-org-assignment-consistency`).

`required: [name, type]`.

**`OrgAssignment`** (slug `OrgAssignment`, icon `AccountArrowRightOutline`, version `0.1.0`, `x-schema-org: schema:EmployeeRole`):

- `employeeId` — string, format uuid, `$ref: Employee`, **required**. The placed employee.
- `orgUnitId` — string, format uuid, `$ref: OrgUnit`, **required**. The unit placed into.
- `role` — string, nullable. Functie/role within the unit ("Consultant", "Officemanager").
- `startDate` — string, format date, **required**. Placement effective from (effective-dating per the draft).
- `endDate` — string, format date, nullable. Placement effective until; null while current. Must be ≥ `startDate` when present (`nl-org-assignment-consistency`).

`required: [employeeId, orgUnitId, startDate]`. No lifecycle on either schema (D-table above).

## New corpus rules (labour.json)

| id | source | statement (short) | severity | machineCheckable |
|---|---|---|---|---|
| `nl-org-assignment-consistency` | HR-administration control (organisational-structure integrity) | An active OrgAssignment (no endDate, or endDate not in the past) must reference an existing OrgUnit with `active: true`, and `startDate <= endDate` must hold whenever endDate is present | mandatory | true |
| `nl-org-unit-cycle` | HR-administration control (organisational-structure integrity) | An OrgUnit's `parentUnitId` ancestor chain must be acyclic — no unit may be its own (transitive) parent | mandatory | true |

Both: `domain: labour`, `jurisdiction: NL`, `framework: hr-org-core` (**new** opaque framework slug — added to the examples list in `lib/Standards/rules/SCHEMA.md`), no `sourceUrl` mandate (integrity controls, `xc-*` precedent). `RuleCatalogue::VERSION` follows SCHEMA.md's "bump on any change" rule (no further bump needed if it already reads the current month).

Checks live in the **new** auto-discovered provider `lib/Standards/Checks/NlOrgChecks.php` (implements `CheckProvider`, empty `seedSpec()` — seeds live in hr-seed.json per ADR-001, and a self-contained provider sample could not carry resolvable cross-references, the `NlPensionFilingChecks` reasoning): an `OrgAssignment` predicate for `nl-org-assignment-consistency` (dates + active-unit lookup via `context['related']['OrgUnit']['byId']`, fail-closed) and an `OrgUnit` predicate for `nl-org-unit-cycle` (visited-set parent walk over the same index).

## Manifest delta

- `OrgUnits` (new index page, route `/org-units`): register `hrmq`, schema `OrgUnit`, columns `name`, `type`, `costCenter`, `managerId`, `active`; filters `type`, `active`; sort `name` asc.
- `OrgUnitDetail` (new detail page, route `/org-units/:id`): a `data` widget (excluding `parentUnitId`/`managerId` — the Related panel resolves both by name, the EmployeeDetail exclude convention); a `related` widget; an FK-scoped `object-list` "Child units" (`OrgUnit`, `filter: { parentUnitId: "@objectId" }`, rowRoute `OrgUnitDetail`); an FK-scoped `object-list` "Assignments" (`OrgAssignment`, `filter: { orgUnitId: "@objectId" }`, columns employee/role/start/end, rowRoute `OrgAssignmentDetail`); audit-history sidebar tab. No lifecycleActions (no lifecycle on the schema — the PayrollRunDetail "never invent a transition" precedent).
- `OrgAssignments` (new index page, route `/org-assignments`): columns `employeeId`, `orgUnitId`, `role`, `startDate`, `endDate`; filters `role`; sort `startDate` desc.
- `OrgAssignmentDetail` (new detail page, route `/org-assignments/:id`): a `data` widget (excluding `employeeId`/`orgUnitId`), a `related` widget resolving both refs, audit-history sidebar tab.
- `EmployeeDetail`: new `object-list` "Assignments" (`OrgAssignment`, `filter: { employeeId: "@objectId" }`, sort `startDate` desc, columns unit/role/start/end, rowRoute `OrgAssignmentDetail`, viewAllRoute `OrgAssignments`) — declared exactly like the existing emp-contracts/emp-timesheets lists, added as a new layout row before the Personnel-file Files widget (which shifts down).
- Menu: `EmployeesGroup` (Personeel) gains children `OrgUnits` ("Organisatie-eenheden", icon `SitemapOutline`) and `OrgAssignments` ("Plaatsingen", icon `AccountArrowRightOutline`) after Contracten. ADR-001 IA re-homing, if any, stays owned by `hrmq-ia-navigation-alignment`.
- `deepLinks`: `OrgUnit` → `/apps/hrmq/org-units/{uuid}`, `OrgAssignment` → `/apps/hrmq/org-assignments/{uuid}`.
- `npm run check:manifest` must stay green.

## Seed Data (ADR-001)

Seeds go in `lib/Settings/register.d/hr-seed.json`, referencing by slug (the Timesheet `employeeId` mechanism). The employee slugs the seed file already uses throughout are `employee-jansen`, `employee-devries`, `employee-bakker`.

- **3 OrgUnit seeds** (Directie → Consultancy team + Backoffice):
  1. `orgunit-directie` — name "Directie", type `afdeling`, no parent (root), costCenter null, managerId `employee-jansen`, active.
  2. `orgunit-consultancy` — name "Consultancy", type `team`, parent `orgunit-directie`, costCenter `CC-100` (the code Timesheet seeds already use), managerId `employee-devries`, active.
  3. `orgunit-backoffice` — name "Backoffice", type `afdeling`, parent `orgunit-directie`, costCenter `CC-200`, managerId null (exercises the nullable manager), active.
- **3 OrgAssignment seeds**:
  1. `orgassignment-jansen-consultancy` — `employee-jansen` → `orgunit-consultancy`, role "Consultant", startDate `2024-01-01` (matches the Employee seed's startDate), endDate null (current; consistent).
  2. `orgassignment-devries-backoffice` — `employee-devries` → `orgunit-backoffice`, role "Officemanager", startDate `2025-03-01`, endDate null (current; consistent).
  3. `orgassignment-bakker-consultancy` — `employee-bakker` → `orgunit-consultancy`, role "Junior consultant", startDate `2026-06-01`, **endDate `2026-05-01`** — deliberately incoherent (`endDate < startDate`) so `occ hrmq:rules:audit` reports exactly one `nl-org-assignment-consistency` violation on seed data (the pension-seed pattern of one intentional violation per alerting rule).

No seeded cycle: `nl-org-unit-cycle` stays green on seeds (a deliberately cyclic seed would corrupt every tree-walking consumer just to light a lamp; the rule's violating path is pinned by unit tests instead). All identifiers are obvious placeholders.

## Risks / Trade-offs

- **Effective-dating without uniqueness**: nothing stops overlapping or multiple concurrent assignments per employee — deliberate (the draft's "primary assignment" flag and one-primary-at-a-time enforcement are deferred); the consistency rule keeps each record internally coherent, not the set.
- **`active` instead of unit validity ranges**: history of the *structure* (as opposed to placements) lives in the audit trail, not in queryable validity ranges — accepted for MVP; the snapshot follow-up owns as-of queries.
- **Cycle check is audit-time, not write-time**: a cycle can be *saved* and is only flagged by the next audit. A write-time guard would need imperative validation OpenRegister does not offer for non-lifecycle writes; audit-time detection with a `mandatory` severity is the corpus-consistent posture.
- **Pre-existing dangling employee slugs**: hr-seed.json references `employee-devries`/`employee-bakker` throughout (Timesheets, LeaveBalances, SickLeaveCases) while only `employee-jansen` has a slugged Employee seed. The new assignments follow the file's established convention; closing that pre-existing gap is a seed-hygiene fix outside this change's scope (flagged for `hrmq-test-coverage-baseline`-era cleanup).
- **Dutch enum values** (`afdeling`/`team`/`kostenplaats`): consistent with the repo's Dutch-domain-terms-in-data precedent; renames later would be breaking, appends are not.

## Open Questions

- None blocking. The D3 tree/exports (`org-chart-visualization`, nc-vue-first), snapshot materialisation, reorg tooling and primary-assignment enforcement are recorded follow-ups (Non-Goals).
