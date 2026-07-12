# Design — leave-verzuim-mvp

## Context

`LeaveRequest` (in `lib/Settings/register.d/hr-leave.json`, landed via the `portal-schemas` change) already carries the full declarative approval workflow: `x-openregister-lifecycle` on `status` with `submit` (draft|rejected→submitted), `approve` and `reject` (submitted→approved/rejected), both guarded by `OCA\Hrmq\Lifecycle\NoSelfApprovalGuard`. What is missing is everything around it: the schema has **no UI at all** (no manifest pages, no menu entry, no deepLink), there is no leave-balance object, and sickness is not modeled anywhere — no case object, no Wet-verbetering-poortwachter milestones, no loondoorbetaling tracking.

Market grounding (verified 2026-07-12, Spectr `hrmq-insight-nc-ecosystem-gap`, `hrmq-insight-ranked-buildlist`): leave management is a core module in every NL competitor and in Krip (the only generic Nextcloud HR app), but nothing in the Nextcloud ecosystem has Dutch verzuim/WVP depth. Statutory anchors: BW art. 7:634 (minimum entitlement 4× contractual weekly hours), BW art. 7:640a (statutory days lapse 1 July of the following year), BW art. 7:629 (70% loondoorbetaling, max 104 weeks; lid 10: interruptions < 4 weeks count as one period — samengesteld ziektegeval), Regeling procesgang eerste en tweede ziektejaar (probleemanalyse week 6, plan van aanpak week 8, eerstejaarsevaluatie week 52), ZW art. 38 (42-weken ziekmelding at UWV).

Verified at HEAD before designing:
- OpenRegister supports `x-openregister-calculations` (read from the schema `configuration`, same home as `x-openregister-lifecycle`; operator vocabulary includes `prop`/`+`/`-`; `materialise: true` persists the value) — see `openregister/lib/Service/Calculation/CalculationAnnotationValidator.php` and the usage in `openregister/lib/Settings/data_subject_request_register.json` (`daysRemaining`).
- `RuleCatalogue` glob-loads `lib/Standards/rules/*.json`, so a new corpus file needs no registration; `RuleCatalogue::VERSION` must bump on any corpus change (SCHEMA.md).
- `RuleEngine` predicates are strictly single-object: `fn(array $object, array $context): bool` with `$context = {jurisdiction}` only — there is no cross-object lookup facility.

## Goals / Non-Goals

**Goals:** ship the ADR-001 menu-5 surface (Verlof & verzuim) with working leave pages on the existing lifecycle; leave balances with a declaratively calculated remaining-hours figure; an administrative (never medical) sickness case with the WVP milestone clock; six versioned machine-checkable NL labour rules with check providers; seed data that exercises both alert branches.

**Non-Goals:** automatic accrual and auto-posting of approved hours to balances (follow-up; needs payroll periods / cross-object write hooks), CAO-specific bovenwettelijk rules, rostering, UWV/arbodienst wire integration, WIA/tweede-spoor flows, any medical or diagnosis data.

## Decisions

### D1 — Leave pages drive the EXISTING lifecycle; nothing on LeaveRequest changes

The `LeaveRequest` schema, its lifecycle and the `NoSelfApprovalGuard` are consumed as-is (they are `portal-schemas` deliverables). The manifest's `lifecycleActions` on `LeaveRequestDetail` mirror the declared transitions exactly — `submit` (from draft|rejected), `approve`, `reject` (from submitted) — structured identically to `TimesheetDetail`'s lifecycleActions. `LeaveApproval` is a pre-filtered index (`defaultFilters: {status: "submitted"}`, sort `submittedAt` asc), copying the proven `TimesheetApproval` pattern verbatim. Inventing extra edges (e.g. a cancel/withdraw action) is deliberately avoided: the manifest must never claim transitions the backend does not guard (see the `PayrollRunDetail` `_note` precedent).

### D2 — `remainingHours` is a declarative calculation, not a stored field

`LeaveBalance` declares, in its `configuration`:

```json
"x-openregister-calculations": {
    "remainingHours": {
        "type": "number",
        "materialise": true,
        "title": "Remaining hours",
        "description": "entitledHours + bovenwettelijkHours − usedHours, evaluated by OpenRegister on save.",
        "expression": { "-": [ { "+": [ { "prop": "entitledHours" }, { "prop": "bovenwettelijkHours" } ] }, { "prop": "usedHours" } ] }
    }
}
```

Verified supported (CalculationOnSaveListener + validator in openregister at HEAD; annotation lives in `configuration`, exactly where hrmq fragments already put `x-openregister-lifecycle`; a malformed annotation degrades to a logged warning, it does not abort import). `materialise: true` persists the value so index columns can list it. `remainingHours` is NOT declared under `properties` — the validator resolves calculation names against properties *or* sibling calculations, and a same-named stored property would shadow the calculation. Trade-off: if a consumer writes `usedHours` outside OpenRegister (raw SQL), the materialised value goes stale until the next save — acceptable; all app writes go through the objects API, and `occ openregister:calculations:rematerialise` exists as a repair path.

### D3 — The 4×-weekly-hours minimum check reads a snapshot field (single-object RuleEngine)

BW 7:634 says the yearly entitlement is at least 4× the contractual weekly working time — a cross-object fact (`LeaveBalance` vs `EmploymentContract.hoursPerWeek`). RuleEngine predicates are single-object with `context = {jurisdiction}` only (verified at HEAD), and every existing check in the corpus follows the denormalised-compliance-fields pattern (Payslip carries its rates, PayrollRun carries its GL totals). So `LeaveBalance` carries `contractHoursPerWeek` (number, nullable) — a snapshot of the employee's contractual weekly hours at grant time, documented as such — and `nl-verlof-wettelijk-minimum` evaluates `entitledHours ≥ 4 × contractHoursPerWeek` when the snapshot is present, passing vacuously when it is null (not decidable from the object; flagging honestly per SCHEMA.md's machineCheckable discipline). The snapshot is also *correct* domain-wise: the entitlement of a year is earned against the hours contracted in that year, not against whatever the contract says at audit time.

### D4 — WVP milestone Due dates are derived, stored, and rule-checked (loonaangifte precedent)

The four Due dates are pure functions of `firstSickDay` (+6/8/42/52 weeks), yet they are stored fields — the exact decision `loonaangifte-filing-lifecycle` made for `deadline`, for the same two reasons: (a) **audit trail** — the dossier must show which clock the employer was actually working against, and (b) **editability** — UWV can grant deferral (uitstel) for the probleemanalyse/plan van aanpak in exceptional cases, and the stored date then legitimately diverges. Derivation correctness is enforced by `nl-wvp-milestone-derivation` (a deviating date is a *violation to be explained*, not silently recomputed away). On an **open** case, a null Due is itself a violation — the clock starts on day one of sickness; on a `hersteld` case null Due fields are fine (a four-day flu never reaches week 6).

### D5 — New corpus file `labour.json`, `domain: labour` (Declarative-vs-imperative decision, ADR-031)

`lib/Standards/rules/` holds only `payroll.json` today (rule domains `tax` / `reporting` / `ledger-integrity`). SCHEMA.md prescribes one JSON file per sub-domain, and leave/verzuim are labour law, not tax — so the six new rules go in a **new** `lib/Standards/rules/labour.json` (`{"domain": "labour", "version": "2026-07", "rules": [...]}`), all six rules `domain: labour`, `jurisdiction: NL`. Frameworks: `bw7-10` for the BW-book-7-title-10 rules (SCHEMA.md's own example slug), `nl-poortwachter` for the WVP procesgang/ZW rules. `RuleCatalogue` picks the file up via glob; `RuleCatalogue::VERSION` bumps to `2026-07`.

| behaviour | path | rationale |
|---|---|---|
| LeaveRequest approval workflow | **declarative** `x-openregister-lifecycle` (already shipped) | ADR-031 default; this change only renders it |
| SickLeaveCase state machine (gemeld⇄hersteld) | **declarative** `x-openregister-lifecycle` on the new schema | ADR-031 default; `recoveredDate` stamped/cleared on the carrying write (Timesheet `approvedAt` pattern) |
| `remainingHours` | **declarative** `x-openregister-calculations` | supported by OpenRegister at HEAD (D2) |
| Statutory minimum / saldo / vervaltermijn / milestone derivation / overdue / loondoorbetaling | imperative **CheckProvider** predicates (`NlLeaveChecks`, `NlVerzuimChecks`) | domain-rule evaluation over the versioned corpus — the established ADR-031 exception this app already uses for all 89 payroll rules; violations surface via `occ hrmq:rules:audit` |
| Overdue notifications | **neither** — deliberately deferred | no new notification channel; x-openregister-notifications adoption is app-wide work (ADR-031/gate-18), same deferral as the loonaangifte change |
| Guard wiring of rule predicates into transitions | out of scope | owned by the active `hrmq-rule-compliance-enforcement` change |

### D6 — SickLeaveCase is administrative-only; privacy is a REQ, not a comment

Under the AVG and the Autoriteit Persoonsgegevens beleidsregels "De zieke werknemer", an employer may record that and how long an employee is sick and the process facts around re-integration — never the nature/cause of the illness. The schema therefore has **no diagnosis, symptom, cause, or medical-note field**, its description says so explicitly, and REQ-VWP-002 pins this with a scenario that fails if such a field ever appears. The Files widget on the detail page is labeled for gespreksverslagen/plan-van-aanpak documents (process documents an employer may hold), and its description repeats the warning.

### D7 — Menu placement is the frozen ADR-001 entry

`Verlof & verzuim` (icon `CalendarClock`, matching the manifest's PascalCase icon convention) is ADR-001 menu 5 — a frozen top-level entry, so adding it needs no ADR amendment. It gets `order: 105` (between Uren 100 and Onkosten 110) as a provisional slot; final ordering of all eight ADR-001 menus is owned by the active `hrmq-ia-navigation-alignment` change and is deliberately not solved here. The index pages omit a computed "next due milestone" column on `SickLeaveCases` — index columns are plain schema fields and no single stored field carries that aggregate, so per the decision rule it is omitted rather than faked.

## Schema deltas

**`LeaveBalance` (new, in `hr-leave.json`, version 0.1.0):** `employeeId` (string, format uuid, `$ref` Employee, required), `year` (integer, required), `leaveType` (enum, the exact `LeaveRequest.leaveType` value set: holiday/sick/unpaid/special/care/parental), `entitledHours` (number — statutory minimum 4× contractual weekly hours, BW 7:634, documented on the property), `bovenwettelijkHours` (number, default 0), `usedHours` (number, default 0), `contractHoursPerWeek` (number, nullable — D3 snapshot), `expiryDate` (string, format date, nullable — statutory hours lapse 1 July of the following year, BW 7:640a, documented), plus the D2 calculation. Required: `employeeId`, `year`, `leaveType`, `entitledHours`. Gate-28: title + description on every property. `LeaveRequest` untouched at 0.1.0; register `info.version` 0.2.0 → 0.3.0.

**`SickLeaveCase` (new fragment `hr-verzuim.json`, version 0.1.0):** `employeeId` (string, format uuid, `$ref` Employee, required), `firstSickDay` (date, required), `recoveredDate` (date, nullable), `status` (enum gemeld/hersteld, default gemeld), `wachtdag` (boolean, default false — first sick day may be an unpaid waiting day where the CAO/contract provides), `loondoorbetalingPercentage` (number, default 70 — statutory minimum 70% for max 104 weeks per BW 7:629; description documents the first-year minimum-wage floor), and the four milestone pairs (all date, nullable): `probleemanalyseDue`/`probleemanalyseDone` (week 6), `planVanAanpakDue`/`planVanAanpakDone` (week 8), `uwv42WeekMeldingDue`/`uwv42WeekMeldingDone` (week 42, ZW art. 38), `eerstejaarsevaluatieDue`/`eerstejaarsevaluatieDone` (week 52). Lifecycle in `configuration`: `field: status`, `initial: gemeld`, transitions `herstellen` (gemeld→hersteld; description: `recoveredDate` stamped on the carrying write) and `heropenen` (hersteld→gemeld; description: relapse within 4 weeks continues the same case — samengesteld ziektegeval, BW 7:629 lid 10 — and the carrying write clears `recoveredDate`). Required: `employeeId`, `firstSickDay`, `status`. No medical fields (D6).

## New corpus rules (labour.json)

| id | framework | source | statement (short) | severity | machineCheckable |
|---|---|---|---|---|---|
| `nl-verlof-wettelijk-minimum` | `bw7-10` | BW art. 7:634 | Yearly statutory leave entitlement is at least 4× the contractual weekly working time (`entitledHours ≥ 4 × contractHoursPerWeek`) | mandatory | true |
| `nl-verlof-saldo-niet-negatief` | `bw7-10` | BW art. 7:634 jo. 7:638 | Recorded leave taken must not exceed the total entitlement (`usedHours ≤ entitledHours + bovenwettelijkHours`) — approved leave may never push a balance negative | mandatory | true |
| `nl-verlof-vervaltermijn` | `bw7-10` | BW art. 7:640a | Statutory leave hours of a year lapse on 1 July of the following year; a balance with statutory hours carries `expiryDate = <year+1>-07-01` | mandatory | true |
| `nl-wvp-milestone-derivation` | `nl-poortwachter` | Regeling procesgang art. 2 en 4; ZW art. 38 (42-wekenmelding) | The WVP milestone Due dates equal `firstSickDay` + 6/8/42/52 weeks (probleemanalyse / plan van aanpak / UWV 42-wekenmelding / eerstejaarsevaluatie); on an open case none may be null | mandatory | true |
| `nl-wvp-milestone-overdue` | `nl-poortwachter` | Regeling procesgang art. 2 en 4; ZW art. 38 | An open case with a milestone Due in the past and no matching Done date is in violation (mandatory); a Due within 14 days is advisory | mandatory | true |
| `nl-loondoorbetaling-minimum` | `bw7-10` | BW art. 7:629 lid 1 | During sickness the employer continues at least 70% of wages (first 104 weeks); an open case must carry `loondoorbetalingPercentage ≥ 70` | mandatory | true |

sourceUrls: BW rules → `https://wetten.overheid.nl/BWBR0005290`; WVP rules → `https://wetten.overheid.nl/BWBR0013540` (Regeling procesgang; the ZW art. 38 anchor `https://wetten.overheid.nl/BWBR0002598` is cited in the `source` string of the milestone rules). All: `domain: labour`, `jurisdiction: NL`, `effectiveDate: null` (long-standing law; the 7:640a vervaltermijn dates from 2012-01-01 — set that one's `effectiveDate` accordingly).

Checks: `NlLeaveChecks` registers the three `LeaveBalance` predicates; `NlVerzuimChecks` registers the three `SickLeaveCase` predicates (overdue/approaching evaluated against the audit run date, like `nl-loonaangifte-deadline-alert`). The 6/8/42/52-week offsets live in the derivation rule's `parameters` (`milestoneWeeks`) and the check reads them from the rule data, not from PHP constants — the same data-over-code convention as the tijdvakcode tables.

## Manifest delta

- **deepLinks**: `LeaveRequest` → `/apps/hrmq/leave-requests/{uuid}`, `LeaveBalance` → `/apps/hrmq/leave-balances/{uuid}`, `SickLeaveCase` → `/apps/hrmq/sick-leave/{uuid}`.
- **Menu group** `VerlofVerzuimGroup` ("Verlof & verzuim", `CalendarClock`, order 105) with children: `LeaveRequests` ("Verlofaanvragen", `CalendarClock`), `LeaveApproval` ("Verlofgoedkeuring", `CheckDecagramOutline`), `LeaveBalances` ("Verlofsaldi", `ScaleBalance`), `SickLeaveCases` ("Ziekmeldingen", `EmoticonSickOutline`).
- **`LeaveRequests`** (index): columns `employeeId`, `leaveType`, `startDate`, `endDate`, `hours`, `status`; filters `status`, `leaveType`; sort `startDate` desc.
- **`LeaveApproval`** (index): `defaultFilters: {status: "submitted"}`, columns `employeeId`, `leaveType`, `startDate`, `endDate`, `submittedAt`, `status`; sort `submittedAt` asc — mirroring `TimesheetApproval` exactly.
- **`LeaveRequestDetail`** (detail): data widget "Request" (exclude `employeeId` — Related resolves the Employee), data widget "Approval" (status/submittedAt/approvedBy/approvedAt/rejectionReason), related, files ("Supporting documents" — e.g. a bijzonder-verlof invitation), lifecycleActions submit/approve/reject per D1, audit-history sidebar tab.
- **`LeaveBalances`** (index): columns `employeeId`, `year`, `leaveType`, `entitledHours`, `bovenwettelijkHours`, `usedHours`, `remainingHours` (materialised calculation), `expiryDate`; filters `year`, `leaveType`; sort `year` desc.
- **`SickLeaveCases`** (index): columns `employeeId`, `firstSickDay`, `recoveredDate`, `status`; filters `status`; sort `firstSickDay` desc. No computed next-milestone column (D7).
- **`SickLeaveCaseDetail`** (detail): data widget "Case" (firstSickDay/recoveredDate/status/wachtdag/loondoorbetalingPercentage, exclude `employeeId`), data widget "Poortwachter milestones" (the eight Due/Done fields), related, files ("Gespreksverslagen & plan van aanpak" — the `_note` and widget description repeat the no-medical-data warning), lifecycleActions Herstellen/Heropenen, audit-history sidebar tab.
- All six pages validate against app-manifest-v2 (`npm run check:manifest`).

## Seed Data (ADR-001)

Extend `lib/Settings/register.d/hr-seed.json` (placeholders only, obvious slugs, matching the existing seed employees):

**LeaveBalance (3):**
1. `leavebalance-jansen-2026-holiday` — employee-jansen, 2026, holiday, contractHoursPerWeek 40, entitledHours 160, bovenwettelijk 40, used 56, expiryDate `2027-07-01` (compliant; remainingHours materialises to 144).
2. `leavebalance-devries-2026-holiday` — employee-devries, 2026, holiday, contractHoursPerWeek 32, entitledHours 128, bovenwettelijk 0, used 140, expiryDate `2027-07-01` (**used > total → exercises `nl-verlof-saldo-niet-negatief`**).
3. `leavebalance-bakker-2026-holiday` — employee-bakker, 2026, holiday, contractHoursPerWeek 36, entitledHours 120, bovenwettelijk 0, used 16, expiryDate `2027-07-01` (**entitled < 4×36=144 → exercises `nl-verlof-wettelijk-minimum`**).

**SickLeaveCase (3):**
1. `sickcase-jansen-flu` — employee-jansen, firstSickDay `2026-05-04`, recoveredDate `2026-05-08`, status `hersteld`, wachtdag true, all milestone fields null (recovered before any clock mattered; derivation check passes — nulls allowed on hersteld).
2. `sickcase-devries-week7` — employee-devries, firstSickDay `2026-05-25`, status `gemeld`, loondoorbetalingPercentage 70, Due dates per derivation: probleemanalyse `2026-07-06`, planVanAanpak `2026-07-20`, uwv42WeekMelding `2027-03-15`, eerstejaarsevaluatie `2027-05-24`; no Done dates (**probleemanalyse overdue → mandatory `nl-wvp-milestone-overdue` violation; plan van aanpak within 14 days → advisory**, relative to the reference audit date 2026-07-12).
3. `sickcase-bakker-longterm` — employee-bakker, firstSickDay `2025-09-29` (~week 41), status `gemeld`, loondoorbetalingPercentage 70, Due: probleemanalyse `2025-11-10` (Done `2025-11-06`), planVanAanpak `2025-11-24` (Done `2025-11-20`), uwv42WeekMelding `2026-07-20` (no Done — **approaching → advisory**), eerstejaarsevaluatie `2026-09-28`.

All Due values are exact `firstSickDay + 6/8/42/52 weeks` derivations so `nl-wvp-milestone-derivation` reports zero violations on the seeds; the overdue check produces exactly the intended mandatory+advisory set.

## Risks / Trade-offs

- **Seed violation dates age**: the week-7/week-41 story is told relative to 2026-07-12; months from now the seeds simply show *more* overdue milestones, which still exercises the check (violations grow, never vanish). Acceptable for placeholder data.
- **Calculated `remainingHours` staleness** on out-of-band writes — mitigated by the objects-API-only write path and the rematerialise command (D2).
- **Snapshot drift**: `contractHoursPerWeek` can diverge from the live contract after a contract change; that is by design (D3) — the balance was granted under the old contract — but the description must say so or auditors will report false positives.
- **Fragment objects go LIVE on import** (portal-schemas flagged this): the seeds are intentionally obvious placeholders (slug-style employee refs, fictional names), consistent with the existing hr-seed.json content and the loonaangifte seed precedent.

## Open Questions

- None blocking. Automatic accrual + auto-posting of approved leave hours to balances is the declared follow-up; UWV transport (Digipoort verzuimmelding) tracks with the wider Digipoort decision in Spectr (`hrmq-insight-digipoort`).
