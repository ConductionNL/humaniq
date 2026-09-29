---
kind: code
---

# Calculate travel claims and commuting allowances, and report travel for WPM

## Why

An employee who drove 150 km for a client meeting opens a travel claim in humaniq, fills in the
distance, and then has to type the euro amount as well. humaniq knows the tax-free rate per
kilometre (the corpus rule `nl-reiskosten-onbelast-tarief`), but only uses it afterwards, in
`occ humaniq:rules:audit`, to flag a claim that was typed too high. Nothing computes the amount.

A commuting allowance is not calculated at all. An HR adviser works out distance times two
times the rate times the working days per year by hand, and a Delft tender asks for exactly
that calculation with a route planner lookup (E17 and E8).

Employers with 100 or more employees also owe a yearly report of their staff's business and
commuting kilometres per transport mode and fuel type, for the werkgebonden personenmobiliteit
(WPM) CO2 duty. humaniq records a transport mode nowhere, so the report is compiled from
spreadsheets. Two tenders ask for it.

This change computes mileage claims and commuting allowances at the tax-free rate, looks up a
commuting distance through integriq, and compiles the WPM figures per administration and year.

### Matrix rows (humaniq `openspec/parity/capabilities.json`)

| row | capability | humaniq today |
|---|---|---|
| `exp-mileage` | Log business mileage and have it paid at the tax-free rate. | `partial`, built.state `built`: `Expense.travelType` and `distanceKm` exist; the amount is typed by the employee; the rate is checked only by `occ humaniq:rules:audit` |
| `td-commute-allowance` | Calculate a commuting allowance from the travel distance and apply the tax-free rules. | `partial`, built.state `built`: commute claims carry a distance; no calculation and no route lookup |
| `td-wpm-co2` | Register commuting and business travel by transport mode for the employer's CO2 mobility reporting duty. | `no`, built.state `none`: only `Asset.fuelType` for company vehicles; trips carry no transport mode and nothing aggregates them |

### Demand

- `td-commute-allowance`, tender: https://www.tenderned.nl/aankondigingen/overzicht/415705
  (Delft Support E17, fiscale rekenregels woon-werkverkeer, and E8, reiskostenberekening via
  routeplanner of OV-API).
- `td-wpm-co2`, tender: https://www.tenderned.nl/aankondigingen/overzicht/415227
  (Sudwest-Fryslan E10.5 and E10.3, CO2-registratieplicht; also Delft Support data E4).

### Competitors rated yes

- `exp-mileage`, AFAS Profit: "employees create a kilometre claim in the InSite claim portal,
  with favourite trips, paid through a wage component"
  (https://help.afas.nl/help/NL/SE/Hrm_Declrs.htm).
- `exp-mileage`, Visma Raet Youforce: "Zakelijke kilometers declareren computes the distance
  from departure and arrival" (https://apps.apple.com/nl/app/youforce/id1541134359).
- `exp-mileage`, HR2day: "travel and kilometre allowances with correct tax processing"
  (https://www.hr2day.com/features/declaraties/).
- `td-commute-allowance`, AFAS Profit: "a work location pattern with means of transport sets
  the travel distance ... so travel costs are paid on working days only"
  (https://help.afas.nl/help/NL/SE/135283.htm).
- `td-commute-allowance`, Visma Raet Youforce: "calculates travel distance automatically via
  Here or TomTom"
  (https://community.visma.com/t5/Releases-YouServe/tkb-p/nl_ys_vismayouserve_releases/page/2).
- `td-commute-allowance`, HR2day: "commuting kilometres per employee for travel allowance"
  (https://www.hr2day.com/nieuws/hr2day-jaguar/).
- `td-wpm-co2`, AFAS Profit: "the mobility functionality supports the werkgebonden
  personenmobiliteit report required for employers with 100 or more employees"
  (https://help.afas.nl/help/NL/SE/128724.htm).
- `td-wpm-co2`, Visma Raet Youforce: "reports Toetsing CO2 aangifteplicht and CO2 rapport"
  (https://community.visma.com/t5/Releases-YouServe/tkb-p/nl_ys_vismayouserve_releases/page/2).
- `td-wpm-co2`, HR2day: "standard means of transport in the employment relation, needed for
  the CO2 report on business trips"
  (https://data.maglr.com/1697/issues/47387/578463/index.html).

### Recorded non-goals this change picks up

- mileage-rules (`openspec/changes/archive/2026-07-14-mileage-rules/design.md`, Non-Goals,
  "MVP, named follow-ups"): "vaste (fixed monthly) reiskostenvergoeding / 214-dagenregeling;
  write-time enforcement (blocking submit/approve on violation); any UI/manifest change." This
  change adds the fixed commuting allowance with the 214-day rule and the page fields. It still
  blocks nothing at submit: it computes the amount instead.
- mileage-rules keeps its other follow-up, "loonheffing gross-up of the bovenmatige vergoeding
  onto a Payslip/PayrollRun", for `payroll-expenses-and-allowances`. This change only splits a
  claim into its tax-free and taxable part.

## What Changes

- **Mileage claims compute their amount.** When a travel claim carries a distance, humaniq
  sets the amount to distance times the employer's rate per kilometre. The employer's rate
  defaults to the tax-free rate from the corpus. When the employer pays more, the claim shows
  its tax-free and its taxable part.
- **A commuting arrangement.** A new `CommuteArrangement` records one employee's commute:
  one-way distance, days per week, transport mode and fuel type, and a period. humaniq
  computes the fixed monthly allowance with the 214-day rule, split into tax-free and taxable.
  The arrangement is submitted by the employee and approved by HR, with separation of duties.
- **Distance from a route planner.** A "Calculate distance" action asks integriq for the route
  distance between two postcodes and stores it with its source. Without integriq, the distance
  is typed in by hand.
- **Transport mode on every trip.** Travel claims and commuting arrangements carry
  `transportMode` and, for motor vehicles, `fuelType`.
- **The WPM figures per year.** A "WPM report" per administration and year sums business and
  commuting kilometres per transport mode and fuel type, states whether the administration
  meets the 100-employee threshold, and lists the claims and arrangements without a mode.

## Capabilities

### New Capabilities

- `expenses-travel-calculation`: computed mileage claims, a calculated commuting allowance with
  a route lookup, and the yearly WPM kilometre figures.

## Impact

- `lib/Settings/register.d/hr-expense.json`: `Expense` gains `ratePerKm`, `taxFreeAmount`,
  `taxableAmount`, `amountSource`, `transportMode`, `fuelType`; new schemas
  `CommuteArrangement` and `WpmReport`, and an `x-openregister-aggregations` entry on
  `Expense` for business kilometres per mode and fuel.
- `lib/Service/TravelAllowanceCalculator.php` (new, pure): claim and 214-day allowance
  arithmetic.
- `lib/Listener/TravelAmountListener.php` (new): stamps the computed figures on save.
- `lib/Service/RouteDistanceService.php` (new): asks integriq through `FleetAppId`.
- `lib/Service/WpmReportService.php` (new) and `lib/Controller/TravelController.php` (new):
  `POST /api/travel/route-distance`, `POST /api/travel/wpm-report`.
- `src/manifest.d/hr-expense.json` and `05-menu.json`: arrangement pages, the WPM report page,
  the new claim fields.
- `lib/Settings/register.d/hr-seed.json`: a commuting arrangement and transport modes on the
  seeded mileage claim.

## Out of scope

- Paying the commuting allowance or a claim through the payslip, and taxing its taxable part:
  `payroll-expenses-and-allowances`.
- Submitting the WPM report to RVO. The page gives the figures; the employer files them.
- Company-car kilometres. humaniq keeps no trip log for a lease car.
- Public transport fares from an OV API. The route lookup answers distance only.

## Cross-app dependencies

- integriq: a route-distance source (for example a route planner API) that answers the road
  distance between two Dutch postcodes, configured by the administrator with its own
  credentials. humaniq calls it duck-typed through `FleetAppId` and degrades to manual entry.
