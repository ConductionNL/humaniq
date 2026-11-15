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

## Build notes (2026-10-02, lane 26): where the build follows the code and the specification

Sources: the 2026 Gegevensspecificaties aangifte loonheffingen v3.0 (GS), sections 2.3 to 2.5
(p21-27) and 4.2 (p56-57), and the 2026 XSD shipped by `filings-wage-tax-message`.

### D1 amended: Correct is an action, and `corrigeren` is removed

An OpenRegister lifecycle guard returns allow or deny; it cannot create an object, and a
transition from `verzonden` to `verzonden` would still write the sent filing. The build makes
Correct an action instead: `POST /api/loonaangifte/filings/{filingId}/correction` (HR or payroll,
the filing read under the caller's RBAC first) creates the correction filing
(`filingType: correctie`, `corrects` the sent filing, same period, `concept`) and returns the open
one when a correction of that period is already open. The `corrigeren` transition is removed from
the schema and the page, so no sent filing can be reopened in place. The sent filing is never
written.

### D2 amended: what the difference is compared against

The baseline is the last stand the Belastingdienst received for the period: the relationships of
the sent message (`messageXml`), with every sent correction of the period applied in order. A
relationship is keyed by BSN, or personnel number without one, and the income relationship
number (GS p58, 0036 and 0037). Address and name details are not compared: they need no
correction back in time (GS 2.4.5). A changed or new relationship goes into the correction in full
(GS 2.4.5); one no longer reported is withdrawn with an `InkomstenverhoudingIntrekking`
(GS 2.3.2). The correction also stores the period's new collective stand and the saldo, the new
`TotTeBet` minus the last received one (GS p56-57). A correction with no changed relationship and
a saldo of 0 is refused with "nothing to correct". A sent return without a humaniq message has no
baseline and is refused with a finding.

"Make message" on a correction makes the correction: the same endpoint as the regular return,
dispatched on `filingType`.

### D3 amended: the two routes

- `volgende-aangifte` (the corrected period lies in the current tax year, GS 2.4.1): the
  correction stores its `TijdvakCorrectie` tree. When the next regular return of that year is
  made, every correction made ready for an earlier period of the year that no other return
  carries goes in as a `TijdvakCorrectie`, with one `SaldoCorrectiesVoorgaandTijdvak` per corrected
  period, and `TotGen` is `TotTeBet` plus the saldi (GS p55, 0011). The return records
  `carriedCorrectionIds`, each correction `carriedBy`. At most 13 per return (XSD).
- `correctiebericht` (a closed year, or a yearly filer, GS 2.4.2 and 2.4.3): the correction is its
  own message, with only `TijdvakCorrectie` groups and no return, validated against the XSD of
  the corrected year. humaniq ships the 2026 XSD only, so a correction of a year before 2026 is
  refused until that year's XSD is added.

The klaarzetten guard (`LoonaangifteMessageGuard`) covers corrections: a correction is made ready
only with no blocking finding and a correction tree (route 1) or a validated message (route 2).
