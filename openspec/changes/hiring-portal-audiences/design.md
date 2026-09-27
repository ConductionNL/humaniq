# Design: candidates, new hires and leavers on the portal

## Context

Read at `development` af702f78.

- `lib/Portal/PortalContributionProvider.php` is humaniq's plain, duck-typed contribution
  (ADR-046). `getAudiences()` (line 67) returns `external-employee`, `client` and `manager`.
  `getContribution()` (line 116) returns one manifest per audience and `null` for any other.
  Every existing collection is scoped by a claim (`employeeId`, `clientId`, `costCenter`)
  and carries `minTrust: low`. No entry carries `anonymous: true`.
- portaliq builds two aggregates (ADR-046 section 4). `aggregateAnonymous()` asks every
  provider, for each of its audiences, and keeps only collection and action entries flagged
  `anonymous: true` (`portaliq lib/Contribution/PortalContributionRegistry.php:185`,
  `keepAnonymousOnly()`). A collection may carry a `filter` map that portaliq applies to the
  read (`portaliq lib/Controller/ContributionController.php:510`).
- `Vacancy` (`lib/Settings/register.d/hr-ats.json:5`, version 0.2.1): `title`,
  `description`, `department`, `status` (`concept`, `gepubliceerd`, `gesloten`),
  `publishedDate`, `closingDate`, `administrationId`, lifecycle `publiceren` and `sluiten`.
  Its description says "no public career page (portaliq per ADR-046)".
- `job-application` (`hr-ats.json`, version 0.3.0): candidate data lives on the object
  (`candidateName`, `email`, `phone`, `cvFile`, `motivation`, `talentPoolOptIn`), with the
  pipeline lifecycle `nieuw` to `aangenomen` and `afwijzen` from every active stage. The
  `afwijzen` transition notes that "absent a public career page and candidate actor, a
  withdrawal is also recorded this way by HR".
- `VacancyDetail` (`src/manifest.d/hr-ats.json:252`) is a detail instance with a fixed
  `include` list; `ApplicationDetail` (`hr-ats.json:4`) shows the application widget, the CV
  files widget, the interviews list and the lifecycle actions.
- `Onboarding` (`lib/Settings/register.d/hr-onboarding.json`, 0.2.0) records the hire's
  checklist as booleans (`contractSigned`, `widCheckDone`, `bsnValidated`, `ibanVerified`,
  `itProvisioned`, `pensioenAangemeld`) with `employeeId` and `startDate`.
- `Employee` (`hr-objects.json:5`) holds `bsn`, `iban` and `tenaamstelling`. `Payslip`,
  `Jaaropgaaf` and `HrGeneratedDocument` (`hr-documents.json`) all carry `employeeId`.
- The library's `CnFormBuilder` (nextcloud-vue 2.40.0) edits a `fields[]` list of
  `{key, type, label, placeholder, required, options}`, the same field shape the manifest
  form grammar reads.

## Goals / Non-Goals

**Goals**

- A visitor without an account sees humaniq's published vacancies and applies to one.
- HR adds questions per vacancy and reads the answers on the application.
- A signed new hire hands in their own details and papers before day one.
- A leaver reads their own payslips, annual statements and letters after they left.

**Non-Goals**

- Any portal page, login, account store or rendering in humaniq. All of it is portaliq's.
- Automatic checklist ticks. A delivered document is evidence for HR, not a completed check.
- Offer-letter signing by the candidate (the `offer-esign` follow-up).

## Decisions

### D1. Three audiences on the one existing provider

`getAudiences()` returns `candidate`, `new-hire` and `former-employee` beside the existing
three, and `getContribution()` gains one private manifest method per audience. The provider
stays pure data: no I/O, no constructor dependencies, `null` for unknown audiences.

Alternative considered: a second provider class for recruiting. Rejected: ADR-046 discovers
exactly one class per app by convention FQCN.

### D2. The careers surface is anonymous, and only two entries are

The `candidate` manifest declares one collection and one action with `anonymous: true`:

- `openVacancies` on `Vacancy`, `filter: {status: 'gepubliceerd'}`, `listable: true`,
  projected to `title`, `description`, `department`, `closingDate` and `questions`.
  `administrationId` and `publishedDate` do not leave humaniq.
- `applyToVacancy`, `type: create` on `job-application`, whitelist `vacancyId`,
  `candidateName`, `email`, `phone`, `motivation`, `talentPoolOptIn`, `answers`. The CV
  arrives through portaliq's file upload onto the created object, the existing
  `cvFile` pattern.

`status`, `rejectedDate`, `retentionExpiryDate`, the offer fields and `administrationId`
are never whitelisted. The lifecycle's `initial: nieuw` sets the status. The talent-pool
choice is a whitelisted boolean that defaults to false, so consent is an explicit act.

Alternative considered: a public `#[PublicPage]` controller in humaniq. Rejected by ADR-108:
anonymous CRUD over OpenRegister objects belongs to portaliq.

### D3. Questions live on the vacancy, answers on the application

`Vacancy.questions` is an array in the `fields[]` shape `CnFormBuilder` writes. `answers`
on `job-application` is an object keyed by question `key`. A required question that is not
answered is refused by portaliq's form validation; humaniq does not re-validate answers
server-side in this change (see Open Questions). Answers are candidate data and follow the
application's retention clock: deleting the application deletes them.

Alternative considered: one `ApplicationAnswer` object per answer. Rejected: it would give
candidate data a second retention clock, which the `recruiting-ats-basic` design D2
deliberately avoided.

### D4. Preboarding reads and writes the hire's own records, nothing else

The `new-hire` manifest is scoped by the `employeeId` claim:

- collections `myEmployeeRecord` (the Employee, not listable) and `myOnboarding`
  (`Onboarding` where `employeeId` matches, projected to `startDate`, `status` and the
  checklist booleans so the hire sees what is still open);
- action `updateMyDetails`, `type: update` on `Employee`, whitelist `iban`,
  `tenaamstelling`, `bsn`, with `minTrust: substantial`, because a bank account and a BSN
  are the two fields a fraudster wants;
- file upload onto the hire's `Onboarding` case for ID, diploma and certificate of conduct.

HR still ticks `widCheckDone`, `bsnValidated` and `ibanVerified` after looking.

Alternative considered: creating the Nextcloud account early. Rejected: the hire has no
account until IT provisions one, and `itProvisioned` is recorded, not automated
(`onboarding-wizard-mvp` non-goal).

### D5. A leaver keeps read access to their own paperwork

The `former-employee` manifest is read-only and scoped by the `employeeId` claim:
`payslips` (`Payslip`), `annualStatements` (`Jaaropgaaf`) and `myDocuments`
(`HrGeneratedDocument` with `status` `generated`). No action is declared. How long the
account lives is portaliq's account policy; humaniq names no end date.

### D6. The schema descriptions follow the code

The `Vacancy` description drops "no public career page", and the `afwijzen` description
drops the absent-candidate-actor clause, because both become false. Both schema versions
bump.

## Declarative-vs-imperative decision (ADR-031)

| behaviour | path | why |
|---|---|---|
| status of a portal application | declarative `x-openregister-lifecycle` `initial` | the existing lifecycle already starts at `nieuw` |
| which vacancies are public | declarative collection `filter` on `status` | the lifecycle state is the publish decision |
| audiences and whitelists | descriptor in `PortalContributionProvider` | ADR-046 contract, pure data |
| question editing | library `CnFormBuilder` in a host section | Vue logic stays in nextcloud-vue |

## Seed data

`lib/Settings/register.d/hr-seed.json` gains: the existing published vacancy with two
questions (a yes or no driving licence question and a free-text availability question), and
one `nieuw` application on it that carries `answers`, as if it came in through the portal.
No employee seed changes.

## Risks / Trade-offs

- [Spam applications] → anonymous submission is throttled by portaliq (ADR-082); HR rejects
  junk with the existing `afwijzen`, which starts the four-week retention clock.
- [BSN and IBAN typed by the hire] → `minTrust: substantial` keeps them off password-only
  accounts; HR still verifies and ticks `bsnValidated` and `ibanVerified`.
- [Portal applications carry no administration] → `administrationId` is left empty on a
  portal application; HR sees it in the index and sets it while screening.

## Open Questions

- Should humaniq re-check required answers server-side, for an application created outside
  the portal form?
- Should the Offboarding case record how long a leaver keeps portal access, so portaliq can
  read it instead of applying one global policy?
