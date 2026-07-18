# Design — abp-aansluiting

## Context

**Verified against HEAD 2026-07-18.** This change reuses, unchanged, the entire UPA delivery
mechanism `pension-filing-upa-mvp` shipped (archived 2026-07-12):

- **`PensionFiling`** (`lib/Settings/register.d/hr-pension.json`) — `fund` already enumerates `abp`
  alongside `spw`/`bpf-bouw`/`schoonmaak`/`pfab`/`pwri`; the `concept -> gecontroleerd -> bevestigd ->
  verzonden` lifecycle, `PayrollRunApprovedGuard`, and the response-status fields are all read
  read-only at HEAD.
- **The `nl-pensioenaangifte` framework** in `lib/Standards/rules/payroll.json` already carries three
  rules: `nl-upa-payrollrun-approved`, `nl-upa-monthly-completeness`, `nl-upa-deadline-alert`, all
  enforced by `lib/Standards/Checks/NlPensionFilingChecks.php`.
- **`NlPensionFilingChecks::monthlyCompletenessSatisfied()`** (verified at HEAD) is explicitly
  fund-blind by design — its docstring: "MVP fund-blind check; full per-configured-fund obligation
  recorded in the rule statement" — and reads a single global `$context['related']['PensionFiling']
  ['filedPeriods']` set with no `administrationId` dimension.
- **`multi-administratie`** (archived 2026-07-14) shipped the `Administration` catalog
  (`administrationId`/`name`/`kvkNumber`/`loonheffingennummer`/`active`), the denormalized
  plain-string `administrationId` convention on the payroll aggregates (`PayrollRun` already carried
  it pre-multi-administratie, per `hr-administratie.json`'s own description), and the
  `RuleAuditService`/`CheckProvider` auto-discovery mechanism this change extends again.
- **`RuleAuditService::buildRelatedContext()`** (verified at HEAD) is a single shared pre-pass that
  has grown incrementally across five prior changes — the `Employee.byId` index alone gained
  `endDate` (offboarding-wizard-mvp), `nextcloudUserId` (mss-team-scope), and `administrationId`
  (multi-administratie) as separate, additive edits to the SAME map. This change adds one more
  increment to the same shared pre-pass rather than inventing a parallel context builder.
- **`payroll-core-engine`'s README disclaimer** (verified at HEAD) names "no ... pension computation"
  as a known MVP limitation of the calculation engine, for every fund, not only ABP.

## Goals / Non-Goals

**Goals:** an admin-set determination of which client administraties are ABP-obligated; one
additional, narrowly-scoped, machine-checkable rule that is both fund-aware (only `abp` deliveries
count) and tenant-aware (scoped to the obligated administratie's own filings) — closing the specific
gap the shipped fund-blind/tenant-blind completeness check documents as out of its own MVP scope.

**Non-Goals (from the proposal, binding):** ABP premium computation, ABP-specific UPA fields, SFTP/
REST delivery integration, retour-bericht ingestion, VPL, Keuzepensioen, Adieu-meldingen, partner
registration, auto-deriving the obligation flag from an employer-sector taxonomy, and editing the
shipped `nl-upa-monthly-completeness` rule's fund-blind behaviour.

## Decisions

### D1 — The ABP obligation is a plain admin-set boolean, not a derived sector taxonomy

`Administration.abpAansluitingsplichtig` (boolean, default `false`). hrmq has no employer-sector
schema today — `cao-sector-datasets` adds CAO *reference* data (six sector corpus files), not an
employer-sector field on any tenant object, and conflating "which CAO does this employer cite" with
"is this employer legally obligated to use ABP" would be a category error: an employer can be
overheid-sector and still choose a CAO scale for a specific function group, while the ABP obligation
attaches to the *employer*, not the CAO. A plain, admin-toggled boolean is the honest MVP shape; the
old draft's per-function mixed-scheme derivation (REQ-001-002) is a named fast-follow that needs a
sector taxonomy hrmq does not have yet.

### D2 — A new, additive, narrower rule — not an edit to the shipped fund-blind rule

`nl-abp-fund-required` is a **new** rule under the **same** `nl-pensioenaangifte` framework, anchored
on `PayrollRun` (the same object type `nl-upa-monthly-completeness` anchors on — `RuleEngine` merges
providers per object type, never overwrites, the precedent `NlPensionFilingChecks` itself already
relies on since it is additive alongside `NlPayrollChecks` on `PayrollRun`). The shipped rule's own
docstring documents its fund-blind scope as a deliberate MVP decision, not an oversight; widening it
in place would be an undocumented behaviour change to an archived, shipped capability. Both rules now
coexist and audit different things: the shipped rule asks "did *any* fund get a filing this period,
anywhere" (a coarse smoke-test); the new rule asks "did *this obligated administratie's own* ABP
filing happen for *its own* period" (the real compliance question a public-sector customer cares
about).

### D3 — Context enrichment extends the one shared pre-pass, keyed the way the rest of it already is

Three additions to `RuleAuditService::buildRelatedContext()`, all cheap indexes built once per audit
run (the existing precedent — no per-object IO):

1. `Administration.abpPlichtigByAdministrationId` — `administrationId` (the business key, not the
   object UUID — the same key every denormalized child field already uses) → `bool`, loaded from
   `loadAll('Administration')` once.
2. `PayrollRun.byId[...]['administrationId']` — the existing `{id, period, status}` map
   (`nl-upa-payrollrun-approved`'s own index) gains one more field, following the exact precedent of
   `Employee.byId` growing a field per change rather than each change inventing its own index.
3. `PensionFiling.abpFiledPeriodsByAdministrationId` — `administrationId` → set of periods with at
   least one `fund: "abp"` filing. This is deliberately a **second**, narrower index alongside the
   existing, unchanged `filedPeriods` global set — the two serve different rules and neither
   generalizes the other without losing the fund/tenant dimension one of them needs.

### D4 — Premium figures are documentation, not a corpus leaf, because there is no consumer yet

The verified 27,1% total 2026 ABP premium (proposal.md Sources) is recorded in this design and the
proposal — never as a `{value, source, verified}` leaf in `lib/Standards/`. A corpus leaf implies a
resolver, which implies a consumer; there is no pension-premium computation anywhere in the engine
for *any* fund (payroll-core-engine's own disclaimer). Shipping a leaf with zero readers would be
exactly the **orphaned-capability defect class** the fleet has been bitten by repeatedly — a
mechanism that looks done because it has a `verified: true` figure and a schema slot, but nothing
ever calls it. When shared pension-premium computation lands for any fund, this verified figure gets
its corpus home then, sourced against ABP's own primary `premietabel-2026.pdf` rather than the
secondary consultancy summaries this research pass found.

### Declarative vs imperative (ADR-031)

| Behaviour | Path | Rationale |
|---|---|---|
| ABP obligation per administratie | **register.d** (`Administration.abpAansluitingsplichtig`) | per-tenant admin-set fact — OpenRegister's job, not a universal rule |
| `nl-abp-fund-required` rule statement | **corpus data** `lib/Standards/rules/payroll.json` | a universal compliance fact (Wet Privatisering ABP 1996), identical for every tenant — the shipped `nl-pensioenaangifte` rules' own precedent |
| The predicate itself | imperative `NlAbpChecks` (auto-discovered `CheckProvider`) | cross-object evaluation (Administration + PayrollRun + PensionFiling) needs code, not a data file |
| Context enrichment (administratie/run/filing indexes) | imperative `RuleAuditService` | cross-object indexes, the `buildRelatedContext()` precedent |
| ABP premium figures | **documentation only** (proposal.md / this file) | no consumer exists yet (D4) — a corpus leaf here would be an orphaned capability |

## Seed Data (ADR-001)

- **`ADM-001`** (existing) — flipped to `abpAansluitingsplichtig: true`. Its two existing approved NL
  `PayrollRun`s (2026-05, 2026-06 — `pension-filing-upa-mvp` REQ-PFU-007) already each carry a
  `fund: "abp"` `PensionFiling`, so `nl-abp-fund-required` reports **zero** violations for `ADM-001`
  with no new filing seeds — the happy path is proven against data that already exists.
- **`ADM-003`** ("Gemeente Voorbeeld", NEW) — a second, minimal, `abpAansluitingsplichtig: true`
  administratie: one `Administration` row, one `AdministrationAccess` row (`admin`, role
  `accountant` — the `ADM-002` shape), and one approved NL `PayrollRun` for period **`2026-06`** —
  deliberately the SAME period `ADM-001` already filed ABP for globally. No `PensionFiling` is seeded
  for `ADM-003`. This proves two things at once: `nl-abp-fund-required` correctly reports a violation
  for `ADM-003`'s run (its own administratie never filed), **and** the shipped
  `nl-upa-monthly-completeness` stays silent for the same run (2026-06 is already in the *global*
  `filedPeriods` set via `ADM-001`) — the exact fund/tenant blindness this change exists to close,
  demonstrated rather than merely asserted.

## Risks / Trade-offs

- **The obligation flag can be set wrong (or never set).** By design (D1): this is an admin
  determination, not a legal-database lookup. Mitigated by the field's description citing the
  statutory basis (Wet Privatisering ABP 1996) so an admin has the citation at the point of decision;
  auto-derivation from a sector taxonomy is a named fast-follow, not silently promised.
- **Two "is the UPA complete" rules can look redundant at a glance.** Mitigated by D2's explicit
  framing (coarse smoke-test vs. real per-obligated-administratie question) and by the `ADM-003` seed
  proving they diverge in a concrete case, not just in prose.
- **No premium figure is enforced or displayed anywhere yet.** By design (D4) — building a reference
  figure with no consumer is the orphaned-capability trap, not caution for its own sake. Coverage
  grows when pension-premium computation exists as a real capability, not before.

## Open Questions

- None blocking. Premium computation, ABP-specific UPA fields/delivery, and sector-derived
  determination are named fast-follows, all blocked on capabilities (shared pension-premium
  computation; an employer-sector taxonomy) that do not exist in hrmq today.
