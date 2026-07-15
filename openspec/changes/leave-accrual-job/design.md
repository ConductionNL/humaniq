# Design — leave-accrual-job

## Context

**Verified against HEAD 2026-07-15.** The leave-verzuim MVP (PR #22) already shipped everything
this change consumes; nothing here depends on an unmerged change.

- **`LeaveBalance` schema** (`lib/Settings/register.d/hr-leave.json`, slug `LeaveBalance`, register
  `hrmq`): `employeeId` (`$ref` Employee, uuid), `year` (int), `leaveType`
  (enum `holiday|sick|unpaid|special|care|parental`), `entitledHours` (number, required),
  `bovenwettelijkHours` (number, default 0), `usedHours` (number, default 0),
  `contractHoursPerWeek` (number, nullable — the denormalised snapshot the single-object
  RuleEngine predicates read), `expiryDate` (date, nullable). It also carries a declarative
  `x-openregister-calculations.remainingHours` (`entitledHours + bovenwettelijkHours − usedHours`,
  `materialise: true`) evaluated by OpenRegister on save — read but **not** modified by this change.
- **The verlof rules** (`lib/Standards/rules/labour.json`, all `severity: mandatory`,
  `machineCheckable: true`):
  - `nl-verlof-wettelijk-minimum` — `entitledHours >= 4 × contractHoursPerWeek` (BW 7:634).
  - `nl-verlof-saldo-niet-negatief` — `usedHours <= entitledHours + bovenwettelijkHours`.
  - `nl-verlof-vervaltermijn` — a balance holding statutory hours records
    `expiryDate = <year+1>-07-01` (BW 7:640a).
  Keeping all three green after the job runs is a hard constraint (D2/D3).
- **Write idiom** — `PayrollRunService` (read at HEAD): container-resolved
  `OCA\OpenRegister\Service\ObjectService` (`$this->container->get('OCA\OpenRegister\Service\ObjectService')`),
  register slug from `SettingsService::getRegisterSlug()`, `->setRegister($reg)->setSchema($schema)
  ->findAll(['limit' => …])` to load, `->saveObject(object: …, register: …, schema: …)` to write,
  never-throw degradation with a logged warning. Active-employee selection is `loadAll('Employee')`
  filtered by `coversPeriod($startDate, $endDate, $period)`; the covering `EmploymentContract` is
  resolved by `coveringContract($employee, …, $period)`. `EmploymentContract.hoursPerWeek`
  (`lib/Settings/register.d/hr-objects.json`) is the contractual weekly-hours source.
- **TimedJob registration** — the app currently registers occ `<commands>` and a
  `<repair-steps>` block in `appinfo/info.xml` but has **no** `<background-jobs>` block and no
  `lib/BackgroundJob/`. Nextcloud enrolls a `<background-jobs><job>FQCN</job></background-jobs>`
  entry into the job list on app enable; the FQCN is an `OCP\BackgroundJob\TimedJob`.

## Goals / Non-Goals

**Goals:** automatic, idempotent monthly build-up of the current-year holiday `LeaveBalance` for
every active NL employee — statutory (BW 7:634) provisioned so the mandatory minimum holds,
bovenwettelijk accrued per period — written through ObjectService, never double-accruing a month,
never regressing an existing mandatory leave rule.

**Non-Goals (from the proposal, binding):** auto-posting `usedHours` from LeaveRequests (separate
follow-up), pro-rata statutory for mid-year entry/exit, CAO-derived bovenwettelijk, non-holiday
leave types, non-NL jurisdictions, any manifest/UI surface (this change ships no page changes).

## Decisions

### D1 — A scheduled cross-object stateful write is imperative: a TimedJob, not a declarative calc

`LeaveBalance` already demonstrates the declarative path — `remainingHours` recomputes on every
save as a pure function of the row's own stored fields. Accrual is categorically different and
cannot be expressed that way: it (a) must fire on a **schedule** with no triggering write, (b)
**enumerates across all Employees** and resolves each one's contract (cross-object), (c) **creates
new** `LeaveBalance` rows that do not yet exist, (d) is **stateful/incremental** — it *adds* to
`bovenwettelijkHours`, whereas a materialised calc is idempotent-by-recompute and would yield the
same value on every save rather than accumulating, and (e) needs **period-idempotency state**
(`lastAccruedPeriod`). None of (a)–(e) is in the declarative calculation engine's vocabulary. So
the producer is an `OCP\BackgroundJob\TimedJob` (imperative), while the balance's *derived* field
stays declarative — the ADR-031 division of labour, mirroring how `PayrollRunService` (imperative)
generates rows whose intra-row totals are declarative.

### D2 — Statutory is provisioned at the FULL annual figure to keep the mandatory minimum green

`nl-verlof-wettelijk-minimum` (mandatory) asserts `entitledHours >= 4 × contractHoursPerWeek` as a
full-year invariant — it does not itself pro-rate. If the job built `entitledHours` up from zero in
twelve monthly slices, every balance would **violate a currently-green mandatory rule** from
January through November. Therefore, on the year's **first** accrual for an employee the job
provisions `entitledHours = 4 × hoursPerWeek` in full (the statutory entitlement vests for the
year), snapshots `contractHoursPerWeek = hoursPerWeek`, and sets `expiryDate = <year+1>-07-01`
(satisfying `nl-verlof-vervaltermijn`). "The increment computed from contractual hours" (BW 7:634)
is exactly this `4 × hoursPerWeek` figure. Over-granting a mid-year joiner (vs strict pro-rata) is
the deliberate trade for keeping the mandatory rule honest; pro-rata is a named fast-follow (it
would require the minimum rule to become entry-date-aware — `hrmq-rule-compliance-enforcement`'s
scope).

### D3 — Bovenwettelijk is the genuinely periodic part: 1/12 per accrued month

Above-statutory hours accrue per period worked. Each accrued month adds
`Δb = round1(annualBovenwettelijk / 12)` to `bovenwettelijkHours`, where `annualBovenwettelijk`
comes from the new `SettingsService::getLeaveBovenwettelijkAnnualHours()` (default `0`). After
twelve accrued months `bovenwettelijkHours ≈ annualBovenwettelijk`. Because `usedHours` starts at
0 and the job only ever grows `entitledHours + bovenwettelijkHours`,
`nl-verlof-saldo-niet-negatief` (`usedHours <= entitledHours + bovenwettelijkHours`) can never be
pushed negative by accrual. `round1` (one decimal) keeps the hours figure clean; the residual from
integer-twelfths is immaterial and self-corrects at the statutory full-grant.

### D4 — Active-employee + covering-contract selection mirrors PayrollRunService

Per run, for `currentPeriod = now → YYYY-MM` and `year = YYYY`:
`loadAll('Employee')`, keep those with `coversPeriod(startDate, endDate, currentPeriod)` true and
an NL/computable identity; resolve `coveringContract(...)` and read its `hoursPerWeek`. An employee
with no covering contract, no `hoursPerWeek`, or no resolvable identity is **skipped** with a
counted reason in the job's log summary (the `PayrollRunService` skip-reporting precedent) — never
computed wrong, never silently dropped. Only `leaveType: holiday` balances are touched.

### D5 — Idempotency: `lastAccruedPeriod` keyed by (employee, year, month); the ONE schema delta

The job needs persisted per-balance state to know a month was already accrued; nothing on the row
carries it today. This change adds one additive **nullable** `lastAccruedPeriod` (`YYYY-MM`)
property to `LeaveBalance` — single-purpose, no effect on existing rows (null on all seeded/legacy
balances), the `remainingHours` calc untouched. Algorithm per resolved employee:

1. Find the `(employeeId, year, leaveType: holiday)` balance (ObjectService filter; the
   probe-before-write pattern).
2. **Absent** → create it: `entitledHours = 4 × hoursPerWeek`, `bovenwettelijkHours = Δb`,
   `usedHours = 0`, `contractHoursPerWeek = hoursPerWeek`, `expiryDate = <year+1>-07-01`,
   `lastAccruedPeriod = currentPeriod`.
3. **Present and `lastAccruedPeriod === currentPeriod`** → **no-op** (already accrued this month).
4. **Present and `lastAccruedPeriod !== currentPeriod`** → `bovenwettelijkHours += Δb`,
   set `lastAccruedPeriod = currentPeriod` (statutory already fully granted for the year in step 2;
   a mid-year contract-hours increase can additionally raise `entitledHours`/`contractHoursPerWeek`
   to the new `4 × hoursPerWeek` if higher, never lowering — keeps the minimum green).

Because the guard is per-`(balance, month)`, the TimedJob's actual fire cadence is irrelevant to
correctness: it may run daily and still accrue each employee-month exactly once. `run($argument)`
therefore sets a modest interval (≈ 1 day) and relies on `lastAccruedPeriod` for the "monthly"
semantics, rather than trusting a fragile 30-day interval to align with calendar months.

### D6 — Registration + the enabled toggle

`appinfo/info.xml` gains
`<background-jobs><job>OCA\Hrmq\BackgroundJob\LeaveAccrualJob</job></background-jobs>`; Nextcloud
enrolls it on app enable (the alternative — `registerBackgroundJob` from the bootstrap `register()`
— is equivalent and noted as the fallback). `LeaveAccrualJob extends TimedJob`, constructor
`setInterval(...)` + `setTimeSensitivity(IJob::TIME_INSENSITIVE)`. `run()` first reads
`SettingsService::isLeaveAccrualEnabled()` (config `leave_accrual_enabled`, default `true`) and
returns immediately when disabled — an operator off-switch with zero writes. All monetary/none —
hours are plain numbers (the schema field type), no cents conversion needed.

### Declarative vs imperative (ADR-031)

| Behaviour | Path | Rationale |
|---|---|---|
| Remaining balance (`entitledHours + bovenwettelijkHours − usedHours`) | **declarative** `x-openregister-calculations.remainingHours` | pure function of the row's own stored fields, recomputed on save — the ADR-031 default; unchanged by this change |
| Periodic accrual write | **imperative** `TimedJob` (`lib/BackgroundJob/`) | a scheduled, cross-object (Employee→Contract→Balance), stateful/incremental, row-creating, period-idempotent WRITE — none of which a save-time declarative calc can express (D1) |
| Active-employee + covering-contract selection | imperative service idiom | cross-object enumeration with skip-reporting (PayrollRunService precedent) |
| Persistence | imperative via ObjectService | create/upsert `LeaveBalance` under register `hrmq` |
| Statutory minimum / vervaltermijn / niet-negatief invariants | declarative rule corpus (`labour.json`) | the app's established check layer; the job is written to keep all three green (D2/D3) |

## Seed Data (ADR-001)

No new seed objects. The hand-entered seeded `LeaveBalance` rows (`hr-seed.json`) keep
`lastAccruedPeriod = null` and are simply eligible for the job's next monthly top-up; they remain
valid under every rule. The dev-container gate exercises the real path instead of seeding output:
enable the app (which registers the job), confirm `occ background-job:list` lists
`OCA\Hrmq\BackgroundJob\LeaveAccrualJob`, run it once with `occ background-job:execute <id>`
(or `occ background-job:worker`), then read the seeded 40h/week employee's holiday `LeaveBalance`
and assert `entitledHours = 160`, `contractHoursPerWeek = 40`, `expiryDate = <year+1>-07-01`,
`lastAccruedPeriod = <currentPeriod>`; a second immediate execute must leave every figure
unchanged. `occ hrmq:rules:audit` must still report zero mandatory verlof violations afterwards.

## Risks / Trade-offs

- **Over-grant vs pro-rata (D2).** A mid-year joiner receives the full annual statutory grant
  rather than a date-proportional slice. Chosen deliberately to keep the mandatory minimum green;
  the honest correction (pro-rata + entry-date-aware rule) is a named fast-follow.
- **Config bovenwettelijk is coarse.** One instance-wide `annualBovenwettelijk` default until the
  CAO-derived path lands; a wrong value affects only the above-statutory portion, never the
  statutory minimum and never `usedHours`.
- **Interval vs calendar month.** Solved by the `lastAccruedPeriod` guard (D5): correctness is
  independent of how often the TimedJob fires; the guard, not the interval, defines "once a month."
- **Contract-hours changes.** The job raises `entitledHours`/`contractHoursPerWeek` to a higher
  `4 × hoursPerWeek` but never lowers an already-granted year — avoids retroactively breaching the
  minimum while not clawing back vested statutory leave.

## Open Questions

- None blocking. Pro-rata statutory, CAO-derived bovenwettelijk, and `usedHours` auto-posting from
  approved LeaveRequests are named fast-follows, each a separate small change.
