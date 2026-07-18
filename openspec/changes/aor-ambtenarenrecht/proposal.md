---
kind: config
---

# AOR ambtenarenrecht — the two obligations that survived Wnra normalization

## Why

The *Wet normalisering rechtspositie ambtenaren* (Wnra, in force since 2020) moved almost every Dutch
civil servant off the old one-sided public-law *aanstelling* and onto an ordinary two-sided BW7
*arbeidsovereenkomst* — the same termination law (BW 7:669), the same statutory transitievergoeding
(BW 7:673), the same disciplinary mechanics (warning, suspension, dismissal via BW7) that any private
employer already faces. Verified against the current architecture first, as instructed: hrmq has no
"ambtenaar" concept anywhere today (schemas, corpus, or specs), and — critically — payroll-core-engine,
cao-library, and every existing labour-law rule already operate on the ordinary BW7
`EmploymentContract`/`Employee` schema with no ambtenaar branch. **That absence is correct, not a
gap**: for the ~95% of public-sector staff Wnra normalized, there is nothing ambtenaar-specific left
for hrmq to model — they are BW7 employees like any other, and the existing engine, CAO library, and
labour rules already cover them completely.

What genuinely still differs, and is the entire scope of this change, is narrow:

1. **A residual special-status regime.** Three sectors were deliberately *excluded* from Wnra and
   remain on the old public-law Ambtenarenwet 2017 footing: politie, defensie, and de rechterlijke
   macht (plus political office-holders). For these, "aanstelling" is not a historical remnant — it is
   the *current* legal basis, still in force today.
2. **The ambtseed.** Every ambtenaar — Wnra-normalized or not — must still take the ambtseed or
   -belofte (oath or affirmation of office) before exercising their function, per Ambtenarenwet 2017
   art. 5. Normalizing the employment *contract* did not remove this formality; it is the one thing
   that is still distinctly "ambtenaar" even for an ordinary BW7 civil servant.
3. **The nevenwerkzaamheden disclosure.** Every ambtenaar, under either regime, carries a standing
   integrity obligation to disclose side activities that could affect the proper performance of duty
   or public trust (Ambtenarenwet 2017 art. 9) — an obligation ordinary private-sector BW7 employees do
   not carry.

Everything else is either already covered by the generic BW7 machinery hrmq ships today, or requires a
case-management/workflow engine hrmq does not have and this change does not attempt to build.

The old draft branch `spec/aor-ambtenarenrecht` (May 2026) proposed a ten-feature legal
case-management platform: guided ontslagprocedure orchestration, a transitievergoeding calculator
(F-002 — not ambtenaar-specific at all; BW 7:673 is the *same* formula for every BW7 employee, Wnra or
not, so it belongs nowhere near an "ambtenaar" spec), an isolated klokkenluider/integriteitsmelding
case system with pseudonymised identities and retaliation-check automation, a tuchtbesluit workflow
for non-normalized sectors, a disciplinary-measures workflow, escalation to college B&W, CRvB-beroep
dossier bundling, a termijnbewaking SLA dashboard, confidentiality-tier access control, and automated
retention/destruction. None of that exists in hrmq today, and none of it is machinery this change
builds: hrmq has no case-management, no document-workflow orchestration, and no
external-tribunal-integration capability anywhere in the shipped architecture, and inventing one here
— for a feature set that is 90% "any Dutch employer's termination law," not "ambtenaar law" — would be
exactly the padding the honesty bar this task is run under forbids. See design.md "Named gaps" for the
explicit mapping from each old F-00x feature to why it is out of scope.

## What Changes

- **`Employee` schema** (`lib/Settings/register.d/hr-objects.json`) gains three fields, mirroring the
  existing `isDga` mode-switch precedent (ADR-001 Rule 4):
  - `publicSectorRegime` (nullable enum `genormaliseerd` \| `ambtenarenwet`, default null) — whether
    this employee is a public-sector ambtenaar and under which regime. `null` means an ordinary
    private-sector employee; neither of the two fields below applies.
  - `ambtseedAfgelegdOp` (date, nullable) — the date the ambtseed/-belofte was taken (Ambtenarenwet
    2017 art. 5). Only meaningful when `publicSectorRegime` is non-null.
  - `nevenwerkzaamhedenGemeld` (boolean, default `false`) — whether the nevenwerkzaamheden
    integrity-disclosure attestation is on file (Ambtenarenwet 2017 art. 9). Only meaningful when
    `publicSectorRegime` is non-null.
- **NEW corpus rules** in `lib/Standards/rules/labour.json` under a new framework slug
  `ambtenarenwet-2017` (added to `SCHEMA.md`'s framework examples, the `hr-signals` precedent):
  `nl-ambtenaar-eed-vereist` (mandatory, presence-only — the `gebruikelijkloonJustification` MVP
  precedent) and `nl-ambtenaar-nevenwerkzaamheden-melding` (mandatory). Both anchor on `Employee`.
  `RuleCatalogue::VERSION` bump.
- **NEW `lib/Standards/Checks/NlAorChecks.php`** (auto-discovered `CheckProvider`) registering both
  predicates, vacuous whenever `publicSectorRegime` is null.
- **Seed data**: one `genormaliseerd` and one `ambtenarenwet` employee with both fields satisfied
  (clean pass), and one `ambtenarenwet` employee missing the eed (proving the violation branch).

### Non-goals (named fast-follows and exclusions, mapped from the old draft's F-00x features)

- **F-001 Ontslagprocedure orchestration, F-006 Escalatie naar college, F-007 CRvB-beroep bundling,
  F-008 Termijnbewaking SLA dashboard** — all require a case-management/workflow-orchestration
  capability hrmq does not have. Ordinary BW7 termination law (already fully modelled generically)
  applies to genormaliseerde ambtenaren exactly as it does to any employee.
- **F-002 Transitievergoeding calculation** — BW 7:673 severance is identical for every BW7 employee,
  ambtenaar or not; it is not an ambtenaar-specific concern and does not belong in this change. A
  general severance calculator (for every employee, not just ambtenaren) is a separate, unrelated
  fast-follow if ever built.
- **F-003 Integriteitsmelding & klokkenluider case management** (pseudonymised identity, 7-year
  protection tracking, retaliation-check automation, external HvK export) — a real and important
  obligation (Wet bescherming klokkenluiders), but a case-management capability of its own scale; out
  of scope for this change, which only records the narrower, ongoing nevenwerkzaamheden disclosure.
- **F-004 Tuchtbesluit (non-normalized sectors), F-005 Disciplinaire maatregelen (genormaliseerd)** —
  workflow engines for hearings, reactietermijnen, and sanction escalation; genormaliseerde discipline
  is ordinary BW7 mechanics already covered generically, and formal tuchtrecht for the three
  non-normalized sectors is a named fast-follow requiring case-management machinery this change does
  not build.
- **F-009 Vertrouwelijkheid & toegangsbeheer, F-010 Bewaartermijnen & archivering** — confidentiality
  tiers and automated retention/destruction for the case types above; moot without the case-management
  capability those features assumed.
- **Auto-deriving `publicSectorRegime` from a sector/function taxonomy** — hrmq has no employer-sector
  or function-category schema today (the same gap `abp-aansluiting` names for its own admin-set flag);
  the field is HR-set, not derived.

## Capabilities

### New Capabilities

- `aor-ambtenarenrecht`: the `publicSectorRegime`/`ambtseedAfgelegdOp`/`nevenwerkzaamhedenGemeld`
  fields on `Employee` and the two machine-checkable rules enforcing the ambtseed and
  nevenwerkzaamheden-disclosure obligations that survived Wnra normalization.

### Modified Capabilities

<!-- none — this change adds a self-contained field set + corpus rules; no existing capability's
     behaviour changes -->

## Impact

- `lib/Settings/register.d/hr-objects.json` — `Employee` +3 fields.
- `lib/Standards/rules/labour.json` — +2 rules (new `ambtenarenwet-2017` framework slug);
  `lib/Standards/rules/SCHEMA.md` — framework examples list; `lib/Standards/RuleCatalogue.php` —
  `VERSION` bump.
- `lib/Standards/Checks/NlAorChecks.php` — NEW.
- `lib/Settings/register.d/hr-seed.json` — 3 new/adjusted `Employee` seeds.
- `tests/Unit/Standards/NlAorChecksTest.php` — NEW.
- No dependency on any other in-flight change; reuses only the shipped `Employee` schema, the
  `CheckProvider` auto-discovery, and the `RuleCatalogue`/labour-corpus mechanism.

## Sources

- Wet normalisering rechtspositie ambtenaren (Wnra), <https://wetten.overheid.nl/BWBR0039393> —
  the 2020 normalization to BW7 for the vast majority of ambtenaren.
- Ambtenarenwet 2017, art. 5 (eed/belofte) and art. 9 (nevenwerkzaamheden),
  <https://wetten.overheid.nl/BWBR0001947> — the two obligations this change models, both still in
  force for every ambtenaar regardless of Wnra-normalization status.
