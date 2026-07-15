# DGA payroll mode — skip werknemersverzekeringen for a director-major-shareholder

## Why

A DGA (directeur-grootaandeelhouder — director-major-shareholder, aanmerkelijkbelanghouder) is
generally **not verzekeringsplichtig** for the werknemersverzekeringen (Werkloosheidswet/WW via
Awf, WIA/WAO via Aof, Zvw-gekoppelde Whk): no Awf/Aof/Wko/Whk premium is levied, employer or
employee side. Loonheffing and Zvw still apply in full. The DGA is instead subject to the
**gebruikelijkloonregeling** (Wet LB 1964 art. 12a): the BV must pay at least a customary-salary
norm, verified at **€58.000 per jaar in 2026** (Belastingdienst — *Loon en aanmerkelijk belang*;
2024/2025 was €56.000). `PayrollCalculator` (verified against HEAD 2026-07-15,
`payroll-core-engine` merged 2026-07-14) currently computes Awf/Aof/Wko/Whk for every employee
unconditionally — there is no DGA path. This is an engine-correctness gap: every DGA run today
silently over-charges the employer for premiums the DGA does not owe and never checks the
gebruikelijkloon floor.

**A grounding correction to the feature framing**: verified against HEAD, `nettoPayCents = tvl -
loonheffingCents` only (`PayrollCalculator::calculate()` step 10; design.md D2 step 10 of
`payroll-core-engine`: "employer charges never reduce net; Zvw is the employer levy"). The
werknemersverzekeringen (`awf/aof/wko/whk`) are **employer-borne charges that already do not
reduce the employee's net pay** in this engine — they only feed `employerChargesCents` and the
`PayrollRun.totalEmployerCharges` roll-up. So for a DGA at the same gross, **`nettoPay` does NOT
rise** when werknemersverzekeringen are zeroed (it was never a function of them); what changes is
`employerChargesCents`, which drops by exactly the zeroed amount. The design.md hand-computation
below reflects this — every DGA anchor figure is computed from the real equation chain, not from
an assumed netto increase.

## What Changes

- **NEW `Employee.isDga`** (boolean, default `false`) — the DGA marker (ADR-001 Rule 4: ZZP/DGA is
  a mode-switch on the existing Employee record, never a forked app or schema).
- **NEW `Employee.gebruikelijkloonJustification`** (string, nullable, optional) — free-text record
  of why a lower-than-norm gebruikelijkloon is justified (lower comparable-function salary,
  start-up dispensation, etc.). MVP: presence-only exemption from the flag (content is not
  validated); full validation is a named follow-up.
- **NEW `CalculationInput::$verzekeringsplichtig`** (bool, default `true` — additive, non-breaking:
  every existing named-argument call site is unaffected). `false` for a DGA.
- **`PayrollCalculator::calculate()`** — when `verzekeringsplichtig === false`, `awfCents`,
  `aofCents`, `wkoCents` and `whkCents` are all `0` (no rate lookup, no premieloon cap applied);
  `werknemersverzekeringenCents = 0`; `employerChargesCents = zvwCents` only.
  `loonheffingCents`/`arbeidskortingCents`/`volksverzekeringenCents`/`zvwCents`/`nettoPayCents`/
  `vakantiegeldReservedCents` are computed exactly as today — byte-identical for
  `verzekeringsplichtig: true` (the default), so the normal path is provably unchanged.
- **`PayrollRunService::generate()`** — builds `CalculationInput.verzekeringsplichtig` from
  `!($employee['isDga'] ?? false)`; stamps the new `Payslip.isDga` field as a denormalized copy of
  `Employee.isDga` (the `nextcloudUserId`→`userId` precedent) so downstream consumers
  (glpost/UPA/loonaangifte) can see WHY werknemersverzekeringen read zero instead of assuming an
  engine bug.
- **NEW `lib/Standards/tables/nl-2026.json` `parameters.gebruikelijkloon`** — the verified,
  sourced 2026 norm (€58.000/jaar), same `{value, source, sourceUrl, verified}` shape as every
  other parameter leaf.
- **NEW `TaxTables::gebruikelijkloon(): array`** — `{jaarnormCents}`, same
  leaf-lookup + `euroToCents()` pattern as `zvw()`/`wml()`.
- **NEW corpus rule `nl-gebruikelijkloon-norm`** (`lib/Standards/rules/payroll.json`, severity
  `conditional`) + **NEW `lib/Standards/Checks/NlDgaChecks.php`** (auto-discovered
  `CheckProvider`, per `RuleEngine::providers()` — no manual registration): flags an `Employee`
  with `isDga: true` whose `grossMonthlySalary × 12` is below the norm, unless
  `gebruikelijkloonJustification` is non-empty. Vacuous for non-DGA employees.

## Non-Goals (named follow-ups)

- **Doorbetaaldloonregeling** (one payer paying loon across multiple linked BVs on a DGA's behalf)
  — not modelled; each Employee/PayrollRun stays single-administration.
- **Multiple-BV aggregation** for the gebruikelijkloon norm (the norm is per DGA across all
  aanmerkelijk-belang BVs combined when the DGA works for several) — the MVP check evaluates one
  Employee record in isolation; cross-administration aggregation is a follow-up.
- **30%-ruling interaction** — a DGA with a granted 30%-ruling reduces the gebruikelijkloon norm
  by the ruling's effective rate (`Employee.thirtyPercentRulingGranted`/`thirtyPercentRulingRate`
  already exist on the schema for the ruling itself); this change does NOT wire that interaction —
  `NlDgaChecks` compares the raw norm only. Named follow-up.
- **`gebruikelijkloonJustification` content validation** — MVP accepts any non-empty value as an
  exemption; validating it against the three statutory justification grounds (comparable-function
  salary, highest-paid-employee salary, or a lower amount substantiated to the Belastingdienst) is
  a follow-up.

## Impact

- `lib/Settings/register.d/hr-objects.json` — Employee: +`isDga`, +`gebruikelijkloonJustification`;
  Payslip: +`isDga`.
- `lib/Payroll/CalculationInput.php` — +`verzekeringsplichtig` (default `true`).
- `lib/Payroll/PayrollCalculator.php` — step 9 gated on `verzekeringsplichtig`.
- `lib/Payroll/TaxTables.php` — +`gebruikelijkloon()`.
- `lib/Standards/tables/nl-2026.json` — +`parameters.gebruikelijkloon`.
- `lib/Service/PayrollRunService.php` — `CalculationInput` construction + `Payslip.isDga` stamp.
- `lib/Standards/rules/payroll.json` — +`nl-gebruikelijkloon-norm`.
- `lib/Standards/Checks/NlDgaChecks.php` — NEW.
- `tests/fixtures/payroll-2026/dga-anchor.json` — NEW golden fixture (this change's anchor).
- Depends on: `payroll-core-engine` (merged 2026-07-14) — consumes `PayrollCalculator`,
  `CalculationInput`, `TaxTables`, `nl-2026.json`, `PayrollRunService` at HEAD; no schema change to
  those files' shape beyond the additive fields above.
