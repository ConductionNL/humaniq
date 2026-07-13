# Design — payroll-core-engine

## Context

**Verified against HEAD 2026-07-13.** Consumes `payroll-core-schema` (merged first, ADR-032):
`lib/Standards/tables/nl-2026.json` (verified 2026 parameters, design.md D3 of the chain head is
the normative record), `Employee.loonheffingskortingToegepast`, `PayrollRun.calculatedAt` +
`engineVersion`, `Payslip.arbeidskorting` + `payrollRunId`, and the corpus rules
`nl-engine-table-version` / `nl-engine-output-consistency` (machine-checkable, unenforced until
this change registers their predicates).

Existing patterns this change mirrors (all read at HEAD):

- **Service shape**: `PayrollNetPayService` / `PayrollGLPostService` — container-resolved
  ObjectService (`OCA\OpenRegister\Service\ObjectService`), `setRegister/setSchema`-style access
  via `find`/`saveObject`, idempotency probe before create, occ command as trigger, outcome
  arrays, never-throw degradation.
- **Endpoint guard**: `DocumentController::generate` — `#[NoAdminRequired]` + resolve the posted
  object id through ObjectService under the caller's ambient RBAC BEFORE any work; unknown and
  unauthorized collapse to the same 404.
- **Check provider**: `NlGlPostChecks` + `RuleAuditService` context enrichment for cross-object
  predicates (`fn(array $o, array $context): bool`).
- **allowCreate verification** (read the openregister checkout read-only, as briefed):
  `grep -r allowCreate openregister/lib/` → **0 hits**; the only occurrences in the hrmq stack are
  manifest object-list widget props consumed by `@conduction/nextcloud-vue`. Conclusion:
  `allowCreate: false` hides the UI create affordance only; the OpenRegister API enforces schema
  validation + RBAC, nothing else. A service-side Payslip write is therefore legitimate and keeps
  the intent ("payroll-generated, not hand-created") — the UI stays create-less.
- **Manifest capability** (read fresh from the vendored
  `@conduction/nextcloud-vue/src/schemas/app-manifest-v2.schema.json`): the `page` definition
  carries `actions` for every page type, and the `action` definition supports `open-form`
  (schema-driven create dialog with register/schema + onSuccessRoute) and `api-call`
  (token-interpolated POST with params/confirm/toasts/auto-refresh). So "Loonrun aanmaken" is an
  index-page `open-form` action (NOT occ-only), and "(Her)berekenen" is a detail-page `api-call`
  action. The `lifecycleActions` **widget** is not used: it renders `x-openregister-lifecycle`
  transitions and PayrollRun deliberately has none (`PayrollRunDetail`'s `_note`: never invent a
  transition the backend does not guard).

## Goals / Non-Goals

**Goals:** deterministic, table-driven, integer-cents NL gross-to-net per the Rekenvoorschriften
2026 formula chain; draft-run generation with idempotent recalculation; the corpus as the engine's
self-check (`hrmq:payroll:verify`); golden tests self-consistent with the tables + slots for
official test cases; honest non-certification disclaimer.

**Non-Goals (from the proposal, binding):** hourly/Timesheet path (fast-follow), anoniementarief
computation (skip-with-warning), CAO logic, bijzonder tarief, 30%-ruling netto-operation, pension
premie, Zvw-inhouding mode, filing-message generation, PayrollRun lifecycle/guards (owned by
`hrmq-rule-compliance-enforcement`), VCR (voortschrijdend cumulatief rekenen — period-capped
premiums only, documented limitation in the disclaimer).

## Decisions

### D1 — The calculator is a pure function of (input, tables); all money is integer cents

`PayrollCalculator` has zero Nextcloud dependencies: no container, no ObjectService, no clock —
`calculate(CalculationInput $in, TaxTables $t): CalculationResult`. Input carries the employee
fields (gross monthly salary in cents, taxTableColor, dateOfBirth, loonheffingskortingToegepast),
contract fields (awfTariff), period, and the employer-level settings (aofTariff, whkPercentage).
Every intermediate is integer cents (or exact integer-scaled percentages); divisions round
per the Rekenvoorschriften rule for that step (D2), never with floats accumulating error. This is
what makes the golden tests byte-stable and the annual re-issue a data-only change.

### D2 — The exact equation chain (Rekenvoorschriften 2026 §2.1–2.2.4, witte maandtabel path)

For a monthly wage `tvl` (cents), tables `t` (= nl-2026), below-AOW, wit, korting applied:

1. **Bruto**: `tvl = grossMonthlySalary` (MVP path; period = maand, F = 12 from
   `t.loonheffing.tijdvakFactoren`).
2. **Vakantiegeldreservering**: `vakantiegeldReserved = round2(tvl × 8,0%)`;
   `vakantiegeldRate = 8.0` (reservation only — payout at bijzonder tarief is a non-goal).
3. **Tabelloon (jaarloon L)**: `L = floor((tvl × F) / Lv) × Lv` with `Lv = 5400` cents
   (€54); if `tvl × F > Lmax` use the above-Lmax step from the tables' `_notes` (fixtures stay
   below Lmax; path implemented, marked edge in tests).
4. **Schijventarief**: pick bracket row from `t.loonheffing.schijven.belowAow` (or the AOW-age set
   per D3): `X1 = floorEuro((L − a) × b/100 + c)`.
5. **Heffingskortingen** (only if `loonheffingskortingToegepast`):
   `AHK = ceilEuro(max(0, ahkm1 − max(0, L − ahkg1) × ahka1))` (0 above `ahkg2`);
   `ARK` (wit only): three opbouw terms each rounded to 5 decimals and cumulatively capped at
   `arkm1/arkm2/arkm3`, minus `arka1 × max(0, L − arkg3)`, floor 0 above `arkg4`, then
   `ceilEuro`; AOW-age additionally `OUK` (and `AOK` only in the SVB-election case — not an MVP
   input, parameter carried, applied never; documented). Without the election: AHK=ARK=OUK=0.
6. **Loonheffing**: `X = max(0, floorEuro(X1 − (AHK + OUK + ARK)))`;
   `loonheffing = round2(X / F)`; `arbeidskorting = round2(ARK / F)` (the loonstaat column);
   `appliedTaxRate = round2(loonheffing / tvl × 100)`.
7. **Volksverzekeringen (informative split)**: `vvJaar = min(L, schijf1Top) × vvRate` (27,65%
   below AOW / 9,75% AOW-age); `volksverzekeringen = min(loonheffing, round2(loonheffing ×
   vvJaar / X1))`; 0 when `X1 = 0`. Informative only — the corpus rule
   `nl-loonheffingen-volksverzekeringen` requires `0 ≤ vv ≤ loonheffing`, which holds by
   construction.
8. **Zvw werkgeversheffing**: `zvw = round2(min(tvl, maxBijdrageloonMaand) × 6,10%)`,
   `zvwMode = werkgeversheffing`, `zvwRate = 6.10` (maxBijdrageloonMaand = 79409/12 → the tables'
   per-month figure 6.617,41).
9. **Employer charges** over `pl = min(tvl, maxPremieloonMaand)`:
   `awf = round2(pl × (awfTariff = low ? 2,74 : 7,74)%)`;
   `aof = round2(pl × (aofTariff = laag ? 6,27 : 7,63)%)`; `wko = round2(pl × 0,50%)`;
   `whk = round2(pl × whkPercentage)` (config, default the tables' flagged average 1,52);
   `werknemersverzekeringen = awf + aof + wko + whk`;
   `employerCharges = werknemersverzekeringen + zvw`.
10. **Netto**: `nettoPay = tvl − loonheffing − pensionContribution(0 in MVP)` — the spec-1
    consistency equation, cents-exact by construction (employer charges never reduce net; Zvw is
    the employer levy in the MVP mode).

**Worked example (the anchor fixture; every figure recomputed by hand from the primary PDFs):**
€3.800,00 wit, korting applied, below AOW, Awf low, Aof laag, Whk 1,52 →
`L = floor(45.600/54)×54 = 45.576`; bracket 2: `X1 = floor(6.693 × 37,56% + 13.900) = 16.413`;
`AHK = ceil(3.115 − 15.840 × 0,06398) = 2.102`; `ARK = ceil(min-chain(996; 5.300;
5.300 + 384,7545 = 5.684,7545) − 0) = 5.685`; `X = 16.413 − 7.787 = 8.626` →
**loonheffing 718,83**, arbeidskorting 473,75, appliedTaxRate 18,92, volksverzekeringen 470,86
(informative), zvw 231,80, awf 104,12, aof 238,26, wko 19,00, whk 57,76 →
werknemersverzekeringen 419,14, employerCharges 650,94, vakantiegeldReserved 304,00,
**nettoPay 3.081,17**.

### D3 — AOW-age and groene-tabel variants are table-set switches, not code branches

AOW-age applies from the first day of the calendar month in which the employee reaches the tables'
`aow.leeftijdJaren` (67), computed from `dateOfBirth` against the run period (RV2026 tabel 3
toelichting): switch to `schijven.aowBorn1946OrLater` (the `aowBorn1945OrEarlier` set is carried
in the tables and selected by birth year — data present, one `if` on birth year) + the AOW korting
columns (AHK 1.556/0,03195, ARK halved factors, OUK applied). `taxTableColor: groen` runs the
identical chain with **ARK skipped** (RV2026 §2.2.3.4: arbeidskorting geldt alleen bij de witte
tabel) — that is the entire, honest extent of groene support (structure-only per the chain head's
non-goals).

### D4 — PayrollRunService: idempotent per (period, administrationId); recalculation only in draft

- **Create**: `runFor(period, administrationId)` probes for an existing run (the netpay/glpost
  idempotency-probe pattern). Exists + `draft` + `--recalculate` → recalculate; exists + not
  `draft` → refuse (`approve`/`posted`/`paid` are downstream truth — glpost/netpay consumed them);
  absent → create `{period, administrationId, jurisdiction: NL, status: draft}`.
- **Generate**: select active employees (startDate ≤ period end, endDate null or ≥ period start)
  with an NL-payroll-computable record and a contract covering the period; per employee run D2 and
  upsert the Payslip keyed on `(payrollRunId, employeeId)` — recalculation updates in place and
  deletes orphaned engine payslips of that run (never touches payslips with a different/null
  `payrollRunId`). Skipped employees (no contract, missing salary → hourly fast-follow, missing
  BSN/ID → anoniementarief fast-follow, non-NL) are reported per employee in the outcome, never
  silently dropped.
- **Payslip stamping**: `payrollRunId`, `userId` (from the Employee's `nextcloudUserId`,
  the mijn-hr convention), period/jurisdiction/currency, the D2 components, `showsGrossWage` /
  `showsDeductionBasis` / `showsMinimumWage` / `showsEmployerEmployeeIds` = true (the record
  carries them; the rendered loonstrook stays payslip-pdf-docudesk's concern), wkr fields 0
  (no WKR administration in the engine MVP), `anoniementariefApplied` = false (precondition
  employees are skipped, D2 non-goal).
- **Roll-up**: `totalGross`, `totalLoonheffing`, `totalEmployerCharges`, `totalWithholdings`
  (= Σ loonheffing in the MVP: no pension, no Zvw-inhouding), `totalNet` — cents-exact sums of the
  run's payslips; then stamp `engineVersion = tables.id` (`nl-2026`) + `calculatedAt = now()`.
  GL/clearing fields are NOT touched (glpost owns them post-approval).
- **Approve stays human**: the service never writes any status except creating `draft`; the
  existing enum edit (UI/API) is the approval act, and guard wiring remains
  `hrmq-rule-compliance-enforcement`'s scope.

### D5 — Writes go through ObjectService despite the UI's allowCreate:false (verified UI-only)

Verified read-only against the openregister checkout: no server-side `allowCreate` enforcement
exists (Context). The service resolves `OCA\OpenRegister\Service\ObjectService` from the container
(RuleAuditService idiom) and writes register `hrmq` schemas `PayrollRun`/`Payslip`. The manifest
keeps every Payslip surface create-less (`allowCreate: false` stays) — generation is the only
create path, which is exactly the modelled intent.

### D6 — ONE guarded endpoint, page-actions manifest wiring

`POST /api/payroll/calculate` with `runId` (+ implicit CSRF; `#[NoAdminRequired]`):
`PayrollController::calculate` resolves the run via ObjectService under the caller's ambient RBAC
first (DocumentController's no-admin-idor guard, unknown/unauthorized → 404), refuses non-draft
runs (400 with a Dutch message), then delegates to `PayrollRunService` (recalculate semantics).
Manifest: `PayrollRunDetail.actions` += `{id: recalculate, type: api-call, label:
"(Her)berekenen", url: "/api/payroll/calculate", method: POST, params: {runId: "@objectId"},
confirm: true}`; `PayrollRuns.actions` += `{id: create-run, type: open-form, label: "Loonrun
aanmaken", register: hrmq, schema: PayrollRun, onSuccessRoute: PayrollRunDetail}`;
`PayrollRunDetail` widgets += FK-scoped Payslips object-list (`filter: {payrollRunId:
"@objectId"}`, `allowCreate: false`) and the `_note`s are updated (the "no Payslip child list"
and "no actions" rationales are now stale). `npm run check:manifest` gates it.

### D7 — The corpus is the engine's self-check: NlEngineChecks + hrmq:payroll:verify

- `nl-engine-table-version` (PayrollRun): vacuous when `engineVersion` is null; else requires
  `calculatedAt` present and `lib/Standards/tables/{engineVersion}.json` to exist (the provider
  globs the tables dir once at construction — no per-object IO).
- `nl-engine-output-consistency` (Payslip): vacuous when `payrollRunId` is null or the referenced
  run (via `$context['payroll']['runsById']`, enriched in `RuleAuditService::audit()` exactly like
  the glpost context) carries no `engineVersion`; else asserts cents-exact
  `nettoPay = grossPay − loonheffing − pensionContribution(null→0) − (zvwMode=inhouding ? zvw : 0)`
  (`NlPayrollChecks::centsEqual` semantics).
- `occ hrmq:payroll:verify --period [--administration]` resolves the run(s) + their payslips and
  runs the RuleEngine over exactly that object set, printing violations and exiting non-zero on
  any mandatory violation (the `hrmq:rules:audit` exit-code convention) — so a computed run is
  audited by the same corpus that audits hand-entered data: the engine has no private truth.

### D8 — Golden tests are computed from the tables, with slots for official cases

`tests/fixtures/payroll-2026/*.json`: one file per case, shape `{name, input: {grossMonthly,
taxTableColor, dateOfBirth, loonheffingskortingToegepast, awfTariff, aofTariff, whkPercentage,
period}, expected: {loonheffing, arbeidskorting, volksverzekeringen, zvw, awf, aof, wko, whk,
werknemersverzekeringen, employerCharges, vakantiegeldReserved, nettoPay}}`. Cases: the D2 anchor
(3.800), minimum-wage earner (2.294,40 — the referentiemaandloon), part-time (0,6 × anchor),
no-loonheffingskorting, AOW-age (67-year-old, born 1959), bracket-3 high earner (9.000), groen
structure case. Expected values are **computed from the tables file** during authoring (self-
consistent — they prove the implementation matches the spec'd chain, not the law) and the fixture
dir carries `official/README.md` with clearly marked empty slots for the Belastingdienst official
test cases (loonheffingstabellen proefberekeningen) to be dropped in verbatim when obtained — the
disclaimer names this as the certification gap. `BalancingInvariantTest` additionally asserts the
D7 net equation and `vv ≤ loonheffing`, `werknemersverzekeringen = awf+aof+wko+whk` across ALL
fixtures, and cross-checks tables-vs-corpus (Zvw 6,10/4,85 and WML 14,71 in `nl-2026.json` equal
the values asserted by the existing rule statements — divergence fails the build, chain-head risk
note).

### Declarative vs imperative (ADR-031)

| Behaviour | Path | Rationale |
|---|---|---|
| Parameters | spec-1 static tables | chain head |
| Gross-to-net computation | **imperative pure PHP** (`lib/Payroll/`) | a multi-step statutory formula chain with per-step rounding rules is exactly what schema-declarative calculation cannot express; ADR-031 exception, same class as the corpus CheckProviders |
| Run/payslip persistence | imperative service via ObjectService | cross-object multi-write with idempotency + skip reporting (netpay/glpost precedent) |
| Triggers | occ commands + ONE guarded endpoint | operator-demand, no lifecycle exists to hang a declarative action on (glpost D5 precedent) |
| Run pages | declarative manifest (`actions`, object-list) | ADR-031 default; verified the v2 schema supports both action types |
| Output contract | corpus rules + CheckProvider predicates | the app's established exception |

## Seed Data (ADR-001)

No new seed objects: seeded runs/payslips stay hand-entered (null `engineVersion`) and vacuous
under the new predicates — the golden fixtures ARE this change's canonical data. The dev-container
gate instead exercises the real path: `occ hrmq:payroll:run --period 2026-02` against the seeded
employee (3.800/wit/permanent → the D2 anchor figures land in a real draft run), then
`occ hrmq:payroll:verify --period 2026-02` must exit 0.

## Risks / Trade-offs

- **Self-consistent goldens can share a bug with the implementation.** Mitigated: the D2 anchor is
  hand-computed in this design from the primary PDFs (not by the future implementation), the
  balancing invariant is independent arithmetic, and the official-case slots + disclaimer make the
  certification gap explicit instead of implied away.
- **Whk/Aof are employer-specific**: config getters with honest defaults (tables average, `laag`);
  a wrong value yields a wrong employer-charge estimate but never touches net pay or loonheffing.
- **No VCR**: period-capped premium bases drift from the cumulative statutory method for wages
  fluctuating around the maximum. Named in the README disclaimer; fixed-salary MVP keeps the error
  at zero for the supported path.
- **Skip-heavy MVP** (hourly, anoniementarief, non-NL are skipped): the outcome report names every
  skipped employee + reason, so a run is never silently partial.

## Open Questions

- None blocking. Hourly/Timesheet path and anoniementarief are named fast-follows; official
  Belastingdienst test-set acquisition is tracked in the fixture README slot.
