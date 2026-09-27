# Design: a complete personnel file and a real right-to-work check

## Context

Read at `development` af702f78.

- `EmployeeCompetence` (`lib/Settings/register.d/hr-agenda.json:21`): `employeeId`,
  `competenceCode`, `label`, `issuedOn`, `validUntil`. `CompetenceCheckService::holds()`
  (`lib/Service/CompetenceCheckService.php:149`) treats a competence past `validUntil` as not
  held on its own date. It is read by the roster check only.
- `HrGeneratedDocument` (`lib/Settings/register.d/hr-documents.json`, 0.2.0):
  `documentType` (`arbeidsovereenkomst`, `aanbiedingsbrief`, `werkgeversverklaring`,
  `getuigschrift`, `loonstrook`, `jaaropgaaf`), `employeeId`, `status`, `filePath`.
- `Employee` (`hr-objects.json:5`): `identityDocumentVerified` and
  `identityDocumentRetainedUntil`. No list of required documents and no expiry of the ID
  itself.
- `Onboarding` (`hr-onboarding.json`, 0.2.0): `widCheckDone` (boolean), `widCheckDate`,
  `startDate`. Transition `gereed_melden` (`gegevens_gevalideerd` to
  `gereed_eerste_werkdag`) documents the WID gate as audit-enforced;
  `NlOnboardingChecks.php:91-103` checks `widCheckDone` against `startDate` in the audit.
- Guards are declared as `requires` on a transition and implement `LifecycleGuardInterface`
  (`check(array $object, string $action, string $userId): GuardResult`), for example
  `lib/Lifecycle/LeaveTypeConditionGuard.php:60` and `hr-comp.json:207`
  (`CompEffectiveDateGuard`).
- `EmployeeDetail` (`src/manifest.d/hr-objects.json:4`) already lists the employee's
  generated documents (REQ-DOSS-001) and has a files widget for the personnel file.
- `lib/Service/ReceiptExtractionService.php` is the duck-typed filinq extraction precedent.
- No `x-openregister-notifications` rule exists in humaniq today (`grep` over
  `lib/Settings/register.d`: no hit); `platform-notifications` adopts the dialect app-wide.

## Goals / Non-Goals

**Goals**

- HR states once which documents a group of employees must have.
- Every employee's file answers present, missing, expired or expiring per requirement.
- A new hire without a right to work cannot be marked ready for day one.

**Non-Goals**

- Storing document copies anywhere new. Files stay on the objects they are attached to.
- Deciding on identity fraud. The check reads and applies a rule; it does not authenticate.

## Decisions

### D1. A requirement names its evidence

`DossierRequirement`: `code`, `label`, `appliesTo` (`{scope: all | contractType |
normfunctie | orgUnit, values[]}`), `evidence` (`personnel-document`,
`generated-document` with a `documentType`, or `competence` with a `competenceCode`),
`expires` (boolean), `warnDaysBefore`, `maxAgeDaysAtStart`, `active`. A requirement that
points at a competence reuses `EmployeeCompetence` as it is; one that points at a generated
document reuses `HrGeneratedDocument` with `status` `generated`.

Alternative considered: one boolean per document type on `Employee`. Rejected: the list
differs per organisation and per role, and a boolean has no expiry.

### D2. A supplied document is a `PersonnelDocument`

`employeeId`, `requirementCode`, `issuedOn`, `validUntil`, `verifiedBy`, `verifiedOn`,
`note`, and the file in the object's files. It carries a declared
`x-openregister-notifications` rule (trigger `scheduled`, filter on `validUntil` within the
requirement's warning window) to the HR group, in the canonical dialect.

### D3. Completeness is computed on read

`DossierCompletenessService::statusFor(string $employeeId, string $date): array` resolves the
active requirements that apply to the employee (through their contracts, normfunctie and
current org placement), finds the newest evidence for each, and answers one row per
requirement: `aanwezig`, `ontbreekt`, `verlopen`, `verloopt-binnenkort` or
`te-oud-bij-start` (issue date more than `maxAgeDaysAtStart` before the employment start),
with the evidence id. `GET /api/employees/{id}/dossier-status` returns it for one employee;
`GET /api/dossier/incomplete` returns the employees with at least one row that is not
`aanwezig`. Both read through `RbacObjectReader`, so a manager sees only their own people.

Alternative considered: a stored status field refreshed by a job. Rejected: the answer is a
pure function of records and today's date, which ADR-031 says to compute on read.

### D4. The right-to-work rule is stated, not guessed

`RightToWorkService::decide(array $input): array` answers `geslaagd` or `mislukt` with a
reason:

1. no document, or `documentExpiry` before the start date: `mislukt`;
2. nationality in `lib/Standards/tables/eea-nationalities.json` (EU, EEA, Switzerland):
   `geslaagd`;
3. otherwise a residence document whose endorsement allows work ("arbeid vrij toegestaan"):
   `geslaagd`, and its expiry becomes a `PersonnelDocument` with `validUntil`;
4. otherwise a work permit (TWV) number with a validity covering the start date:
   `geslaagd`;
5. anything else: `mislukt`.

When filinq returns the machine-readable zone, the ICAO 9303 check digits of document
number, birth date and expiry are verified first; a wrong digit is `mislukt` with that
reason. When no zone can be read, HR enters the fields and the method is `handmatig`.

`RightToWorkCheck` records `employeeId`, `onboardingId`, `documentType`, `nationality`,
`documentExpiry`, `endorsement`, `twvValidUntil`, `method` (`extractie` or `handmatig`),
`result`, `reason`, `checkedBy`, `checkedOn`. It stores no document number. A passing check
sets `Onboarding.widCheckDone` and `widCheckDate`, so the existing audit rule agrees.

### D5. The hard stop is a guard

`RightToWorkGuard` is declared as `requires` on `gereed_melden` and `starten`. It refuses
unless the case's employee has a `RightToWorkCheck` with `result` `geslaagd` and `checkedOn`
on or before `startDate`. A `mislukt` check cannot be overridden; a new check with new
evidence replaces it.

Alternative considered: keep it an audit rule. Rejected: the row asks for a hard stop, and
an audit that runs after day one is exactly the gap.

## Declarative-vs-imperative decision (ADR-031)

| behaviour | path | why |
|---|---|---|
| expiry reminder | declarative `x-openregister-notifications`, `scheduled` | the canonical dialect |
| completeness per employee | imperative `DossierCompletenessService`, on read | evidence spans four schemas and applicability rules |
| right-to-work decision and check digits | imperative `RightToWorkService` | statutory rule and checksum arithmetic |
| hard stop | lifecycle guard via `requires` | the ADR-031 PHP seam for a precondition |
| pages and sections | declarative manifest | existing widgets |

## Seed data

- Requirements: ID (everyone, expires, warn 60 days), arbeidsovereenkomst (everyone,
  generated document), VOG (normfunctie of the seeded care role, max age 180 days at start),
  BIG (competence `big-verpleegkundige`, warn 90 days).
- One seed employee complete, one with an expired VOG, one with a BIG registration expiring
  in 30 days.
- One passing right-to-work check (Dutch passport) and one failing check (residence document
  without work endorsement) on the two seeded onboarding cases.

## Risks / Trade-offs

- [A requirement list that is too strict floods the index] → requirements have `active` and
  the index groups by requirement.
- [Nationality tables change] → the EEA list is a data file with a source note, not code.
- [An onboarding already past `gereed_melden` when this lands] → the guard only acts on the
  transition, so running cases are not reopened; the audit rule still reports them.

## Open Questions

- Should an expired ID on an active employee (not only a new hire) raise a separate
  right-to-work re-check for non-EU nationals whose residence document lapses?
