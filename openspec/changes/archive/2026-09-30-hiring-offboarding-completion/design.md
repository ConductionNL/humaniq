# Design: finish the offboarding

## Context

Read at `development` af702f78.

- `Offboarding` (`lib/Settings/register.d/hr-onboarding.json`, 0.1.0): `employeeId`,
  `lastWorkingDay`, `reason` (`opzegging-werknemer`, `opzegging-werkgever`,
  `einde-contract`, `pensioen`, `overlijden`, `vso`), `status`, `exitGesprekDone` (a date,
  line 268), `assetsIngeleverd`, `toegangIngetrokken` (boolean, line 281, "OCS automation is
  a non-goal"), `verlofsaldoUitbetaald`, `vakantiegeldAfgerekend`,
  `transitievergoedingBedrag` (number, line 299), `getuigschriftVerstrekt`, `notes`.
  Lifecycle: `afronding_plannen`, `eindafrekening_gereedmelden`, `afronden`, `annuleren`;
  its gates are audit rules, not write-time guards.
- `lib/Standards/Checks/NlOffboardingChecks.php:77` checks only that
  `transitievergoedingBedrag` is a number at or past `eindafrekening_gereed` for
  `opzegging-werkgever` and `einde-contract` (`DISMISSAL_INITIATED_REASONS`, line 57).
  Nothing checks `toegangIngetrokken`.
- `lib/Standards/rules/labour.json`, rule `nl-offboarding-transitievergoeding`, carries
  `parameters`: `wageFractionPerServiceYear` `1/3`, `capEur` 98000 with a `_note` that it is
  the 2025 figure and must be replaced by the published 2026 figure before computation,
  `capAlternative` "one gross annual salary when higher", and the dismissal reasons.
- `Employee` (`hr-objects.json:5`) holds `startDate`, `endDate`, `grossMonthlySalary` and
  `nextcloudUserId` (line 45, "the employee's own Nextcloud account").
  `EmploymentContract` holds `startDate`, `endDate`, `hoursPerWeek`, `hourlyWage`.
  `Payslip` holds `grossPay`, `vakantiegeldRate`, `period`.
- `OffboardingDetail` (`src/manifest.d/hr-onboarding.json:168`) groups the checklist and
  the final settlement in two data widgets and exposes the four lifecycle actions. It has no
  page action.
- `grep -rn "IUserManager\|setEnabled" lib --include=*.php`: no hit. humaniq never touches a
  Nextcloud account.
- `lib/Flow/HumaniqFlowNodeListener.php` registers humaniq's flow nodes from a class list
  (`NODES`); four payroll nodes exist today.
- `lib/Controller/OfferController.php` is the precedent for a guarded page action: resolve
  the object with RBAC first, then an admin or HR check.

## Goals / Non-Goals

**Goals**

- What a leaver said is kept as structured data, and the person link does not outlive 90
  days.
- The Nextcloud account of a leaver is disabled by humaniq, and the tick means it happened.
- The transition payment is computed from the statute's inputs, with the breakdown kept.

**Non-Goals**

- Deleting or transferring the leaver's files. Disabling keeps the data; what happens to it
  is Nextcloud administration.
- Access in other systems, and the rest of the final settlement.

## Decisions

### D1. `ExitInterview` is its own schema, anonymised by a flow

`ExitInterview`: `offboardingId` (`$ref` `Offboarding`), `employeeId` (`$ref` `Employee`),
`orgUnitId`, `heldOn`, `conductedBy`, `mainReason` (`salaris`, `loopbaan`,
`leidinggevende`, `werkdruk`, `werk-prive`, `verhuizing`, `pensioen`, `anders`),
`wouldRecommend` (integer 0 to 10), `wouldReturn` (boolean), `whatWorked`,
`whatToImprove`, `anonymisedAt`. `Offboarding.reason` stays the legal ground; `mainReason`
is the leaver's own motive, and the two are different questions.

A shipped `x-openregister-flows` entry with `openregister.trigger-schedule` (daily) selects
interviews with `heldOn` older than 90 days and no `anonymisedAt`, and with
`openregister.object-write` clears `employeeId`, `offboardingId`, `conductedBy`,
`whatWorked` and `whatToImprove` and stamps `anonymisedAt`. It arrives disabled; until an
admin adopts it the page says the records are not yet anonymised.

Writing an `ExitInterview` sets `Offboarding.exitGesprekDone` to `heldOn` through the same
flow engine (`openregister.trigger-object` on create, `openregister.object-write`), so the
existing audit rule keeps working.

Alternative considered: fields on `Offboarding`. Rejected: anonymising them would strip the
case HR must keep for years; one record per interview can lose its person link on its own.

### D2. Disabling the account goes through Nextcloud's user manager

`AccessRevocationService::revoke(string $offboardingId, string $actorUid)` resolves the
case and its employee with `RbacObjectReader`, reads `Employee.nextcloudUserId`, and calls
`IUserManager::get($uid)->setEnabled(false)`. It refuses when the uid is empty (the case
records "no Nextcloud account" and still ticks the box), when the uid is the actor's own,
or when the user is in the `admin` group. On success it sets `toegangIngetrokken` true,
`toegangIngetrokkenDoor` and `toegangIngetrokkenOp`. A repeat call on a disabled account is a
no-op that returns the existing stamp.

`POST /api/offboarding/{id}/revoke-access` is `#[NoAdminRequired]` with the OfferController
guard: resolve first (404 when unreadable), then admin or HR. `RevokeAccessNode`
(`humaniq.revoke-access`) calls the same service, and a shipped, disabled flow runs it daily
for open cases whose `lastWorkingDay` has passed.

Alternative considered: SCIM or an IAM connector. Rejected: `hris-api-public` refuses SCIM as
Nextcloud's layer; disabling through `IUserManager` uses that layer instead of duplicating it.

### D3. The transition payment is a pure calculator over stored inputs

`TransitionPaymentCalculator::calculate(array $employee, array $contracts, array $payslips,
array $parameters, string $endDate): array` returns `{amountEur, serviceStart, serviceYears,
serviceRemainderMonths, monthlyWage, monthlyWageParts, capEur, capApplied, ruleId}`.

- Service runs from the start of the unbroken contract chain: contracts whose gap to the
  next is six months or less count together (BW 7:673 lid 4). The chain helper is shared
  with `people-flex-contract-rules`; whichever change lands first adds it.
- The monthly wage is the base monthly salary plus holiday allowance at the latest payslip's
  `vakantiegeldRate`, plus the average variable pay over the last twelve payslips, as the
  Besluit loonbegrip vergoedingen art. 7:673 BW prescribes. Each part is in
  `monthlyWageParts`.
- One third of the monthly wage per full year of service, pro rata for the rest, capped at
  `capEur` or one gross annual salary when that is higher.
- Money is integer cents inside the calculator, the `PayrollCalculator` convention.

`POST /api/offboarding/{id}/transition-payment` runs it, writes `transitievergoedingBedrag`
and `transitievergoedingBerekening` (the breakdown), and answers the breakdown. For a
reason outside the dismissal list it answers zero with the reason why. The action is a
page action on `OffboardingDetail`, admin or HR only.

Alternative considered: an `x-openregister-calculations` field. Rejected: the inputs span
three schemas and a date chain, which a derived field cannot express, and ADR-031 keeps
domain arithmetic in code.

### D4. The cap is data, and 2026 replaces 2025

The calculator reads `capEur` from the rule parameters, never from a constant. The rule's
2025 figure is replaced by the published 2026 figure from wetten.overheid.nl in this change,
and the `_note` goes.

## Declarative-vs-imperative decision (ADR-031)

| behaviour | path | why |
|---|---|---|
| exit interview record and its reason counts | declarative schema and `x-openregister-aggregations` on `mainReason` | plain data and a count |
| 90-day anonymisation | declarative `x-openregister-flows`, schedule trigger and object write | a scheduled write over a queue, ADR-031 pattern 2 |
| stamping `exitGesprekDone` | declarative flow on create | a cross-object write on an event |
| disabling the account | imperative `AccessRevocationService` | a platform API call no primitive covers |
| daily revocation after the last day | declarative flow calling `humaniq.revoke-access` | scheduled orchestration |
| transition payment | imperative `TransitionPaymentCalculator` | statutory arithmetic across schemas |

## Seed data

- One `ExitInterview` on the seeded completed offboarding case, `mainReason` `loopbaan`,
  score 7, not anonymised; one older seeded interview already anonymised.
- The seeded dismissal case gains `transitievergoedingBerekening` computed from its seed
  employee, so the page shows a breakdown.
- No seeded employee gains a real Nextcloud uid; the revoke path is exercised by tests.

## Risks / Trade-offs

- [Disabling the wrong account] → the uid comes only from `Employee.nextcloudUserId`, admin
  and self are refused, and the action stamps who did it.
- [A leaver still needs access for a few days] → the scheduled flow runs only after
  `lastWorkingDay`, and HR can re-enable in Nextcloud's user management.
- [Variable pay history is short] → with fewer than twelve payslips the average uses what
  exists, and `monthlyWageParts` says how many months it read.

## Open Questions

- Should a re-enabled account (by an admin in Nextcloud) clear `toegangIngetrokken`?
- Which unit should an anonymised interview keep when the leaver's placement ended before
  the interview?

## Changes made while building (2026-09-30)

- D1: the case stamp and the 90-day anonymisation are humaniq code, not `openregister.object-write`
  flows. `ExitInterviewListener` fills in the leaver, administration and department before the
  create and stamps `Offboarding.exitGesprekDone` after it (OpenRegister replaces an object on
  save, so the whole case is written). The anonymisation is the `humaniq.anonymise-exit-interviews`
  step inside the shipped, disabled schedule flow "Exit interviews anonymiseren", so it is still
  the flow engine that schedules it and an administrator who adopts it, and the clearing is unit
  tested against the real schema.
- D1: the reason count is `GET /api/offboarding/exit-reasons` (HR only) drawn as a bar chart on
  `ExitInterviews`, not an `x-openregister-aggregations` entry: a named aggregation has no date
  window, and the count is over the last twelve months (the same finding as the mobility report in
  expenses-travel-calculation).
- D2: an account name Nextcloud does not know is refused (409), not ticked, so a typo in
  `nextcloudUserId` cannot mark access as revoked. `toegangIngetrokkenToelichting` holds "No
  Nextcloud account" when the employee has none.
- D3: the contract chain is the calculator's own: `ContractChainService` (people-flex-contract-rules)
  only chains fixed-term types, while BW 7:673 lid 4 adds every contract, permanent ones too.
  Service is the sum of the contract spans in the chain, gaps not counted. The annual salary for
  the cap is twelve times the monthly wage the calculation uses. HR and payroll may calculate
  (the spec's scenario has a payroll officer press it); `vso` is outside the dismissal list and
  answers zero.
- Seed: the seeded dismissal case `offboarding-jansen` keeps its empty amount, because it is the
  audit rule's demonstration of a missing transition payment; the live check calculates it.
