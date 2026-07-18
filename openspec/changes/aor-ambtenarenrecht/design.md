# Design — aor-ambtenarenrecht

## Context

**Verified against HEAD 2026-07-18.** hrmq's `Employee`/`EmploymentContract` schemas
(`lib/Settings/register.d/hr-objects.json`) carry no ambtenaar concept today — every existing
labour-law and payroll rule (BW 7:668 aanzegtermijn, BW 7:673-shaped severance logic where it
exists, the CAO library, the `nl-loonheffingen-volksverzekeringen` engine chain) already operates on
the ordinary BW7 employee shape with no special-status branch. This is not an oversight to correct;
it is the accurate state of the law post-Wnra for the large majority of Dutch civil servants.

Two existing precedents this change follows directly:

- **`Employee.isDga`** (dga-payroll-mode) — a plain boolean mode-switch on the `Employee` record
  (ADR-001 Rule 4: "mode-switches, never a separate app/schema") that gates a small set of
  additional, otherwise-inert fields (`gebruikelijkloonJustification`) and a presence-only MVP check
  (`nl-gebruikelijkloon-norm`). `publicSectorRegime` is the same shape: a mode flag gating two
  otherwise-inert fields and two presence-only checks.
- **`hr-signals`** (archived 2026-07-13) — introduced a brand-new framework slug (`hr-signals`) into
  `lib/Standards/rules/labour.json` for a genuinely new compliance concern with no prior corpus home,
  documented the addition in `SCHEMA.md`'s framework-examples list, and used a presence/date-window
  predicate reading only the object itself (no cross-object context). `ambtenarenwet-2017` follows the
  identical shape.

## Goals / Non-Goals

**Goals:** record which employees are ambtenaren and under which of the two live legal regimes;
enforce the two obligations that genuinely survive Wnra normalization for every ambtenaar (the
ambtseed and the nevenwerkzaamheden disclosure) as machine-checkable, presence-only corpus rules.

**Non-Goals (from the proposal, binding):** any case-management, workflow-orchestration, or
external-tribunal-integration capability — ontslagprocedure orchestration, transitievergoeding
calculation (not ambtenaar-specific at all), integriteitsmelding/klokkenluider case management,
tuchtbesluit and disciplinaire-maatregelen workflows, escalation to college B&W, CRvB-beroep
bundling, a termijnbewaking SLA dashboard, confidentiality-tier access control, and automated
retention/destruction. Auto-deriving `publicSectorRegime` from a sector/function taxonomy that does
not exist in hrmq today.

## Decisions

### D1 — `publicSectorRegime` is a two-value mode flag, not a resurrected `aanstelling` schema

The old draft's design assumed a parallel `aanstelling`-shaped world running alongside the BW7
`arbeidsovereenkomst`. Post-Wnra that is wrong for ~95% of ambtenaren: `genormaliseerd` employees ARE
ordinary `EmploymentContract` rows — same `type` enum, same Awf tariff logic, same CAO reference,
nothing about the contract shape changes. The flag exists solely to scope the two obligations below;
it deliberately does **not** introduce a parallel contract type, a `aanstellingsbesluit` schema, or
any BW7-shaped field duplication. For the three sectors still legally on Ambtenarenwet 2017
footing (`ambtenarenwet`), this MVP does not model their genuinely different substantive law (formal
tuchtrecht, appointment-decision mechanics) — it only flags that they exist, which is itself useful
(D3) and honest about not modelling more than that.

### D2 — Both new obligations are presence-only checks, following the `gebruikelijkloonJustification` MVP precedent

Neither `nl-ambtenaar-eed-vereist` nor `nl-ambtenaar-nevenwerkzaamheden-melding` validates the
*content* of what was recorded (that the eed was administered by an authorized person; that a
nevenwerkzaamheden disclosure accurately lists every side activity) — both are presence checks,
exactly like `nl-gebruikelijkloon-norm`'s "presence-only exemption... content is not validated (named
follow-up)" precedent for DGA mode. This is an honest MVP boundary: the two statutory obligations are
procedural facts an HR system can attest were recorded, not judgement calls a machine can verify.

### D3 — Both rules are new, in the existing labour corpus, under a new framework slug

`ambtenarenwet-2017` is added to `lib/Standards/rules/labour.json` (the `hr-signals` framework-slug
precedent) rather than folding into `bw7-10` (that framework already means "the ordinary BW7
employment-law corpus"; Ambtenarenwet obligations are a distinct statutory source that happens to sit
alongside BW7 for the same employee). Both rules anchor on `Employee` (where the three new fields
live) and are vacuous whenever `publicSectorRegime` is null — the overwhelming majority of hrmq's
seeded and real-world employee population, for whom these rules must never fire.

### D4 — This is deliberately smaller than the old draft's F-00x feature list, and says so

The old draft (`spec/aor-ambtenarenrecht`, May 2026) is an idea source describing a ten-feature legal
case-management platform. Named mapping from old feature to this change's disposition:

| Old draft feature | Disposition |
|---|---|
| F-001 Ontslagprocedure orchestration | Out of scope — ordinary BW7 termination law, already generic; no case-management engine exists |
| F-002 Transitievergoeding calculation | Out of scope — BW 7:673 is identical for every BW7 employee; not ambtenaar-specific, does not belong here |
| F-003 Integriteitsmelding & klokkenluider | Out of scope — real obligation (Wet bescherming klokkenluiders), but a case-management capability of its own; this change only records the narrower ongoing nevenwerkzaamheden disclosure |
| F-004 Tuchtbesluit (non-normalized sectors) | Out of scope — workflow engine for hearings/sanctions; `publicSectorRegime: ambtenarenwet` flags the population, nothing more |
| F-005 Disciplinaire maatregelen (genormaliseerd) | Out of scope — ordinary BW7 mechanics already covered generically |
| F-006 Escalatie naar college B&W | Out of scope — no case-management/agenda-integration capability |
| F-007 Beroep bij CRvB | Out of scope — no external-tribunal dossier-bundling capability |
| F-008 Termijnbewaking SLA dashboard | Out of scope — no case-management deadlines exist to bewaken; `hr-signals`'s existing termijn pattern (aanzegtermijn) is the closest precedent and is unrelated |
| F-009 Vertrouwelijkheid & toegangsbeheer | Out of scope — moot without the case types F-003/F-004/F-006/F-007 assumed |
| F-010 Bewaartermijnen & archivering | Out of scope — moot without the case types those retention schedules were for |

### Declarative vs imperative (ADR-031)

| Behaviour | Path | Rationale |
|---|---|---|
| `publicSectorRegime` / `ambtseedAfgelegdOp` / `nevenwerkzaamhedenGemeld` | **register.d** (`Employee`) | per-employee HR-set facts — OpenRegister's job, the `isDga` mode-switch precedent |
| `nl-ambtenaar-eed-vereist` / `nl-ambtenaar-nevenwerkzaamheden-melding` rule statements | **corpus data** `lib/Standards/rules/labour.json` | universal statutory facts (Ambtenarenwet 2017), identical for every tenant |
| The two predicates | imperative `NlAorChecks` (auto-discovered `CheckProvider`) | simple presence checks over one object — no cross-object context needed, the `hr-signals` shape |

## Seed Data (ADR-001)

- One `Employee` with `publicSectorRegime: "genormaliseerd"`, `ambtseedAfgelegdOp` set, and
  `nevenwerkzaamhedenGemeld: true` — a Wnra-normalized municipal employee, both checks pass.
- One `Employee` with `publicSectorRegime: "ambtenarenwet"`, both fields satisfied — a police-sector
  employee, both checks pass, proving the special-status regime also passes when properly recorded.
- One `Employee` with `publicSectorRegime: "ambtenarenwet"` and `ambtseedAfgelegdOp: null` — proving
  `nl-ambtenaar-eed-vereist` reports a violation while unrelated rules (CAO, payroll, aanzegtermijn)
  stay unaffected.
- Every pre-existing seeded `Employee` keeps `publicSectorRegime` unset (null) — both new rules must
  report zero violations against the entire pre-existing seed population.

## Risks / Trade-offs

- **Presence-only checks cannot catch a falsely-attested disclosure.** By design (D2) — the same
  honest limitation `nl-gebruikelijkloon-norm` already accepts for DGA mode. A machine cannot verify
  the content of a nevenwerkzaamheden disclosure or that an oath ceremony genuinely occurred; it can
  only verify the organisation recorded that it did.
- **The `ambtenarenwet` regime is flagged but not modelled.** Its genuinely different substantive law
  (formal tuchtrecht, appointment mechanics) is out of scope; the flag's only present value is
  identifying the population for future, explicitly-scoped fast-follows.
- **`publicSectorRegime` can be set wrong or left unset.** HR-set, not derived (D1) — the same
  trade-off `abp-aansluiting`'s `abpAansluitingsplichtig` accepts for the identical reason (no
  employer-sector taxonomy exists yet).

## Open Questions

- None blocking. Deriving `publicSectorRegime` from a sector/function taxonomy, and any of the ten
  named-out-of-scope features, are fast-follows blocked on capabilities (case-management, a
  sector/function taxonomy) that do not exist in hrmq today.
