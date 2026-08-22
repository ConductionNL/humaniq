# Design — uitzend-flexpool

## Context

**Verified against HEAD 2026-07-17.** Read read-only, directly grounding this design:

- `lib/Settings/register.d/hr-objects.json` — `EmploymentContract.type` enum already includes
  `agency`. `grep -rn "'agency'\|\"agency\"" lib/` outside `hr-objects.json` returns exactly one
  line: `EuUsPayrollChecks.php:161`, a German (`de-mindestlohn-arbeitszeit-doku`) exemption, no NL
  logic at all.
- `grep -rli "inlener\|uitzend\|flexpool\|fasensysteem\|ABU\|NBBU" lib/ openspec/specs/` initially
  returns many hits, but every one is a false-positive substring match (`Vocabulary` contains
  "abu", etc.) — verified by grepping the literal terms directly, zero real matches. This is
  genuinely greenfield except for the `agency` enum value itself.
- `openspec/specs/multi-administratie/spec.md` — no "employer entity separate from the
  administratie" concept exists; an `Employee`/`EmploymentContract` belongs to exactly one
  `administrationId` (the running instance's own administratie). This confirms humaniq's data model
  already assumes "the administratie running this instance IS the employer" — consistent with
  serving the agency, not a third party the agency serves.
- `openspec/specs/rostering/spec.md` and `openspec/specs/time-attendance/spec.md` — neither
  document mentions `contractType`/`agency`; `hr-roster.json` (`Shift`/`Roster`/`RosterAssignment`)
  and `hr-attendance.json` (`AttendanceRecord`) key exclusively on `employeeId`. Confirmed
  contract-type-agnostic by reading both fragments' schemas directly.
- `openspec/specs/cao-library/spec.md` + `lib/Standards/Checks/NlCaoChecks.php:178-206` —
  `minimumloonSchaalSatisfied()` (the `nl-cao-minimumloon-schaal` predicate) is gated only on
  `EmploymentContract.cao`/`caoSchaal` being present and `CaoRegistry::minMaandloonCents()`
  resolving a non-null, non-placeholder figure — no `type` check anywhere in the predicate. A new
  CAO id with real (non-placeholder) `payScales` data would activate this check for an `agency`
  contract with zero PHP change.
- `lib/Standards/cao/cao-metaal-techniek.json` — the placeholder-CAO shape this change's
  `cao-abu.json` copies: `{value, source, verified: false, placeholder: true, checkAgainst}` on
  every leaf, `basedOn` naming the real CAO-tekst source.
- BW art. 7:691 lid 2 (Burgerlijk Wetboek, uitzendovereenkomst) — the statutory basis for the
  uitzendbeding: during a period the article and the applicable CAO define, a beding may provide
  that the uitzendovereenkomst ends by operation of law when the inlener ends the terinbeschikking-
  stelling. The **statutory default is 26 weken** without a CAO extending it; ABU/NBBU CAOs
  historically extend this via a fasensysteem (fase A). This change cites the statutory basis with
  confidence; it does **not** assert the current post-WAB (2020) ABU/NBBU fase A duration in
  weeks, because that figure has changed over successive CAO texts and this design has no current
  citation it can stand behind (D2).
- WAADI (Wet allocatie arbeidskrachten door intermediairs) art. 8 — the inlenersbeloning duty: the
  uitzendbureau must pay at least the beloning a comparable werknemer in an equivalent function at
  the inlener would receive. This change cites the duty's existence with confidence; it does not
  model the specific multi-element test used to determine the inlenersbeloning figure (D3).
- `lib/Settings/register.d/hr-loonbeslag.json` (`Loonbeslag.beslagvrijeVoet`) — the precedent for a
  trusted, HR-entered sensitive figure with no computed derivation; `inlenersbeloningReferentie`
  follows the same trust boundary.

## Goals / Non-Goals

**Goals:** commit humaniq to modelling the uitzendkracht as the agency's own `Employee` on an
`agency`-type `EmploymentContract`; add the smallest set of fields and rules that make that
commitment real (fasensysteem stage, uitzendbeding applicability, inlenersbeloning reference);
reuse the existing CAO mechanism for ABU/NBBU wage data instead of inventing a parallel one;
confirm (not rebuild) the rostering/time-attendance overlap.

**Non-Goals (binding, from the proposal):** any inlener-side entity or workflow
(`InhuurOpdracht`/`Bureau`/SNA-keurmerk/G-rekening/ketenaansprakelijkheid/invoice-matching/TCO
dashboard); exact fasensysteem week-thresholds; sourced ABU/NBBU loontabel figures; a structured
multi-element inlenersbeloning test.

## Decisions

### D1 — humaniq serves the uitzendbureau; the uitzendkracht is a real Employee, not a parallel entity

The draft's central design choice — "Inhuur is geen Employee" (REQ-UZI-001) — is correct **for an
inlener**, but humaniq is not built as an inlener's tool: it has no capability today that manages a
relationship with an external vendor/bureau, and its entire product surface (payroll, CAO, leave,
onboarding) assumes the person is this administratie's own employee. Re-pointing humaniq at the
inlener side would mean building `InhuurOpdracht`/`Bureau` as humaniq's *first* capability with zero
connection to `PayrollCalculator` — a structurally different product. Pointing it at the agency
side means one existing enum value (`agency`) plus three additive fields complete the picture,
because everything else (payroll, CAO resolution, rostering, time-attendance) already treats an
`EmploymentContract` generically regardless of `type`.

| Side | What it would need | Fits humaniq's shipped shape? |
|---|---|---|
| Inlener (hirer) | New `Bureau`/`InhuurOpdracht` entities, vendor-risk checks, zero payroll link | No — a parallel, disconnected capability |
| Uitzendbureau (agency) — **chosen** | 3 fields on the existing `EmploymentContract`, 2 rules, 1 CAO data file | Yes — extends the existing engine |

### D2 — Uitzendbeding: cite the statutory default with confidence, mark the CAO-extended duration unverified

BW 7:691 lid 2's 26-weken statutory default for the uitzendbeding is a stable, citable fact
(Context). The ABU/NBBU fasensysteem's actual fase A duration has been amended by successive CAO
texts (most notably around the 2020 Wet arbeidsmarkt in balans), and this design does not have a
confirmed current figure to assert. `uitzendFase` is therefore **HR-entered**, not derived from a
week-counting calculation this change would otherwise need to get right — the same posture
`stagiair-bbl-admin` D2 takes for the stagevergoeding ceiling: cite what is confirmed, mark what
is not, invent nothing. The one thing this change **does** assert with confidence is the
*structural* relationship: `uitzendbedingVanToepassing: true` is only ever legally sound while
`uitzendFase: A` (whatever that stage's exact duration is under the currently-applicable CAO) —
that relationship holds regardless of the disputed week-count, which is why
`nl-uitzendbeding-alleen-fase-a` checks the *relationship*, not a *duration*.

### D3 — Inlenersbeloning: a presence gate, not a computed figure

WAADI art. 8's duty is well-established; the specific procedure ABU/NBBU CAOs and Belastingdienst
guidance use to *determine* the inlenersbeloning figure (base wage, periodieken, toeslagen, ADV-
compensatie, kostenvergoedingen — the draft's "6-element" test) is not something this design can
verify precisely enough to encode as a machine-checkable computation. `inlenersbeloningReferentie`
is a free-text field HR populates with whatever documentation (client's loonschaal reference, a
signed onderbouwing) backs the `hourlyWage` set on the contract — `nl-inlenersbeloning-
onderbouwing-vereist` checks only that the field is **non-empty** when `hourlyWage` is populated on
an `agency` contract, mirroring the `nl-onboarding-wid-check` boolean-gate shape rather than
attempting to validate the figure's correctness.

### D4 — ABU CAO ships as placeholder data through the existing mechanism, not a new one

`cao-library`'s `CaoRegistry`/`NlCaoChecks` already resolve any CAO id present in
`lib/Standards/cao/*.json` against any `EmploymentContract.cao`/`caoSchaal`, with no `type` gate
(Context). Adding `cao-abu.json` in the exact `cao-metaal-techniek` shape — placeholder-marked,
sourced `basedOn` a real URL, `verified: false` on every leaf — activates `nl-cao-minimumloon-
schaal` for agency contracts the moment a contract sets `cao: "cao-abu"` + a `caoSchaal`, without
touching `CaoRegistry.php` or `NlCaoChecks.php`. This is the same "possibly pack/CAO data, not a
new architecture" shape the task brief anticipated.

### D5 — Rostering and time-attendance: confirmed already-delivered, not re-verified by new code

Both capabilities are `employeeId`-scoped with no `type` branch (Context). An agency-contract
`Employee` already schedules and clocks in identically to a permanent one. This change adds no
test, no manifest change, and no service change to either capability — the overlap is real and
already closed, and claiming otherwise (e.g. adding a redundant "agency scheduling" flag) would be
manufacturing work against a gap that does not exist.

### Declarative vs imperative (ADR-031)

| Behaviour | Path | Rationale |
|---|---|---|
| `uitzendFase`/`uitzendbedingVanToepassing`/`inlenersbeloningReferentie` | declarative schema properties | plain HR-entered facts, no computation |
| `nl-uitzendbeding-alleen-fase-a` predicate | imperative `CheckProvider` | ADR-031's corpus convention — a structural relationship between two fields, not expressible as a schema constraint |
| `nl-inlenersbeloning-onderbouwing-vereist` predicate | imperative `CheckProvider` | the WID-check boolean-gate shape |
| ABU CAO wage resolution | existing declarative `CaoRegistry` + `nl-cao-minimumloon-schaal` | zero new code (D4) |
| Rostering / time-attendance | unchanged, existing declarative pages | already contract-type-agnostic (D5) |

## Seed Data (ADR-001)

Two `EmploymentContract` seeds against a new or existing agency-context employee anchor:

1. **Compliant**: `type: agency`, `uitzendFase: A`, `uitzendbedingVanToepassing: true`,
   `cao: "cao-abu"`, `caoSchaal` set, `hourlyWage` populated, `inlenersbeloningReferentie`
   populated — passes both new rules (structural relationship holds; onderbouwing present) and
   `nl-cao-minimumloon-schaal` passes vacuously (placeholder CAO figure, per `CaoRegistry`'s own
   unverified-figure degradation, `cao-library` REQ-CAO-003).
2. **Intended violation**: `type: agency`, `uitzendFase: B`, `uitzendbedingVanToepassing: true` —
   violates `nl-uitzendbeding-alleen-fase-a` (the beding cannot legally apply once past fase A).

Dev-container verification gate: `occ humaniq:rules:audit` reports exactly one new violation (the
fase-B/uitzendbeding-true seed → `nl-uitzendbeding-alleen-fase-a`) and zero regressions.

## Risks / Trade-offs

- **`uitzendFase` is self-reported, not derived** — nothing in this change counts worked weeks to
  validate an HR-entered fase; a stale value is possible. Named, not silently assumed correct
  (mirrors D3's inlenersbeloning trust boundary).
- **`cao-abu.json` ships with zero real figures** (D4) — `nl-cao-minimumloon-schaal` passes
  vacuously for every agency contract until a maintainer sources and transcribes the actual ABU/NBBU
  loontabel; this change proves the wiring, not the compliance value, until that follow-up lands.
- **The 26-week statutory default (D2) is not itself machine-checked** — this change checks the
  fase/beding *relationship*, not whether a fase-A contract has exceeded 26 weken (or whatever the
  applicable CAO extension is) without transitioning; that would require a week-counting job this
  change does not build (a `hr-signals`-shaped fast-follow, named not built).

## Open Questions

- None blocking. The exact fase-A duration, the real ABU/NBBU loontabel, and a week-counting
  fase-transition signal are named fast-follows (Non-Goals), not open blockers for this change.
