---
capability: proforma-payslip
status: done
built_by: openspec/changes/archive/2026-07-14-proforma-payslip
---

# proforma-payslip Specification

**Status**: done
**Scope**: humaniq (`depends_on: [payroll-core-engine]` — reuses the pure calculator AS-IS, adds ZERO tax logic)
**OpenSpec changes**:
- [proforma-payslip](../../changes/archive/2026-07-14-proforma-payslip/) _(archived 2026-07-14)_ —
  a persist-nothing front door to the existing `payroll-core-engine` calculator: a stateless
  `ProformaPayslipService`, one RBAC-gated `POST /api/payroll/proforma` endpoint
  (`PayrollController::proforma`, resolve-first capability probe → 404), one occ command
  `humaniq:payroll:proforma`, and a manifest "Simuleer loonstrook" `type: custom` page + host-app SFC.
  The `PayrollCalculator`, `CalculationInput`, `CalculationResult` and `TaxTables` are consumed
  unchanged (kind: code)

## Purpose

HR admins repeatedly need the answer to a hypothetical ("if I offer €4.100 bruto wit, what is the
net?", "what does an AOW-age hire net?", "how does a 0,8 part-time factor land?") without creating
throw-away Employee/Contract/Run/Payslip records. The `payroll-core-engine` calculator is already a
pure function; this capability adds the missing **persist-nothing** front door to it — building a
`CalculationInput` from hypothetical inputs, running the existing calculator against the real
`nl-<YYYY>` tables, and returning the full gross-to-net breakdown as JSON. It creates no Employee,
Contract, PayrollRun or Payslip and writes nothing through ObjectService. The anchor case
(€3.800 wit → netto €3.081,17) proves the reuse is faithful — a divergence means the wrapper
corrupted an input, not that the tax maths changed.

## Requirements

### Requirement: A stateless proforma service SHALL compute the full breakdown and persist NOTHING (REQ-PRO-001)

`lib/Service/ProformaPayslipService.php` SHALL, given hypothetical inputs (bruto maandsalaris,
loonheffingstabel `wit`/`groen`, `dateOfBirth`/AOW hint, part-time factor, one-off bijzondere
beloning, period), construct a `CalculationInput`, load the tax-year `TaxTables`
(`nl-<YYYY>` for the period, default the current year — `nl-2026`), run the **existing**
`PayrollCalculator::calculate()`, and return the full `CalculationResult` breakdown mapped to
euro decimals. The service SHALL hold no state between calls, SHALL NOT inject or call OpenRegister's
ObjectService, and SHALL create or mutate NO object — no Employee, EmploymentContract, PayrollRun or
Payslip. It SHALL add no tax logic: the calculator, its input/result value objects and the tables are
consumed unchanged.

#### Scenario: Computing a simulation writes no object
- **GIVEN** a proforma request for €3.800,00 wit, below AOW, period 2026-02
- **WHEN** the service computes the breakdown
- **THEN** the full gross-to-net breakdown is returned AND no Employee, EmploymentContract, PayrollRun
  or Payslip is created and no ObjectService write occurs (the register object count is unchanged)

#### Scenario: The service is a thin reuse of the engine, not new tax logic
- **GIVEN** the same input twice
- **WHEN** `simulate()` runs
- **THEN** it returns identical figures both times (stateless, deterministic) and every figure comes
  from `PayrollCalculator::calculate()` over the `nl-2026` tables — no tax parameter is computed in the
  proforma service itself

### Requirement: A guarded endpoint SHALL return the full gross-to-net breakdown as JSON (REQ-PRO-002)

`appinfo/routes.php` SHALL add `POST /api/payroll/proforma` → `PayrollController::proforma`
(`#[NoAdminRequired]`), registered before the SPA catch-all. On a valid, authorized request it SHALL
return a `JSONResponse` carrying the complete breakdown — at least grossPay, loonheffing,
arbeidskorting, volksverzekeringen, zvw (+ mode/rate), appliedTaxRate, vakantiegeldReserved,
werknemersverzekeringen, employerCharges and **nettoPay** — computed by
`ProformaPayslipService::simulate()`. Malformed input (non-numeric gross, unknown table colour) SHALL
return HTTP 400 with a clear Dutch message. No `runId` is accepted and no object is read for its data
or written.

#### Scenario: A valid request returns the complete breakdown
- **GIVEN** an authorized HR caller posts `{gross: 3800, table: "wit", period: "2026-02"}`
- **WHEN** `POST /api/payroll/proforma` is handled
- **THEN** the JSON response contains loonheffing, arbeidskorting, volksverzekeringen, zvw,
  werknemersverzekeringen, employerCharges, vakantiegeldReserved and nettoPay, and no object was
  persisted

#### Scenario: Malformed input is refused
- **GIVEN** an authorized caller posts `{gross: "n/a"}`
- **WHEN** the endpoint is handled
- **THEN** the response is HTTP 400 with a Dutch validation message and nothing is computed or persisted

### Requirement: An occ command SHALL compute the same breakdown from CLI flags (REQ-PRO-003)

The `humaniq:payroll:proforma` occ command SHALL accept the flags gross, table (wit/groen),
date-of-birth, parttime, bijzonder, period, aof and whk, and SHALL call the same
`ProformaPayslipService::simulate()` and print the full breakdown (bruto, loonheffing,
arbeidskorting, volksverzekeringen, Zvw, werknemersverzekeringen, werkgeverslasten,
vakantiegeldreservering, netto, applied rate). It SHALL persist nothing and SHALL be registered as a
`<command>` in `appinfo/info.xml`. This is the support-facing surface — reproduce a net figure with no
browser and no DB access.

#### Scenario: The command prints the anchor breakdown
- **GIVEN** `occ humaniq:payroll:proforma --gross 3800 --table wit --period 2026-02`
- **WHEN** the command runs
- **THEN** it prints netto €3.081,17 (the engine anchor) and exits 0, and the register object count is
  unchanged

### Requirement: The proforma surface SHALL be RBAC-gated and collapse to 404 for non-HR callers (REQ-PRO-004)

`PayrollController::proforma` SHALL resolve the caller's access to the humaniq payroll register/schema
through the container-provided ObjectService under the caller's **ambient RBAC** BEFORE any
computation (the `PayrollController::authorizeRun` resolve-first pattern applied to a capability rather
than a row). A caller whose RBAC cannot see the payroll register — i.e. anyone who could not see a real
Payslip — SHALL receive HTTP **404**, so unavailable and unauthorized collapse to the same status and
the simulator surface is never leaked. The probe SHALL be read-only (it reads no object for its data)
and SHALL write nothing.

#### Scenario: A non-HR caller cannot reach the engine
- **GIVEN** an authenticated user whose RBAC cannot resolve the humaniq payroll register
- **WHEN** they POST `/api/payroll/proforma`
- **THEN** the response is HTTP 404 and no calculation or write occurs

#### Scenario: An HR caller reaches the simulation
- **GIVEN** an HR/admin caller whose RBAC can see payroll records
- **WHEN** they POST a valid proforma request
- **THEN** the RBAC gate passes and the breakdown is returned

### Requirement: A manifest "Simuleer loonstrook" surface SHALL expose the simulator and document the live-compute constraint (REQ-PRO-005)

`src/manifest.json` SHALL gain a top-level "Simuleer loonstrook" `menu` entry routing to a `type:
custom` page (`ProformaPayslip`) whose host-app SFC renders the hypothetical-input form and the
returned breakdown by calling `POST /api/payroll/proforma`. Because manifest v2 has no declarative
primitive for a persist-nothing interactive compute-form — `open-form` is bound to a register/schema
and PERSISTS the created object, and `api-call` only interpolates fixed/`@token` params and surfaces a
toast — the page SHALL carry the schema-REQUIRED `_note` documenting exactly this constraint (why
open-form and api-call cannot express it). The page SHALL reference no new schema and cause no register
write. `npm run check:manifest` MUST pass.

#### Scenario: The custom page documents why it is not declarative
- **WHEN** `src/manifest.json` is validated after this change
- **THEN** a `type: custom` `ProformaPayslip` page exists with a `_note` stating that open-form persists
  and api-call cannot gather hypothetical inputs / render a breakdown, and `npm run check:manifest`
  passes

#### Scenario: The menu entry opens the simulator without touching the register
@e2e exclude the custom-page SFC + endpoint wiring is covered by the service/controller unit tests; the app-level e2e suite does not yet exist (tracked by active change humaniq-test-coverage-baseline)
- **GIVEN** an HR user opens "Simuleer loonstrook" from the menu
- **WHEN** they enter €3.800 wit and submit
- **THEN** the breakdown (incl. netto €3.081,17) is shown and no PayrollRun/Payslip/Employee is created

### Requirement: The worked anchor SHALL prove the reuse is faithful (REQ-PRO-006)

The proforma input assembly SHALL map hypothetical params to a `CalculationInput` per design.md D2:
`grossMonthlySalaryCents = round((gross × parttimeFactor + bijzondereBeloning) × 100)`, table colour
validated to `wit`/`groen` (default `wit`), `loonheffingskortingToegepast` default true, nullable
`dateOfBirth` passed straight through (the calculator's own `isAowAge()` decides AOW), period default
the current month, `aofTariff`/`whkPercentage` from `SettingsService` (overridable), `awfTariff`
default `low` (no contract to read). The one-off bijzondere beloning SHALL be folded into the period
gross as a **combined-loon estimate** — explicitly NOT the statutory bijzonder tarief (an engine
fast-follow) — adding no new tax logic. Given the anchor input, the proforma SHALL reproduce the
`payroll-core-engine` figures exactly.

#### Scenario: The anchor input reproduces the engine's net
- **GIVEN** a proforma request `{gross: 3800, table: "wit", loonheffingskorting: true, dateOfBirth:
  (below-AOW), parttime: 1.0, bijzonder: 0, period: "2026-02", aof: "laag", whk: 1.52}`
- **WHEN** `simulate()` runs against `nl-2026`
- **THEN** loonheffing is €718,83, arbeidskorting €473,75, zvw €231,80, werknemersverzekeringen
  €419,14, vakantiegeldReserved €304,00 and nettoPay is **€3.081,17** (the engine design.md D2 anchor)

#### Scenario: A part-time factor scales the gross before the same chain
- **GIVEN** the anchor input with `parttime: 0.5`
- **WHEN** `simulate()` runs
- **THEN** the `CalculationInput.grossMonthlySalaryCents` is €1.900,00 and the breakdown is that of a
  €1.900 wit monthly wage on `nl-2026` (no new tax logic, just a scaled gross)

#### Scenario: A one-off bijzondere beloning is a combined-loon estimate, not bijzonder tarief
- **GIVEN** the anchor input with `bijzonder: 1000`
- **WHEN** `simulate()` runs
- **THEN** the gross fed to the calculator is €4.800,00 (3.800 + 1.000) through the regular maandtabel
  and the breakdown is labelled a combined-loon estimate, stating the statutory bijzonder tarief is a
  named engine fast-follow not applied here
