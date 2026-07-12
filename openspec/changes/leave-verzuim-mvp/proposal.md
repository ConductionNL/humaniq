---
kind: config
---

# Leave & Verzuim MVP (verlof-UI + saldi, ziekteverzuim + Wet verbetering poortwachter)

## Why

The 2026-07-12 market deep-research (logged in Spectr, insights `hrmq-insight-nc-ecosystem-gap` / `hrmq-insight-ranked-buildlist`) found that leave management is a core module in every NL competitor (AFAS, Loket.nl, Nmbrs, Employes) and in Nextcloud's only generic HR app (Krip) — but that **no** Nextcloud app has Dutch-law depth on sickness (verzuim / Wet verbetering poortwachter). hrmq's `LeaveRequest` schema exists (landed in `portal-schemas` with a full declarative submit→approve/reject lifecycle + `NoSelfApprovalGuard`) but has **zero UI** — no manifest pages, no menu entry — no leave balances, and no sickness tracking at all. Dutch employers are legally required to run WVP case management from day one of sickness (Regeling procesgang eerste en tweede ziektejaar), to continue paying at least 70% of wages for up to 104 weeks (BW art. 7:629), and to file the 42-week ziekmelding with UWV (ZW art. 38). This change makes both surfaces real.

## What Changes

- **`Verlof & verzuim` menu group** — the frozen ADR-001 menu-5 top-level entry (icon `calendar-clock`) finally appears in `src/manifest.json`, carrying all pages below. No new top-level menu is invented — this IS the ADR-001 placement.
- **Leave UI on the existing schema** — `LeaveRequests` (index), `LeaveApproval` (index pre-filtered `status=submitted`, mirroring `TimesheetApproval`) and `LeaveRequestDetail` (data + lifecycleActions submit/approve/reject per the **existing** `x-openregister-lifecycle` in `hr-leave.json` + related + files). The lifecycle and guard are NOT re-created; the pages drive what already ships.
- **New `LeaveBalance` schema** in `lib/Settings/register.d/hr-leave.json` — per employee/year/leaveType: `entitledHours` (statutory minimum 4× contractual weekly hours, BW art. 7:634), `bovenwettelijkHours`, `usedHours`, declaratively calculated `remainingHours` (`x-openregister-calculations`, verified supported by OpenRegister at HEAD), and `expiryDate` (statutory hours lapse 1 July of the following year, BW art. 7:640a). Plus a `LeaveBalances` index page.
- **New `SickLeaveCase` schema** in a new fragment `lib/Settings/register.d/hr-verzuim.json` — administrative sickness case with declarative lifecycle `gemeld ⇄ hersteld` (relapse within 4 weeks reopens the case — samengesteld ziektegeval, BW 7:629 lid 10), `wachtdag`, `loondoorbetalingPercentage` (statutory floor 70%), and the four WVP milestone pairs as stored dates (`probleemanalyse` week 6, `planVanAanpak` week 8, `uwv42WeekMelding` week 42, `eerstejaarsevaluatie` week 52) with derivation correctness enforced by a rule check — the same stored-but-checked decision the loonaangifte change made for `deadline`. **No medical data is ever stored** — administrative case data only (AVG / Autoriteit Persoonsgegevens beleidsregels "De zieke werknemer"); this is a spec'd requirement, not a footnote.
- **Six new machine-checkable NL labour rules** in a new corpus file `lib/Standards/rules/labour.json` (payroll.json is the tax/reporting corpus; SCHEMA.md prescribes one file per sub-domain) + two new check providers `NlLeaveChecks` / `NlVerzuimChecks`: statutory leave minimum, non-negative balance, vervaltermijn, WVP milestone derivation, WVP milestone overdue/approaching, loondoorbetaling ≥ 70%.
- **Verzuim pages** — `SickLeaveCases` (index) and `SickLeaveCaseDetail` (case data + poortwachter-milestones widget + lifecycleActions Herstellen/Heropenen + files for gespreksverslagen/plan van aanpak).
- **Seed data** — 3 SickLeaveCases (recovered short case; open case at ~week 7 with probleemanalyse overdue → mandatory violation; open long case at ~week 41 with the 42-week melding approaching → advisory) + LeaveBalances for the seeded employees, placeholders kept obvious.

### Non-goals

- **No automatic monthly accrual job** — accrual needs payroll periods; follow-up spec. `usedHours` is likewise not auto-posted from approved LeaveRequests in this change (needs a cross-object write hook; the `nl-verlof-saldo-niet-negatief` check audits the recorded balance instead).
- **No CAO-specific bovenwettelijk rules** and no rostering — `bovenwettelijkHours` is a plain per-balance number; CAO rulesets are configuration (ADR-001 rule 1).
- **No UWV wire submission** — the 42-weken melding is recorded (`uwv42WeekMeldingDone`), not transmitted; no arbodienst integration; no WIA-aanvraag (week 93) or tweede-spoor flows beyond the eerstejaarsevaluatie milestone.
- **No medical/diagnosis data, ever** — deliberately out of scope AND actively forbidden by REQ-VWP-002.

## Capabilities

### New Capabilities

- `leave-management`: the Verlof & verzuim menu group with leave request/approval/detail pages driving the existing LeaveRequest lifecycle, the new `LeaveBalance` schema with calculated `remainingHours`, and the three statutory leave rules (wettelijk minimum, saldo niet negatief, vervaltermijn).
- `verzuim-wvp`: the `SickLeaveCase` schema with gemeld⇄hersteld lifecycle and stored WVP milestone dates, the three verzuim rules (milestone derivation, milestone overdue, loondoorbetaling minimum), the verzuim pages, and the no-medical-data privacy requirement.

### Modified Capabilities

<!-- none — portal-schemas (LeaveRequest schema+lifecycle) is untouched; this change only ADDS UI on top of it. The existing specs (hrmq-expenses, hrmq-timesheet-approval, portal-*) are untouched. -->

## Impact

- `lib/Settings/register.d/hr-leave.json` — new `LeaveBalance` schema (0.1.0) with `x-openregister-calculations`; `LeaveRequest` schema untouched.
- `lib/Settings/register.d/hr-verzuim.json` — **new fragment**, `SickLeaveCase` schema (0.1.0) with `x-openregister-lifecycle`.
- `lib/Settings/hrmq_register.json` — `info.version` 0.2.0 → 0.3.0 (version-gated re-import).
- `lib/Standards/rules/labour.json` — **new corpus file** (`domain: labour`), 6 rules; `RuleCatalogue::VERSION` 2026-06 → 2026-07.
- `lib/Standards/Checks/NlLeaveChecks.php` + `lib/Standards/Checks/NlVerzuimChecks.php` — new check providers (auto-discovered by `RuleEngine::providers()`).
- `src/manifest.json` — `Verlof & verzuim` menu group; pages `LeaveRequests`, `LeaveApproval`, `LeaveRequestDetail`, `LeaveBalances`, `SickLeaveCases`, `SickLeaveCaseDetail`; deepLinks for `LeaveRequest`, `LeaveBalance`, `SickLeaveCase`.
- `lib/Settings/register.d/hr-seed.json` — seed LeaveBalances + SickLeaveCases.
- `lib/Repair/InitializeRegister.php` — no change (fragment import picks up the new fragment + version bumps).
- Related: `portal-schemas` (archived-track change that created `hr-leave.json`) is the direct predecessor; `hrmq-ia-navigation-alignment` owns any further IA re-ordering; `hrmq-rule-compliance-enforcement` owns guard wiring — this change keeps rule checks audit-only, per the loonaangifte precedent.
