# Design — proforma-payslip

## Context

**Verified against HEAD 2026-07-14.** Consumes `payroll-core-engine` (merged/archived) unchanged:

- **`lib/Payroll/PayrollCalculator.php`** — pure, stateless, zero Nextcloud deps:
  `calculate(CalculationInput $in, TaxTables $t): CalculationResult`. No new tax logic is added by
  this change; it is called exactly as `PayrollRunService::generate()` calls it.
- **`lib/Payroll/CalculationInput.php`** — the immutable input value object:
  `grossMonthlySalaryCents`, `taxTableColor` (`wit`/`groen`), `loonheffingskortingToegepast`,
  `dateOfBirth` (nullable → below-AOW), `period` (`YYYY-MM`), `awfTariff` (`low`/`high`), `aofTariff`
  (`laag`/`hoog`), `whkPercentage`.
- **`lib/Payroll/CalculationResult.php`** — the immutable output: `grossPayCents`, `loonheffingCents`,
  `arbeidskortingCents`, `volksverzekeringenCents`, `zvwCents`/`zvwMode`/`zvwRate`, `appliedTaxRate`,
  `nettoPayCents`, `vakantiegeldReservedCents`/`vakantiegeldRate`, `awf/aof/wko/whkCents`,
  `werknemersverzekeringenCents`, `employerChargesCents`, `aboveLmax`.
- **`lib/Payroll/TaxTables.php`** — `TaxTables::load($id)` loads `lib/Standards/tables/<id>.json`
  (integer cents), exposes `werknemersverzekeringen()['whkDefault']` (the Whk fallback).
- **`lib/Service/SettingsService.php`** — `getPayrollAofTariff()` (`laag`, default) and
  `getPayrollWhkPercentage(float $tablesDefault)` (the two employer-level knobs the tables file cannot
  know), plus `getRegisterSlug()`.
- **`lib/Controller/PayrollController.php`** — the resolve-first no-admin-idor pattern: an
  `#[NoAdminRequired]` method resolves an object through the container-provided ObjectService under the
  caller's ambient RBAC BEFORE any work, and unknown/unauthorized collapse to the same 404
  (`authorizeRun()`).

Existing patterns this change mirrors (all read at HEAD):

- **Endpoint guard**: `PayrollController::calculate` / `authorizeRun` — container-resolved ObjectService,
  ambient RBAC, resolve-first, 404 on unknown-or-unauthorized (no capability leak).
- **occ command**: `PayrollRunCommand` — `Symfony\Console`, `--option` flags, Dutch operator output,
  registered under `<commands>` in `appinfo/info.xml`.
- **Manifest actions/pages**: `src/manifest.json` — `api-call` (EmploymentContractDetail
  "Genereer arbeidsovereenkomst") and `open-form` (PayrollRuns "Loonrun aanmaken") are the two compute
  action types; the v2 schema's `pages[].type` enum includes `custom` (host-app SFC, REQUIRED `_note`).

## Goals / Non-Goals

**Goals:** a persist-nothing front door to the existing calculator — a stateless service + guarded
endpoint + occ command that turn hypothetical inputs into the full gross-to-net breakdown, plus a
declarative-where-possible manifest surface. Deterministic, cents-exact, reproducing the engine anchor.

**Non-Goals (binding):** bijzonder tarief computation (engine fast-follow — the one-off is a combined-
loon estimate through the regular table, see D3), any persistence, saved scenarios/history, Employee
PII, and every engine non-goal (hourly, anoniementarief, CAO, 30%-ruling, pension, VCR). No new tax
logic — the calculator is reused verbatim.

## Decisions

### D1 — The proforma service is a stateless wrapper over the existing calculator; it persists NOTHING

`ProformaPayslipService::simulate(ProformaInput): array` holds no state, injects no ObjectService, and
writes no object. It (a) resolves the tax-year `TaxTables` for the period (default `nl-<current year>`
= `nl-2026`), (b) reads the two employer-level knobs from `SettingsService`
(`getPayrollAofTariff()`, `getPayrollWhkPercentage($tables->werknemersverzekeringen()['whkDefault'])`)
— overridable per call for the occ path, (c) constructs a `CalculationInput`, (d) calls the **existing**
`PayrollCalculator::calculate()`, and (e) maps the `CalculationResult` (integer cents) to a euro-decimal
JSON breakdown. No Employee, EmploymentContract, PayrollRun or Payslip is created or read for its data,
and OpenRegister is never mutated. This is the entire persistence contract: **stateless in, computed
out, nothing stored.**

### D2 — Input construction: hypothetical params → CalculationInput

| Proforma input | CalculationInput field | Rule |
|---|---|---|
| `gross` (bruto maandsalaris, euro) | `grossMonthlySalaryCents` | `round((gross × parttimeFactor + bijzondereBeloning) × 100)` (D3) |
| `table` (`wit`/`groen`) | `taxTableColor` | validated to `wit`/`groen`; default `wit` |
| `loonheffingskorting` (bool) | `loonheffingskortingToegepast` | default `true` |
| `dateOfBirth` / AOW hint | `dateOfBirth` | ISO date or null; the calculator's own `isAowAge()` decides AOW from period (no code branch here) |
| `period` (`YYYY-MM`) | `period` | default current month; selects the tax-year tables |
| `parttime` (factor, e.g. `0.8`) | folded into gross | default `1.0`; clamped `> 0` |
| `bijzonder` (one-off, euro) | folded into gross | default `0` (D3) |
| — (`aofTariff`) | `aofTariff` | `SettingsService::getPayrollAofTariff()`, `--aof` override |
| — (`whkPercentage`) | `whkPercentage` | `SettingsService::getPayrollWhkPercentage(tablesDefault)`, `--whk` override |
| — (`awfTariff`) | `awfTariff` | `low` (no contract to read; the proforma default, documented) |

Age/AOW is **not** a code branch in this change: the raw `dateOfBirth` is passed straight to the
calculator, whose existing `isAowAge()`/`schijvenSet()` do the AOW-age table-set switch — exactly the
same path a real run takes. `awfTariff` defaults to `low` because there is no contract to read it from
(documented in the response + `_note`); an operator override is a named fast-follow if ever needed.

### D3 — The one-off bijzondere beloning is a combined-loon estimate, NOT bijzonder tarief

The engine has no bijzonder-tarief path (a `payroll-core-engine` named fast-follow). To honour
"reuse the calculator as-is, no new tax logic", the one-off bijzondere beloning is **added to the
period gross** and run through the regular maandtabel — a legitimate "what does my net look like this
month if I also receive €X" combined-period simulation. The response and the manifest `_note` label
this explicitly as a **combined-loon estimate**, and state that the statutory bijzonder tarief (a
separate percentage table for incidental payments) is the engine's fast-follow and is deliberately not
applied here. Zero new tax logic: the value is summed into `grossMonthlySalaryCents` before the
existing `calculate()`.

### D4 — ONE guarded endpoint, resolve-first → 404, persist-nothing

`POST /api/payroll/proforma` (`#[NoAdminRequired]`): `PayrollController::proforma(...)` first calls a
capability probe `authorizeProformaAccess()` that resolves the caller's access to the hrmq payroll
register/schema through the container-provided ObjectService under **ambient RBAC** (the same
ObjectService + `getRegisterSlug()` idiom as `authorizeRun()`). A caller whose RBAC cannot see the
payroll register — i.e. anyone who could not see a real Payslip — gets a **404**: the simulator is
invisible to non-HR callers and unauthorized/unavailable collapse to the same status (no capability
leak, ADR-005 no-admin-idor spirit applied to a capability rather than a row). The probe is
**read-only** and reads no object for its data; it never writes. Only after the gate passes does the
controller delegate to `ProformaPayslipService::simulate()` and return the breakdown as a
`JSONResponse`. Malformed input (non-numeric gross, unknown table colour) → 400 with a Dutch message.
No `runId`, no object created, nothing persisted — the endpoint is a pure function behind an RBAC gate.

### D5 — occ command mirrors the endpoint for support

`occ hrmq:payroll:proforma --gross 3800 [--table wit] [--date-of-birth 1990-01-01] [--parttime 1.0]
[--bijzonder 0] [--period 2026-02] [--aof laag] [--whk 1.52]` calls the same
`ProformaPayslipService::simulate()` and prints the full breakdown (bruto, loonheffing, arbeidskorting,
volksverzekeringen, Zvw, werknemersverzekeringen, werkgeverslasten, vakantiegeldreservering, netto,
applied rate). Registered as a `<command>` in `appinfo/info.xml`. This gives support a way to reproduce
a net figure with no browser and no DB access, and it exercises the identical code path the endpoint
uses (so a golden test on the service covers both surfaces).

### D6 — Manifest surface + the live-compute constraint (documented, not worked around)

Manifest v2's `action` discriminator is `[handler, open-modal, open-page, navigate, object-op, export,
open-form, refresh, api-call, toggle]` (read fresh from the vendored
`@conduction/nextcloud-vue/src/schemas/app-manifest-v2.schema.json`). **None** expresses a
persist-nothing interactive compute-form:

- `open-form` opens a schema-driven create dialog that **saves** the object to a register — persistence
  is its entire purpose, the opposite of proforma.
- `api-call` POSTs a **token-interpolated** url (`@objectId`, fixed params) and surfaces a
  **toast + page refresh** — it neither gathers a bespoke set of hypothetical inputs from the user nor
  renders a returned JSON breakdown.

So a purely declarative page cannot render "collect hypothetical inputs → POST → show breakdown, save
nothing". The honest MVP therefore uses the v2 `custom` page type (`pages[].type` enum includes
`custom`), a host-app SFC `ProformaPayslip` that renders the form + the breakdown by calling
`POST /api/payroll/proforma`, with the schema-**REQUIRED** `_note` on a `custom` page documenting
exactly this constraint (why open-form/api-call cannot express it). A new top-level "Simuleer
loonstrook" `menu` entry routes to it. The stable, test-covered contract stays the endpoint + occ
command; the custom page is the thin interactive shell. `npm run check:manifest` gates it.

### D7 — The worked anchor proves the reuse

Proforma input `{gross: 3800, table: wit, loonheffingskorting: true, dateOfBirth: (below-AOW),
parttime: 1.0, bijzonder: 0, period: 2026-02, aof: laag, whk: 1.52}` MUST reproduce the
`payroll-core-engine` design.md D2 anchor **exactly**: loonheffing €718,83, arbeidskorting €473,75,
zvw €231,80, werknemersverzekeringen €419,14, vakantiegeldReserved €304,00, **nettoPay €3.081,17** —
because the proforma service builds the same `CalculationInput` and calls the same
`PayrollCalculator`. A divergence means the wrapper corrupted an input, not that the tax maths changed.

### Declarative vs imperative (ADR-031)

| Behaviour | Path | Rationale |
|---|---|---|
| Tax parameters | `payroll-core-engine` static tables (`nl-2026.json`) | reused unchanged — no new tables, no new tax logic |
| Gross-to-net computation | **existing** imperative pure PHP (`PayrollCalculator`) reused AS-IS | the multi-step statutory chain already exists; this change adds ZERO tax logic (ADR-031 exception already granted to the engine) |
| Input assembly | imperative stateless service (`ProformaPayslipService`) | a pure param→value-object mapping with no persistence — nothing to declare |
| Trigger | ONE guarded endpoint + ONE occ command | operator-demand; no object/lifecycle exists to hang a declarative action on (PayrollController precedent) |
| Persistence | **none** | the defining property of a pro-forma simulation — no object, no ObjectService write |
| UI surface | `type: custom` manifest page (SFC) + menu entry | ADR-031 default is declarative, BUT manifest v2 has no persist-nothing live-compute primitive (D6) — `custom` with a REQUIRED `_note` is the documented, honest fallback |
| RBAC | resolve-first capability probe → 404 | reuses PayrollController's ObjectService+ambient-RBAC idiom against the register rather than a row |

## Seed Data (ADR-001)

No new seed objects and no new schema — proforma persists nothing, so there is nothing to seed. The
dev-container gate instead exercises the real path: `occ hrmq:payroll:proforma --gross 3800 --table wit
--period 2026-02` must print **netto €3.081,17** (the engine anchor), and the register object counts
before and after MUST be identical (proof that nothing was written).

## Risks / Trade-offs

- **Combined-loon vs bijzonder tarief (D3).** A one-off run through the regular table over-/under-states
  the true bijzonder-tarief net. Mitigated: the field is labelled a combined-loon estimate in both the
  response and the `_note`, and the statutory rate is named as the engine's fast-follow — no silent
  wrong number.
- **`awfTariff` defaults to `low` (D2)** because there is no contract to read. A high-tariff employer's
  employer-charge lines are then optimistic, but employer charges never affect the employee net — the
  headline proforma figure is unaffected; documented in the response.
- **Custom page is a host-app SFC (D6)**, a small deviation from the fleet's declarative-manifest
  default — unavoidable given manifest v2 has no persist-nothing live-compute primitive; the `_note`
  documents it and the endpoint/command remain the tested contract.
- **RBAC is a capability probe, not a row guard (D4).** Because there is no object, the gate resolves
  register access instead of an id; anyone who can see real payslips can simulate, which is the intended
  HR-only boundary.

## Open Questions

- None blocking. Bijzonder tarief and an operator `awfTariff` override are named fast-follows; a
  saved-scenario feature, if ever wanted, is a separate (persisting) change out of this MVP's scope.
