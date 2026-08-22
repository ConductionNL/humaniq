---
sidebar_position: 1
description: What Humaniq is, who it's for, and how it's built.
---

# Humaniq

Humaniq is open-source HR and payroll administration on Nextcloud, built on
the [OpenRegister](https://openregister.conduction.nl) data layer. It
runs inside your own Nextcloud — no external SaaS, no vendor lock-in,
your employee data never leaves your instance.

Three things define what Humaniq now is:

- A **country-pluggable payroll engine** — the gross-to-net chain is
  declarative configuration (a jurisdiction pack) run by a pure
  interpreter, not hardcoded PHP per country. The Netherlands ships as
  the first bundled pack.
- **Multi-administratie** for accountants — one instance can run payroll
  for many client companies, with a per-user active-administratie
  switch.
- A **machine-checkable compliance corpus** that grows with every
  capability — not narrative documentation, but a versioned rule set an
  executable engine audits your actual data against.

## Who it's for

Humaniq targets Dutch and EU small and medium-sized employers — and the
accountant offices that run payroll on their behalf — who need HR and
payroll administration without the enterprise price tag of an AFAS or
the per-seat SaaS billing of a Personio or BambooHR. If you already run
Nextcloud, Humaniq adds:

- Timesheets, expense claims (with receipt-OCR prefill and mileage-rate
  compliance), leave & absence (verzuim) case management, automatic
  leave accrual, buy/sell of bovenwettelijk hours, and shift rostering
  with a working-time-law pre-check
- Onboarding and offboarding wizards, recruiting (ATS) with offer
  e-signature and interview scheduling, compensation review cycles,
  goals & OKRs, performance reviews, an organisation chart, and an
  asset register
- A country-pluggable payroll engine — retroactive corrections, sick
  pay, company-car bijtelling, DGA payroll mode, wage garnishment,
  werkkostenregeling, and run-to-run mutation reporting all on top of
  it
- **Multi-administratie**, so one instance serves many client companies
- A versioned, machine-checkable corpus of Dutch labour-law,
  EU-directive, wage-tax, and AVG/GDPR compliance rules — including a
  statutory-retention guard on data-subject erasure requests

## The OpenRegister foundation

Every HR object in Humaniq — `Employee`, `EmploymentContract`, `Timesheet`,
`Expense`, `LeaveRequest`, `PayrollRun`, `Payslip`, and dozens more — is
stored as an [OpenRegister](https://openregister.conduction.nl) object.
OpenRegister is the shared data layer every Conduction app is built on: it
gives Humaniq schema validation, declarative lifecycle state machines
(`x-openregister-lifecycle`), audit trails, and multi-tenant scoping for
free, instead of hand-rolled PHP transition code and bespoke database
tables.

Because Humaniq is a **leaf app** on top of OpenRegister, Humaniq itself must be
installed alongside OpenRegister — see
[Installation](/docs/getting-started/installation) for the dependency and
first-run register import.

## A versioned compliance rule corpus

Alongside the OpenRegister data layer, Humaniq ships a static, versioned
corpus of international HR/labour and payroll rules — EU labour
directives, ILO conventions, GDPR for employee data, occupational health &
safety, Dutch labour law, and payroll / wage-tax & social-security
compliance — under `lib/Standards/rules/`. An executable `RuleEngine`
audits the register's HR/labour objects against that corpus and reports
compliance coverage. See [The compliance rule
engine](/docs/compliance/rule-engine) for how it works and how to run it.

## Honesty as a feature: the payroll disclaimer

Humaniq's payroll calculation engine is **not a certified payroll product**,
for any jurisdiction pack it runs. The bundled NL pack implements the
Belastingdienst *Rekenvoorschriften voor de geautomatiseerde
loonadministratie 2026* formula chain over a verified, versioned
tax-year parameter file, but the official Belastingdienst test sets
have not yet been run against it. Every computed run carries an
`engineVersion` (naming the exact pack and version that produced it) so
its provenance is always traceable. Read the full disclaimer, the
jurisdiction-pack trust model, and the engine's honestly-stated limits
in [The payroll engine](/docs/payroll/payroll-engine) before relying on
its output — production use requires verification by a qualified
loonadministrateur.

## License

Humaniq is free and open source under the **EUPL-1.2** license — the European
Union Public Licence. Source code lives at
[github.com/ConductionNL/humaniq](https://github.com/ConductionNL/humaniq).

- New to Humaniq? Start with [Installation](/docs/getting-started/installation).
- Want the payroll story first? Go straight to [The payroll
  engine](/docs/payroll/payroll-engine).

For support, contact support@conduction.nl.
