# Design — stagiair-bbl-admin

## Context

**Verified against HEAD 2026-07-17.** Read read-only, directly grounding this design:

- `lib/Settings/register.d/hr-objects.json` — `EmploymentContract.type` is `enum:
  [permanent, temporary, agency, minijob]`, no `bbl`/`stage` value. `Employee` has no
  "not employed" flag and no field modelling a non-dienstverband relationship; `Employee.bsn` is
  `required` for payroll (`PayrollRunService.php:429` — `bsn`/`identityDocumentVerified` gate a run).
  There is no schema anywhere in `lib/Settings/register.d/` for a person who is not an `Employee`.
- `lib/Payroll/PayrollCalculator.php` and the jurisdiction-pack engine (`adr-101-jurisdiction-
  packs.md`) compute loon/loonheffing from `EmploymentContract`+`Employee` fields only — there is no
  branch for "no dienstverband, no loonheffing"; a `Stagiair` therefore structurally cannot and
  must not be an `EmploymentContract`.
- `lib/Standards/Checks/NlPayrollChecks.php:124-129` — `nl-minimumloon-2026` hard-codes `hourlyWage
  >= 14.71` (the 21+ WML floor) and `nl-minimumuurloon-wet` checks only that `hoursPerWeek > 0` and
  `hourlyWage` is numeric. Neither reads `Employee.dateOfBirth`. Confirmed by `grep -rn
  dateOfBirth lib/Standards/Checks/NlPayrollChecks.php` — no match.
- `openspec/specs/cao-library/spec.md` — `CaoRegistry::minMaandloonCents(caoId, schaal)` resolves a
  `payScales` entry keyed by `caoSchaal`; `EmploymentContract.cao`/`caoSchaal` are existing fields
  (`cao-library`, REQ-CAO-003 redefines `EmploymentContract.cao`, adds `caoSchaal`). Three MVP CAOs
  exist (`cao-generiek`, `cao-metaal-techniek`, `cao-horeca`); none currently defines a
  leerling/BBL-tier `payScales` entry (`grep -rn leerling lib/Standards/cao/*.json` — no match).
- `openspec/changes/archive/2026-07-15-offer-esign/design.md` point 4 — docudesk's
  `SigningService::sign()` requires `signer.userId === $user->getUID()`; offer-esign's own
  `signers` entries for a non-NC candidate carry `userId: ''` and the request "is real" but nothing
  claims the candidate can complete the click. This is the exact shape of POK's
  onderwijsinstelling-contactpersoon and deelnemer signers in the common case (neither is
  ordinarily an NC user of this hrmq instance).
- `openspec/specs/onboarding-wizard/spec.md` / `lib/Standards/Checks/NlOnboardingChecks.php` — the
  "boolean gate that must be true by a date, else violate" shape (`nl-onboarding-wid-check`) is the
  established, auto-discovered `CheckProvider` pattern this change reuses for
  `nl-bpv-overeenkomst-vereist`.
- `lib/Standards/rules/labour.json` + `SCHEMA.md` — new rules add a `framework` slug to the
  examples list and bump `RuleCatalogue::VERSION`; the `hr-signals`/`nl-arbeidstijdenwet` precedent.
- ADR-001 §Top-level navigation — menu 3 `Medewerkers` already names "stagiairs/BBL" as content;
  no new top-level menu, no ADR amendment required.
- `openspec/architecture/adr-101-jurisdiction-packs.md` — Decision 1: incidence is a step property;
  a `bbl` contract's loon flows through the NL pack exactly as any other contract's does (no pack
  change needed — the pack computes from `EmploymentContract`/`Employee` fields, not from `type`).

## Goals / Non-Goals

**Goals:** give hrmq a schema for a stagiair (a person tracked but explicitly outside
Employee/payroll); make BBL-leerling a `type` variation on the existing `EmploymentContract`, not a
parallel entity; track the BPV-overeenkomst signing fact honestly (HR-entered, not automated);
surface both in the existing `Medewerkers` menu group; add exactly one new machine-checkable rule.

**Non-Goals (binding, from the proposal):** SBB-erkenning/CREBO validation; RVO Subsidieregeling
Praktijkleren submission or polling; automated evaluation scheduling; digital three-party POK
signing; BBL-staffel payscale data; widening `nl-minimumloon-2026`/`nl-minimumuurloon-wet` to be
age-aware (named, not fixed, here).

## Decisions

### D1 — `Stagiair` is a standalone schema; `BBLLeerling` is not a schema at all, it is `EmploymentContract.type: bbl`

The draft modelled `Stagiair` and `BBLLeerling` as two parallel entities plus a shared POK entity.
Re-grounded against the engine: a stagiair has no arbeidsovereenkomst and must never reach
`PayrollCalculator` (Context) — it needs its own small schema, structurally separate from
`Employee`/`EmploymentContract` so nothing in the payroll path can ever pick it up by accident (no
`$ref` from `PayrollRun`/`Payslip`/`PayrollMutationReport` targets `Stagiair`, and none is added by
this change). A BBL-leerling, by contrast, already fits the exact shape `EmploymentContract` models
— hours, wage, CAO, written contract — and forcing it into a second entity (the draft's
`BBLLeerling` + a mandatory linked `Employee`) would duplicate every field `EmploymentContract`
already carries for the sole purpose of naming "this one is BBL". Adding one enum value is the
entire data-model change for BBL.

| Concept | Modelled as | Reaches PayrollCalculator? | New schema? |
|---|---|---|---|
| Stagiair (HBO/WO/MBO-BOL, geen dienstverband) | `Stagiair` (new) | No — structurally unreachable | Yes, small |
| BBL-leerling (MBO-BBL, leerarbeidsovereenkomst) | `EmploymentContract` with `type: bbl` | Yes, exactly like any contract | No |

### D2 — Stagevergoeding fiscal treatment: cited where confirmed, `verified:false` where not

The Belastingdienst's Handboek Loonheffingen (hoofdstuk 17, "Stagiairs") confirms the general
principle this design relies on: a stagiair **zonder dienstbetrekking** who receives a
stagevergoeding is, in the ordinary case the handbook describes, not automatically subject to
loonheffing the way an employee's loon is — but the handbook's actual test turns on concrete facts
(who pays the vergoeding, whether it is capped to daadwerkelijke onkosten, whether the stagiair
valt onder de studenten- en scholierenregeling) that this change cannot resolve in the abstract for
every seeded organisation. **No specific euro threshold is asserted by this change.**
`Stagiair.stagevergoedingPerMaand` is stored as a plain informational number; this change adds **no
machine-checkable rule asserting a stagevergoeding ceiling**, because doing so would require citing
a figure this design cannot verify. A future change sourcing the exact Belastingdienst
onkostenvergoeding threshold (with URL + effective date) can add
`nl-stagevergoeding-fiscale-grens` to the corpus using the same `{value, source, verified}` leaf
discipline `nl-2026.json` already establishes — `verified: false`, `checkAgainst:
"https://www.belastingdienst.nl/.../stagiairs"` in the interim.

The BBL side has a firmer citation: a BBL-leerling has a real arbeidsovereenkomst (a
*leerarbeidsovereenkomst*), and the Handboek Loonheffingen treats a leerling met een
arbeidsovereenkomst as an ordinary werknemer for loonheffing purposes — loon, loonheffing, premies
all apply. This is the fact D1's modelling choice rests on (a `bbl` `EmploymentContract` needs no
special-case exemption in `PayrollCalculator`). The *amount* of BBL-staffel salary per leerjaar is
sector-CAO data, not statute, and is out of scope here (proposal Non-goals; see D1 table and the
`caoSchaal` mechanism `cao-library` already ships).

### D3 — BPV-overeenkomst signing: a plain HR-entered boolean, not an e-signature flow

`Stagiair.bpvOvereenkomstOndertekend` and `EmploymentContract.bpvOvereenkomstOndertekend` mirror
`EmploymentContract.writtenContract` exactly — a fact HR marks true once the paper (or externally-
handled digital) three-party signature is complete. This is a deliberate, documented boundary, not
an oversight: `offer-esign`'s own design.md already proved digital multi-party signing through the
shipped docudesk leaf cannot complete for a non-NC-user signer (Context), and two of POK's three
signers (onderwijsinstelling contactpersoon, deelnemer) are not ordinarily NC users of this
instance. Building a second signing mechanism for exactly the case the first one already ruled out
would not fix the limitation — it would duplicate the attempt.

### D4 — `nl-bpv-overeenkomst-vereist`: one rule, two object types, the WID-check shape

`lib/Standards/Checks/NlStagiairChecks.php::checks()` registers the same predicate under two
object-type keys, `Stagiair` and `EmploymentContract` (the latter guarded `type === 'bbl'` so it is
vacuous for every other contract type):

```
(startDate has passed) AND (bpvOvereenkomstOndertekend !== true)  → violates
```

No cross-object context is needed (unlike `nl-onboarding-proeftijd-bewaking`, which reads
`context['related']`) — both fields live on the same object. This is the simplest predicate shape
in the corpus, deliberately: the rule exists to catch a specific, common operational failure (a
placement starts before the paperwork is done), not to model the full POK lifecycle.

### D5 — Evaluation tracking, SBB, RVO: named future work, not modelled

The draft's evaluatie_punten array, SBB nightly sync and RVO subsidie-aanvraag submission all
require infrastructure hrmq does not have today: a task-management capability to schedule
reminders against (none exists — `hr-signals`' reminders are corpus-rule advisories surfaced on
audit, not a scheduled task system), and an external-system integration leaf (`openconnector` is a
sibling app, not something any hrmq service currently calls; grounding this properly is its own
spec, not a field on `Stagiair`). Naming these here, rather than adding placeholder fields nothing
reads, keeps the schema honest about what it actually does.

### Declarative vs imperative (ADR-031)

| Behaviour | Path | Rationale |
|---|---|---|
| `Stagiair` record shape + lifecycle | declarative schema + `x-openregister-lifecycle` | plain three-state lifecycle, no guard needed (unlike `avg-dsr`/`Loonbeslag`'s caller-role+session cases) |
| `EmploymentContract.type: bbl` | declarative schema (enum value) | a contract-type variation the engine already handles generically |
| `nl-bpv-overeenkomst-vereist` predicate | imperative `CheckProvider` (`NlStagiairChecks`) | ADR-031's own corpus convention — machine-checkable rules are PHP predicates over declarative data, never a lifecycle guard |
| BPV signing fact | plain boolean field, HR-entered | not automatable through the shipped signing leaf (D3) |
| BBL payroll calculation | existing declarative jurisdiction pack | zero change — `type: bbl` is not a pack input at all (ADR-101 Decision 1) |
| Index/detail pages | declarative manifest | ADR-031 default |

## Seed Data (ADR-001)

Three seeds, all against existing seeded anchors:

1. **Compliant Stagiair**: `stagiair-devries` (`niveau: hbo`, `status: lopend`,
   `bpvOvereenkomstOndertekend: true`, `startDate` in the past) — passes `nl-bpv-overeenkomst-
   vereist` vacuously (condition already satisfied).
2. **Intended-violation Stagiair**: `stagiair-bakker` (`startDate` 2026-06-01, in the past relative
   to the seed anchor date, `bpvOvereenkomstOndertekend: false`) — the one deliberate corpus
   violation this change introduces, exercising `nl-bpv-overeenkomst-vereist`'s `Stagiair` branch.
3. **BBL `EmploymentContract`**: a new contract for the existing seeded `employee-jansen` (or
   another anchor with no conflicting active contract), `type: bbl`, `bpvOvereenkomstOndertekend:
   true`, `bpvSchoolNaam: "ROC Amsterdam"` — proves the `type` enum value round-trips through
   register import and that `PayrollCalculator`/the audit path treat it exactly like any other
   contract (no special-case branch fires, because none exists — D1).

Dev-container verification gate: `occ hrmq:rules:audit` after seed import reports exactly one new
violation (`stagiair-bakker` → `nl-bpv-overeenkomst-vereist`) and zero regressions on existing
rules; `stagiair-devries` and the `bbl` contract report clean.

## Risks / Trade-offs

- **`nl-minimumloon-2026`/`nl-minimumuurloon-wet` stay age-unaware** (Context) — a genuinely-
  compliant BBL-leerling under 21 paid a lower, legitimate minimumjeugdloon rate would falsely
  violate `nl-minimumloon-2026`'s hard-coded 21+ floor. This is a **pre-existing** corpus gap this
  change's grounding work surfaced, not one it introduces or fixes — it is orthogonal to entity
  modelling (it would affect a 20-year-old `permanent` contract identically) and is named here so
  it is not lost.
- **No stagevergoeding fiscal-ceiling rule** (D2) — the schema stores the figure but nothing
  machine-checks it against a threshold, because this design has no confirmed threshold to check
  against. Under- or over-informing HR is possible until a maintainer sources the exact
  Belastingdienst rule and adds `nl-stagevergoeding-fiscale-grens` with a real citation.
- **BPV signing status can be marked true without verification** (D3) — same trust boundary
  `EmploymentContract.writtenContract` already accepts; not a new risk this change introduces, but
  worth stating since POK is a compliance-bearing document.

## Open Questions

- None blocking. The stagevergoeding ceiling, BBL-staffel CAO data, SBB/RVO integration and
  evaluation scheduling are named fast-follows (Non-Goals), not open blockers for this change's own
  scope.
