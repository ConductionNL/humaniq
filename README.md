> [!IMPORTANT]
> ## 🚚 This repository has moved to Codeberg
>
> Active development now happens at **https://codeberg.org/Conduction/hrmq**.
> This GitHub mirror is read-only — issues, pull requests, and new commits should go to Codeberg.
> Update your remote with: `git remote set-url origin https://codeberg.org/Conduction/hrmq`# hrmq

ConductionNL — Human Resources & Payroll administration for Dutch SMBs.

Status: specs pending. See [openspec/](openspec/) for the change log.

Part of the Conduction ecosystem alongside [shillinq](https://codeberg.org/Conduction/shillinq) (bookkeeping), [pipelinq](https://codeberg.org/Conduction/pipelinq) (CRM), and [openregister](https://codeberg.org/Conduction/openregister) (data layer).

## Payroll engine — NOT certified (read this before production use)

hrmq ships an open-source Dutch payroll calculation engine
(`lib/Payroll/PayrollCalculator.php`) implementing the Belastingdienst
*Rekenvoorschriften voor de geautomatiseerde loonadministratie 2026* formula
chain (witte/groene maandtabel, schijventarief, AHK/ARK/OUK heffingskortingen,
Zvw werkgeversheffing, Awf/Aof/Wko/Whk employer charges) over the versioned
tax-year parameter file `lib/Standards/tables/nl-2026.json`.

**The engine is NOT certified.** Be aware of the following before relying on
its output:

- **Traceability**: every computed `PayrollRun` carries `engineVersion` (the
  exact parameter file that produced it, e.g. `nl-2026`) and `calculatedAt`,
  and every computed `Payslip` reconciles cents-exact to the declared net
  equation — both enforced by the machine-checkable corpus rules
  `nl-engine-table-version` and `nl-engine-output-consistency`
  (`occ hrmq:payroll:verify --period YYYY-MM` audits a run against the same
  corpus that audits hand-entered data).
- **Certification gap**: the golden tests
  (`tests/fixtures/payroll-2026/*.json`) are *self-consistent* with the
  parameter tables — the anchor case is hand-computed from the primary PDFs,
  but the official Belastingdienst test sets (loonheffingstabellen
  proefberekeningen) have **not** been run against this engine yet. The
  marked slot for them is `tests/fixtures/payroll-2026/official/README.md`.
- **Known MVP limitations**: fixed monthly salary only (hourly wage x
  approved Timesheet hours is a named fast-follow); no VCR (voortschrijdend
  cumulatief rekenen — premium bases are period-capped, not cumulative, which
  drifts for wages fluctuating around the maximum premieloon); no
  anoniementarief computation (employees failing the BSN/ID preconditions are
  skipped with a reason, never computed wrong); no CAO logic, no bijzonder
  tarief (vakantiegeld payout), no 30%-ruling netto-operation, no pension
  premie calculation, no Zvw-inhouding mode, no loonaangifte message
  generation.
- **Production use requires verification** of the engine's output against the
  official Belastingdienst test sets by a qualified loonadministrateur.

Honesty is a feature: this disclaimer is a requirement of the
`payroll-core-engine` spec, not a footnote.

## Rostering (MVP)

hrmq ships a forward-looking shift-planning MVP that plans *and* pre-checks a
roster against the same Arbeidstijdenwet (working-time law) rules the app
already enforces on realised clock data:

- **Define shifts** — reusable `Shift` definitions (name, start/end time,
  break, optional org-unit scope). A shift whose end time is not after its
  start time denotes a night shift crossing midnight.
- **Assign employees per period** — a `RosterAssignment` places one employee
  on one shift on one date within a `Roster`, projecting the shift's times
  onto the date (`plannedStart`/`plannedEnd`/`plannedBreakMinutes`).
- **Publish a roster** — the `Roster` header carries a real
  `concept → gepubliceerd` lifecycle (`publiceren`/`intrekken`); publishing
  freezes the plan and makes it the team's roster.
- **Check against the Arbeidstijdenwet** — the roster ATW cross-check
  **reuses the three existing corpus rules** (`nl-atw-dagelijkse-rust`,
  `nl-atw-max-werkdag`, `nl-atw-pauze`) over the *planned* assignments — no
  new working-time rule is invented. Run it on demand with
  `occ hrmq:roster:check --roster ID | --period YYYY-Www [--administration ADM]`
  (exits non-zero on any mandatory violation) or from the `RosterDetail`
  "ATW-controle" action; published assignments also join the standing
  `occ hrmq:rules:audit`.

**Non-goals (deeper workforce management is a future integration, not this
change):** auto-optimisation, demand forecasting and rule-based
auto-scheduling are deferred to a dedicated workforce-management tool
integrated via **openconnector** — hrmq owns the plan of record and the ATW
compliance view, not the WFM optimiser. A drag-and-drop planbord,
availability/preferences, skills-matching, open-shift bidding/shift-swap and
coverage alerts are named fast-follows. There is no automation between a
published roster and realised `AttendanceRecord`/`Timesheet` hours.
