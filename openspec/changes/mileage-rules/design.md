# Design — mileage-rules

## Context

Verified against HEAD 2026-07-15. `Expense` (`lib/Settings/register.d/hr-expense.json`, version
0.3.0) already carries `employeeId`, `title`, `amount`, `currency`, `category` (enum including
`travel`), `expenseDate`, `receiptFile`, `status`, and the approval-trail fields
(`submittedAt`/`approvedBy`/`approvedAt`/`rejectionReason`/`reimbursedAt`), plus the
mijn-hr-self-service `userId`/`managerUserId` denormalizations. Its `configuration` declares
`x-openregister-lifecycle`: `status`, initial `draft`, terminal `reimbursed`, transitions
`submit` (draft/rejected to submitted), `approve` (submitted to approved,
`NoSelfApprovalGuard`), `reject` (submitted to rejected, `NoSelfApprovalGuard`), `reimburse`
(approved to reimbursed). None of this changes.

The rule corpus (`lib/Standards/rules/*.json`, `SCHEMA.md`) is versioned static data merged by
`RuleCatalogue::all()` (globs `rules/*.json`); a rule becomes enforced only when a
`CheckProvider` under `lib/Standards/Checks/` registers a predicate keyed by that rule id
(`RuleEngine::providers()` globs `Checks/*.php` and instantiates any class implementing
`CheckProvider` — verified at HEAD, no registration list to edit). Several existing providers
already read rate/threshold values out of a rule's `parameters` instead of hardcoding them in PHP
(`NlAttendanceChecks::breakTiers()`, `NlWageTaxFilingChecks::tijdvakcodeParameters()`,
`NlAtsChecks::derivatieParameters()`), returning a vacuous pass when the catalogue entry or its
`parameters` cannot be read — this change follows that exact precedent.

Real-world grounding for "the rate must be data, not code": the Belastingdienst's own
kilometervergoeding-verhoging notice (see sourceUrl below) states the 2026 onbelaste
kilometervergoeding was originally EUR 0,23 per km and was later raised, with retroactive effect
to 1 January 2026, to EUR 0,25 per km ("Het kabinet verhoogt de onbelaste vergoeding van
EUR 0,23 naar EUR 0,25 per kilometer" / "Deze maatregel gaat in met terugwerkende kracht vanaf
1 januari 2026"). This change models the originally effective 2026 figure, EUR 0,23, as the
corpus baseline; the later increase is named as a one-number follow-up (Risks section), which is
itself the proof of D1 below.

## Goals / Non-Goals

**Goals:** a versioned, sourced onbelast-rate rule; a small vacuous-scope predicate over Expense;
the two additive fields the predicate needs; zero lifecycle change; zero RuleEngine edits.

**Non-Goals (MVP, named follow-ups):** loonheffing gross-up of the bovenmatige vergoeding onto a
Payslip/PayrollRun; vaste (fixed monthly) reiskostenvergoeding / 214-dagenregeling; write-time
enforcement (blocking submit/approve on violation); any UI/manifest change.

## Decisions

### D1 — The onbelast rate lives in rule-corpus `parameters`, never in PHP

`lib/Standards/rules/payroll.json` gains `nl-reiskosten-onbelast-tarief` carrying
`parameters: { "rateEurPerKm": 0.23 }`. The check predicate (D3) reads this value via
`RuleCatalogue::all()` at evaluation time — it never contains a literal `0.23`. Consequence: the
annual re-issue (or a same-year correction, exactly like the Belastingdienst's real EUR 0,23 to
EUR 0,25 mid-2026 change cited in Context) is a one-number JSON edit with no code change, no
predicate rewrite, and no deploy of `lib/Standards/Checks/`.

### D2 — Expense gains two additive, nullable fields; no lifecycle or required change

`travelType` (string, nullable, enum `business`/`commute`, title "Type reis") distinguishes
zakelijke kilometers from woon-werkverkeer; both are eligible for the onbelaste
kilometervergoeding, so the enum has exactly these two values, not a broader travel-mode list.
`distanceKm` (number, nullable, minimum 0, title "Afstand (km)") is the kilometers driven for that
claim. Both properties are additive and outside `required`, so every previously stored Expense —
including the three existing seed objects (`hrmq-expenses`, none of which set these fields) —
stays valid with zero migration. `Expense.version` moves 0.3.0 to 0.4.0; the owning register's
`info.version` (`lib/Settings/hrmq_register.json`, currently 0.8.0, verified fresh at HEAD) moves
to 0.9.0. `category` keeps its existing enum unchanged — mileage claims are still
`category: "travel"`; `travelType` narrows only within that category.

### D3 — A vacuous-scope predicate, mirroring `nl-cao-minimumloon-schaal`

`NlReiskostenChecks::checks()['Expense']['nl-reiskosten-onbelast-tarief']` is
`fn(array $o, array $context): bool`. It is vacuously true (never a false violation) unless ALL of
the following hold: `category === 'travel'`; `travelType` is `business` or `commute`;
`distanceKm` is numeric and greater than 0; `amount` is numeric; and the catalogue's
`rateEurPerKm` for this rule id can be read as a number. When every condition holds, it computes
`perKm = amount / distanceKm` and returns `perKm` less than or equal to `rateEurPerKm` (a small
epsilon tolerance, the existing `ratesEqual`/`centsEqual` style already used by
`NlPayrollChecks`). This mirrors `nl-cao-minimumloon-schaal`'s stated discipline — "vacuous when
the contract names no CAO/scale... a placeholder figure is advisory, never a false mandatory
violation" — applied here to absent mileage fields instead of an absent CAO reference.

### D4 — Auto-discovery needs zero `RuleEngine.php` edits

Verified at HEAD (`RuleEngine.php`, the discovery loop): `providers()` globs
`__DIR__.'/Checks/*.php'`, builds the FQCN `\OCA\Hrmq\Standards\Checks\{basename}`, and keeps it
only if `class_implements($class)` contains `CheckProvider`. Dropping
`lib/Standards/Checks/NlReiskostenChecks.php` into that directory, implementing `CheckProvider`
(both `checks()` and `seedSpec()`, the interface's two required methods), is therefore the entire
wiring step — no orphaned-capability risk (the fleet-wide defect class this corpus is built to
avoid): the moment the file exists and implements the interface, `RuleEngine::checks()` merges its
`Expense` predicate additively alongside any other provider's `Expense` entries (the same additive
merge `mss-team-scope` relied on for `NlOrgChecks`).

### Declarative vs imperative (ADR-031)

| Behaviour | Path | Rationale |
|---|---|---|
| Onbelast rate (EUR per km) | declarative rule-corpus data (`parameters.rateEurPerKm`) | ADR-031 default — a single numeric threshold is exactly what schema-declarative rule data expresses; no formula chain, unlike `payroll-core-engine`'s gross-to-net (that change's ADR-031 imperative exception) |
| Per-km compliance decision | imperative check predicate (`NlReiskostenChecks`) | the corpus's established exception for `machineCheckable` rules: an arithmetic comparison needs a predicate function, not schema validation |
| Expense fields (`travelType`/`distanceKm`) | declarative schema (`register.d`) | ADR-031 default |
| Approval workflow | EXISTING declarative `x-openregister-lifecycle` (unchanged) | reuse only; no new lifecycle is this change's explicit non-goal |
| Enforcement surface | existing `occ hrmq:rules:audit` / `RuleAuditService` | no new command; the rule is picked up by the existing corpus-wide audit the moment the predicate exists |

## Seed Data (ADR-001)

The three existing `Expense` seed objects (`hrmq-expenses`, in `lib/Settings/register.d/
hr-seed.json`) set no `travelType`/`distanceKm`, so they stay valid and are vacuously satisfied
under the new predicate — no migration. This change adds exactly one further seed `Expense`
(category `travel`, `travelType: "business"`, a `distanceKm`/`amount` pair at or under
EUR 0,23 per km) so `occ hrmq:rules:audit` exercises a real, non-vacuous pass case for the new
rule, mirroring the `NlPayrollChecks` precedent that `seedObjects()` samples satisfy every
predicate keyed to their type. No seed object is added that violates the rate — a deliberately
non-compliant fixture belongs in the unit test, not in shipped seed data.

## Risks / Trade-offs

- **The real 2026 rate already moved once.** The Belastingdienst raised EUR 0,23 to EUR 0,25 per
  km retroactive to 1 January 2026 (Context sourceUrl). Shipping EUR 0,23 as the corpus baseline
  is the historically-correct originally-effective-2026-01-01 figure; bumping
  `parameters.rateEurPerKm` to 0.25 to track the real correction is named here as the immediate,
  purely-data follow-up (D1 exists precisely so that bump needs no code change).
- **No gross-up.** An Expense flagged by `nl-reiskosten-onbelast-tarief` is a compliance signal
  only (`occ hrmq:rules:audit`); this change does not compute or post the loonheffing on the
  bovenmatige vergoeding to any Payslip. Named as a follow-up, not silently dropped.
- **Narrow scope by construction.** `travelType` only has meaning under `category: "travel"`;
  every other category is vacuously out of scope for this rule, which is the intended MVP
  boundary, not an oversight.

## Open Questions

None blocking. Named follow-ups: (a) loonheffing gross-up of the bovenmatige vergoeding onto the
relevant Payslip/PayrollRun period; (b) tracking the Belastingdienst's mid-2026 EUR 0,23 to
EUR 0,25 change as the `parameters.rateEurPerKm` data bump once hrmq adopts it; (c) vaste
(fixed monthly) reiskostenvergoeding / 214-dagenregeling modelling, which is a different claim
shape entirely and out of scope here.
