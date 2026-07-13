## ADDED Requirements

### Requirement: The `hr-onboarding` fragment SHALL define the `Offboarding` schema (REQ-OFB-001)

`lib/Settings/register.d/hr-onboarding.json` (existing fragment, `x-hrmq-fragment: hr-onboarding`) SHALL gain a second schema **`Offboarding`** as a sibling of `Onboarding` (slug `Offboarding`, icon `AccountMinus`, version `0.1.0`, `x-schema-org: schema:Action`) with properties: `employeeId` (string, format uuid, `$ref: Employee`), `lastWorkingDay` (string, format date), `reason` (enum `opzegging-werknemer|opzegging-werkgever|einde-contract|pensioen|overlijden|vso`), `status` (enum `aangekondigd|afronding_gepland|eindafrekening_gereed|afgerond|geannuleerd`, default `aangekondigd`), `exitGesprekDone` (string, format date, nullable), checklist booleans `assetsIngeleverd`, `toegangIngetrokken`, `verlofsaldoUitbetaald`, `vakantiegeldAfgerekend`, `getuigschriftVerstrekt` (all default false), `transitievergoedingBedrag` (number, nullable), `notes` (string, nullable). `required: [employeeId, lastWorkingDay, reason, status]`. The existing register Repair import picks the extended fragment up without code changes.

#### Scenario: Schema validates a complete case

- **GIVEN** the imported hrmq register
- **WHEN** an object `{employeeId: "00000000-0000-0000-0000-000000000000", lastWorkingDay: "2026-09-30", reason: "opzegging-werknemer"}` is created
- **THEN** creation succeeds with `status` defaulted to `aangekondigd` and the five checklist/eindafrekening booleans defaulted to `false`

#### Scenario: Unknown reason rejected

- **WHEN** an object is written with `reason: "not-a-reason"`
- **THEN** OpenRegister schema validation rejects it (enum mismatch)

### Requirement: `Offboarding` SHALL carry a declarative lifecycle `aangekondigd → … → afgerond` with `annuleren` from every pre-`afgerond` state (REQ-OFB-002)

`configuration.x-openregister-lifecycle` on the schema SHALL declare `field: status`, `initial: aangekondigd`, `terminal: [afgerond, geannuleerd]`, and transitions `afronding_plannen` (aangekondigd→afronding_gepland), `eindafrekening_gereedmelden` (afronding_gepland→eindafrekening_gereed), `afronden` (eindafrekening_gereed→afgerond), `annuleren` (aangekondigd|afronding_gepland|eindafrekening_gereed→geannuleerd). Each forward transition's description documents its gate (`eindafrekening_gereedmelden`: verlofsaldoUitbetaald when an open leave balance remains + vakantiegeldAfgerekend + transitievergoedingBedrag recorded for dismissal-initiated reasons; `afronden`: exitGesprekDone recorded + assetsIngeleverd + toegangIngetrokken + getuigschriftVerstrekt on request + Employee.endDate equals lastWorkingDay). **No transition SHALL carry a `requires:` guard** — gate enforcement stays in the audit rules because the active `hrmq-rule-compliance-enforcement` change owns write-time guard wiring for compliance-checked schemas (the onboarding-wizard-mvp D2 precedent).

#### Scenario: Case walks the happy path

- **GIVEN** an `Offboarding` in status `aangekondigd`
- **WHEN** the actions `afronding_plannen`, `eindafrekening_gereedmelden`, `afronden` are executed in order via the OpenRegister lifecycle endpoint
- **THEN** the object's `status` ends as `afgerond`

#### Scenario: Illegal jump is rejected

- **GIVEN** a case in status `aangekondigd`
- **WHEN** the `afronden` action is attempted
- **THEN** OpenRegister rejects the transition (no `aangekondigd→afgerond` edge)

#### Scenario: Cancellation from any pre-afgerond state

- **GIVEN** a case in status `eindafrekening_gereed`
- **WHEN** `annuleren` is executed
- **THEN** status becomes `geannuleerd`; **AND** `annuleren` is not a declared edge from `afgerond` or `geannuleerd`

#### Scenario: No guard classes are declared

- **WHEN** the lifecycle block of the `Offboarding` schema is inspected
- **THEN** no transition declares a `requires:` guard FQCN

### Requirement: The rule corpus SHALL gain four machine-checkable NL offboarding rules with the transitievergoeding constants as parameters data (REQ-OFB-003)

`lib/Standards/rules/labour.json` SHALL gain `nl-offboarding-transitievergoeding` (source BW art. 7:673; `severity: mandatory`; `parameters` carrying the formula constants as data: `wageFractionPerServiceYear: "1/3"`, `capEur: 98000` — a TODO-commented placeholder equal to the published 2025 indexed cap, to be replaced with the published 2026 Regeling indexering figure during implementation — `capAlternative` (one gross annual salary when higher), `dismissalInitiatedReasons: ["opzegging-werkgever", "einde-contract"]`), `nl-offboarding-verlofsaldo-uitbetaling` (source BW art. 7:641; `severity: mandatory`), `nl-offboarding-getuigschrift` (source BW art. 7:656; `severity: recommended` — the obligation is on-request and the MVP has no request field), and `nl-offboarding-einddatum-consistentie` (source BW art. 7:667; `severity: mandatory`). All four: framework `bw7-10`, `domain: labour`, `jurisdiction: NL`, `machineCheckable: true`, `sourceUrl: https://wetten.overheid.nl/BWBR0005290`. `RuleCatalogue::VERSION` SHALL bump from `2026-07.5` to `2026-07.6` (next free increment if a parallel change bumped first).

#### Scenario: Corpus stays loadable and versioned

- **WHEN** `occ hrmq:rules:audit` runs after the corpus edit
- **THEN** the RuleCatalogue loads without error at version `2026-07.6` and reports the four new rules as enforced (each has a CheckProvider predicate)

#### Scenario: Formula constants are data, not code

- **WHEN** the `nl-offboarding-transitievergoeding` corpus entry is inspected
- **THEN** its `parameters` object carries the 1/3-monthly-wage-per-service-year fraction, the EUR cap constant (TODO-marked pending the published 2026 figure), the annual-salary cap alternative and the dismissal-initiated reason list — and no PHP file hardcodes these constants

### Requirement: `NlOffboardingChecks` SHALL enforce transitievergoeding presence, verlofsaldo payout, getuigschrift provision, and einddatum consistency (REQ-OFB-004)

New auto-discovered provider `lib/Standards/Checks/NlOffboardingChecks.php` (implements `CheckProvider`; does NOT implement `SeedsObjects`). `RuleAuditService::buildRelatedContext()` SHALL extend the existing `context['related']['Employee']` index with `endDate` and add a `context['related']['LeaveBalance']['byEmployeeId']` index (per `employeeId`: the list of `{leaveType, year, entitledHours, bovenwettelijkHours, usedHours}` rows). Predicates, all keyed on `Offboarding`:

1. **`nl-offboarding-transitievergoeding`** — violates when `reason` ∈ {`opzegging-werkgever`, `einde-contract`} and `status` ∈ {`eindafrekening_gereed`, `afgerond`} and `transitievergoedingBedrag` is not a number ≥ 0.
2. **`nl-offboarding-verlofsaldo-uitbetaling`** — violates when `status` is `afgerond`, `verlofsaldoUitbetaald` is not true, and the resolved employee's open leave balance Σ max(0, `entitledHours + bovenwettelijkHours − usedHours`) over their LeaveBalance rows is > 0. When no LeaveBalance row resolves for the employee the check is skipped (deliberately not fail-closed — balance rows are optional MVP data; design.md D3).
3. **`nl-offboarding-getuigschrift`** — violates (recommended) when `status` is `afgerond` and `getuigschriftVerstrekt` is not true.
4. **`nl-offboarding-einddatum-consistentie`** — violates when `status` is `afgerond` and the resolved Employee's `endDate` does not equal `lastWorkingDay` (a missing or empty `endDate` counts as a mismatch); fail-closed when `employeeId` does not resolve at `afgerond`.

#### Scenario: Missing transitievergoeding flagged on a ready eindafrekening

- **GIVEN** the seed case `offboarding-jansen` (`reason: opzegging-werkgever`, status `eindafrekening_gereed`, `transitievergoedingBedrag: null`)
- **WHEN** `occ hrmq:rules:audit` runs
- **THEN** a `nl-offboarding-transitievergoeding` violation is reported for that case

#### Scenario: Resignation never requires a transitievergoeding

- **GIVEN** a case with `reason: opzegging-werknemer`, status `afgerond` and `transitievergoedingBedrag: null`
- **WHEN** the audit runs
- **THEN** no `nl-offboarding-transitievergoeding` violation is reported for it

#### Scenario: Completing with an open leave balance and no payout flagged

- **GIVEN** a case in status `afgerond` with `verlofsaldoUitbetaald: false` whose employee has a LeaveBalance row with `entitledHours + bovenwettelijkHours − usedHours > 0`
- **WHEN** the audit runs
- **THEN** a `nl-offboarding-verlofsaldo-uitbetaling` violation is reported

#### Scenario: No balance rows skips the verlofsaldo check

- **GIVEN** a case in status `afgerond` with `verlofsaldoUitbetaald: false` whose employee has no LeaveBalance rows
- **WHEN** the audit runs
- **THEN** no `nl-offboarding-verlofsaldo-uitbetaling` violation is reported (skip, not fail-closed)

#### Scenario: Completed case without getuigschrift flagged as recommended

- **GIVEN** a case in status `afgerond` with `getuigschriftVerstrekt: false`
- **WHEN** the audit runs
- **THEN** a `nl-offboarding-getuigschrift` violation is reported at `recommended` severity (it does not count as a mandatory violation)

#### Scenario: Einddatum mismatch on a completed case flagged

- **GIVEN** a case in status `afgerond` with `lastWorkingDay: 2026-05-31` whose resolved Employee has `endDate` null or unequal to `2026-05-31`
- **WHEN** the audit runs
- **THEN** a `nl-offboarding-einddatum-consistentie` violation is reported

#### Scenario: Seed data yields exactly one new violation

- **GIVEN** the seeded register (REQ-OFB-006)
- **WHEN** the audit runs
- **THEN** `offboarding-jansen` flags `nl-offboarding-transitievergoeding` and no other new-rule violation is reported; **AND** no pre-existing check regresses

### Requirement: New offboarding pages SHALL surface the case, its checklist and eindafrekening, and the lifecycle under the existing `Onboarding & ATS` menu group (REQ-OFB-005)

`src/manifest.json` SHALL gain (a) an `Offboardings` child (`label: "Offboardings"`, icon `AccountMinusOutline`) appended to the **existing** `OnboardingAtsGroup` — the group tuple `{id: OnboardingAtsGroup, label: "Onboarding & ATS", icon: AccountPlus, order: 106}` stays byte-identical (design.md D5); (b) an `Offboardings` index page (route `/offboardings`, register `hrmq`, schema `Offboarding`) with columns `employeeId`, `reason`, `status`, `lastWorkingDay`, filters `status` and `reason`, default sort `lastWorkingDay` descending; (c) an `OffboardingDetail` detail page (route `/offboardings/:id`) with a "Case" `data` widget (excluding `employeeId`, the checklist/eindafrekening fields and `notes`), a "Checklist" `data` widget (include: `exitGesprekDone`, `assetsIngeleverd`, `toegangIngetrokken`), an "Eindafrekening" `data` widget (include: `verlofsaldoUitbetaald`, `vakantiegeldAfgerekend`, `transitievergoedingBedrag`, `getuigschriftVerstrekt`, `notes`) — the OnboardingDetail grouped-data pattern — a `related` widget (the `$ref` Employee resolves there), a files widget for VSO/getuigschrift artefacts (`type: integration`, `integrationId: files`), a `lifecycleActions` block exposing Afronding plannen / Eindafrekening gereedmelden / Afronden / Annuleren (exactly the REQ-OFB-002 edges, no invented ones), and an audit-history sidebar tab. `src/icons.js` SHALL register `AccountMinus` and `AccountMinusOutline`. The manifest validates (`npm run check:manifest`).

#### Scenario: Manifest stays valid

- **WHEN** `npm run check:manifest` runs
- **THEN** it exits 0

#### Scenario: Detail page drives the lifecycle

- @e2e exclude declarative widget wiring is covered by the shared CnPageRenderer library tests; app-level e2e suite does not exist yet (tracked by active change hrmq-test-coverage-baseline)
- **GIVEN** a case in status `aangekondigd` opened on `OffboardingDetail`
- **WHEN** the user executes Afronding plannen
- **THEN** the page reflects status `afronding_gepland` and offers Eindafrekening gereedmelden

#### Scenario: Existing menu group is extended, not duplicated

- **WHEN** the merged manifest menu is inspected
- **THEN** exactly one group with id `OnboardingAtsGroup` exists (label `Onboarding & ATS`, icon `AccountPlus`, order `106`) and its children include `Onboardings` and `Offboardings`

### Requirement: Seed data SHALL provide one mid-flow case exercising the transitievergoeding violation and one clean completed case (REQ-OFB-006)

`lib/Settings/register.d/hr-seed.json` SHALL gain one former-employee seed (`employee-de-boer`, `startDate: 2019-02-01`, `endDate: 2026-05-31`, `identityDocumentVerified: true`, `identityDocumentRetainedUntil: 2031-12-31`, `loonheffingenVerklaringOnFile: true` — fields chosen so no Employee-keyed payroll check flags it) and two Offboarding seeds referencing employees by slug (the Timesheet/Onboarding `employeeId` mechanism): `offboarding-jansen` (the existing `employee-jansen`; `reason: opzegging-werkgever`, `lastWorkingDay: 2026-08-31`, status `eindafrekening_gereed`, `vakantiegeldAfgerekend: true`, all other gates false/null, `transitievergoedingBedrag: null` — mid-flow, missing eindafrekening component → exactly one violation; the verlofsaldo/getuigschrift/einddatum rules stay silent because the case is not `afgerond`) and `offboarding-de-boer` (`reason: opzegging-werknemer`, `lastWorkingDay: 2026-05-31` = the employee's `endDate`, status `afgerond`, `exitGesprekDone: 2026-05-28`, all checklist/eindafrekening booleans true, `transitievergoedingBedrag: null` — statutorily exempt for a resignation — the clean, fully-walked historical case). All identifiers are obvious placeholders. `NlOffboardingChecks` seeds no provider objects: a self-contained sample cannot carry a resolvable `employeeId`.

#### Scenario: Idempotent seed

- **WHEN** the register Repair import runs twice
- **THEN** the new employee and the two cases exist exactly once

#### Scenario: Completed case is clean

- **GIVEN** the seeded data
- **WHEN** the audit runs
- **THEN** `offboarding-de-boer` reports no violation on any of the four new rules
