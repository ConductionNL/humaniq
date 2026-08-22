---
kind: config
---

# Uitzendkrachten & flexpool — humaniq serves the uitzendbureau, not the inlener

## Why

The 2026-05-23 draft `spec/uitzend-flexpool-integration` designed humaniq as the **inlener's** tool: a
new `InhuurOpdracht`/`Bureau` entity pair explicitly kept out of `Employee` ("Inhuur is geen
Employee", REQ-UZI-001), validating a third-party bureau's SNA-keurmerk before allowing a booking,
tracking G-rekening/ketenaansprakelijkheid risk, and matching monthly invoices. **That is the wrong
side for humaniq.** humaniq's entire shipped product is a payroll engine (`payroll-core-engine`,
`jurisdiction-packs`) that computes loon/loonheffing/premies for people **on its own payroll** — an
inlener, by definition, has no payroll obligation toward an uitzendkracht at all (WAADI: the
uitzendbureau is the werkgever). Building `InhuurOpdracht`/`Bureau` would add humaniq's first
capability with **zero connection to `PayrollCalculator`**, mirroring `Stagiair` in
`stagiair-bbl-admin` but for the wrong reason — there, "structurally outside payroll" is correct
because a stagiair genuinely has no dienstverband; here, the person **does** have a dienstverband,
with the agency, and humaniq already almost models it.

**Verified against HEAD 2026-07-17**: `EmploymentContract.type` (`hr-objects.json`) already carries
the enum value `agency` — added at some point in the schema's history but with **exactly one
consuming line in the entire codebase** (`EuUsPayrollChecks.php:161`, which merely exempts
`agency`/`minijob` contracts from a *German* `de-mindestlohn-arbeitszeit-doku` check; irrelevant to
NL). No ABU/NBBU CAO, no fasensysteem, no uitzendbeding, no inlenersbeloning tracking exists
anywhere in `lib/`. humaniq **already decided**, in its data model if not its behaviour, that an
uitzendkracht is an `Employee` on an `EmploymentContract` of the agency running this humaniq instance
— it just never finished the thought. This change finishes it: **humaniq serves the uitzendbureau**.
The uitzendkracht is a real `Employee`, paid via the normal payroll path, with `type: agency`; the
inlener is an external party this instance has no payroll relationship with and this change adds no
schema for.

`rostering` (`hr-roster.json`: `Shift`/`Roster`/`RosterAssignment`) and `time-attendance`
(`hr-attendance.json`: `AttendanceRecord`) are both scoped by `employeeId` alone — neither reads
`EmploymentContract.type` (`grep -rn "agency\|contractType" openspec/specs/rostering/spec.md
openspec/specs/time-attendance/spec.md` — no match). An agency-contract `Employee` is scheduled and
clocks in exactly like a permanent one, today, with zero change. **That overlap is fully delivered
already** — this change adds nothing to either capability.

## What Changes

- **`EmploymentContract` gains two nullable, `agency`-scoped properties** (`hr-objects.json`):
  `uitzendFase` (enum `A`/`B`/`C`, nullable — the ABU/NBBU fasensysteem stage) and
  `uitzendbedingVanToepassing` (boolean, nullable — whether the beding that ends the
  uitzendovereenkomst automatically when the inlener terminates the assignment, BW art. 7:691 lid 2,
  applies to this contract). Both HR-entered, like `writtenContract`/`awfTariff` today.
- **`EmploymentContract` gains `inlenersbeloningReferentie`** (nullable string) — a free-text
  reference to the inlener's comparable-function wage basis the hourly rate is set against (WAADI
  art. 8, the inlenersbeloning duty) — an audit-trail field, not a computed enforcement, the same
  trust boundary `Loonbeslag.beslagvrijeVoet` already establishes for a different sensitive field.
- **Two new machine-checkable rules** in the existing `lib/Standards/rules/labour.json`:
  `nl-uitzendbeding-alleen-fase-a` (mandatory — `uitzendbedingVanToepassing: true` structurally
  requires `uitzendFase: A`, BW 7:691 lid 2) and `nl-inlenersbeloning-onderbouwing-vereist`
  (mandatory — an `agency`-type contract with a populated `hourlyWage` requires a populated
  `inlenersbeloningReferentie`, WAADI art. 8). NEW check provider
  `lib/Standards/Checks/NlUitzendChecks.php` (auto-discovered).
- **NEW placeholder CAO `lib/Standards/cao/cao-abu.json`** (ABU CAO voor Uitzendkrachten), the
  `cao-metaal-techniek`/`cao-horeca` precedent — `{value, source, verified: false, placeholder:
  true, checkAgainst}` leaves throughout, because this change does not source the actual 2026
  loontabel. Once an `EmploymentContract` sets `cao: "cao-abu"` + `caoSchaal`, the **existing**
  `nl-cao-minimumloon-schaal` check (`cao-library`, `NlCaoChecks.php`, contract-type-agnostic —
  verified by reading its predicate) applies with no further code change.
- **No manifest change** — `EmploymentContract`/`EmploymentContractDetail` already render every
  `type` value generically; the three new fields appear on the existing detail page like any other
  contract property, no new page.
- **Seed data**: one `agency`-type `EmploymentContract` (`uitzendFase: A`,
  `uitzendbedingVanToepassing: true`, `cao: cao-abu`) plus one intended-violation seed
  (`uitzendbedingVanToepassing: true`, `uitzendFase: B` — violates `nl-uitzendbeding-alleen-fase-a`).

### Non-goals (named fast-follows and exclusions)

- **No `InhuurOpdracht`/`Bureau` entity, no SNA-keurmerk validation, no G-rekening tracking, no
  ketenaansprakelijkheid risk model, no invoice-matching, no TCO dashboard** — all inlener-side
  vendor-risk concerns; out of scope because humaniq serves the agency, not the inlener (Why, above).
  If a future product needs the inlener side, it is a **different** capability with a different
  employer-of-record relationship, not an extension of this one.
- **No exact fasensysteem week-thresholds asserted** — the post-WAB (2020) exact duration of fase A
  is not confidently cited by this change (design.md D2); `uitzendFase` is HR-entered, not derived
  from a week-counting job.
- **No ABU/NBBU loontabel figures sourced** — `cao-abu.json` ships fully placeholder-marked, the
  `cao-metaal-techniek` precedent; sourcing the real 2026 loontabel is a data-only follow-up.
- **No 6-element inlenersbeloning onderbouwing checklist** — the draft's REQ-UZI-003 modelled a
  specific multi-field test this change cannot verify precisely; `inlenersbeloningReferentie` is a
  single free-text presence-gated field, not a structured checklist.

## Capabilities

### New Capabilities

- `uitzend-flexpool`: the three new `EmploymentContract` fields, the two new corpus rules +
  `NlUitzendChecks` provider, and the placeholder `cao-abu` CAO data — all agency-side, all
  reusing the existing `Employee`/`EmploymentContract`/payroll/CAO architecture.

### Modified Capabilities

- `payroll-core-schema` — `EmploymentContract` gains 3 additive nullable properties; no required-
  list change, no lifecycle change.
- `cao-library` — gains one new CAO data file; `CaoRegistry::availableCaos()` return grows by one;
  no `CaoRegistry`/`NlCaoChecks` code change.

## Impact

- `lib/Settings/register.d/hr-objects.json` — `EmploymentContract` +3 nullable properties; version
  bump.
- `lib/Standards/cao/cao-abu.json` — NEW, fully placeholder-marked.
- `lib/Standards/rules/labour.json` — 2 new rules, framework `hr-uitzend`; `RuleCatalogue::VERSION`
  bumps; `SCHEMA.md` framework examples gain `hr-uitzend`.
- `lib/Standards/Checks/NlUitzendChecks.php` — NEW (auto-discovered).
- `lib/Settings/register.d/hr-seed.json` — 2 new `EmploymentContract` seeds (1 compliant, 1
  intended violation).
- `tests/Unit/Standards/Checks/NlUitzendChecksTest.php` — NEW.
- `README.md` — the agency-not-inlener scope decision, cited plainly so a future contributor does
  not silently rebuild the inlener side inside this capability.
- No `src/manifest.json` change (new fields render on the existing generic contract pages).
- No `rostering`/`time-attendance`/`RuleAuditService` change — both confirmed contract-type-
  agnostic already (Why, above).
- Related: the superseded `spec/uitzend-flexpool-integration` draft branch is the source material;
  its inlener-side vendor-risk scope is recorded above as a different, un-built capability, not
  silently dropped. `cao-library` (archived) owns the CAO mechanism this change's `cao-abu.json`
  extends with data only.
