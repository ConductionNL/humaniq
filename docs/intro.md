---
sidebar_position: 1
description: What HRMQ is, who it's for, and how it's built.
---

# HRMQ

HRMQ is open-source HR and payroll administration for Dutch and EU
employers, built on the [OpenRegister](https://openregister.conduction.nl)
data layer. It runs inside your own Nextcloud — no external SaaS, no vendor
lock-in, your employee data never leaves your instance.

## Who it's for

HRMQ targets Dutch and EU small and medium-sized employers who need HR and
payroll administration without the enterprise price tag of an AFAS or the
per-seat SaaS billing of a Personio or BambooHR. If you already run
Nextcloud, HRMQ adds:

- Timesheets, expense claims, leave & absence (verzuim) case management
- Onboarding and offboarding wizards, recruiting (ATS), performance
  reviews, an organisation chart, and an asset register
- The only open-source Dutch payroll calculation engine
- A versioned, machine-checkable corpus of Dutch labour-law, EU-directive
  and wage-tax compliance rules

## The OpenRegister foundation

Every HR object in HRMQ — `Employee`, `EmploymentContract`, `Timesheet`,
`Expense`, `LeaveRequest`, `PayrollRun`, `Payslip`, and dozens more — is
stored as an [OpenRegister](https://openregister.conduction.nl) object.
OpenRegister is the shared data layer every Conduction app is built on: it
gives HRMQ schema validation, declarative lifecycle state machines
(`x-openregister-lifecycle`), audit trails, and multi-tenant scoping for
free, instead of hand-rolled PHP transition code and bespoke database
tables.

Because HRMQ is a **leaf app** on top of OpenRegister, HRMQ itself must be
installed alongside OpenRegister — see
[Installation](/docs/getting-started/installation) for the dependency and
first-run register import.

## A versioned compliance rule corpus

Alongside the OpenRegister data layer, HRMQ ships a static, versioned
corpus of international HR/labour and payroll rules — EU labour
directives, ILO conventions, GDPR for employee data, occupational health &
safety, Dutch labour law, and payroll / wage-tax & social-security
compliance — under `lib/Standards/rules/`. An executable `RuleEngine`
audits the register's HR/labour objects against that corpus and reports
compliance coverage. See [The compliance rule
engine](/docs/compliance/rule-engine) for how it works and how to run it.

## Honesty as a feature: the payroll disclaimer

HRMQ's payroll calculation engine is **not a certified payroll product**.
It implements the Belastingdienst *Rekenvoorschriften voor de
geautomatiseerde loonadministratie 2026* formula chain over a verified,
versioned tax-year parameter file, but the official Belastingdienst test
sets have not yet been run against it. Every computed run carries an
`engineVersion` so its provenance is always traceable. Read the full
disclaimer in [The payroll engine](/docs/payroll/payroll-engine) before
relying on its output — production use requires verification by a
qualified loonadministrateur.

## License

HRMQ is free and open source under the **EUPL-1.2** license — the European
Union Public Licence. Source code lives at
[codeberg.org/Conduction/hrmq](https://codeberg.org/Conduction/hrmq).

- New to HRMQ? Start with [Installation](/docs/getting-started/installation).
- Want the payroll story first? Go straight to [The payroll
  engine](/docs/payroll/payroll-engine).

For support, contact support@conduction.nl.
