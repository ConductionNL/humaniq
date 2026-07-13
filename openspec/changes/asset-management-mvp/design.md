# Design — asset-management-mvp

## Context

hrmq models people (`Employee`), contracts, hours, expenses, leave, payroll, filings, onboarding and the org chart — but not the things employees are handed: laptops, phones, vehicles, tools, access passes. The only trace today is the `assetsIngeleverd`-style *assertion* the parallel `offboarding-wizard-mvp` change is adding to its Offboarding checklist — an assertion with nothing behind it. Everything this change needs already exists as an established pattern:

- register fragments in `lib/Settings/register.d/` are glob-imported by the Repair step (no code change for a new file); `hr-org.json` is the direct template — one fragment, one plain effective-dated relation schema next to its subject schema;
- `x-openregister-lifecycle` on Expense (`hr-expense.json`) is the declarative state-machine template for the Asset status lifecycle (submit/approve/reject/reimburse ↔ uitgeven/innemen/vrijgeven/afschrijven);
- ADR-062 rule 7 canonical `$ref`s + the renderer's `related` widget resolve reference fields by name on the detail pages; FK-scoped `object-list` widgets (EmployeeDetail's Contracts/Timesheets, OrgUnitDetail's Assignments) are the child-list pattern;
- the versioned rule corpus + auto-discovered `CheckProvider`s enforce the machine-checkable rules, and `RuleAuditService::buildRelatedContext()` is the established cross-type index pre-pass (`PayrollRun`/`PensionFiling`/`Employee`/`EmploymentContract`/`OrgUnit` indexes at HEAD) predicates read from `$context['related']`;
- the `integration: files` widget (ExpenseDetail's "Receipt / bon") is the document-evidence surface reused for signed uitgiftebonnen.

Source material: the round-0 draft on `spec/asset-management` (Specter Intelligence, 2026-05-22) and the Spectr canon insights `hrmq-canon-asset-management` (2/9 competitive coverage — differentiation) and `hrmq-canon-fleet-bijtelling` (bijtelling automation is engine-blocked). The draft's *why* is adopted (single source of truth for fragmented asset tracking, offboarding return visibility, employee-coupled assignment history); its *how* (five entities, SQL migrations, RabbitMQ payroll coupling, staffel automation, barcode scan, GDPR batches) predates the declarative architecture and is re-scoped in the proposal's Non-goals.

## Goals / Non-Goals

**Goals:** an `Asset` register with category, identity fields (serienummer, kenteken for vehicles), acquisition fields and a guarded status lifecycle (`beschikbaar`→`uitgegeven`→`ingenomen`→`beschikbaar`/`afgeschreven`); effective-dated `AssetAssignment` uitgiftes linking assets to employees; `$ref`-driven related surfaces; assignment consistency and offboarding asset-return as versioned machine-checkable corpus rules; index/detail pages under the expenses group (ADR-001 menu 7 target); seed data exercising the rules.

**Non-Goals:** bijtelling / LeaseCarTaxRecord (engine-blocked per `hrmq-canon-fleet-bijtelling` — follow-up `fleet-bijtelling`), AssetHistoryEntry (OpenRegister audit trail already is one), payroll event coupling, barcode/QR, CSV import, damage flows, GDPR anonymization batch, supplier/lease-company refs (no Organisation schema in this register set — ADR-062 rule 7), serienummer/one-open-assignment uniqueness enforcement, offboarding blocking-gate (owned by `offboarding-wizard-mvp`'s lifecycle).

## Decisions

### D1 — Two schemas: a lifecycle-bearing Asset, a plain effective-dated AssetAssignment

The draft's Asset/AssetAssignment split is kept and its other three entities are dropped (proposal Non-goals). The *asset* carries the workflow — its physical custody state is a genuine state machine with an initial state, guarded transitions and a terminal state, so it gets a declarative `x-openregister-lifecycle` on `status` (the Expense precedent):

- `uitgeven`: `beschikbaar` → `uitgegeven` — the asset is handed to an employee (recorded by an AssetAssignment).
- `innemen`: `uitgegeven` → `ingenomen` — the asset is returned and awaiting check.
- `vrijgeven`: `ingenomen` → `beschikbaar` — checked and back in stock, re-issuable (the "heropenen" edge that closes the loop).
- `afschrijven`: [`beschikbaar`, `ingenomen`] → `afgeschreven` — written off; **terminal**. Deliberately not reachable from `uitgegeven`: an asset that is out must be taken in (or the loss recorded at inname) before it is written off.

No lifecycle guards (`LifecycleGuardInterface`): no cross-actor rule exists here that the state machine cannot express — unlike Expense's NoSelfApprovalGuard. The *assignment* is a plain effective-dated record exactly like `OrgAssignment` (hr-org.json): `uitgifteDatum` required, `innameDatum` nullable = still out; no lifecycle, no guards. Cross-record coherence (open assignment ⇔ asset uitgegeven) is audit-time corpus territory (D3), not write-time — the org-chart-basic posture.

### D2 — Relations are canonical `$ref`s; the UI is the existing related/object-list machinery

Both reference fields are `$ref`s per ADR-062 rule 7 (both target schemas exist in this register set): `AssetAssignment.assetId` → Asset, `AssetAssignment.employeeId` → Employee. Consequences, all zero-code: outbound, the `related` widget on AssetAssignmentDetail resolves the asset and the employee by name (the OrgAssignmentDetail behaviour); inbound, an asset's assignment history is an FK-scoped `object-list` on AssetDetail (`filter: { assetId: "@objectId" }`). References are stored as UUIDs; seeds reference by slug, resolved at import like every existing seed FK. No EmployeeDetail change: generic related surfacing on the employee hub is owned by the active `hrmq-employee-relations-widget` change, and adding a third-party row there would collide with it.

### D3 — Consistency and offboarding-return are corpus rules riding the related-context

Both rules are deterministic over structured fields, so they are `machineCheckable: true` corpus entries enforced by a new `NlAssetChecks` provider — the app's established ADR-031 exception for domain-rule evaluation. Both predicates are keyed on **`AssetAssignment`** (our own type — never on the parallel change's Offboarding type):

- **`nl-asset-assignment-consistency`** — violates when `innameDatum` is present and earlier than `uitgifteDatum` (incoherent dates), or when the assignment is *open* (no `innameDatum`) and `assetId` does not resolve in the Asset index **or** resolves to an asset whose `status` is not `uitgegeven` **or** `employeeId` does not resolve in the existing Employee index. Fail-closed on dangling references, the `nl-org-assignment-consistency` posture. Severity `mandatory`: an open uitgifte on an asset the register says is in stock (or on a ghost employee) silently corrupts the exact custody question this feature exists to answer.
- **`nl-asset-inname-bij-offboarding`** — violates when the assignment is *open* and its `employeeId` has an entry in the Offboarding index whose planned completion date is strictly before the audit date (offboarding should have finished, yet company property is still out). Severity `recommended` — the rule statement is a SHOULD: an overdue open uitgifte is a hygiene lapse to chase (and the auditable truth behind the offboarding checklist's `assetsIngeleverd` assertion), not a statute breach.

**Defensive coordination with `offboarding-wizard-mvp` (parallel, either landing order):** the Offboarding index is built with the same degrade-to-empty `loadAll()` behaviour every other index uses — if the `Offboarding` schema does not exist in the register yet (the parallel change not landed), `loadAll('Offboarding')` yields nothing, the index is empty, no open assignment matches, and the rule **passes vacuously**. Conversely, if offboarding lands first, this change's index simply starts reading real objects. The index maps `employeeId` → latest planned-completion date, read tolerantly from the Offboarding object's planned-completion field (`afrondingGepland` per that change's draft vocabulary; entries whose employee ref or date is absent/unparseable are skipped — skipping degrades to vacuous-pass, never to a false violation). If the landed field name differs, the one-line index extractor follows the landed schema at apply time; the rule id, statement and predicate shape do not change.

Cross-object data comes from the existing mechanism: `RuleAuditService::buildRelatedContext()` is extended **consistently** with two more indexes — `context['related']['Asset']` = `byId` map of `{id, status, active}` (the OrgUnit index shape) and `context['related']['Offboarding']` = `plannedCompletionByEmployeeId` map — same single pre-pass, same degrade-to-empty behaviour. The Employee index already exists (onboarding-wizard-mvp). The predicate contract stays `fn(array $o, array $context): bool`; no RuleEngine change.

Corpus placement: both rules go in `lib/Standards/rules/labour.json` (`domain: labour`). Justification against SCHEMA.md's one-file-per-domain rule: company property issued to and returned by employees is labour-relationship administration — the same file already holds the org-placement (`hr-org-core`) and onboarding (`nl-onboarding-*`) integrity rules these interlock with, and no rule here is payroll or privacy domain; a new domain file for two rules would fragment the corpus without a domain boundary to justify it. They are administration-integrity controls, not statute transcriptions, so they follow the `hr-org-core` precedent: control-style `source`, new opaque `framework: hr-assets-core` slug (added to SCHEMA.md's examples — the only place frameworks are enumerated), no `sourceUrl`. `RuleCatalogue::VERSION` follows SCHEMA.md's "bump on any change" rule — at authoring time `2026-07.5` → `2026-07.6`, **re-verify the current value against HEAD at apply time** (parallel changes bump the same constant).

### D4 — Schema.org: Asset is `schema:IndividualProduct`, AssetAssignment is `schema:OwnershipInfo`

Per the schema.org marker convention: an Asset is one specific tracked item — `schema:IndividualProduct`, whose `serialNumber` property is exactly our `serienummer` (not `schema:Product`, which is the model/type, and not `schema:Vehicle` — one marker per schema, and only one of seven categories is a vehicle). An AssetAssignment is custody of a good over a period — `schema:OwnershipInfo` (`ownedFrom`/`ownedThrough` mirror `uitgifteDatum`/`innameDatum`, `typeOfGood` mirrors `assetId`). Field vocabulary is Dutch domain terms in data (`uitgifteDatum`, `innameDatum`, `kenteken`, category values `laptop`…`overig`), consistent with the corpus/lifecycle vocabulary precedent; the draft's ten-value type enum is collapsed to the seven categories the MVP integrations need — enum append later is non-breaking.

### Declarative-vs-imperative decision (ADR-031)

| behaviour | path | rationale |
|---|---|---|
| Asset / AssetAssignment data model + relations | **declarative** schemas with `$ref`s in the fragment | ADR-031 default; renderer resolves related/object-list widgets |
| Asset custody state machine | **declarative** `x-openregister-lifecycle` on `status` | genuine workflow with initial/terminal states; the Expense precedent |
| Lifecycle guards | **none** | no cross-actor rule the state machine cannot express (no NoSelfApprovalGuard analogue here) |
| Detail/index/child-list UI + lifecycle buttons | declarative manifest pages (`lifecycleActions`) | existing page archetypes; no custom Vue |
| Uitgiftebon evidence | declarative `integration: files` widget | the ExpenseDetail receipt pattern |
| Assignment consistency, inname-bij-offboarding | imperative **CheckProvider** methods (`NlAssetChecks`) | domain-rule evaluation over the versioned corpus — the established ADR-031 exception; a JSON-schema cannot express cross-object predicates |
| Asset + Offboarding sibling indexes for the checks | imperative pre-pass in `RuleAuditService::buildRelatedContext()` | the established related-context mechanism, extended with two more indexes |

## Schemas (new fragment `lib/Settings/register.d/hr-assets.json`)

OpenAPI 3.0.0 `components.schemas` fragment shape (like `hr-org.json`), `x-hrmq-fragment: hr-assets`, two schemas:

**`Asset`** (slug `Asset`, icon `PackageVariantClosed`, version `0.1.0`, `x-schema-org: schema:IndividualProduct`):

- `name` — string, **required**. Asset name ("Dell Latitude 5450", "Ford Transit bedrijfsbus").
- `category` — string enum `laptop|telefoon|voertuig|gereedschap|toegangspas|kleding|overig`, **required** (D4; append-only).
- `serienummer` — string, nullable. Serial number; null for uncoded items (kleding, toegangspas).
- `kenteken` — string, nullable. Dutch licence plate — meaningful for `voertuig` (the fleet field; carries **no** fiscal semantics, see Non-goals/`hrmq-canon-fleet-bijtelling`).
- `aanschafdatum` — string, format date, nullable. Acquisition date.
- `aanschafwaarde` — number, nullable. Acquisition value in EUR.
- `status` — string enum `beschikbaar|uitgegeven|ingenomen|afgeschreven`, **required**, with the D1 `x-openregister-lifecycle` (initial `beschikbaar`, terminal `afgeschreven`, transitions `uitgeven`/`innemen`/`vrijgeven`/`afschrijven`).
- `active` — boolean, default `true`. Administrative visibility toggle (the OrgUnit convention), distinct from the custody lifecycle.

`required: [name, category, status]`.

**`AssetAssignment`** (slug `AssetAssignment`, icon `HandshakeOutline`, version `0.1.0`, `x-schema-org: schema:OwnershipInfo`):

- `assetId` — string, format uuid, `$ref: Asset`, **required**. The issued asset.
- `employeeId` — string, format uuid, `$ref: Employee`, **required**. The receiving employee.
- `uitgifteDatum` — string, format date, **required**. Issued on (effective-dating per the OrgAssignment pattern).
- `innameDatum` — string, format date, nullable. Returned on; null while the asset is out. Must be ≥ `uitgifteDatum` when present (`nl-asset-assignment-consistency`).
- `uitgifteBonSigned` — boolean, default `false`. Whether a signed uitgiftebon is on file (the document itself lives in the AssetDetail Files widget).
- `notes` — string, nullable. Free-form notes (condition at handout/return).

`required: [assetId, employeeId, uitgifteDatum]`. No lifecycle (D1).

## New corpus rules (labour.json)

| id | source | statement (short) | severity | machineCheckable |
|---|---|---|---|---|
| `nl-asset-assignment-consistency` | HR-administration control (asset-custody integrity) | An open AssetAssignment (no innameDatum) must reference an existing Asset in status `uitgegeven` and an existing Employee, and `uitgifteDatum <= innameDatum` must hold whenever innameDatum is present | mandatory | true |
| `nl-asset-inname-bij-offboarding` | HR-administration control (offboarding asset return; goed werkgeverschap/werknemerschap, BW 7:611 sfeer) | An employee whose Offboarding is past its planned completion date SHOULD have no open AssetAssignments — company property is returned as part of clean offboarding | recommended | true |

Both: `domain: labour`, `jurisdiction: NL`, `framework: hr-assets-core` (**new** opaque framework slug — added to the examples list in `lib/Standards/rules/SCHEMA.md`), no `sourceUrl` (integrity controls, the `hr-org-core`/`xc-*` precedent). `RuleCatalogue::VERSION` bump per D3.

Checks live in the **new** auto-discovered provider `lib/Standards/Checks/NlAssetChecks.php` (implements `CheckProvider`, no `SeedsObjects` — seeds live in hr-seed.json per ADR-001, and a self-contained provider sample could not carry resolvable cross-references; the NlOrgChecks reasoning): both predicates keyed on `AssetAssignment` per D3, reading `context['related']['Asset']['byId']`, `context['related']['Employee']['byId']` and `context['related']['Offboarding']['plannedCompletionByEmployeeId']`.

## Manifest delta

All additions target `src/manifest.json` as it exists at HEAD (single-file manifest; if `hrmq-ia-navigation-alignment`'s fragment pipeline lands first, the same page/menu objects go into the fragment file instead — content identical):

- Menu: the **existing** `ExpensesGroup` ("Onkosten") gains children `Assets` ("Assets", icon `PackageVariantClosed`) and `AssetAssignments` ("Uitgiftes", icon `HandshakeOutline`) after `ExpenseApproval`. **No rename of the group here** — the active `hrmq-ia-navigation-alignment` change owns IA renames and will re-home this group to the frozen ADR-001 menu 7 "**Declaraties & assets** — declaraties, assets, WKR-overzicht", which is precisely where these pages belong; landing order is irrelevant (children move with the group). Icon reality check: `LaptopOutline` does **not** exist in `node_modules/vue-material-design-icons` (only `Laptop`/`LaptopAccount`/`LaptopOff`); `PackageVariantClosed` and `HandshakeOutline` are both verified present.
- `Assets` (new index page, route `/assets`): register `hrmq`, schema `Asset`, columns `name`, `category`, `status`, `serienummer`; filters `category`, `status`; sort `name` asc.
- `AssetDetail` (new detail page, route `/assets/:id`): a `data` widget "Asset" (name/category/serienummer/kenteken/aanschafdatum/aanschafwaarde/status/active); `lifecycleActions` exposing exactly the four fragment transitions (`uitgeven`/`innemen`/`vrijgeven`/`afschrijven` — never invent a transition, the PayrollRunDetail precedent); a `related` widget; an FK-scoped `object-list` "Uitgiftes" (`AssetAssignment`, `filter: { assetId: "@objectId" }`, columns employee/uitgifteDatum/innameDatum/uitgifteBonSigned, sort `uitgifteDatum` desc, rowRoute `AssetAssignmentDetail`); an `integration: files` widget "Uitgiftebonnen" (signed handout receipts — the ExpenseDetail receipt placement, full-width after the domain cards); audit-history sidebar tab.
- `AssetAssignments` (new index page, route `/asset-assignments`): columns `assetId`, `employeeId`, `uitgifteDatum`, `innameDatum`, `uitgifteBonSigned`; sort `uitgifteDatum` desc.
- `AssetAssignmentDetail` (new detail page, route `/asset-assignments/:id`): a `data` widget (excluding `assetId`/`employeeId` — the Related panel resolves both by name, the OrgAssignmentDetail exclude convention), a `related` widget, audit-history sidebar tab. No lifecycleActions (no lifecycle on the schema).
- `deepLinks`: `Asset` → `/apps/hrmq/assets/{uuid}`, `AssetAssignment` → `/apps/hrmq/asset-assignments/{uuid}`.
- `npm run check:manifest` must stay green.

## Seed Data (ADR-001)

Seeds go in `lib/Settings/register.d/hr-seed.json`, referencing by slug (the Timesheet/OrgAssignment `employeeId` mechanism). The **slugged** Employee seeds at HEAD are `employee-jansen` and `employee-visser` — both assignments use those two (not the dangling `employee-devries`/`employee-bakker` slugs older seeds reference; that pre-existing gap stays flagged for seed-hygiene cleanup).

- **3 Asset seeds**:
  1. `asset-laptop-latitude` — name "Dell Latitude 5450", category `laptop`, serienummer `SN-PLACEHOLDER-0001`, status **`uitgegeven`**, aanschafdatum `2025-09-01`, aanschafwaarde `1249.00`, active.
  2. `asset-telefoon-fairphone` — name "Fairphone 5", category `telefoon`, serienummer `SN-PLACEHOLDER-0002`, status **`beschikbaar`** (in stock), active.
  3. `asset-bus-transit` — name "Ford Transit bedrijfsbus", category `voertuig`, **kenteken `V-000-XX`** (obvious placeholder), serienummer null, status **`uitgegeven`**, aanschafdatum `2024-02-15`, aanschafwaarde `38500.00`, active — the fleet example: a vehicle is just an Asset with a kenteken; nothing fiscal.
- **2 AssetAssignment seeds**:
  1. `assetassignment-visser-bus` — `asset-bus-transit` → `employee-visser`, uitgifteDatum `2025-01-06`, **innameDatum `2025-12-19`** (closed; coherent dates), uitgifteBonSigned `true`, notes "Winterperiode buitendienst." — the consistent, closed record.
  2. `assetassignment-jansen-telefoon` — `asset-telefoon-fairphone` → `employee-jansen`, uitgifteDatum `2026-06-15`, innameDatum null (**open**), uitgifteBonSigned `false` — **deliberately inconsistent**: an open uitgifte on an asset whose status is `beschikbaar`, so `occ hrmq:rules:audit` reports exactly one `nl-asset-assignment-consistency` violation on seed data (the one-intentional-violation-per-alerting-rule pattern from org-chart-basic/pension seeds).

`nl-asset-inname-bij-offboarding` stays vacuously green on seeds — no Offboarding seeds exist here (they belong to `offboarding-wizard-mvp`); its violating path is pinned by unit tests with a synthetic Offboarding index. Note: `asset-laptop-latitude` is seeded `uitgegeven` without an open assignment record — the consistency rule is deliberately one-directional (open assignment ⇒ uitgegeven) and does not flag it; see Risks.

## Risks / Trade-offs

- **One-directional consistency**: the rule checks open-assignment ⇒ asset-uitgegeven, not uitgegeven-asset ⇒ open-assignment-exists. An issued asset whose assignment was never recorded is invisible to the audit (the seeded laptop demonstrates this on purpose). The reverse control needs an assignment-per-asset index in the related-context — a cheap, purely additive follow-up rule (`nl-asset-uitgegeven-heeft-uitgifte`) once real usage shows the gap matters.
- **No one-open-assignment-per-asset uniqueness**: two open uitgiftes on the same asset are individually consistent (both see `uitgegeven`) — accepted for MVP, same posture as org-chart-basic's overlapping placements; set-level cardinality is follow-up corpus material.
- **Parallel-change coupling**: `nl-asset-inname-bij-offboarding` reads a schema owned by a change being authored in parallel. Mitigated by D3's vacuous-pass construction (either landing order, no shared files beyond the append-only labour.json/SCHEMA.md/RuleAuditService/RuleCatalogue touchpoints — union-merge those on conflict and re-verify `RuleCatalogue::VERSION` monotonicity); worst case the field-name extractor is a one-line follow-up.
- **Status vs. reality drift**: lifecycle transitions on Asset and open/close on AssetAssignment are two writes with no transaction; the mandatory consistency rule is exactly the alarm for the half-applied case — accepted (audit-time posture, D1/D3).
- **Kenteken carries no validation**: no NL plate regex in MVP (the draft had one) — format validation is schema-level follow-up material; the field is presentational until `fleet-bijtelling` needs it fiscally.
- **Dutch field/enum vocabulary** (`uitgifteDatum`, `kenteken`, `beschikbaar`…): consistent with the repo's Dutch-domain-terms-in-data precedent; renames later would be breaking, appends are not.

## Open Questions

- None blocking. Fleet bijtelling (`fleet-bijtelling`, engine-blocked per Spectr `hrmq-canon-fleet-bijtelling`), the reverse-direction custody rule, uniqueness/cardinality rules, barcode/CSV tooling and the offboarding blocking-gate (owned by `offboarding-wizard-mvp`) are recorded follow-ups.
