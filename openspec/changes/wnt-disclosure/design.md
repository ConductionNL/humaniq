# Design — wnt-disclosure

## Context

**Verified against HEAD 2026-07-18.** humaniq has no WNT concept today. Three precedents this change
reuses directly:

- **`pension-filing-upa-mvp`** (archived 2026-07-12) — the shape of "a per-period disclosure/filing
  record with a small declarative lifecycle, landing in the existing payroll menu group, audited by a
  corpus rule reading cross-object context." `WntDisclosure` follows this shape at a smaller scale (a
  two-state lifecycle, not four).
- **`30-procent-regeling`** (active, not yet merged) — its design.md D3 independently verifies the
  2026 WNT bezoldigingsmaximum at €262.000 and lands it as
  `parameters.dertigProcentRegeling.aftoppingsgrens` (`{jaar: 262000, maand: 21833.33}`,
  `verified: true`) in `lib/Standards/tables/nl-2026.json`, citing Wet LB 1964 art. 31a's own
  reference to "de WNT-norm." This is the same statutory figure — not a lookalike — so this change
  reads that leaf rather than declaring a second one (`cao-library`'s D5 "one severity/one figure per
  fact" discipline, applied to a shared datum instead of a shared rule).
- **`Employee.isDga`** (dga-payroll-mode) / **`Employee.thirtyPercentRulingGranted`** — the
  established shape for "a boolean marker on Employee that gates an otherwise-inert set of fields and
  a presence-style compliance check." `wntTopfunctionaris` follows the identical shape.

## Goals / Non-Goals

**Goals:** mark which employees are topfunctionarissen and record a valid transitional-exemption
ground when one applies; record the annual WNT-verantwoording as a small, auditable, published
disclosure record per topfunctionaris per year; enforce the one machine-checkable compliance question
that record makes possible — pay above norm without a recorded valid exemption.

**Non-Goals (from the proposal, binding):** automated compensation aggregation, real-time dashboards
and alerting, interim-executive norm tiering, education/healthcare klasse-indeling automation,
severance-plafond administration, recovery tracking with escalation, immutable multi-version PDF
generation, a dedicated auditor RBAC role, ZIP audit export, and multi-year retroactive
reconciliation.

## Decisions

### D1 — The WNT-norm figure is read from the shared `30-procent-regeling` leaf, never re-declared

`TaxTables` (`lib/Payroll/TaxTables.php`) is the verified, established accessor for every
`nl-2026.json` parameter group — `gebruikelijkloon()`, `wkr()`, `bijtellingPrivegebruikAuto()`, and
`wml()` all follow the same shape: a typed method resolving one or more `{value, source, verified}`
leaves via `$this->leaf([...])`. `30-procent-regeling` adds `parameters.dertigProcentRegeling`
(including `aftoppingsgrens.jaar`) to that same file; this change's predicate SHALL call the
accessor `30-procent-regeling` ships for it (mirroring `NlDgaChecks`' own
`TaxTables::load(max(TaxTables::availableIds()))->gebruikelijkloon()['jaarnormCents']` call site) —
never re-declare the figure in a second file, and never read the raw JSON path directly, matching
how every other `Nl*Checks` provider consumes `TaxTables`. If `30-procent-regeling` has not yet
merged when this change lands, the accessor (and the underlying leaf) does not exist yet, and the
predicate SHALL treat that as "norm unknown" — a vacuous pass, never a fabricated fallback figure
(Risks, below). This is the honest consequence of taking a real dependency instead of duplicating a
number that must not silently diverge.

### D2 — `WntDisclosure` is one row per (topfunctionaris, year); the annual report is the filtered list, not a new aggregate object

The old draft modelled a distinct "annual jaarverslag report" object with its own
concept→approved→published lifecycle wrapping many executives' line items. This change does not:
each topfunctionaris's yearly figure is its own `WntDisclosure` row (`year` + `employeeId` +
`totalCompensation` + `status`), and "the WNT-verantwoording for 2026" is simply every
`WntDisclosure` row with `year: 2026` — exactly how `Caos`/`PensionFilings` are already "the CAO
library" / "this period's UPA deliveries" without a wrapping aggregate schema. Publication is a
per-row `status` transition, not a whole-report freeze; the old draft's whole-report immutable
versioning is named out of scope (Non-Goals) rather than silently dropped.

### D3 — `totalCompensation` is hand-entered; this change computes nothing

Finance enters the year's aggregated WNT-bezoldiging (salary + vacation allowance + year-end bonus +
taxable expense reimbursements + pension contribution, per the WNT's own compensation definition) as
a single number. humaniq's payroll engine has no annual roll-up across these components today (it rolls
up per PayrollRun, not per employee-year across pension/nature-compensation), so building automated
aggregation now would be new engine machinery disconnected from anything shipped — exactly what the
task's honesty bar warns against padding in. The MVP surface is: record the number, check it against
the norm, publish it.

### D4 — The exemption is a presence-style gate, following the `gebruikelijkloonJustification`/`aor-ambtenarenrecht` precedent

`Employee.wntUitzonderingReden` (`overgangsrecht` | `ontheffing-minister` | null) is read at
audit-time via the `Employee.byId` context (D5). The check does not validate that the recorded
ground is substantively correct (that an overgangsrecht schedule is followed correctly, or that a
ministerial ontheffing was genuinely granted) — it validates that a ground was recorded. Content
validation of either transitional mechanism is real WNT nuance and a named fast-follow, not silently
promised.

### D5 — Context enrichment extends the one shared pre-pass by one more field

`RuleAuditService::buildRelatedContext()`'s existing `Employee.byId` map (already carrying
`loonheffingenVerklaringOnFile`, `startDate`, `endDate`, `nextcloudUserId`, `administrationId`) gains
`wntUitzonderingReden` — the same incremental-field precedent `abp-aansluiting` and
`aor-ambtenarenrecht` both also extend elsewhere in this batch.

### D6 — Manifest placement follows the shipped precedent, not ADR-001's original (stale) text

ADR-001 Rule 5 names a top-level "Aangiftes & compliance" menu for exactly this kind of disclosure.
Verified against HEAD: `PensionFilings` and `LoonaangifteFilings` — the two closest shipped
precedents — both actually live inside the existing `PayrollGroup` ("Loonadministratie") menu, not a
separate compliance menu; that top-level menu was never built. `WntDisclosures` follows the shipped
precedent as a third sibling entry rather than reviving the ADR's original, unbuilt menu — the same
correction in kind as `multi-administratie`'s #64 (REQ-MULTI-005) made when its own assumption proved
stale against HEAD.

### Declarative vs imperative (ADR-031)

| Behaviour | Path | Rationale |
|---|---|---|
| `wntTopfunctionaris` / `wntUitzonderingReden` | **register.d** (`Employee`) | per-employee HR-set facts, the `isDga` precedent |
| `WntDisclosure` record + `concept -> gepubliceerd` lifecycle | **register.d** + `x-openregister-lifecycle` | a per-tenant disclosure record with a simple state machine — no imperative write needed |
| `nl-wnt-norm-overschrijding` rule statement | **corpus data** `lib/Standards/rules/payroll.json` | a universal statutory fact (WNT art. 2.3), identical for every tenant |
| The predicate + context enrichment | imperative `NlWntChecks` + `RuleAuditService` | cross-object read (Employee's exemption ground + the shared tables leaf) |
| `WntDisclosures` / `WntDisclosureDetail` pages | declarative manifest | ADR-031 default; register-backed index/detail |

## Seed Data (ADR-001)

- One topfunctionaris `Employee` (`wntTopfunctionaris: true`, `wntUitzonderingReden: null`) with a
  `WntDisclosure` for 2026 at `totalCompensation` below €262.000 — clean pass.
- One topfunctionaris `Employee` with `wntUitzonderingReden: "overgangsrecht"` and a `WntDisclosure`
  above €262.000 — clean pass (the exemption gate).
- One topfunctionaris `Employee` with `wntUitzonderingReden: null` and a `WntDisclosure` above
  €262.000 — the violation branch.
- Every pre-existing seeded `Employee` keeps `wntTopfunctionaris: false` — the rule must stay silent
  for the entire pre-existing population.

## Risks / Trade-offs

- **Landing-order dependency on `30-procent-regeling`.** If this change lands first, the
  `aftoppingsgrens` leaf does not exist yet and `nl-wnt-norm-overschrijding` is vacuous for everyone
  until `30-procent-regeling` lands — a silent, temporary under-enforcement, not a false violation
  (D1's fail-safe direction). Documented here so it is a known, accepted landing-order consequence,
  not a surprise.
- **Hand-entered `totalCompensation` can be wrong or stale.** By design (D3) — no aggregation engine
  exists to compute it automatically. Mitigated by nothing beyond finance's own diligence; automated
  aggregation is a named fast-follow.
- **The exemption ground is not content-validated.** By design (D4), the same honest limitation
  `aor-ambtenarenrecht`'s presence-only checks accept.

## Open Questions

- None blocking. Automated aggregation, real-time monitoring, klasse-indeling, severance
  administration, and PDF/export generation are named fast-follows, all blocked on capabilities
  (aggregation engine, notification/case-management machinery, an employer-klasse taxonomy,
  document-generation reuse of `payslip-pdf-docudesk`) that either do not exist yet or are out of
  scope for this change specifically.
