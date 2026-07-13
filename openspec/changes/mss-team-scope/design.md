# Design — mss-team-scope

## Context

Round 2 left hrmq with a global approver surface and a fresh org structure that nothing consumes yet:

- `mijn-hr-self-service` established the whole self-service mechanism this change reuses: the `@me` filter token on a fixed index-page base `filter`, the denormalized plain-string NC-user-id property (`userId`) with an honestly-documented population trade-off, the Dashboard with 3 `@me` KPIs + 2 **global** approver KPIs (`dash-approve-hours`/`dash-approve-expenses`, counting ALL submitted timesheets/expenses), and ADR-001 Rule 2 as the placement law for manager scope;
- `org-chart-basic` shipped `OrgUnit` (with nullable `managerId` → Employee) and effective-dated `OrgAssignment` (employeeId/orgUnitId/startDate/endDate), the `hr-org-core` framework slug, the `NlOrgChecks` provider and the `context['related']['OrgUnit']['byId']` audit index;
- the approval pages (`TimesheetApproval`/`ExpenseApproval`/`LeaveApproval`) are pre-filtered global queues (`defaultFilters: { status: "submitted" }`, sort `submittedAt` asc).

Spectr `hrmq-canon-mss-team-scope` (3/9 competitive coverage) names the missing piece: a manager should see *their team's* queue, not the company's.

## Scoping mechanism (investigated)

The design hinges on whether a manifest index page can filter records to "employees whose active OrgAssignment's unit is managed by @me" — a **two-hop join** (record → Employee → OrgAssignment → OrgUnit.managerId). Verified against the vendored `node_modules/@conduction/nextcloud-vue/src/schemas/app-manifest-v2.schema.json` at HEAD:

- `sentinelFilterToken` is the complete fetch-time token grammar: `^@(?:me|now|today|monthStart|quarterStart|yearStart)$|^@today[+-][0-9]+d$` — **no join, no path, no sub-query form**. `@object.<field>` exists only in detail-page object context, not on index filters.
- Filter values are one-hop maps over the page's own schema (`{ field: value | value[] | { op: value } }`, per the `objectTableSource` grammar) serialized as `filter[field][op]=value` — OpenRegister evaluates them against the record's own properties only.
- The exact shapes this change uses are already proven in this app's manifest: `@me` in an index page's fixed base `filter` (`MijnUren` et al.), `@me` inside a stat widget's `content.source.filter` (`dash-my-hours` et al. — `CnStatWidget.flattenFilter()` runs `resolveFilterTokens` before hitting `/apps/openregister/api/objects/aggregations/{register}/{schema}/value`), and `defaultFilters` on approval pages.

So two-hop filtering does NOT exist (unchanged from the round-2 investigation), and the honest MVP per ADR-001 Rule 2 is: (a) a denormalized `managerUserId`, (b) team pages as scoped variants of the existing approval pages, (c) Dashboard widget re-scope — plus a corpus rule that keeps the denormalization from silently rotting.

## Goals / Non-Goals

**Goals:** one-hop manager scoping (`managerUserId` on Timesheet/Expense/LeaveRequest); `Team*goedkeuring` pages inside the existing menu groups; team-scoped Dashboard approver widgets with a global HR fallback; the `nl-mss-manager-consistency` recommended-severity cross-object rule riding the related-context; seeds that demo a populated team queue for `admin` and evaluate the rule non-vacuously.

**Non-Goals:** manager portal / top-level menu (ADR-001 Rule 2), two-hop manifest filtering (grammar gap belongs upstream), automatic stamping (no create-form token defaults — round-2 verified, unchanged), `managerUserId` on schemas without an approval surface, any authorization semantics (scoping ≠ permission; `NoSelfApprovalGuard` untouched).

## Decisions

### D1 — Denormalized `managerUserId`, the `userId` trade-off applied to the manager axis

Same shape, same documentation duty as round-2 `userId`: a plain, optional, nullable NC-user-id **string** (never a `$ref` — it names an *account*, not an Employee object), maintained by HR/back-office alongside `userId` when a record is created/triaged. The property description on all three schemas states: it is a denormalized copy of the `nextcloudUserId` of the manager of the employee's active org placement, carried because the manifest filter grammar cannot join across schemas; the `nl-mss-manager-consistency` audit rule cross-checks it. Trade-off accepted deliberately: reorganisations make stamps stale until HR restamps — that is exactly what the recommended-severity rule surfaces, record by record. Only the three schemas with an approval surface get the field.

### D2 — Team pages are scoped variants of the existing approval pages (ADR-001 Rule 2)

`TeamUrengoedkeuring` (`/timesheets/team-approval`), `TeamDeclaratiegoedkeuring` (`/expenses/team-approval`), `TeamVerlofgoedkeuring` (`/leave-requests/team-approval`): each copies its global approval page's config verbatim and adds the fixed base filter `{ "managerUserId": "@me" }` (the `MijnUren` mechanism — base `filter` and user-facing `defaultFilters` compose, both shapes already live in this manifest). Menu placement per Rule 2 ("scoped acties inside existing menus"): a child in `TimesheetsGroup`, `ExpensesGroup` and `VerlofVerzuimGroup` directly after the global approval entry, icon `AccountCheckOutline`, labels `Team-urengoedkeuring` / `Team-declaratiegoedkeuring` / `Team-verlofgoedkeuring`. The global pages stay untouched — they are the HR/back-office surface.

### D3 — Dashboard: re-scope in place, add the missing leave widget, restore the global counts as an HR fallback row

Read fresh from `src/manifest.json`: the Dashboard today has 6 widgets — 3 `@me` KPIs (row 0), 2 global approver KPIs (row 2: `dash-approve-hours`, `dash-approve-expenses`), 1 `@me` object-table (row 4). Adjust, don't duplicate:

- **Re-scope** `dash-approve-hours` / `dash-approve-expenses`: filter becomes `{ "managerUserId": "@me", "status": "submitted" }`, titles gain "(mijn team)", routes move to the new team pages. Widget ids stay stable (layout references them).
- **Add** `dash-team-approve-leave` (LeaveRequest, same team filter, route `TeamVerlofgoedkeuring`) — the approver trio then matches the three schemas that carry `managerUserId`; the team row becomes 3 × width-4.
- **Add an HR fallback row**: `dash-hr-approve-hours` / `dash-hr-approve-expenses` (2 × width-6) restore exactly the two global submitted-counts the re-scope removed, routes to the existing global `TimesheetApproval`/`ExpenseApproval` pages, captions saying "alle medewerkers". Rationale: team scoping only works where `managerUserId` is populated; HR keeps a queue view that can never be starved by missing stamps. A global *leave* count is deliberately NOT added here — the sibling active change `verzuim-analytics-widgets` owns the leave/verzuim analytics row (no double widget).
- The `@me` object-table shifts down. All widgets stay type `stat` with the proven `content.source` shape (`register`/`schema`/`metric: count`/`filter`) — no new widget types, no charts.

### D4 — `nl-mss-manager-consistency` is a recommended-severity corpus rule enforced by extending `NlOrgChecks`

- **Rule data** (`lib/Standards/rules/labour.json`): `domain: labour`, `jurisdiction: NL`, `framework: hr-org-core` (the existing org-integrity slug from org-chart-basic — this rule polices the same administrative surface, so no new framework and **no SCHEMA.md edit**), `severity: recommended` (a stale stamp degrades list scoping, it breaks no statutory duty and blocks no consumer — unlike the `mandatory` cycle/consistency rules), `machineCheckable: true`, control-style `source` (administration-integrity control, the `xc-*`/`hr-org-core` precedent). `RuleCatalogue::VERSION` bumps `2026-07.5` → `2026-07.6` (checked fresh; SCHEMA.md "bump on any change").
- **Provider**: extend `NlOrgChecks` rather than adding a provider — justified because (a) the rule *is* an `hr-org-core` org-integrity control and NlOrgChecks is that framework's home, (b) it consumes the same `related.OrgUnit` index the provider already documents, and (c) `RuleEngine::checks()` merges providers additively per object type (verified at HEAD: `array_merge` per `$objectType`), so NlOrgChecks registering `Timesheet`/`Expense`/`LeaveRequest` keys coexists with the other providers' predicates on those types. One shared predicate, three registrations.
- **Predicate semantics** (pure `fn(array $o, array $c): bool`): resolve the record's `employeeId` → the employee's *active* OrgAssignments (no `endDate`, or `endDate` on/after the audit date — the exact `NlOrgChecks::isCurrentlyActive()` semantics) → each assignment's OrgUnit → `managerId` → manager Employee → `nextcloudUserId`. **Violates** only when the record carries a non-empty `managerUserId`, at least one hop chain fully resolves to a manager `nextcloudUserId`, and none of the resolved values equals `managerUserId`. **Vacuous (passes)** when: `managerUserId` absent/empty (optional field), `employeeId` empty, no active assignment, unit unresolvable or `managerId` empty (unmanaged unit), manager Employee unresolvable, or manager `nextcloudUserId` empty — the rule reports *provable* inconsistency, never punishes absent org data. Multiple concurrent placements (allowed since org-chart-basic): matching ANY active placement's manager passes.
- **Context** (`RuleAuditService::buildRelatedContext()`, extended consistently — same single pre-pass, same degrade-to-empty): the `OrgUnit` index gains `managerId`; the `Employee` index gains `nextcloudUserId`; a new `OrgAssignment` index `byEmployeeId` maps employeeId → list of `{orgUnitId, endDate}` (start dates are irrelevant to activeness-at-audit; the predicate applies the endDate rule itself so the index stays a dumb load).

### Declarative-vs-imperative decision (ADR-031)

| behaviour | path | rationale |
|---|---|---|
| `managerUserId` property on the three schemas | **declarative** schema property in the existing fragments | ADR-031 default; plain nullable string, version bumps |
| Team pages + menu children + Dashboard widget changes | declarative manifest config | existing page/widget archetypes; no custom Vue |
| Manager-consistency check | imperative **CheckProvider** predicate (extending `NlOrgChecks`) | domain-rule evaluation over the versioned corpus — the established ADR-031 exception; a JSON schema cannot express the four-hop cross-object comparison |
| OrgAssignment/manager indexes for the check | imperative pre-pass in `RuleAuditService::buildRelatedContext()` | the established related-context mechanism, extended consistently (org-chart-basic precedent) |
| Lifecycle / guards | **none** | no schema lifecycle changes; scoping grants nothing — `NoSelfApprovalGuard` and publish/RBAC untouched |

## Schema delta (existing fragments)

On `Timesheet` (hr-timesheet.json, 0.3.0 → 0.4.0), `Expense` (hr-expense.json, 0.2.0 → 0.3.0), `LeaveRequest` (hr-leave.json, 0.2.0 → 0.3.0), identically:

- `managerUserId` — string, nullable, NOT a `$ref`. Title "Manager user id". Description per D1 (denormalized copy of the active-placement unit manager's `nextcloudUserId`; populated by HR/back-office alongside `userId`; cannot be joined from the manifest filter grammar; audited by `nl-mss-manager-consistency`).

Register `info.version` (lib/Settings/hrmq_register.json) 0.5.0 → 0.6.0. Not added to `required`; no lifecycle change.

## New corpus rule (labour.json)

| id | source | statement (short) | severity | machineCheckable |
|---|---|---|---|---|
| `nl-mss-manager-consistency` | HR-administration control (manager self-service scoping integrity) | A Timesheet/Expense/LeaveRequest `managerUserId` SHOULD equal the `nextcloudUserId` of the manager (`OrgUnit.managerId`) of the record's employee's active OrgAssignment unit; vacuous when any hop is absent | recommended | true |

`domain: labour`, `jurisdiction: NL`, `framework: hr-org-core` (existing slug), no `sourceUrl` (integrity control). `RuleCatalogue::VERSION` → `2026-07.6`.

## Manifest delta

- `TeamUrengoedkeuring` (`/timesheets/team-approval`, index, Timesheet): base `filter: { "managerUserId": "@me" }`; columns/`filters`/`defaultFilters`/sort copied from `TimesheetApproval` (`employeeId`, `period`, `hours`, `submittedAt`, `status`; filters status/period; defaultFilters status=submitted; sort submittedAt asc); description names the team scope and the HR-stamped mechanism.
- `TeamDeclaratiegoedkeuring` (`/expenses/team-approval`, index, Expense): same treatment mirroring `ExpenseApproval`.
- `TeamVerlofgoedkeuring` (`/leave-requests/team-approval`, index, LeaveRequest): same treatment mirroring `LeaveApproval`.
- Menu: `TimesheetsGroup` gains child `TeamUrengoedkeuring` ("Team-urengoedkeuring") after `TimesheetApproval`; `ExpensesGroup` gains `TeamDeclaratiegoedkeuring` after `ExpenseApproval`; `VerlofVerzuimGroup` gains `TeamVerlofgoedkeuring` after `LeaveApproval` — all icon `AccountCheckOutline`. No new top-level entries, no deepLinks changes (index pages).
- Dashboard per D3: re-scope `dash-approve-hours`/`dash-approve-expenses` (filter + title + caption + route), add `dash-team-approve-leave`, add `dash-hr-approve-hours`/`dash-hr-approve-expenses`; layout rows: mine (Y0), team 3×4-wide (Y2), HR fallback 2×6-wide (Y4), recent-hours table (Y6). The page `_note` is updated to document the round-3 re-scope and the fallback rationale.
- `npm run check:manifest` must stay green.

## Seed Data (ADR-001)

`lib/Settings/register.d/hr-seed.json` (read fresh — the submitted-status seeds are `timesheet-jansen-2026-05`, `expense-jansen-hotel`, `leave-jansen-zomer`, all `userId: "admin"`; the org seeds are directie/consultancy/backoffice with jansen placed in consultancy):

- Stamp `managerUserId: "admin"` on exactly those three submitted seeds (HR stamps alongside `userId` — D1). Approved/rejected/reimbursed seeds stay unstamped: they demo the vacuous path.
- Re-point `orgunit-consultancy.managerId`: `employee-devries` → `employee-jansen`. This is what makes the seed world coherent AND the rule non-vacuous: employee-jansen's active placement is Consultancy; with Jansen (nextcloudUserId `admin`) as its manager, the three stamped records resolve manager → `admin` → **consistent**. It deliberately supersedes the archived `org-chart-basic` seed *value* (REQ-ORG-008 named devries as Consultancy's manager) — documented here because devries's Employee object was never seeded (a pre-existing dangling slug org-chart-basic itself flagged), so the old value made every manager resolution dead-end. The nullable-manager path stays exercised by `orgunit-backoffice` (managerId null); in the seed world the dev `admin` account doubles as employee Jansen *and* the Consultancy team lead — an explicit placeholder-data simplification (self-approval remains blocked server-side by `NoSelfApprovalGuard` regardless).
- **No seeded violation**: deliberately none (the org-chart `nl-org-unit-cycle` precedent) — stamping a wrong manager on a seed would corrupt the team-queue demo just to light a recommended lamp; the violating and each vacuous path are pinned by unit tests instead.

## Risks / Trade-offs

- **Stale stamps after reorgs** — inherent to denormalization; mitigated by the audit rule (recommended severity: visible, non-blocking) and by HR owning both the org record and the stamp.
- **Manual population** — same as round-2 `userId`; a record without a stamp simply doesn't appear in team queues (it stays in the global HR queue), which is the safe failure direction.
- **Dashboard co-edit with `verzuim-analytics-widgets`** — both active changes append Dashboard rows; whichever lands second union-merges `config.widgets` + `config.layout` and re-flows `gridY` (no widget-id collisions: this change uses `dash-team-*`/`dash-hr-*`, the sibling uses `dash-verzuim-*`/`dash-leave-*`).
- **Seed supersession** — one archived-change seed value is re-pointed (documented above); structure (3 units / 3 assignments) and every org-chart requirement stay intact.
- **`recommended` severity means nothing blocks** — intentional: the rule is a data-quality lamp, and `hrmq-rule-compliance-enforcement`'s exit gate correctly ignores it.

## Open Questions

- None blocking. A future OpenRegister/nc-vue join-token (filter across `$ref` hops) would let `managerUserId` be retired; automatic stamping needs create-form token defaults in the renderer (both recorded round-2, both still open upstream).
