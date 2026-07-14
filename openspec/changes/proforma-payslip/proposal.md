---
kind: code
depends_on:
  - payroll-core-engine   # the pure PayrollCalculator + CalculationInput/CalculationResult + TaxTables this change reuses AS-IS
---

# Pro-forma Payslip — interactive gross-to-net simulation that persists nothing

## Why

`payroll-core-engine` shipped the pure, stateless `PayrollCalculator` (design.md D1/D2):
`calculate(CalculationInput, TaxTables): CalculationResult` — zero Nextcloud dependencies, integer
cents, verified against the Rekenvoorschriften 2026 anchor (€3.800 wit → **netto €3.081,17**). Today
that engine only runs inside `PayrollRunService`, which requires an active Employee, a covering
EmploymentContract, a draft PayrollRun and a persisted Payslip before a single euro is computed.

HR admins repeatedly need the answer to a **hypothetical**: "if I offer €4.100 bruto on the witte
tabel, what is the net?", "what does an AOW-age hire net?", "how does a 0,8 part-time factor land?",
"what does a €1.500 one-off do to this month's net?". Answering that today means creating throw-away
Employee/Contract/Run/Payslip records and then deleting them — noise in the register, audit rows for
fictional people, and a real risk of leaving test data behind. The engine is already a pure function;
the only thing missing is a **persist-nothing** front door to it. This change adds exactly that: a
stateless proforma endpoint + service + occ command that build a `CalculationInput` from hypothetical
inputs, run the **existing** calculator against the real `nl-2026.json`, and return the full
`CalculationResult` breakdown as JSON — creating no Employee, no Contract, no PayrollRun, no Payslip,
and writing nothing through ObjectService. A natural fast-follow now that the payroll engine exists.

## What Changes

- **NEW `lib/Service/ProformaPayslipService.php`** — a thin, stateless builder: takes the posted
  hypothetical inputs (bruto maandsalaris, loonheffingstabel `wit`/`groen`, `dateOfBirth`/AOW hint,
  part-time factor, one-off bijzondere beloning), constructs a `CalculationInput`
  (`grossMonthlySalaryCents = round((bruto × parttimeFactor + bijzondereBeloning) × 100)`), loads the
  tax-year `TaxTables` for the period (default the current year's `nl-<YYYY>` — `nl-2026`), runs the
  **existing** `PayrollCalculator::calculate()`, and maps the returned `CalculationResult` to a
  euro-decimal JSON breakdown. It **never** touches ObjectService, never writes any object, and holds
  no state between calls (design.md D1). The employer-level knobs it cannot ask the user for
  (`aofTariff`, `whkPercentage`) come from `SettingsService::getPayrollAofTariff()` /
  `getPayrollWhkPercentage()` exactly as `PayrollRunService` reads them.
- **NEW guarded endpoint** — `POST /api/payroll/proforma` (`PayrollController::proforma`,
  `#[NoAdminRequired]`), mirroring the PayrollController resolve-first RBAC pattern: the caller's
  access to the hrmq payroll register/schema is resolved through ObjectService under ambient RBAC
  BEFORE any compute; a caller who could not see real payslips gets a **404** (unauthorized and
  unavailable collapse to the same 404 — the simulator surface is invisible to non-HR callers). No
  `runId`, no object is read for its data and none is written — the resolve is a pure capability probe.
- **NEW occ command `hrmq:payroll:proforma`** — same computation from CLI flags
  (`--gross`, `--table wit|groen`, `--date-of-birth`, `--parttime`, `--bijzonder`, `--period`,
  `--aof`, `--whk`) printing the full breakdown; useful for support to reproduce a net figure without
  DB access. Registered in `appinfo/info.xml`.
- **Manifest "Simuleer loonstrook"** — a new top-level menu entry + a `type: custom` page
  (`ProformaPayslip`) that renders the input form and the returned breakdown by calling the endpoint.
  Manifest v2 has **no declarative primitive** for a persist-nothing interactive compute-form:
  `open-form` is bound to a register/schema and **persists** the created object (exactly what proforma
  must not do), and `api-call` only interpolates fixed/`@token` params + surfaces a toast — it cannot
  gather arbitrary hypothetical inputs nor render a full JSON breakdown. So the interactive surface is
  a `type: custom` host-app SFC (its REQUIRED `_note` documents precisely this constraint), while the
  stable, testable contract is the endpoint + occ command. No new schema, no register writes.

### Non-goals (named fast-follows and exclusions)

- **Bijzonder tarief (the statutory special rate for one-off payments)** — the engine has no
  bijzonder-tarief path (a named `payroll-core-engine` fast-follow). The one-off bijzondere beloning is
  therefore added to the period gross and run through the **regular** maandtabel as a combined-loon
  "wat-als" estimate, explicitly labelled as NOT the statutory bijzonder tarief. No new tax logic is
  introduced — the calculator is reused exactly as-is.
- **Hourly path, anoniementarief, CAO, 30%-ruling, pension, VCR** — all remain the engine's named
  non-goals; proforma inherits the engine's supported path (fixed monthly salary) and nothing more.
- **Persistence of any kind** — no draft "simulation" object, no history, no saved scenarios. If a
  saved-scenario feature is wanted later it is a separate change; the MVP is deliberately stateless.
- **Employee PII** — proforma takes only hypothetical numbers; it reads no Employee/Contract record.

## Capabilities

### New Capabilities

- `proforma-payslip`: the stateless proforma builder service, the guarded persist-nothing
  `POST /api/payroll/proforma` endpoint, the `hrmq:payroll:proforma` occ command, the "Simuleer
  loonstrook" manifest surface (with the documented manifest live-compute constraint), all reusing the
  existing `PayrollCalculator` as-is.

### Modified Capabilities

<!-- none — payroll-core-engine's calculator/input/result/tables are consumed unchanged, not modified -->

## Impact

- `lib/Service/ProformaPayslipService.php` — NEW (pure orchestration; reads SettingsService config +
  TaxTables, calls PayrollCalculator; zero ObjectService use).
- `lib/Controller/PayrollController.php` — +1 method `proforma()` (`#[NoAdminRequired]`, resolve-first
  → 404); `appinfo/routes.php` +1 route (`POST /api/payroll/proforma`, before the SPA catch-all).
- `lib/Command/ProformaPayslipCommand.php` — NEW; `appinfo/info.xml` +1 `<command>` entry.
- `src/manifest.json` — new "Simuleer loonstrook" menu entry + `type: custom` `ProformaPayslip` page
  with the REQUIRED constraint `_note`; `npm run check:manifest` passes.
- `src/**` — NEW host-app SFC for the `ProformaPayslip` custom page (form + breakdown, calls the
  endpoint).
- `tests/Unit/Service/ProformaPayslipServiceTest.php`,
  `tests/Unit/Controller/PayrollControllerProformaTest.php` — NEW (the anchor input reproduces the
  engine's €3.081,17 net; the RBAC gate returns 404 for a non-HR caller; nothing is written).
- Depends on: `payroll-core-engine` (the calculator, input/result value objects and TaxTables loader —
  reused unchanged).
