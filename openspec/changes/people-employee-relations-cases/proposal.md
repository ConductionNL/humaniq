---
kind: code
depends_on: [compliance-roles-and-field-access]
---

# Employee relations cases: grievances, warnings and disciplinary measures

## Why

When an employee files a grievance, or an employer issues a written warning or suspends
someone, HR needs a record of what happened, what was decided, which documents belong to it,
and who may read it. humaniq has nowhere to put it. A spec named
`humaniq-employee-relations` exists, but it is about a relations widget resolving an
`Employee` reference, not about these cases (the matrix calls it a name collision). The
ambtenarenrecht change (`2026-08-20-aor-ambtenarenrecht`) assumed disciplinary measures were
"ordinary BW7 mechanics already covered generically"; nothing in humaniq records them.

The row sits in humaniq's core area (people), which decided it build. No competitor was
rated yes on it; the competitor readers could not settle it from public documents.

### Matrix rows (humaniq `openspec/parity/capabilities.json`)

| row | capability | humaniq today |
|---|---|---|
| `ppl-employee-relations` | Record grievances, disciplinary measures and other employee relations cases. | `no`, none: no schema for a grievance, warning or measure |

## What Changes

- **A case per matter.** A new `EmployeeRelationsCase` records the employee, the kind
  (grievance, conflict, written warning, disciplinary measure, other), the facts, the date
  opened, the measure taken with its period (for example a suspension from and to), the
  outcome, the documents, and a lifecycle `geopend`, `in behandeling`, `afgesloten`.
- **Only HR reads it.** The schema declares OpenRegister authorization so only the HR role
  may read, create or change a case; the employee's manager sees that a case exists on the
  employee's page but not its content; the employee sees their own closed warnings and
  measures in Mijn HR, as they are entitled to under the AVG.
- **A retention date.** Each closed case carries a retention date, by default two years
  after closing, and the existing retention check flags a case kept past it.
- **On the personnel file.** `EmployeeDetail` gains a relations cases list for HR.

## Capabilities

### New Capabilities

- `employee-relations-cases`: grievance, warning and disciplinary cases on the personnel file,
  readable only by HR, with a retention date.

## Impact

- `lib/Settings/register.d/hr-relations.json` (new): `EmployeeRelationsCase` with its
  lifecycle, schema-level and property-level `authorization`.
- `lib/Standards/rules/privacy.json` rule `nl-bewaartermijn-verstreken` and its check
  provider `lib/Standards/Checks/NlDossierRetentionChecks.php`: the case joins the flag
  through its `retainedUntil` field.
- `src/manifest.d/hr-relations.json` (new): `RelationsCases`, `RelationsCaseDetail`,
  `MijnMaatregelen`; a list on `EmployeeDetail`.

## Out of scope

- Formal tuchtrecht for the three non-normalised public sectors (hearing terms, sanction
  escalation, appeal). `aor-ambtenarenrecht` names it a fast-follow needing case management.
- Whistleblower reports (Wet bescherming klokkenluiders). `cmp-whistleblowing` was deferred;
  a report is not an employee relations case about the reporter.
- Generating the warning letter. The letter is attached as a document.
