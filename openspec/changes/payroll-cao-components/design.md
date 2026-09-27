# Design: pay the allowances and premiums a collective agreement prescribes

## Context

Read at `development` af702f78.

- `lib/Standards/cao/SCHEMA.md` defines `allowances` as a leaf whose value is
  `{ allowanceKey: {...} }` with no fixed inner shape. The corpus holds, among others:
  `cao-metaal-techniek.json` `ploegentoeslag {pct: 13.3}`, `cao-horeca.json`
  `toeslagOnregelmatigheid {pct: 15}`, `cao-ziekenhuizen.json` `ort {avond, nacht, zaterdag,
  zondagFeestdag}` (line 35), `cao-zorg-vvt.json` `ort {weekdagNacht0006of2224: {pctFrom,
  pctTo, effectiveDate}, ...}` (line 35), `cao-rijk.json` `ikb {...}` (line 43). Most leaves
  are `verified: false, placeholder: true`; `cao-rijk` and the fictional `cao-voorbeeld` are
  verified.
- `lib/Standards/CaoRegistry.php`: `minMaandloonCents()` (line 140), `minLeaveHours()`
  (line 180), `overtimeToeslagPercentages()` (line 223); all resolve to null when a leaf is
  unverified or a placeholder. Nothing reads `allowances`.
- `lib/Standards/Checks/NlCaoChecks.php` runs `nl-cao-minimumloon-schaal` and
  `nl-cao-verlof-minimum`.
- `lib/Service/EmploymentTermsResolver.php`: contract override first (with a mandatory
  reason, and never less favourable than the agreement), CAO second, null otherwise.
- `EmploymentContract` (`hr-objects.json:59`): `cao` (line 80), `caoSchaal` (line 81),
  `hourlyWage`, `hoursPerWeek`.
- `lib/Service/PayrollRunService.php` `generate()`: pre-calculation gross folds sit before
  `new CalculationInput(` (line 492).
- `TimeEntry` (`hr-timesheet.json:5`) carries `date`, `startedAt`, `endedAt`,
  `breakMinutes`, `hours`. The change `time-hours-and-overtime-to-payroll` (this change's
  dependency) makes the run select each employee's approved, unpaid timesheets and stamps the
  run on them; this change reuses that selection.
- `lib/Service/WorkingCalendarReader.php:111` `nonWorkingDates()` gives public holidays.
- `Caos` and `CaoDetail` (`src/manifest.d/hr-cao.json`) show `payScales`, `allowances`,
  `leaveEntitlement` as reference.

## Goals / Non-Goals

**Goals**

- One documented shape for CAO components the run can compute.
- Components resolved per contract, with the override rule that already exists for overtime.
- Premiums per hour worked in a window, from approved hours only.

**Non-Goals**

- Authoring agreements in the app.
- Premiums on planned but unworked shifts.
- Pension premiums.

## Decisions

### D1. Three component kinds, one shape

In `allowances.value` each key maps to one of:

- `{kind: "percentage-of-wage", pct}`: a percentage of the regular gross;
- `{kind: "fixed-monthly", amountCents}`: a fixed amount per month, pro rata for a part month;
- `{kind: "hourly-surcharge", windows: [{days, from, to, pct}], holidayPct}`: a premium on
  the hourly rate for each hour inside a window; `days` are weekday names, `from` and `to` are
  times, and a window may cross midnight.

Every existing entry is rewritten in this shape, keeping its flags and sources.
`CaoRegistry::components(caoId)` returns the map, or null when the leaf is unverified or a
placeholder, the same lever as the other resolvers.

Alternative considered: keep the free-form entries and interpret each key in code. Rejected:
code that knows what `weekdagNacht0006of2224` means is CAO logic in PHP, which the
payroll-core-schema change ruled out.

### D2. A contract names the components that apply

`EmploymentContract.caoComponents` lists component keys from its agreement.
`caoComponentOverrides` may replace a figure (a percentage or an amount) with
`caoComponentOverrideReason` required, and `EmploymentTermsResolver::resolveComponents()`
refuses an override below the agreement's figure, the rule `assertOvertimeNotWorse()` already
applies. The resolver returns each component with its figures and `source` (`cao` or
`contract-override`), or marks it unresolved when the agreement is a placeholder and the
contract has no override.

Alternative considered: apply every component of the agreement to every contract under it.
Rejected: a shift allowance belongs to shift workers, not to everyone under the agreement.

### D3. The run adds components to the gross

`CaoComponentCalculator` is pure. Given the resolved components, the regular gross, the hourly
rate (the same basis as `time-hours-and-overtime-to-payroll`) and the approved time entries
the run selected, it returns lines `{key, kind, basis, hours, pct, amountCents, source}`.
For `hourly-surcharge` it splits each entry's span, minus breaks taken at the end of the span,
over the windows, and uses `holidayPct` for hours on a date in `nonWorkingDates()`. An entry
with hours but no start and end earns no premium and is listed as `no-times`. `generate()`
adds the total to `grossMonthlySalaryCents` before the calculator, so components are taxed and
insured as wage.

Alternative considered: compute the premiums from roster assignments. Rejected: a roster is a
plan; the agreement pays for hours worked.

### D4. Unresolved components are visible

The payslip carries `caoComponentsUnresolved` with each component key the contract names but
that did not resolve, and pays nothing for it. `payroll-run-checks`, when present, turns that
list into warnings. A new check `nl-cao-component-onbekend` flags a contract naming a key its
agreement does not have.

## Declarative-vs-imperative decision (ADR-031)

| behaviour | path | why |
|---|---|---|
| component figures | corpus data, `CaoRegistry` | universal CAO facts live in the corpus |
| per-contract applicability and overrides | contract fields, resolved by `EmploymentTermsResolver` | the existing terms pattern |
| premium hours and amounts | imperative, pure `CaoComponentCalculator` | window arithmetic over entries |
| fold into the run | imperative, `PayrollRunService` | engine input |
| unknown component key | a corpus check in `NlCaoChecks` | machine-checkable rule |

## Seed data

- `cao-voorbeeld` (verified and fictional) gains an `hourly-surcharge` example with a night
  window, so a dev instance can pay one without an unverified agreement.
- One seeded contract under `cao-voorbeeld` names both its components, with two approved night
  entries.

## Risks / Trade-offs

- [Most agreements are placeholders, so most premiums resolve to nothing] -> that is the honest
  state of the corpus; a contract override with a reason pays them meanwhile, and the payslip
  says which are unresolved.
- [Break placement inside a span is unknown] -> the calculator takes breaks at the end of the
  span, which never adds premium hours; the rule is documented on the payslip line.
- [Rewriting the corpus shape] -> every file keeps its figures; a unit test compares old and
  new values key by key.

## Open Questions

- Should a BHV or EHBO allowance follow an active `BhvCertificering` automatically instead of
  the contract's list? This design keeps the contract list.
