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
