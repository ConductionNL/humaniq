# Design: calculate travel claims and commuting allowances, and report travel for WPM

## Context

Read at `development` af702f78.

- `Expense` (`lib/Settings/register.d/hr-expense.json:5`): `amount` (line 16, typed by the
  employee), `category` (line 29, `travel` among others), `travelType` (line 210, `business`
  or `commute`), `distanceKm` (line 221), the lifecycle `submit`, `approve`, `reject`,
  `reimburse` (lines 44 to 84, approve and reject guarded by `NoSelfApprovalGuard`). No
  transport mode, no computed amount.
- `lib/Standards/rules/payroll.json:1358` rule `nl-reiskosten-onbelast-tarief`, parameter
  `rateEurPerKm` 0.23. `lib/Standards/Checks/NlTravelExpenseChecks.php:94`
  `onbelastTariefSatisfied()` reads the rate from `RuleCatalogue::all()` and flags a claim
  above it, at audit time only. Its docblock records that a same-year correction of the rate
  is a one-number JSON edit.
- `lib/Listener/TimeEntryStampListener.php` is the precedent for stamping derived values on
  OpenRegister's `ObjectCreatingEvent` and `ObjectUpdatingEvent` through
  `setModifiedData()`; `lib/AppInfo/Application.php` registers such listeners with
  `addServiceListener()`.
- `lib/Support/FleetAppId.php` resolves `integriq` (or its old id `openconnector`) and its
  classes duck-typed. humaniq calls integriq nowhere today.
- `Asset.fuelType` (`lib/Settings/register.d/hr-assets.json:159`) is the only fuel type in
  the register: `gasoline`, `diesel`, `hybrid`, `fullyElectric`, `hydrogen`, `other`.
- `Employee` (`hr-objects.json:5`) carries no address, and `hrAdministration`
  (`hr-administratie.json:5`) carries no headcount.
- Seed `expense-devries-mileage` is a 150 km business claim of 34.50, typed by hand.

## Goals / Non-Goals

**Goals**

- A mileage claim's amount is computed, and its tax-free and taxable parts are known.
- A commuting allowance is computed from distance and days with the 214-day rule.
- The yearly WPM kilometres per mode and fuel come from the records humaniq holds.

**Non-Goals**

- Paying claims or allowances through the payslip (`payroll-expenses-and-allowances`).
- Filing the WPM report with RVO.
- Trip logs for company cars.

## Decisions

### D1. The amount is stamped on save from one pure calculator

`TravelAllowanceCalculator` is a pure class. For a claim: `amount = round(distanceKm x
ratePerKm, 2)`, `taxFreeAmount = round(distanceKm x taxFreeRate, 2)` capped at `amount`,
`taxableAmount = amount - taxFreeAmount`. `ratePerKm` is the employer's configured rate
(`SettingsService`, default: the tax-free rate). `TravelAmountListener` runs on create and
update of an `Expense` with `category: travel` and a positive `distanceKm`, and stamps
`amount`, `ratePerKm`, `taxFreeAmount`, `taxableAmount` and `amountSource: calculated`. A claim
without a distance keeps its typed amount and `amountSource: entered`.

Alternative considered: `x-openregister-calculations` on `Expense`. Rejected: the tax-free
rate lives in the rule corpus in code, which a calculation expression cannot read, and copying
the rate onto the schema would give it a second home that drifts at the next correction.

### D2. A commute is an arrangement, not a monthly claim

`CommuteArrangement`: `employeeId`, `originPostcode`, `destinationPostcode`,
`distanceKmOneWay`, `distanceSource` (`manual` or `routeplanner`), `routeProvider`,
`daysPerWeek`, `transportMode`, `fuelType`, `startDate`, `endDate`, `ratePerKm`,
`monthlyAllowance`, `taxFreeMonthly`, `taxableMonthly`, `status`, `userId`,
`managerUserId`, `administrationId`. Lifecycle: `submit` (draft to submitted), `approve` and
`reject` (guarded by `NoSelfApprovalGuard`), `end` (approved to ended). The calculator gives
`monthlyAllowance = distanceKmOneWay x 2 x ratePerKm x 214 x (daysPerWeek / 5) / 12` and the
same with the tax-free rate for `taxFreeMonthly`. The listener stamps these on save.

Only postcodes are stored, never a street address, to keep the personal data minimal.

Alternative considered: a monthly travel claim per employee. Rejected: the 214-day rule exists
so employers do not collect monthly claims, and it is what the tender asks for.

### D3. The route lookup goes through integriq

`POST /api/travel/route-distance {arrangementId}` resolves the arrangement under the caller's
RBAC (404 otherwise), then `RouteDistanceService` asks integriq, resolved through
`FleetAppId`, for the road distance between the two postcodes. On success it stamps
`distanceKmOneWay`, `distanceSource: routeplanner` and `routeProvider`, and the listener
recomputes the allowance. Without integriq or without a configured source it answers 409 with
a message, and the arrangement keeps its typed distance.

Alternative considered: humaniq calling a route planner API itself. Rejected by ADR-091: an
external API surface and its credentials belong to integriq.

### D4. Transport mode is a field on the trip

`Expense` and `CommuteArrangement` gain `transportMode` (`car`, `van`, `motorcycle`, `moped`,
`public-transport`, `bicycle`, `e-bike`, `walking`, `other`) and `fuelType` with
`Asset.fuelType`'s values plus `lpg`, meaningful only for motor vehicles.

### D5. Business kilometres are a declared aggregation; the report composes

`Expense` declares an `x-openregister-aggregations` entry `wpmBusinessKm`: sum of
`distanceKm` grouped by `transportMode` and `fuelType`, filtered to `travelType: business` and
approved or reimbursed claims. `WpmReportService::compile(administrationId, year)` reads that
aggregation for the year, adds commuting kilometres per approved arrangement (one-way distance
x 2 x 214 x days per week / 5, pro rata for the months active in the year), counts employees
employed in the year for the threshold, and upserts one `WpmReport` per administration and
year. `POST /api/travel/wpm-report` runs it for administrators or the `hr` role.

Alternative considered: one imperative loop for both halves. Rejected in part: the business
half is a plain group-and-sum, which ADR-031 wants declared so a dashboard widget can reuse it.
The commuting half needs date arithmetic across two schemas, so it stays in the service.

## Declarative-vs-imperative decision (ADR-031)

| behaviour | path | why |
|---|---|---|
| claim amount and split | imperative listener with a pure calculator | the rate lives in the code corpus |
| arrangement lifecycle | declarative `x-openregister-lifecycle` with `NoSelfApprovalGuard` | the engine's state machine |
| monthly allowance | imperative, same calculator | same rate reason |
| business kilometres per mode | declarative `x-openregister-aggregations` | a group-and-sum on one schema |
| WPM report with commuting kilometres | imperative `WpmReportService` | pro-rata dates across schemas |
| route distance | imperative, through integriq | external API (ADR-091) |

## Seed data

- `expense-devries-mileage` gains `transportMode: car` and `fuelType: gasoline`; its amount is
  recomputed to 150 x 0.23 = 34.50, the same figure, now `amountSource: calculated`.
- A new `commute-jansen` arrangement: 18 km one way, 4 days a week, car, gasoline, approved,
  giving 18 x 2 x 0.23 x 214 x 0.8 / 12 = 118.13 a month.

## Risks / Trade-offs

- [The rate changes mid-year] -> the listener stamps `ratePerKm` on each row; a recomputation
  happens only when the row is saved again, so approved history keeps the rate it was approved
  with.
- [Commuting kilometres are an estimate] -> the 214-day rule is the fiscal basis; the report
  says the commuting figure is derived from arrangements, not measured.
- [Headcount for the threshold] -> counted from employees employed at any time in the year;
  the report shows the count so the employer can check it.

## Open Questions

- Should the employer's rate per kilometre be per administration rather than per instance?
  This design starts per instance.
