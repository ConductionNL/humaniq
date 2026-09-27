# Design: maintain the job framework inside humaniq

## Context

Read at `development` af702f78.

- `Normfunctie` (`lib/Settings/register.d/hr-hr21.json`, version 0.1.0, `x-schema-org`
  `schema:Occupation`): required `functiecode`, `naam`, `functiegroep`, `caoSchaal`;
  plus `caoSchaalVerified`, `caoSchaalSource`. Its description calls it read-only and an
  illustrative subset. Five rows are seeded in `lib/Settings/register.d/hr-seed.json`.
- Pages: `Normfuncties` (index) and `NormfunctieDetail` in `src/manifest.d/hr-hr21.json`
  with `allowCreate: false` and every action toggle off.
- `EmploymentContract.normfunctieId` links a contract to a function;
  `nl-hr21-schaal-consistentie` (`lib/Standards/rules/payroll.json:1334`, provider
  `lib/Standards/Checks/NlHr21Checks.php`) checks a contract's `caoSchaal` against its
  function.
- `SalaryBand` (`lib/Settings/register.d/hr-comp.json`): `bandId`, `grade`, `minSalary`,
  `referenceSalary`, `maxSalary`, `cao`, `caoSchaal`.

## Goals / Non-Goals

**Goals**

- The employer maintains its own function catalogue in the app, with HR21 rows beside its
  own.
- No existing contract loses its function when a function is retired.

**Non-Goals**

- A workflow for assigning functions to employees.
- Any change to how pay is computed.

## Decisions

### D1. Retire, never delete

`status` (`actief`, `vervallen`), default `actief`; the index filters on `actief` by
default and the delete action stays off. Alternative considered: allowing delete with a
reference check. Rejected: a retired function is history the contracts still point at.

### D2. Source and grading fields

`bron` enum `hr21`, `eigen` (default `eigen`, the five seeds set to `hr21`);
`functiefamilie` (string, nullable); `salaryBandId` ($ref SalaryBand, nullable). The
`caoSchaal` requirement stays, since the consistency rule reads it.

### D3. Who may edit

Create and edit are offered to the HR role; the page toggles follow the role through the
manifest's permission hooks where available, and OpenRegister authorization on the schema
(the pattern `compliance-roles-and-field-access` introduces) enforces it on the API. Until
that change lands the existing admin gate applies.

### D4. A rule for retired functions

`nl-hr21-vervallen-functie` (recommended): a live contract whose `normfunctieId` points at
a `vervallen` function is flagged, so HR reassigns it.

## Declarative-vs-imperative decision (ADR-031)

| behaviour | path | why |
|---|---|---|
| maintaining functions | declarative schema and manifest toggles | plain data |
| retired function on a live contract | corpus rule and predicate | the existing rule family |

## Seed data

- The five seeded HR21 functions get `bron: hr21`, `status: actief`.
- One employer function "Adviseur informatiebeheer" (`bron: eigen`, schaal 10, family
  "Informatie") linked to a seeded `SalaryBand`.

## Risks / Trade-offs

- [Employers load unverified scales] → `caoSchaalVerified` stays false for rows they enter
  unless they set it, and the consistency rule keeps treating unverified figures as the CAO
  library does.

## Open Questions

- None.
