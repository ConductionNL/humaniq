---
kind: code+config
---

# single-person-modes — an Administration.mode toggle, not a new app, for the ~400k NL single-person BV/eenmanszaak entrepreneur

## Why

**Verified against HEAD 2026-07-17.** ADR-001 (`openspec/architecture/adr-001-information-architecture.md`) Rule 4
already legislates the answer this change must honour: *"ZZP/DGA en eenmanszaak zijn MODES, geen aparte app"* —
naming the two May-2026 draft branches this change modernises (`zzp-dga-single-person-mode`,
`zzp-eenmanszaak-no-payroll-mode`) as the specs that were expected to deliver those modes. Neither branch ever
merged. What DID merge in the interim, under a different change name, is the payroll *arithmetic* half of the DGA
story: `openspec/specs/dga-payroll-mode/spec.md` (status: done) ships `Employee.isDga` driving
`CalculationInput.verzekeringsplichtig: false` (zeroing Awf/Aof/Wko/Whk while leaving loonheffing/Zvw/nettoPay
untouched), the sourced 2026 gebruikelijkloonregeling norm (€58.000/jaar, `nl-2026.json`), and
`NlDgaChecks`/`nl-gebruikelijkloon-norm` — a conditional-severity corpus rule flagging a below-norm DGA with no
justification on file. `openspec/specs/proforma-payslip/spec.md` (done) separately ships a persist-nothing
"Simuleer loonstrook" simulator any HR caller — including a self-running DGA — can already use to answer "what
does €4.100 bruto net?" without creating throwaway records. **The payroll calculation, the norm table, the
compliance check, and the simulate-without-persisting surface are DONE. This change does not respec any of them.**

What was never built, and is not incidentally covered by the above, is the *mode* itself: `openspec/specs/
multi-administratie/spec.md`'s `Administration` schema (`lib/Settings/register.d/hr-administratie.json`) — the
one place ADR-001 Rule 4 says a mode flag belongs ("Configuratie › Administraties") — carries no mode field at
all today (`grep -n singlePersonMode\|personnelMode lib/Settings/register.d/hr-administratie.json` returns
nothing). Every hrmq menu item (org-chart, team approval queues, roster/shift planning, the entire Salarissen
group) renders unconditionally for every administratie, single-person or not — `grep -c visibleIf src/
manifest.json` returns **zero**: the `visibleIf` menu-visibility primitive nc-vue's manifest v2 schema already
defines (`$defs/visibleIfCondition`, evaluated against a backend-injected `manifest.runtime.user` block) has never
been wired into hrmq at all. `multi-administratie`'s own spec names this exact gap as a live, unclosed item:
REQ-MULTI-006 states *"the Dashboard-widget `runtime.user` visibleIf wiring remains a separate, small, named
follow-up."* And the one thing a self-running DGA most needs to see without an accountant — whether this month's
salary clears the gebruikelijkloon norm — is answered today only by `occ hrmq:rules:audit`
(`lib/Service/RuleAuditService.php`, `lib/Command/RulesAuditCommand.php`): a CLI-only, register-wide audit report
with no HTTP surface and no manifest page, unreachable by exactly the persona (a DGA running their own payroll,
no accountant, no shell access) this whole capability exists for.

**So the honest scope, after subtracting everything already delivered:** (1) the `Administration.mode` toggle
itself (`standard` / `dga_single_person` / `eenmanszaak_no_payroll`) — the SETTING flag Rule 4 promises but that
does not exist; (2) wiring that flag into `manifest.runtime.user` so `visibleIf` can act on it at all — closing
REQ-MULTI-006's named follow-up; (3) applying `visibleIf` to the genuinely multi-person-only surfaces (approval
queues, org-chart, roster/shift planning) and, for the no-payroll flavour, the entire Salarissen group; (4) a
soft, recommended-severity drift check (the `nl-administratie-scope-consistency` posture applied to headcount)
so a `dga_single_person` administratie that quietly grows past one employee is flagged, not silently wrong; and
(5) surfacing the *existing* `nl-gebruikelijkloon-norm` check result on a **self-service** "Mijn HR" surface (ADR-001
Rule 2) instead of only a CLI audit — reusing `NlDgaChecks`'s exact predicate, adding zero new tax logic.

**What this change explicitly does NOT build**, because the two May-2026 drafts invented an entire second product
surface hrmq has no data model for and, as a payroll/HRM suite (`openspec/config.yaml`'s own scope statement), has
no mandate to grow into: FOR-saldo tracking, lijfrente-jaarruimte calculation, box-2 aanmerkelijk-belang
dividend/verkrijgingsprijs tracking, an IB-pakket ZIP export for an accountant, an `accountant_of_record`
delegation role, a KilometerLog entity (`Expense` already carries a "Type reis"/"Afstand (km)" pair — this data
already lives on the existing declaraties schema, ADR-001 Rule 5's "content types as leaves" posture), a
TaxContext/urencriterium engine, or an IB-tax jaaroverzicht export. None of these compute a payroll figure, none
reuse an existing hrmq engine, and every one is IB-aangifte (income-tax-return) territory — a different
compliance domain than the wage-tax/social-security domain hrmq's rule corpus and calculator are built for. A true
zero-payroll eenmanszaak (no employees, no DGA-loon, profit taken as `winstuitkering` rather than `loon`) has, by
definition, no payroll data for hrmq's engine to touch; for that persona this change's contribution is exactly
"hide the payroll surfaces that don't apply to you," not a new tax-return product. Naming this explicitly so it is
not silently rediscovered as a gap: it is a scope boundary, not an oversight.

## What Changes

- **NEW `Administration.mode`** (`lib/Settings/register.d/hr-administratie.json`): enum
  `standard`/`dga_single_person`/`eenmanszaak_no_payroll`, default `standard` — the ADR-001 Rule 4 mode-switch,
  living exactly where `multi-administratie` already put the tenant catalog, adding zero new schemas.
- **`manifest.runtime.user` wiring**: `PageController::index()` additionally stamps the caller's active
  administratie's resolved `mode` as initial state (the exact `activeAdministrationId` mechanism, REQ-MULTI-004,
  applied to a second key); `AdministrationController::context()` additionally returns each administratie's
  `mode`. This closes `multi-administratie` REQ-MULTI-006's named follow-up and is the first real use of nc-vue's
  `visibleIf` primitive in hrmq.
- **`visibleIf` on multi-person-only menu entries**: org-chart (`OrgUnits`, `OrgAssignments`), the three
  team-approval-queue leaves (`TimesheetApproval`/`TeamUrengoedkeuring`, `LeaveApproval`/
  `TeamVerlofgoedkeuring`, `ExpenseApproval`/`TeamDeclaratiegoedkeuring`), and the whole `PlanningGroup`
  (Rosters/RosterAssignments/Shifts — scheduling multiple people) hide when `mode` is not `standard`. The whole
  `PayrollGroup` and `ProformaPayslipMenu` additionally hide when `mode` is `eenmanszaak_no_payroll`.
  `Medewerkers`/`Salarissen` core items stay visible under `dga_single_person` — the DGA is still one Employee
  record running one payroll each month.
- **NEW `nl-single-person-mode-employee-count`** (`lib/Standards/Checks/NlSinglePersonChecks.php`, auto-discovered
  `CheckProvider`, recommended severity, vacuous unless `mode: dga_single_person`): flags an administratie whose
  active `Employee` headcount for that `administrationId` has drifted away from exactly one — a data-quality lamp,
  never a write-time block, the `nl-administratie-scope-consistency` posture applied to headcount instead of scope
  drift.
- **NEW self-service "Mijn gebruikelijk loon"** (`MijnHrGroup`, `visibleIf mode: dga_single_person`): a guarded,
  read-only, stateless `PayrollController::dgaStatus()` endpoint resolving the caller's own `Employee` (via
  `nextcloudUserId`, the existing `mijn-hr-self-service` link) and returning the **existing**
  `NlDgaChecks`/`nl-gebruikelijkloon-norm` predicate's verdict for that one record — zero new tax logic, zero new
  persistence — surfaced as a banner widget so a self-running DGA sees their compliance status without `occ`.

### Non-goals (named exclusions, not deferred follow-ups)

See "Why" — FOR/lijfrente/box-2/IB-pakket/accountant-delegation/KilometerLog/urencriterium/IB-jaaroverzicht are
explicitly out of scope: no existing hrmq data model or engine backs any of them, and building one is a different
product (IB-aangifte tooling), not a mode-switch on the existing payroll/HRM suite.

## Capabilities

### New Capabilities

- `single-person-modes`: `Administration.mode`, the `runtime.user` wiring, `visibleIf`-gated menu surfaces for
  both flavours, the headcount-drift check, and the self-service gebruikelijkloon-status surface.

### Modified Capabilities

- `multi-administratie`: `Administration` schema gains `mode`; `AdministrationController::context()` response
  gains `mode` per administratie; `PageController::index()` stamps a second initial-state key. Closes
  REQ-MULTI-006's named follow-up. No change to the existing tenant-switch, access-guard, or scoping behaviour.
- `dga-payroll-mode`: unchanged calculation/table/check logic; `NlDgaChecks`'s predicate gains one new caller
  (the self-service status endpoint) but is not modified.

## Impact

- `lib/Settings/register.d/hr-administratie.json` — `Administration.mode` enum field.
- `lib/Controller/AdministrationController.php` — `context()` includes `mode` per administratie.
- `lib/Controller/PageController.php` — `index()` stamps `activeAdministrationMode` initial state.
- `lib/Controller/PayrollController.php` — NEW `dgaStatus()` self-service endpoint (resolve-first, 404 collapse).
- `appinfo/routes.php` — +1 route (`GET /api/payroll/dga-status`), before the SPA catch-all.
- `lib/Standards/Checks/NlSinglePersonChecks.php` — NEW, auto-discovered.
- `src/manifest.json` — `visibleIf` on the named menu entries; NEW `MijnGebruikelijkLoon` self-service page/widget.
  `npm run check:manifest` passes.
- `tests/Unit/Controller/PayrollControllerTest.php`, `tests/Unit/Standards/Checks/NlSinglePersonChecksTest.php` —
  NEW test coverage.
- `README.md` — the mode toggle + what it hides, and the explicit non-goals list (so the FOR/lijfrente/IB-pakket
  scope boundary is not silently rediscovered as a gap by a future proposal).
