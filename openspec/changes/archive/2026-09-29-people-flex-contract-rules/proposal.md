---
kind: code
---

# Warnings for the fixed-term chain and the on-call fixed-hours offer

## Why

Two rules of Dutch labour law decide when a flexible contract stops being flexible, and
humaniq watches neither.

The chain rule (ketenregeling, BW 7:668a) turns a series of fixed-term contracts into a
permanent one after the fourth contract, or once the series runs past 36 months, counting
contracts that follow each other with gaps of six months or less. humaniq's contract
signal (`nl-signaal-contract-verloopt`) only asks whether one later contract exists, so an
HR adviser who renews for the fourth time gets no warning that the employee is now
permanent. The hr-signals change named the chain rule a follow-up "once chains matter".

For on-call workers (oproepkrachten) the employer must offer, after twelve months, a fixed
number of hours equal to the average worked over those twelve months (BW 7:628a lid 5,
Wet arbeidsmarkt in balans). humaniq has no on-call contract type and cannot tell HR what
that average is.

### Matrix rows (humaniq `openspec/parity/capabilities.json`)

| row | capability | humaniq today |
|---|---|---|
| `ppl-chain-rule` | Get warned before a series of fixed-term contracts turns into a permanent one. | `no`, none: named a follow-up in `openspec/changes/archive/2026-07-13-hr-signals/proposal.md:28` |
| `dm-oncall-average-hours` | See the average hours worked by an on-call worker over a chosen period, to decide on the statutory offer of fixed hours. | `no`, none: `EmploymentContract.type` has no on-call value, nothing averages hours |

Both rows are in humaniq's core area (people), which is what decided them build.

### Demand

- `dm-oncall-average-hours`, changelog: https://loket.nl/roadmap/ (Overzicht gemiddelde
  uren/dagen oproepkrachten, launched Q3 2026).

### Competitors rated yes

- `ppl-chain-rule`, AFAS Profit: "signal that the maximum duration of the contract chain
  rule (ketenbepaling) is about to end" (https://help.afas.nl/content/NL/SE/98198.htm, and
  https://help.afas.nl/content/NL/SE/98197.htm for the third contract).
- `dm-oncall-average-hours`, Loket.nl: "an overview in Loket that calculates the average
  hours or days of on-call workers over a chosen period, exportable to CSV or Excel"
  (https://loket.nl/roadmap/).

## What Changes

- **A chain signal.** A new corpus rule `nl-signaal-ketenregeling` (framework
  `hr-signals`) flags an employee whose running fixed-term chain reaches its third
  contract, or whose chain will pass 36 months within 60 days, so HR decides before the
  next renewal. The statutory numbers are rule parameters.
- **The chain on the contract page.** `EmploymentContractDetail` shows where the contract
  sits in its chain: its position (for example 3 of 3), the months counted so far and the
  date the chain turns permanent if the contract is renewed.
- **An on-call contract type.** `EmploymentContract.type` gains `oproep`, with two fields
  recording the fixed-hours offer: the date it was made and the hours offered.
- **The average over a chosen period.** An on-call overview lists every on-call contract
  with its average worked hours per week and per month over a period the user picks,
  from approved hours, and exports to CSV.
- **An offer signal.** A corpus rule `nl-signaal-oproep-vaste-uren` flags an on-call
  contract older than twelve months with no offer recorded.

## Capabilities

### New Capabilities

- `flex-contract-rules`: the fixed-term chain signal, the on-call contract type, its
  average-hours overview and the fixed-hours offer signal.

## Impact

- `lib/Standards/rules/labour.json`: two rules. `lib/Standards/Checks/NlSignalChecks.php`:
  two predicates over the existing full-list `signals.contractsByEmployeeId` index.
- `lib/Service/ContractChainService.php` (new): chain composition shared by the predicate
  and the contract page. `lib/Service/OnCallAverageService.php` (new).
- `lib/Controller/FlexContractController.php` (new), `appinfo/routes.php`:
  `GET /api/contracts/{id}/chain`, `GET /api/contracts/on-call-averages`.
- `lib/Settings/register.d/hr-objects.json`: `EmploymentContract.type` enum value `oproep`,
  properties `vasteUrenAanbodOp`, `vasteUrenAanbodUren`.
- `src/manifest.d/hr-objects.json`: a chain section on `EmploymentContractDetail`;
  `src/manifest.d/hr-flex-contracts.json` (new): the on-call overview page.

## Out of scope

- CAO deviations from the chain rule (BW 7:668a lid 5 lets a CAO allow up to six contracts
  in 48 months for named functions). The rule reads its parameters; a per-CAO override is
  data-only follow-up work on `lib/Standards/cao/`.
- Generating the offer letter. The fields record that it was made.
- Min-max and zero-hours contracts as separate types. They are on-call contracts for this
  rule and use `oproep`.
