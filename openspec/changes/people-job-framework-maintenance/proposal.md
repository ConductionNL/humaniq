---
kind: config
---

# Maintain the job framework inside humaniq

## Why

humaniq ships a job framework (functiehuis) as `Normfunctie`: a standard function with its
code, group and CAO scale, linked from an employment contract and checked by
`nl-hr21-schaal-consistentie`. It is seeded with five illustrative HR21 functions and every
page is read-only (`allowCreate: false`), so an employer whose function is missing cannot
add it, cannot record its own functions, and cannot retire one. The functiehuis change said
itself the library is "data-extensible without code changes", but nothing in the app lets
anyone extend it.

AFAS and Personio both let the employer keep its own catalogue of functions with their
grading. This change makes the framework the employer's to maintain.

### Matrix rows (humaniq `openspec/parity/capabilities.json`)

| row | capability | humaniq today |
|---|---|---|
| `ppl-job-framework` | Keep a job framework of standard functions with their grading, such as HR21. | `partial`: five seeded read-only `Normfunctie` rows; nobody can add one in the app |

### Competitors rated yes

- `ppl-job-framework`, AFAS Profit: "the functiehuis records functions per employer with a
  code and a function type that groups them into clusters, groups or salary scales"
  (https://help.afas.nl/help/NL/SE/140129.htm).
- `ppl-job-framework`, Personio: "a job catalog with standardized job name, job family,
  track, level and salary band, assigned to employees"
  (https://support.personio.de/hc/en-us/articles/18286680534813-Overview-of-the-Job-architecture-in-Personio).

## What Changes

- **HR can add, edit and retire functions.** `Normfuncties` and `NormfunctieDetail` allow
  create and edit for the HR role; a function is retired, never deleted, so contracts that
  point at it keep their meaning.
- **A function says where it came from.** `Normfunctie` gains `bron` (`hr21`, `eigen`), a
  function family, and an optional link to a `SalaryBand`, so an employer's own function
  sits beside the HR21 reference ones and is graded by the same band machinery comp-cycles
  uses.
- **Bulk load.** The library's mass import is switched on for the index, so a full HR21 or
  employer catalogue can be loaded from a spreadsheet.
- **The consistency rule reads retired functions correctly.** A contract on a retired
  function is flagged for review by the existing rule family instead of silently passing.

## Capabilities

### Modified Capabilities

- `functiehuis-hr21`: the reference library becomes maintainable by the employer.

## Impact

- `lib/Settings/register.d/hr-hr21.json`: `Normfunctie` gains `bron`, `functiefamilie`,
  `salaryBandId`, `status`; version bump; description no longer says read-only.
- `src/manifest.d/hr-hr21.json`: `allowCreate` and edit toggles on, mass import on,
  a `status` filter.
- `lib/Standards/rules/payroll.json` and `lib/Standards/Checks/NlHr21Checks.php`: a
  recommended rule `nl-hr21-vervallen-functie` for contracts on a retired function.

## Out of scope

- The functietoekenning approval workflow, maatwerkfunctie governance, the Awb objection
  procedure and decision letters. `functiehuis-hr21` names them non-goals needing case
  management.
- Loading the official HR21 catalogue. It is VNG-owned; the mass import lets an employer
  load its licensed copy.
