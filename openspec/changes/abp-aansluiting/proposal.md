---
kind: config+code
---

# ABP aansluiting — mandatory-fund determination + a fund-and-tenant-aware UPA completeness check

## Why

`pension-filing-upa-mvp` (archived 2026-07-12) already ships the whole UPA delivery mechanism for
ABP alongside the five other APG-administered funds: a `PensionFiling.fund` enum that already
includes `abp`, a declarative `concept -> gecontroleerd -> bevestigd -> verzonden` lifecycle gated
on `PayrollRunApprovedGuard`, and three corpus rules under the `nl-pensioenaangifte` framework
(`nl-upa-payrollrun-approved`, `nl-upa-monthly-completeness`, `nl-upa-deadline-alert`). ABP is not
a missing fund; it is one of the six already modelled. **Check `pension-filing-upa-mvp` first, as
instructed** — the delta this change adds is therefore small.

ABP is legally different from the other five funds in one specific way: under the *Wet
Privatisering ABP* (1996), Dutch public-sector and (most) education employers are not choosing ABP
as a matter of preference — they are legally obligated to affiliate with it. Nothing in hrmq today
records *which* client administraties carry that obligation, and the shipped
`nl-upa-monthly-completeness` check is deliberately, honestly **fund-blind**: its own docstring says
"the full per-configured-fund obligation is recorded in the rule statement, not enforced
field-by-field here." It is also, separately, tenant-blind — its period index is a single global
set, not scoped per `administrationId` (multi-administratie, archived 2026-07-14, postdates it by
two days). The one real compliance failure an "ABP aansluiting verplicht" feature exists to catch —
a public-sector client administratie whose payroll was approved but never actually filed with ABP
for that period — cannot be caught by the shipped mechanism as it stands.

The old draft branch `spec/abp-aansluiting-verplicht` (May 2026) imagined a large bespoke
integration surface that predates both the payroll engine and the UPA mechanism entirely: six new
OpenRegister registers (`DeelnemerRegistratie`, `UpaRecord`, `PensioenPartner`, `AdieuMelding`,
`RetourBericht`, `VplSaldo`), 30-minute SFTP polling for daily retour-berichten in CRS format with
247 fault codes, a REST integration for partner-pension mutations, VPL cohort tracking, Keuzepensioen
flexibilisering, and background-job Adieu-meldingen. None of that exists today, and building it now
would either duplicate or directly contradict the shipped mechanism: UPA *delivery* (XML generation,
APG wire transmission, scheduled dispatch) is an explicit, still-current non-goal of
`pension-filing-upa-mvp`, and `payroll-core-engine`'s own README disclaimer names "no ... pension
computation" as a known MVP limitation that applies to every fund, not just ABP. The old draft is an
idea source, not a target — see design.md "Named gaps" for what it describes that this change
deliberately does not build.

**Premium computation is out of scope for the same reason cao-library kept CAO allowances out of
scope**: there is no shared pension-premium computation capability for *any* fund yet, so an
ABP-specific calculator would be a parallel, unintegrated one-off, not an extension of something
that exists. For context only — never computed, stored, or enforced by this change — ABP's own
published 2026 tariff sets the total premium at **27,1%** (up from 27,0% in 2025), effective
1 January 2026, verified directly against abp.nl and independently corroborated by PO-Raad (see
Sources, design.md). The employer/employee split (reported elsewhere as ≈18,97%/8,13%) and the 2026
franchise amount (reported elsewhere as €19.200) come only from secondary payroll-consultancy
summaries, not from ABP's own primary `premietabel-2026.pdf`, and are recorded honestly as
unconfirmed rather than asserted as fact.

## What Changes

- **`Administration` schema** (`lib/Settings/register.d/hr-administratie.json`) gains
  `abpAansluitingsplichtig` (boolean, default `false`) — an admin-set determination that this client
  administratie is legally obligated (Wet Privatisering ABP 1996) to file its pension-bearing
  payroll with ABP, not a derived/computed flag.
- **NEW corpus rule `nl-abp-fund-required`** (`lib/Standards/rules/payroll.json`, same
  `nl-pensioenaangifte` framework the three shipped UPA rules already use; `PayrollRun`,
  `severity: mandatory`, `machineCheckable: true`) + `RuleCatalogue::VERSION` bump.
- **NEW `lib/Standards/Checks/NlAbpChecks.php`** (auto-discovered `CheckProvider`) registering the
  predicate: an NL `PayrollRun` in approved-or-later status, belonging to an
  `abpAansluitingsplichtig` administratie, violates when no `PensionFiling` with `fund: "abp"`
  exists for its own `(period, administrationId)` pair. Vacuous for non-NL runs, draft runs, and
  runs whose administratie does not resolve or is not `abpAansluitingsplichtig`.
- **`lib/Service/RuleAuditService.php`** context enrichment, extending the existing
  `buildRelatedContext()` pre-pass (the `Employee.byId` incremental-index precedent): an
  `Administration` index keyed on `administrationId` carrying `abpAansluitingsplichtig`; the
  existing `PayrollRun.byId` entries gain `administrationId`; a new `PensionFiling`
  `abpFiledPeriodsByAdministrationId` index (fund- *and* tenant-scoped) alongside the existing,
  unchanged, fund-blind global `filedPeriods` set.
- **Seed data** (`lib/Settings/register.d/hr-seed.json`): the existing `ADM-001` row is flipped to
  `abpAansluitingsplichtig: true` — the two already-seeded approved NL runs for 2026-05/2026-06 both
  already carry an ABP `PensionFiling` (`pension-filing-upa-mvp` REQ-PFU-007), so the happy path is
  proven with **zero new filing seeds**. One new, small `ADM-003` ("Gemeente Voorbeeld") +
  `AdministrationAccess` + one new approved NL `PayrollRun` scoped to it, deliberately reusing
  period `2026-06` (already globally filed by `ADM-001`'s ABP delivery), proves the new check is
  administratie-scoped where the shipped `nl-upa-monthly-completeness` is not.
- **Doc fix**: `hr-objects.json`'s `PayrollRun.administrationId` description still reads "No
  Administration schema is modeled in hrmq" — false since `multi-administratie` shipped
  (2026-07-14, two days after this comment was last accurate). Corrected in place as part of this
  change since it sits directly in the field this change extends.

### Non-goals (named fast-follows and exclusions)

- **ABP premium computation** (27,1% split, 36-hour FTE factor, franchise pro-rata, VPL) — blocked
  on a shared pension-premium computation capability that does not exist for *any* fund yet (see
  Why). Named fast-follow, not silently dropped.
- **ABP-specific UPA fields, SFTP/REST delivery, retour-bericht ingestion, VPL, Keuzepensioen,
  Adieu-meldingen, partner-pension registration** — all explicit non-goals of the shipped
  `pension-filing-upa-mvp`; this change does not reopen any of them.
- **Auto-deriving `abpAansluitingsplichtig` from employer sector/function** (the old draft's mixed
  ABP/PFZW-scheme logic) — hrmq has no employer-sector taxonomy today (`cao-sector-datasets` adds
  CAO reference data, not an employer-sector field); the flag is admin-set. Deriving it is a named
  fast-follow once such a taxonomy exists.
- **Editing the shipped `nl-upa-monthly-completeness` rule** to become fund/tenant-aware — its own
  docstring already documents the fund-blind MVP scope as deliberate; this change adds a narrower,
  additive rule instead of changing an archived capability's behaviour.

## Capabilities

### New Capabilities

- `abp-aansluiting`: the `Administration.abpAansluitingsplichtig` determination field and the
  fund-and-tenant-aware `nl-abp-fund-required` completeness check.

### Modified Capabilities

- `pension-filing-upa-mvp`: additive `PayrollRun` rule + context enrichment only — the shipped
  `PensionFiling` schema, lifecycle, guard, and the three existing rules are unchanged.

## Impact

- `lib/Settings/register.d/hr-administratie.json` — `Administration` +1 field.
- `lib/Settings/register.d/hr-objects.json` — `PayrollRun.administrationId` doc fix (no schema
  change).
- `lib/Standards/rules/payroll.json` — +1 rule; `lib/Standards/RuleCatalogue.php` — `VERSION` bump.
- `lib/Standards/Checks/NlAbpChecks.php` — NEW.
- `lib/Service/RuleAuditService.php` — context enrichment (`Administration` index, `PayrollRun.byId`
  + `administrationId`, `PensionFiling` ABP-fund index).
- `lib/Settings/register.d/hr-seed.json` — `ADM-001` flag flip; NEW `ADM-003` `Administration` +
  `AdministrationAccess` + `PayrollRun`.
- `tests/Unit/Standards/NlAbpChecksTest.php` — NEW.
- Depends on `pension-filing-upa-mvp` (archived 2026-07-12) and `multi-administratie` (archived
  2026-07-14), both reused unchanged.

## Sources

- ABP, "Premie blijft met 27,1% nagenoeg gelijk" (November 2025), <https://www.abp.nl/werkgevers/nieuws/2025/november/premie-blijft-nagenoeg-gelijk> —
  total 2026 premium 27,1% (up from 27,0%), effective 2026-01-01. **Verified.**
- PO-Raad, "Pensioenpremie 2026 stijgt licht naar 27,1%", <https://www.poraad.nl/pensioenpremie-2026-stijgt-licht-naar-271> —
  independent corroboration of the 27,1% figure. **Verified.**
- Pardon Consult, "ABP Pensioenpremie en Franchise 2026" — reports an ≈18,97%/8,13% employer/employee
  split and a €19.200 franchise; secondary source, not cross-checked against ABP's own
  `premietabel-2026.pdf`. **Not verified — `checkAgainst`:**
  <https://www.abp.nl/content/dam/abp/documenten/formulieren-tabellen/premietabel-2026.pdf>.
