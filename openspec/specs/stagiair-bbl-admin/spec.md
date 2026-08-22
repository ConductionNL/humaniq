# stagiair-bbl-admin Specification

## Purpose
TBD - created by archiving change stagiair-bbl-admin. Update Purpose after archive.

## Requirements

### Requirement: A Stagiair schema SHALL model a stage placement structurally outside Employee and the payroll engine (REQ-STAG-001)

`lib/Settings/register.d/hr-stagiair.json` SHALL define a `Stagiair` schema carrying `onderwijsinstelling`, `opleiding`, `niveau` (enum `hbo`/`wo`/`mbo-bol`), `stagetype` (enum `snuffelstage`/`meeloopstage`/`afstudeerstage`), `startDate`, `endDate`, `begeleiderId` (`$ref` Employee, nullable), `stagevergoedingPerMaand` (nullable number), `bpvOvereenkomstOndertekend` (boolean, default false), `verzekeringGeverifieerd` (boolean, default false), a declarative `x-openregister-lifecycle` with states `aangemeld`/`lopend`/`afgerond`/`gestopt`, and `administrationId`. No property on `Stagiair` SHALL be a `$ref` from any payroll schema (`PayrollRun`, `Payslip`, `PayrollMutationReport`), and no payroll schema SHALL gain a `$ref` to `Stagiair`.

#### Scenario: A Stagiair record validates and defaults to aangemeld
- **GIVEN** the imported hrmq register
- **WHEN** a `Stagiair` object is created with `onderwijsinstelling`, `opleiding`, `niveau: hbo`, `startDate`, and `endDate`
- **THEN** the object validates and `status` defaults to `aangemeld`

#### Scenario: A Stagiair is invisible to the payroll engine
- **GIVEN** a `Stagiair` record with a populated `stagevergoedingPerMaand`
- **WHEN** a `PayrollRun` is created and its member employees resolved
- **THEN** the `Stagiair` record is never included, because no payroll schema references `Stagiair` and `PayrollCalculator` never reads it

### Requirement: A BBL-leerling SHALL be modelled as an EmploymentContract with type bbl, not a second entity (REQ-STAG-002)

`EmploymentContract.type` (`hr-objects.json`) SHALL gain the enum value `bbl` alongside the existing `permanent`/`temporary`/`agency`/`minijob`. A `bbl`-type `EmploymentContract` SHALL require no new property, no new `$ref`, and no branch in `PayrollCalculator` or the NL jurisdiction pack beyond what any other contract type already exercises — it SHALL flow through the existing payroll path (loon, loonheffing, premies, CAO-toepassing via `cao`/`caoSchaal`) exactly as a `permanent` or `temporary` contract does.

#### Scenario: A bbl contract validates and computes payroll like any other type
- **GIVEN** an `EmploymentContract` with `type: bbl`, `hourlyWage`, `hoursPerWeek`, and a linked `Employee`
- **WHEN** the contract is included in a payroll run
- **THEN** `PayrollCalculator::calculate()` produces a result using the identical calculation path as a `permanent`-type contract with the same wage/hours inputs — no `type`-specific branch exists or is added

#### Scenario: Existing contract types are unaffected
- **GIVEN** the three existing seeded `EmploymentContract` records (`permanent`/`temporary`/`agency`)
- **WHEN** the register import runs after this change
- **THEN** all three still validate and their `type` values are unchanged

### Requirement: The stagevergoeding fiscal treatment SHALL be documented with a cited source or marked unverified, never invented (REQ-STAG-003)

`Stagiair.stagevergoedingPerMaand`'s schema description SHALL cite the Belastingdienst Handboek Loonheffingen (hoofdstuk 17, "Stagiairs") as the governing source and SHALL NOT assert a specific untaxed euro ceiling. This change SHALL NOT add a machine-checkable rule asserting a stagevergoeding fiscal limit, because no confirmed threshold is cited; any future rule doing so SHALL follow the `{value, source, verified}` leaf discipline (`nl-2026.json`) with `verified: false` and a `checkAgainst` URL until a maintainer confirms the figure against the official text.

#### Scenario: No fabricated ceiling exists anywhere in the corpus
- **GIVEN** the labour corpus (`lib/Standards/rules/labour.json`) after this change
- **WHEN** the corpus is searched for a rule id matching `stagevergoeding`
- **THEN** no such rule exists — this change adds none, per the documented uncertainty

#### Scenario: BBL fiscal treatment is cited and asserted as ordinary employment
- **GIVEN** an `EmploymentContract` with `type: bbl`
- **WHEN** the schema description for `EmploymentContract.type`'s `bbl` value is read
- **THEN** it states that a BBL-leerling has a real arbeidsovereenkomst and is loonheffingplichtig as an ordinary employee, citing the Handboek Loonheffingen

### Requirement: The BPV-overeenkomst signing fact SHALL be an HR-entered boolean, not an automated multi-party signing flow (REQ-STAG-004)

`Stagiair.bpvOvereenkomstOndertekend` and `EmploymentContract.bpvOvereenkomstOndertekend` SHALL be plain boolean properties that HR sets manually after the three-party (leerbedrijf/onderwijsinstelling/deelnemer) practijkleerovereenkomst is signed by whatever external means the parties use. This change SHALL NOT build or invoke a digital multi-party signing mechanism for the onderwijsinstelling contactpersoon or deelnemer signers.

#### Scenario: The field is a plain manual toggle
- **GIVEN** a `Stagiair` record with `bpvOvereenkomstOndertekend: false`
- **WHEN** HR updates the record after receiving the signed POK
- **THEN** setting `bpvOvereenkomstOndertekend: true` requires only an ordinary object update — no signing-request service, no docudesk call, and no external-party session is involved

#### Scenario: No signing request is ever created for a POK
- **GIVEN** this change's full diff
- **WHEN** it is searched for any call to `SigningService::createRequest()` scoped to `Stagiair` or a BPV context
- **THEN** none exists

### Requirement: The corpus SHALL flag a Stagiair or BBL placement that started without a signed BPV-overeenkomst (REQ-STAG-005)

`lib/Standards/rules/labour.json` SHALL gain `nl-bpv-overeenkomst-vereist` (domain `labour`, jurisdiction `NL`, framework `hr-stagiair`, severity `mandatory`, `machineCheckable: true`): a `Stagiair` whose `startDate` has passed and whose `bpvOvereenkomstOndertekend` is not `true`, OR an `EmploymentContract` with `type: bbl` whose `startDate` has passed and whose `bpvOvereenkomstOndertekend` is not `true`, SHALL be reported as a violation. A `Stagiair`/`EmploymentContract` whose `startDate` has not yet passed, or whose `bpvOvereenkomstOndertekend` is `true`, SHALL pass. An `EmploymentContract` with any `type` other than `bbl` SHALL pass vacuously regardless of its `bpvOvereenkomstOndertekend` value.

#### Scenario: An unsigned BPV past the start date is flagged
- **GIVEN** a `Stagiair` with `startDate` in the past and `bpvOvereenkomstOndertekend: false`
- **WHEN** `occ humaniq:rules:audit` runs
- **THEN** the record is reported with an `nl-bpv-overeenkomst-vereist` violation at severity `mandatory`

#### Scenario: A signed BPV passes
- **GIVEN** the same `Stagiair` with `bpvOvereenkomstOndertekend: true`
- **WHEN** the audit runs
- **THEN** no `nl-bpv-overeenkomst-vereist` violation is reported for that record

#### Scenario: A future-dated placement is not yet checked
- **GIVEN** a `Stagiair` with `startDate` 30 days from today and `bpvOvereenkomstOndertekend: false`
- **WHEN** the audit runs
- **THEN** no `nl-bpv-overeenkomst-vereist` violation is reported (the placement has not started)

#### Scenario: A non-bbl contract is never checked by this rule
- **GIVEN** a `permanent`-type `EmploymentContract` with `bpvOvereenkomstOndertekend` left null
- **WHEN** the audit runs
- **THEN** `nl-bpv-overeenkomst-vereist` does not evaluate it — the rule's `EmploymentContract` branch is guarded to `type: bbl` only

### Requirement: Stagiair and BBL surfaces SHALL live under the existing Medewerkers menu group, never a new top-level menu (REQ-STAG-006)

`src/manifest.json` SHALL expose `Stagiairs` (index) and `StagiairDetail` (data, `lifecycleActions` for `starten`/`afronden`/`stoppen`, related `Employee` via `begeleiderId`) as `SUB_PAGE`/`DETAIL_TAB` placements under the existing `Medewerkers` menu group. BBL-leerlingen SHALL require no new page — they SHALL be visible on the existing `EmploymentContracts`/`EmploymentContractDetail` pages via `type: bbl` like any other contract type. No new top-level menu SHALL be added.

#### Scenario: Stagiairs page is reachable under Medewerkers
- **GIVEN** the manifest after this change
- **WHEN** a user navigates to `Medewerkers`
- **THEN** a `Stagiairs` entry is present, and the top-level menu count is unchanged from ADR-001's frozen 9 (8 menus + Configuratie)

#### Scenario: A bbl contract appears on the existing contract pages
- **GIVEN** an `EmploymentContract` with `type: bbl`
- **WHEN** the `EmploymentContracts` index page is viewed
- **THEN** the record appears alongside `permanent`/`temporary`/`agency`/`minijob` contracts with no separate page required
