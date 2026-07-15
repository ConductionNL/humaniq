---
kind: code+config
---

## Why

hrmq's existing `Expense` schema (`lib/Settings/register.d/hr-expense.json`, verified at HEAD:
version 0.3.0, category enum `travel`/`accommodation`/`meals`/`supplies`/`software`/`training`/
`other`, the submit to approve to reimburse `x-openregister-lifecycle`) already carries every
field a fixed-cost declaratie needs, but nothing in the register or the machine-checkable rule
corpus (`lib/Standards/rules/*.json`, loaded by `RuleCatalogue`) understands Dutch mileage
reimbursement (reiskosten/kilometervergoeding). Verified at HEAD: no rule mentions
"kilometer"/"reiskosten"/"woon-werk" anywhere in `lib/Standards/rules/`, and no `CheckProvider`
under `lib/Standards/Checks/` targets `Expense` at all.

The Belastingdienst's onbelaste kilometervergoeding — the per-kilometer amount an employer may
reimburse tax-free for both zakelijke (business) and woon-werkverkeer (commute) kilometers — is
EUR 0,23 per km for 2026 (Wet LB 1964 art. 31a lid 2, gerichte vrijstelling). Reimbursing more per
km without additional withholding makes the excess taxable wage. This is exactly the shape of
rule the corpus already handles elsewhere (e.g. `nl-vakantiebijslag-8procent`,
`nl-zvw-werkgeversheffing`): a numeric threshold, versioned as data, checked by a small predicate.
Notably, the Belastingdienst itself raised this same rate mid-2026 (EUR 0,23 to EUR 0,25,
retroactive to 1 January 2026 — see design.md), which is live proof that the rate belongs in
versioned rule-corpus data, not in PHP: next year's change (or a mid-year correction like this
one) becomes a one-number JSON edit, never a code change.

This change is deliberately small: it adds one corpus rule, one check predicate, and two additive
Expense fields the predicate needs to compute a per-km rate. It reuses the existing Expense
approval workflow unchanged and does not compute or gross up the loonheffing on any bovenmatige
(excess) vergoeding — that full gross-to-net treatment is named as a follow-up, not this MVP.

## What Changes

- **`lib/Settings/register.d/hr-expense.json`** — `Expense` gains two additive, nullable
  properties outside `required`: `travelType` (enum `business`/`commute`) and `distanceKm`
  (number, minimum 0). No `category` change, no `required` change, no lifecycle change. Schema
  version 0.3.0 to 0.4.0; `lib/Settings/hrmq_register.json` `info.version` 0.8.0 to 0.9.0 (both
  verified fresh at HEAD before bumping).
- **`lib/Standards/rules/payroll.json`** — new rule `nl-reiskosten-onbelast-tarief`
  (domain `tax`, jurisdiction `NL`, framework `nl-loonheffingen`, severity `mandatory`,
  `machineCheckable: true`, `effectiveDate: 2026-01-01`) carrying
  `parameters.rateEurPerKm: 0.23`, sourced to the Belastingdienst kilometervergoeding page.
  `RuleCatalogue::VERSION` bumps per `SCHEMA.md`'s bump-on-any-change rule.
- **NEW `lib/Standards/Checks/NlReiskostenChecks.php`** — a `CheckProvider` registering
  `checks()['Expense']['nl-reiskosten-onbelast-tarief']`: a pure predicate comparing
  `amount / distanceKm` to the catalogue's `rateEurPerKm`, vacuous outside the mileage scope
  (wrong category, missing travelType, absent/zero distanceKm, unreadable parameters). Discovered
  automatically by `RuleEngine::providers()`'s existing `Checks/*.php` glob — zero edits to
  `RuleEngine.php`.
- **Seed** — one additional compliant `Expense` seed object (mileage, at-or-under-rate) so the
  audit exercises a real pass case, not only vacuous pre-existing data.
- **No lifecycle change** — Expense's `submit`/`approve`/`reject`/`reimburse` transitions and the
  `NoSelfApprovalGuard` stay byte-identical; the new rule is an audit-time signal
  (`occ hrmq:rules:audit`), never a write-time guard.

## Non-Goals (MVP)

- No loonheffing gross-up of the bovenmatige (excess) vergoeding onto any Payslip/PayrollRun —
  named as a follow-up in design.md.
- No new lifecycle transition, guard, or status.
- No vaste (fixed monthly) reiskostenvergoeding / 214-dagenregeling modelling — per-claim mileage
  only.
- No UI/manifest change — the existing generic `ExpenseDetail` data widget renders any schema
  field without edits.
