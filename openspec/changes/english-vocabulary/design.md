## Context

hrmq is where the fleet's statutory rule gets its real test. Scan: **16 schemas / 25
Dutch properties**, **2 files / 2 classes / 9 methods**. Almost none of it is casual
Dutch — it is Dutch employment, payroll and social-security law: `loonbeslag`,
`transitievergoeding`, `wkrEindheffing`, `loonaangifte`, `uwv42WeekMelding`,
`loondoorbetaling`, `aanzegtermijn`, `dertigProcentRegeling`, `Normfunctie`, `BHV`.

hrmq is also a **cross-app consumer**: shillinq reads these properties. A rename here is
not app-local.

⚠️ Unlike openbuild and opencatalogi, hrmq's `title` fields are **also Dutch**
(`Aanschafdatum`, `Uitgiftedatum`, `Cataloguswaarde`). There is no English intent already
recorded in the schema to copy. Every name in this change is a genuine translation
decision, which makes it the highest-judgement change in the programme despite its
modest size.

## Goals / Non-Goals

**Goals:**

- Apply the ratified rule for statutory concepts: **English name plus a statute marker**,
  never Dutch preserved on the grounds of being legal.
- Distinguish the genuinely NL-specific concepts from those with ordinary international
  counterparts, and abstract the latter.
- Coordinate with shillinq, which consumes these properties.

**Non-Goals:**

- Preserving Dutch because a term is "the standardised term". The ratified policy
  explicitly overrules that exemption for statutory concepts.
- Renaming statutory *wire* field names inside a filing adapter — the payload sent to
  the Belastingdienst or UWV keeps its published field names.
- Changing any calculation, rate or threshold. This change renames; it does not touch
  a single number.

## Decisions

### 1. Three categories, not two

The fleet policy's binary (ours / wire) is too coarse for hrmq. Three apply:

**(a) Ordinary concepts that merely happen to be written in Dutch** — abstract them
outright, no marker:

| Dutch | English |
|---|---|
| `aanschafdatum` / `aanschafwaarde` | `purchaseDate` / `purchaseValue` |
| `uitgifteDatum` / `innameDatum` | `issuedDate` / `returnedDate` |
| `cataloguswaarde` | `listPrice` |
| `eigenBijdrage` | `employeeContribution` |
| `certificaatGeldigTot` | `certificateValidUntil` |
| `naam` | `name` |
| `titel` / `toelichting` | `title` / `notes` |
| `niveau` | `level` |
| `onderwijsinstelling` | `educationalInstitution` |
| `dossierRef` | `caseRef` |
| `Stagiair` | `Intern` |
| `toegangIngetrokken` | `accessRevoked` |

**(b) Concepts with a genuine international counterpart** — abstract to the
international term, marker optional:

| Dutch | English | why |
|---|---|---|
| `Loonbeslag` | `WageGarnishment` | wage garnishment exists in every jurisdiction |
| `betalingskenmerk` / `aanleverkenmerk` | `paymentReference` / `submissionReference` | ordinary filing references |
| `bpvSchoolNaam` | `vocationalSchoolName` | vocational placement is not NL-only |
| `stagevergoedingPerMaand` | `monthlyInternshipAllowance` | |
| `loondoorbetalingPercentage` | `sickPayContinuationPercentage` | statutory sick pay is near-universal |
| `BhvCertificering` | `EmergencyResponseCertification` | workplace first-aid duty is EU-wide |

**(c) Genuinely NL-specific statutory constructs** — English name **plus** a statute
marker recording jurisdiction and instrument:

| Dutch | English | statutory basis |
|---|---|---|
| `transitievergoedingBedrag` | `statutorySeveranceAmount` | NL, WWZ |
| `wkrEindheffingRate` / `eindheffingRate` / `eindheffingDue` | `finalLevyRate` / `finalLevyDue` | NL, WKR (werkkostenregeling) |
| `uwv42WeekMeldingDue` / `…Done` | `sicknessReportDue` / `…Done` | NL, Wet verbetering poortwachter, 42-week report |
| `Normfunctie` | `JobProfile` | NL, HR21 job-grading system |
| `dertigProcentRegeling` | `expatTaxRuling` | NL, 30% facility |
| `aanzegtermijn` | `contractEndNoticePeriod` | NL, WWZ |
| `vervaltermijn` | `leaveForfeitPeriod` | NL, BW 7:640a |

**Decision:** the statute marker carries the jurisdiction and the instrument, so the
NL-specific meaning is not lost — it is recorded as data rather than smuggled into an
identifier where only Dutch speakers can read it.

### 2. `uwv` → `socialSecurityAgency`, but only where it means the institution

The ratified abstraction dictionary maps `uwv` → `socialSecurityAgency`. That is right
where UWV appears as the *counterparty* — a filing destination, a notifying authority.
It is wrong where `uwv42WeekMelding` names a specific statutory milestone; there the
concept is "the 42-week sickness report", and the agency is incidental.

**Decision:** `uwv42WeekMeldingDue` → `sicknessReportDue` with the statute marker, not
`socialSecurityAgency42WeekNotificationDue`. Abstraction that produces an unreadable
identifier has failed at the thing it was for.

### 3. The filing payload keeps its published field names

`LoonaangifteFiling` and `PensionFiling` submit to the Belastingdienst and to pension
administrators using published message formats. `betalingskenmerk` and
`aanleverkenmerk` are our *schema* properties and are renamed; the field names in the
**submitted payload** are not.

**Decision:** rename the schema property, map to the published wire name at the adapter
boundary. Same key-versus-wire split as openconnector.

### 4. shillinq is a consumer and moves in the same window

shillinq reads hrmq properties. Which ones has not been enumerated — only the fact of
the dependency is recorded. Every consumer reads with a null-coalescing default, so a
desynchronised property yields `null` and shillinq's suite stays green while the value
is permanently absent.

**Decision:** enumerate shillinq's reads of hrmq properties **before** renaming, and land
both sides in one window. This enumeration does not yet exist and is the first task.

## Risks / Trade-offs

- **A rename desynchronises shillinq silently** → payroll values read as `null`, no error
  anywhere. Mitigated by enumerating the reads first and landing together.
- **Abstraction loses a legally-significant distinction** → `transitievergoeding` is not
  generic severance; it is a specific statutory entitlement with its own calculation.
  Mitigated by the statute marker, which keeps the specificity as machine-readable data.
- **Over-abstraction produces unreadable names** → mitigated by decision 2: readability
  beats mechanical application of the dictionary.
- **A rename is mistaken for a calculation change** → mitigated by an explicit non-goal
  and by requiring payroll regression output to be byte-identical.
- **A renamed property breaks a filing payload** → mitigated by the adapter-boundary
  mapping, and by testing an actual filing rather than only the schema.

## Migration Plan

1. Enumerate shillinq's reads of hrmq properties. Nothing else starts before this.
2. Apply category (a) renames — pure abstraction, no markers, lowest risk.
3. Apply category (b) renames.
4. Apply category (c) renames **with** statute markers.
5. Add the adapter-boundary mapping for filing payloads.
6. Rename the 2 classes and 9 methods (`NlVerzuimChecks`, `NlDossierRetentionChecks`,
   `NlReiskostenChecks` and their satisfied-predicates).
7. `l10n/nl.json` — the Dutch titles move here, so Dutch HR users see no change.
8. Land with shillinq in one window.

**Rollback:** steps 2–7 revert cleanly per app. Step 8 is a two-repo window and reverts
only as a pair.

## Open Questions

- Exactly which hrmq properties does shillinq read? Unmeasured. This is the gating
  question and the reason step 1 exists.
- Do `LoonaangifteFiling` / `PensionFiling` submit live to an external endpoint in any
  environment, or are they currently local-only? If live, the adapter-boundary mapping
  needs testing against a real submission rather than a fixture.
- Does `Normfunctie` → `JobProfile` collide with an existing hrmq `JobProfile` concept?
  Not checked.
