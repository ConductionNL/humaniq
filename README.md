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

## Retroactive corrections (TWK) — terugwerkende kracht herrekening

Real payroll inputs change *after* a period is sealed (a backdated raise, a
late-corrected sick day, a retroactive contract fix). hrmq settles these the
Dutch way — **terugwerkende kracht herrekening (TWK)** — via a
`PayrollAdjustment` that models a **delta**, never a rewrite of the sealed
original (`lib/Service/RetroAdjustmentService.php`,
`occ hrmq:payroll:adjust`). Be aware of the following:

- **The sealed original is never mutated.** A correction reads the stored,
  already-approved/posted/paid `Payslip` only to diff against it; it writes a
  new `PayrollAdjustment` carrying the cents-exact `delta*` fields. The
  historical payslip (filed in a loonaangifte, posted to the GL) stays
  byte-untouched — an adjustment can exist against a run the engine itself
  refuses to recompute.
- **The recompute uses the ORIGINAL period's tax year.** A 2025 correction
  must use the 2025 schijven/kortingen/premiepercentages, not 2026 —
  `RetroAdjustmentService` derives `nl-{year of the original period}` and
  recomputes against that table. **Same-tax-year MVP boundary**: only
  `nl-2026.json` ships today, so a correction whose original period falls in a
  year for which no table exists is **refused** with a clear
  `historical-tables-missing` message rather than recomputed against the wrong
  year. Seeding historical `nl-YYYY.json` corpora is a named follow-up
  (`retro-multi-year-tables`); the recompute code is already year-generic, so
  that follow-up is data, not logic.
- **The delta surfaces in the CURRENT run, never in history.** An `applied`
  adjustment folds its net delta into the current period's `Payslip`
  (`retroAdjustment` component + `nettoPay`) as a nabetaling (positive) or
  terugvordering (negative). A `draft` adjustment is computed-but-unsettled and
  affects no run. Adjustments are idempotent by
  `(originalPeriod, employeeId, correctionRef)` — re-running the same
  correction updates one object and never double-counts.
- **The tax year is period-derived and immutable once stamped.** There is
  deliberately no mutable "active tax year" global: each run derives its table
  from its own period, and a generated run's `engineVersion`/`calculatedAt`
  stamp plus the non-`draft` recompute refusal make that stamp immutable. The
  annual roll is therefore **data-only** — ship `lib/Standards/tables/nl-YYYY.json`
  and runs for `YYYY-MM` periods pick it up automatically;
  `occ hrmq:payroll:year-transition --year YYYY` is the preflight that asserts
  the new table exists and confirms the immutable-stamp guard, changing no
  engine state.
- **Known MVP limitations**: no bijzonder tarief on the nabetaling (a
  nabetaling is often taxed at bijzonder tarief; the MVP settles the delta at
  the tabel result and names bijzonder tarief as a follow-up — it inherits the
  engine's own bijzonder-tarief non-goal); no cumulative/VCR reconciliation
  (the delta is a period-vs-period recompute); no automated loonaangifte
  correctie-berichten (the filing lifecycle's `corrigeren` transition is the
  manual route). The `nl-retro-adjustment-consistency` corpus rule recomputes
  every adjustment's delta during `occ hrmq:rules:audit`, so a tampered delta
  is a mandatory audit violation.

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

## Multi-administratie (accountant multi-client)

hrmq supports multiple administraties (companies/clients) in one instance — the
NL accountant channel where one office runs payroll for many SMBs:

- **Tenant model** — an `Administration` (name, KvK, loonheffingennummer) plus
  an `AdministrationAccess` membership (userId → administratie, role
  accountant/hr/employee). Every core HR/payroll object carries an optional
  plain-string `administrationId` (the `PayrollRun` convention, never a
  `$ref` — there is deliberately no `Administration` `$ref` graph).
- **Active administratie per user** — `GET/POST /api/administration/*` set and
  read a per-user active-administratie pointer, guard-first: the setter refuses
  any administratie the caller has no `AdministrationAccess` row for (unknown or
  inaccessible → 404). The `Configuratie › Administraties` switcher drives it.
- **Consistency** — `nl-administratie-scope-consistency` (recommended severity)
  flags a child object whose `administrationId` disagrees with its parent;
  vacuous when the field is absent, so single-administratie installs are
  unaffected.

> **Scoping is NOT a security boundary.** The active-administratie pointer and
> the per-page filtering it will drive are a *convenience* scoping layer, not an
> isolation guarantee: OpenRegister still serves objects by the app's own RBAC,
> and a user with register access can read across administraties via the API.
> Hard tenant isolation (per-administratie OpenRegister organisation ownership)
> is a named security fast-follow, tracked separately. Do not rely on
> administratie scoping to keep one client's data from another's.

> **Upstream dependency (MVP boundary):** automatic per-page filtering by the
> active administratie needs an `@administration` filter token in the shared
> nextcloud-vue manifest vocabulary (a closed, schema-validated set — it cannot
> be invented app-side without failing `check:manifest`). This build ships the
> full hrmq side (schemas, service, guarded endpoints, switcher UI, consistency
> rule, seeds); wiring `@administration` into `sentinelTokens.js` /
> `resolveFilterTokens.js` + stamping `runtime.user.activeAdministrationId` into
> the served manifest + adding `filter: { administrationId: "@administration?" }`
> to each page is a named nextcloud-vue follow-up. Until it lands, all
> administraties a user can access are shown together (the safe `?`-optional
> default).

## Loonbeslag (wage garnishment)

hrmq models a court/deurwaarder-ordered wage garnishment (loonbeslag) as a
`Loonbeslag` record: a fourth current-run, post-tax component folded into
`Payslip.nettoPay` by `PayrollRunService::generate()` (the exact
sick-pay/retro-adjustment/leave-buy-sell shape — the deduction is computed
entirely against the already-decided net figure, `PayrollCalculator` is never
re-invoked). Be aware of the following:

- **The beslagvrije voet is a stored INPUT, not a computed figure.**
  `Loonbeslag.beslagvrijeVoet` is a required, HR-entered field trusted as the
  authoritative figure the deurwaarder is legally required to state on the
  garnishment order. This build does **not** compute the protected minimum
  from income and household composition per the *Wet vereenvoudiging
  beslagvrije voet* (partner income, co-residents, housing costs,
  health-insurance premium) — that computation is a named fast-follow.
  `beslagvrijeVoet` would simply gain a second, computed source feeding the
  same field the floor formula already reads; the fold mechanics do not
  change.
- **The floor itself IS a hard, machine-checked rule.** The deduction is
  computed as `min(orderedAmount, max(0, nettoPay − beslagvrijeVoet))` — by
  construction it can never push `nettoPay` below the voet — and
  `nl-loonbeslag-beslagvrije-voet-floor` (auto-discovered `CheckProvider`,
  reachable via `occ hrmq:rules:audit`) flags any Payslip where a tampered or
  otherwise inconsistent `nettoPay` falls below its referenced Loonbeslag's
  `beslagvrijeVoet`.
- **Single-active-beslag is the MVP scope**, not a corner case glossed over:
  hrmq selects at most ONE `actief` Loonbeslag per employee per period.
  Priority/preferente-vordering ordering across multiple simultaneous
  garnishments for the same employee (e.g. alimony arriving mid-order on top
  of an existing tax-debt beslag — BW art. 475d governs the ordering) is a
  named fast-follow, not implemented here. The assumption is enforced, not a
  silent doc note: `nl-loonbeslag-single-active` flags any employee with more
  than one `actief` Loonbeslag whose effective ranges overlap. Should the
  selection ever encounter more than one active match despite the check, the
  earliest `effectiveFrom` wins deterministically — never a silent drop, never
  a double deduction.
- **Admin/HR-only, guarded transitions, never a bare lifecycle button.**
  `Loonbeslag.status` carries no `x-openregister-lifecycle` map — activating,
  settling, and withdrawing a garnishment are sensitive, caller-role-gated
  writes (`LoonbeslagController`, the `PayrollController::mutations()`/
  `wkrAssess()` two-gate shape: admin/HR 403 BEFORE any RBAC resolve, then
  RBAC-resolve-first 404). A dedicated Nextcloud "HR" group (vs. reusing the
  admin group) is a shared fast-follow across every admin/HR-gated endpoint in
  this app, not specific to loonbeslag.
