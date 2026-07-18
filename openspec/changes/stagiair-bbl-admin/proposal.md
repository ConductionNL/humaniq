---
kind: config
---

# Stagiair & BBL-leerling administratie (stageovereenkomst vs. arbeidsovereenkomst)

## Why

The 2026-05-23 draft `spec/stagiair-bbl-admin` designed a five-schema platform (`Stagiair`,
`BBLLeerling`, `PraktijkLeerOvereenkomst`, `SBBErkenning`, `SubsidieAanvraagPraktijkleren`) with a
nightly SBB-register sync job, an RVO subsidie-aanvraag submission/polling integration, an
automatic 25%/50%/75% evaluation scheduler and a three-party digital-signing flow — a parallel
onboarding platform, not a right-sized addition to the shipped engine. **Verified against HEAD
2026-07-17**, re-grounding the two real distinctions the draft correctly identified:

**Stagiair (HBO/WO/MBO-BOL, zonder dienstverband)** has no arbeidsovereenkomst and, in the
ordinary case, no loonheffing on the stagevergoeding. hrmq's `Employee`/`EmploymentContract`
schemas and `PayrollCalculator` model dienstverband-based loon exclusively (`grep -rn
"grossMonthlySalary\|hourlyWage" lib/Settings/register.d/hr-objects.json` — both are
`EmploymentContract`/`Employee` properties with no "no dienstverband" branch); there is currently
**no schema at all** for a person hrmq tracks who is not an employee. That is a genuine gap, not
an abstraction question.

**BBL-leerling (MBO-BBL)** has a *leerarbeidsovereenkomst* — a real arbeidsovereenkomst — and is,
fiscally, an ordinary employee: loon, loonheffing, premies and CAO-toepassing all apply exactly as
for any other employee (Belastingdienst, Handboek Loonheffingen, hoofdstuk 17 "Stagiairs",
https://www.belastingdienst.nl/wps/wcm/connect/bldcontentnl/belastingdienst/zakelijk/personeel_en_loon/personeel_in_dienst/stagiairs
— confirmed general position: a leerling met een arbeidsovereenkomst is loonbelastingplichtig
zoals iedere werknemer; `verified:false` on the exact BBL-staffel amounts per leerjaar, no
Belastingdienst/CAO source cited for those figures, see design.md). `EmploymentContract` already
has a `type` enum (`permanent`/`temporary`/`agency`/`minijob`, `hr-objects.json`) that is exactly
the right mechanism for this — it is missing a `bbl` value, and `caoSchaal` (the cao-library
schaal-resolution field, `EmploymentContract.caoSchaal` + `CaoRegistry::minMaandloonCents()`)
already exists to carry a leerjaar-scale reference once a sourced CAO defines one. A BBL-leerling
is a **contract-type variation on the existing engine**, exactly as the task brief suspected — not
a second employee entity.

The stagevergoeding tax/premium question (draft's own flagged risk) has no single universal
Belastingdienst threshold this change can respectably assert without a source; that uncertainty is
carried into the requirements as `verified:false` + `checkAgainst`, not resolved by invention (see
design.md D2).

The three-party POK/BPV-overeenkomst signing flow the draft designed (leerbedrijf +
onderwijsinstelling + deelnemer, all digitally signing) hits the exact limitation `offer-esign`
already documented and shipped around: docudesk's `SigningService::sign()` requires
`signer.userId === $user->getUID()` (offer-esign design.md, point 4) — **no external, non-NC-user
party can complete a signature through the shipped leaf**, and two of POK's three signers
(onderwijsinstelling contactpersoon, deelnemer) are never NC users in the common case. Building a
new signing mechanism for exactly the case the existing one already ruled out is out of scope here;
this change tracks the same fact `EmploymentContract.writtenContract` already tracks for an
ordinary contract — a boolean HR marks after paper/external signing happens, not an automated flow.

## What Changes

- **NEW fragment `lib/Settings/register.d/hr-stagiair.json`** — `Stagiair` schema: a person on a
  stageovereenkomst, explicitly **not** an `Employee`, **not** payroll-engine input, **not**
  CAO-scoped. Carries `onderwijsinstelling`, `opleiding`, `niveau` (`hbo`/`wo`/`mbo-bol`),
  `stagetype` (`snuffelstage`/`meeloopstage`/`afstudeerstage`), `startDate`/`endDate`,
  `begeleiderId` (`$ref` Employee, the internal supervisor), `stagevergoedingPerMaand` (nullable
  number), `bpvOvereenkomstOndertekend` (boolean, HR-entered), `verzekeringGeverifieerd` (boolean,
  HR-entered), a declarative `x-openregister-lifecycle` `aangemeld → lopend →
  afgerond`/`gestopt` (three plain transitions, no guard — no caller-role/session complication the
  way `avg-dsr`/`Loonbeslag` needed one), and the `administrationId` multi-administratie
  convention property every scoped schema carries.
- **`EmploymentContract` gains `bbl`** in its `type` enum (`hr-objects.json`,
  `permanent`/`temporary`/`agency`/`minijob` → `+bbl`) plus two nullable properties,
  `bpvOvereenkomstOndertekend` (boolean) and `bpvSchoolNaam` (string) — documented as the
  BBL-specific counterpart of `Stagiair.bpvOvereenkomstOndertekend`, reusing the contract record
  instead of a second entity because a BBL-leerling already has one. `EmploymentContract` version
  bumps.
- **NEW corpus rule `nl-bpv-overeenkomst-vereist`** (mandatory, domain `labour`, framework
  `hr-stagiair`) in the existing `lib/Standards/rules/labour.json`: a `Stagiair` whose `startDate`
  has passed with `bpvOvereenkomstOndertekend: false`, or a `bbl`-type `EmploymentContract` in the
  same state, is flagged — the exact shape `nl-onboarding-wid-check` already established for "a
  boolean gate that must be true by a date". NEW check provider `lib/Standards/Checks/
  NlStagiairChecks.php` (auto-discovered, no registration step, the `NlOnboardingChecks` pattern).
- **Manifest**: `Stagiairs` index + `StagiairDetail` pages under the existing `Medewerkers` menu
  group (ADR-001 already lists "stagiairs/BBL" as `Medewerkers` content — no new top-level menu, no
  ADR amendment needed), `lifecycleActions` for the three transitions, related `Employee`
  (`begeleiderId`) surface. BBL-leerlingen need no new page: they are `EmploymentContract` records
  with `type: bbl`, visible on the existing `EmploymentContracts`/`EmploymentContractDetail`
  pages exactly like any other contract type.
- **Seed data**: one `Stagiair` (lopend, BPV signed) and one `EmploymentContract` with `type: bbl`
  against an existing seeded `Employee`, plus one intended-violation `Stagiair` (`startDate` in the
  past, `bpvOvereenkomstOndertekend: false`) exercising `nl-bpv-overeenkomst-vereist`.

### Non-goals (named fast-follows and exclusions)

- **No SBB-erkenning register integration, no CREBO validation, no RVO Subsidieregeling
  Praktijkleren submission/polling** — all three are real external systems hrmq has zero existing
  integration surface for (no `openconnector`-mediated leaf exists in this codebase today); the
  draft's nightly-sync/API-submission jobs are a distinct integration-project scope, not a
  data-modeling gap. Named as future work, not silently dropped.
- **No automated 25%/50%/75% evaluation scheduling, no reminder tasks** — hrmq has no
  task-management capability to create them against; an HR-entered evaluation note is out of this
  MVP's schema (see design.md D5 — a fast-follow, not invented here).
- **No digital three-party POK signing** — the offer-esign precedent already rules this out for
  non-NC-user signers (Why, above). `bpvOvereenkomstOndertekend` is a plain HR-entered boolean.
- **No BBL-staffel payscale data** — adding a leerjaar-tier `payScales` entry to a sourced sector
  CAO (`lib/Standards/cao/<cao-id>.json`) is a data-only follow-up once a concrete CAO-tekst is
  sourced; this change does not fabricate BBL salary figures (design.md D2).
- **`nl-minimumloon-2026`/`nl-minimumuurloon-wet` stay age-unaware** — both hard-code the 21+ adult
  WML floor (`hourlyWage >= 14.71`, `NlPayrollChecks.php`) with no `dateOfBirth` branch; a
  genuinely-compliant lower minimumjeugdloon rate for a BBL-leerling under 21 would falsely
  violate. This is a **pre-existing corpus gap surfaced by this change, not fixed by it** — it is
  orthogonal to entity modeling and touches every contract type, not just `bbl` (design.md Risks).

## Capabilities

### New Capabilities

- `stagiair-bbl-admin`: the `Stagiair` schema and lifecycle, the `EmploymentContract` `bbl` type +
  BPV fields, the `nl-bpv-overeenkomst-vereist` corpus rule + `NlStagiairChecks` provider, and the
  `Medewerkers` manifest surface.

### Modified Capabilities

- `payroll-core-schema` — `EmploymentContract.type` enum gains `bbl`; no engine/calculator change,
  a `bbl` contract flows through `PayrollCalculator` exactly like `permanent`/`temporary` (design.md
  D1).

## Impact

- `lib/Settings/register.d/hr-stagiair.json` — NEW (`Stagiair` schema + lifecycle).
- `lib/Settings/register.d/hr-objects.json` — `EmploymentContract.type` enum +`bbl`;
  `+bpvOvereenkomstOndertekend`/`+bpvSchoolNaam`; version bump.
- `lib/Settings/hrmq_register.json` — `info.version` bump (new fragment).
- `lib/Standards/rules/labour.json` — 1 new rule, framework `hr-stagiair`; `RuleCatalogue::VERSION`
  bumps.
- `lib/Standards/Checks/NlStagiairChecks.php` — NEW (auto-discovered).
- `src/manifest.json` — `Stagiairs`/`StagiairDetail` pages under `Medewerkers`; `npm run
  check:manifest` passes.
- `lib/Settings/register.d/hr-seed.json` — 2 `Stagiair` seeds (1 compliant, 1 intended violation) +
  1 `bbl`-type `EmploymentContract` seed.
- `tests/Unit/Standards/Checks/NlStagiairChecksTest.php` — NEW.
- `README.md` — the BPV-signing boundary (HR-entered boolean, not an e-signature flow) and the
  SBB/RVO out-of-scope note.
- Related: the superseded `spec/stagiair-bbl-admin` draft branch is the source material; its
  SBB/RVO/scheduler/e-signature scope is recorded above as named future work, not silently
  dropped. `offer-esign` (archived) is the precedent this change reuses for the signing-boundary
  finding. `cao-library` (archived) owns the `caoSchaal` mechanism a future BBL-staffel data change
  would extend.
