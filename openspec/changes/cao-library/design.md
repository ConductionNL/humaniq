# Design — cao-library

## Context

**Verified against HEAD 2026-07-14.** This change reuses, unchanged, four mechanisms shipped by
`payroll-core-engine` (PR #46) and its predecessors — all read read-only at HEAD:

- **Corpus-as-code**: universal facts (tax parameters, labour rules) ship as versioned JSON in code,
  never in OpenRegister (per-tenant config only). `lib/Standards/tables/nl-2026.json` (loaded by
  `TaxTables`) and `lib/Standards/rules/*.json` (loaded + merged by `RuleCatalogue`) are the two
  precedents; both use the `{value, source, verified}` leaf discipline (tables/SCHEMA.md) or a flat
  sourced-rule shape (rules/SCHEMA.md), and both bump a `VERSION` const on any change. A CAO is the
  same kind of fact (a sector ruleset, identical for every tenant, changing only with the CAO-tekst),
  so it ships the same way.
- **Auto-discovered CheckProviders**: `RuleEngine::providers()` globs `lib/Standards/Checks/*.php`
  for classes implementing `CheckProvider` and merges their `checks()` (keyed object-type → rule-id →
  `fn(array $o, array $context): bool`). A rule becomes *enforced* only when a corpus entry AND a
  registered predicate both exist. A new provider auto-registers by existing — no wiring.
- **Cross-object context enrichment**: `RuleAuditService::audit()` builds per-audit indexes and passes
  them as `$context` (the glpost/engine precedent — `payroll.runsById`, consumed by
  `NlEngineChecks::isOutputConsistent`). The CAO checks need salary and the employee→CAO mapping, both
  cross-object, so they read from `$context` the same way.
- **SeedsObjects**: a provider may also implement `SeedsObjects::seedObjects()` to supply compliant
  sample objects for an empty object type. The read-only `Cao` display objects are seeded from the
  corpus through this hook.

Existing shape the loader mirrors: `TaxTables` loads one versioned file and exposes typed resolvers;
`RuleCatalogue` globs + merges a directory of `*.json` and memoises. `CaoRegistry` does both — a
directory of per-CAO files, merged and memoised, with typed resolvers.

## Goals / Non-Goals

**Goals:** model CAOs as versioned, source-cited corpus data; a `CaoRegistry` loader mirroring
`RuleCatalogue`/`TaxTables`; link a contract to its CAO; enforce two machine-checkable below-CAO
checks (pay scale, leave) that are honest about unverified figures; a read-only CAO reference surface;
idempotent seed of the display objects from the corpus.

**Non-Goals (from the proposal, binding):** CAO-driven computation into net pay (allowances/overwerk),
per-trede progression / age tables, part-time proration nuance beyond a documented limitation, a CAO
authoring UI, and the full 200+ catalogue (three seed CAOs establish the mechanism).

## Decisions

### D1 — The CAO corpus is versioned code data, one file per CAO, `{value, source, verified}` leaves

`lib/Standards/cao/{cao-id}.json`, one file per CAO so each carries its own `version` / `effectiveDate`
and can be revised independently (the `TaxTables` one-file-per-version discipline). Top-level shape
mirrors a tax-table file:

```jsonc
{
  "id": "cao-metaal-techniek",
  "name": "CAO Metaal & Techniek",
  "sector": "Metaal en Techniek",
  "version": "2026-1",
  "effectiveDate": "2026-01-01",
  "basedOn": [{"doc": "CAO Metaal & Techniek 2025-2026", "url": "<authoritative URL>"}],
  "payScales": {
    "value": { "A": 2400_00, "B": 2600_00, "C": 2850_00 },   // schaal -> minimum maandloon in cents
    "source": "CAO-tekst art. <n>, loontabel per 2026-01-01",
    "verified": false, "placeholder": true,
    "checkAgainst": "official CAO Metaal & Techniek loontabel 2026"
  },
  "allowances": { "value": { "ploegentoeslag": { "pct": 13.3 } }, "source": "...", "verified": false },
  "leaveEntitlement": {
    "value": { "vakantiedagenWettelijk": 20, "vakantiedagenBovenwettelijk": 5 },
    "source": "CAO-tekst art. <n>", "verified": false, "placeholder": true, "checkAgainst": "..."
  },
  "workingTime": { "value": { "fulltimeHoursPerWeek": 38 }, "source": "...", "verified": true }
}
```

Every leaf keeps `{value, source, verified}`; a figure not yet confirmed against the CAO-tekst carries
`verified: false` + `placeholder: true` + `checkAgainst`, exactly the `nl-2026.json` discipline — an
unconfirmed value is never silent.

### D2 — `CaoRegistry` mirrors `RuleCatalogue` + `TaxTables`

Pure PHP, zero Nextcloud dependencies. `VERSION` const (bumped on any `cao/*.json` change). Globs
`lib/Standards/cao/*.json` ONCE, validates the leaf shape, memoises. Resolvers the rest of the app
consumes:

- `availableCaos(): array` — id → `{name, sector, version, effectiveDate}` (drives the seed).
- `get(string $caoId): ?array` — the full CAO, or null if unknown.
- `minMaandloonCents(string $caoId, string $schaal): ?int` — null when the CAO/scale is unknown **or
  the `payScales` leaf is `verified:false`/`placeholder:true`** (so the check can treat it as advisory).
- `minLeaveHours(string $caoId, float $contractHoursPerWeek): ?int` — total statutory + bovenwettelijk
  annual leave in hours for a given contract week, null when unknown or unverified.

Returning `null` for unverified figures is the single lever that keeps the two checks honest under the
one-severity-per-rule constraint (D5).

### D3 — Contract → CAO reference

`EmploymentContract` (register.d `hr-objects.json`) already carries a free-text `cao` string; this
change redefines it to reference a CAO `id` from the library (description points at the corpus) and
adds `caoSchaal` (the pay scale within that CAO). No new schema for the contract link — the two
scalar fields are the whole reference. `caoSchaal` is nullable: a contract may name a CAO without a
scale (the pay-scale check is then vacuous).

### D4 — The two checks are cross-object, `CaoRegistry`-backed, placeholder-aware

Both live in `NlCaoChecks` (auto-discovered) and read `CaoRegistry` (loaded once at first use, no
per-object IO) plus the `cao.*` audit context.

- **`nl-cao-minimumloon-schaal`** (payroll.json, EmploymentContract, mandatory, machineCheckable):
  vacuous pass when `cao` is null/unknown, `caoSchaal` is null, or `minMaandloonCents` returns null
  (unverified/placeholder scale — D2). Else resolve the owning employee's `grossMonthlySalary` from
  `$context['cao']['employeesById'][employeeId]` and require
  `round(grossMonthlySalary × 100) ≥ minMaandloonCents`. Below the CAO minimum → violation.
- **`nl-cao-verlof-minimum`** (labour.json, LeaveBalance, mandatory, machineCheckable): vacuous pass
  when `leaveType` is not the statutory annual type (`vakantie`), when no CAO resolves for the
  employee (`$context['cao']['caoByEmployeeId'][employeeId]`), or when `minLeaveHours` returns null
  (unverified). Else require `entitledHours + bovenwettelijkHours ≥ minLeaveHours(cao, contractHoursPerWeek)`.
  Below the CAO minimum → violation.

`RuleAuditService::audit()` enrichment (the `runsById` precedent): `cao.caosById` (the corpus keyed
by id), `cao.employeesById` (Employee objects by id, for salary), `cao.caoByEmployeeId` (each
employee's active-contract `cao`, built once from the EmploymentContract set).

### D5 — `RuleEngine` attaches ONE static severity per rule id — nuance lives in the predicate

Known engine constraint (`RuleEngine::buildViolation` reads `severity` straight from the catalogue
rule; there is exactly one value per rule id). So a CAO check cannot be "mandatory when the figure is
verified, advisory when it is a placeholder" via severity. The predicate carries the nuance instead:
`minMaandloonCents` / `minLeaveHours` return `null` for `verified:false`/`placeholder:true` figures,
and the predicate treats `null` as a **vacuous pass**. Result: both rules are statically `mandatory`,
but a placeholder seed CAO never raises a false mandatory violation — only a verified minimum is
enforced hard. This is the same vacuous-scope discipline `NlEngineChecks` uses for null `engineVersion`.

### D6 — The reference surface is a seeded read-only `Cao` schema, not a new page renderer

The manifest v2 index/detail pages are register+schema-backed (verified: `src/manifest.json` uses only
`index`/`detail`/`dashboard`, all bound to `register`/`schema`; there is no arbitrary-endpoint list
widget). To list CAOs read-only without inventing a page-renderer capability, the corpus is mirrored
into a read-only `Cao` OpenRegister schema (register.d `hr-cao.json`) and seeded from `CaoRegistry`
via `SeedsObjects` (D7). The `Caos` index + `CaoDetail` pages are ordinary register-backed pages with
`allowCreate: false` — the corpus stays the single authoring source; the seeded objects are a display
projection. The contract's CAO (`cao` + `caoSchaal`) renders on `EmploymentContractDetail` for free:
the `ct-data` widget excludes only `employeeId`, so both fields already appear.

### D7 — Idempotent seed of the `Cao` display objects from the corpus

`NlCaoChecks::seedObjects()` returns one `Cao` object per `CaoRegistry::availableCaos()` entry, keyed
on cao id. The seed is idempotent: re-seeding upserts on `id` (no duplicate `Cao` objects), and the
values are read from the corpus so a corpus edit + re-seed converges the display objects — the corpus
is authoritative, the objects are derived. Seeded `Cao` objects satisfy `NlCaoChecks`' own checks
vacuously (they are reference data, not contracts/balances).

### Declarative vs imperative (ADR-031)

| Behaviour | Path | Rationale |
|---|---|---|
| CAO facts (scales, allowances, leave, working-time) | **corpus data** `lib/Standards/cao/*.json` | universal sector facts, identical per tenant — the tables/rules corpus precedent; not OpenRegister (per-tenant config only) |
| CAO corpus loading + resolvers | imperative pure PHP `CaoRegistry` | typed resolution + memoised glob + placeholder→null policy — a data file cannot express the verified-vs-placeholder lever (D5) |
| Contract → CAO link | **register.d** (`cao`, `caoSchaal` on EmploymentContract) | per-tenant config: which CAO *this* employer's contract follows — OpenRegister's job |
| Below-CAO checks | corpus rules + `NlCaoChecks` predicates | the app's established audit exception; machine-checkable facts about a contract/balance |
| Context enrichment (salary, employee→CAO) | imperative `RuleAuditService` | cross-object indexes, the `runsById` precedent |
| `Cao` reference display object | **register.d** schema, **seeded** from corpus (SeedsObjects) | read-only projection so a register-backed manifest page can list it (D6) |
| Reference pages (`Caos`, `CaoDetail`) + contract-detail display | declarative manifest | ADR-031 default; register-backed index/detail, `allowCreate:false` |

## Seed Data (ADR-001)

Three MVP CAOs, authored in the corpus and projected to read-only `Cao` objects by the idempotent seed
(D7):

- **`cao-generiek`** — the statutory-floor baseline (WML-derived minimum maandloon per a single scale,
  20 wettelijke vakantiedagen, 40u/week). The `verified: true` anchor: its figures derive from the
  same statutory sources the engine already cites, so the below-min checks enforce it hard and a
  smoke-test contract below WML raises a real violation.
- **`cao-metaal-techniek`** — CAO Metaal & Techniek (industrial sector), representative multi-scale
  loontabel; schaalbedragen `placeholder: true` + `checkAgainst` the official loontabel until verified.
- **`cao-horeca`** — CAO Horeca NL (services sector), a second concrete sector; scales likewise
  placeholder-marked pending the CAO-tekst.

Placeholder-marked figures are advisory (D5): they populate the reference page and the contract link
but do not raise mandatory violations until a maintainer confirms them and flips `verified: true`.
No hand-entered `Cao` objects — the seed is the canonical display data; existing contracts keep a null
`cao` and stay vacuous under both new checks.

## Risks / Trade-offs

- **Placeholder scales enforce nothing.** By design (D5): a wrong-but-unverified minimum must not
  raise a false mandatory violation. Mitigated by `checkAgainst` on every placeholder leaf and the
  `verified: true` `cao-generiek` anchor that proves the check path end-to-end. Coverage grows as
  maintainers verify figures — not as code changes.
- **Corpus ↔ display drift.** The `Cao` objects are derived; a corpus edit without a re-seed leaves the
  reference page stale. Mitigated: the seed is idempotent and re-runs on the standard seeding hook; the
  corpus (not the objects) is authoritative for the checks, so drift affects only the display page.
- **Part-time / per-trede nuance.** Pay scales are full-time monthly minima; a part-time contract
  compared naively could false-positive. Mitigated: the pay-scale predicate documents proration as a
  known limitation and the MVP anchor case is full-time; per-trede + proration are named fast-follows.
- **One severity per rule id (D5).** Cannot vary mandatory/advisory per CAO; handled entirely in the
  predicate via the verified→enforce / placeholder→vacuous lever.

## Open Questions

- None blocking. CAO-driven computation into net pay, per-trede progression and part-time proration
  are named fast-follows; verifying the two seed-sector loontabellen against the official CAO-teksten
  is tracked by the `checkAgainst` leaves.
