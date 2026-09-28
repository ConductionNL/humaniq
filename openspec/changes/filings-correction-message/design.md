# Design: a correction for an earlier wage tax return

## Context

Read at `development` 76f27f20.

- `LoonaangifteFiling` in `lib/Settings/register.d/hr-objects.json` has the lifecycle
  `klaarzetten`, `bevestigen`, `verzenden`, `heropenen`, `corrigeren`. `corrigeren` goes from
  `verzonden` back to `concept`, and its description calls itself a placeholder.
- The detail page `LoonaangifteFilingDetail` (`src/manifest.d/hr-objects.json`, around line
  1170) lists `corrigeren` among its `lifecycleActions`.
- Retro recalculation already exists (`retro-adjustments`, `PayrollRunService`), so a changed
  past month is recalculated into new payslip values.
- Guards live in `lib/Lifecycle/` (for example `PayrollRunApprovedGuard`) and register in
  `lib/AppInfo/Application.php`; the register walk test counts them.
- The message builder and the snapshot it stores come from `filings-wage-tax-message`, which
  is specified, not built.

## Decisions

### D1. A linked new filing

`corrigeren` becomes a transition from `verzonden` to `verzonden` whose guard
(`LoonaangifteCorrectionGuard`) creates the correction filing and refuses the write if it
cannot. The sent filing never changes status again. Alternative considered: keep resetting
the sent filing. Rejected: it overwrites the proof of what was sent, which the 7 year
retention duty protects.

### D2. The difference per employee

`LoonaangifteCorrectionService::diff(sent, current)` compares, per employee, the collective
and individual amounts stored with the sent message against the current approved payslips of
that period. Only employees with a difference become `correctionLines`. A correction with no
difference is refused at `klaarzetten` with the finding "nothing to correct".

### D3. Route by year

`correctionRoute` is `volgende-aangifte` when the corrected period lies in the tax year of the
correction's creation date and `correctiebericht` otherwise. The first attaches the lines to
the next regular filing's message; the second renders a standalone message through the same
builder with the correction element set.

## Declarative-vs-imperative decision (ADR-031)

| behaviour | path | why |
|---|---|---|
| filing fields and lifecycle | declarative schema | data and a state machine |
| creating the linked filing | guard | the write must fail if the new object cannot be made |
| the difference | imperative service | a comparison across payslips and a stored snapshot |

## Risks

- A retro run that has not been approved yet would produce a correction against draft
  numbers. The diff reads only approved, posted or paid runs, as the regular message does.
