## ADDED Requirements

### Requirement: `AbsenceRateService` SHALL be exposed as a period trend through a guarded analytics endpoint (REQ-ABSRATE-006)

`GET /apps/hrmq/api/analytics/trends?metric=absence-rate` SHALL call
`AbsenceRateService::absenceRate()` once per bucketed period over the requested range, scoped to
the caller's active administration (`hrmq-dashboard-steering-indicators` REQ-DSI-005), and SHALL
return each bucket's `percentage` exactly as the service returns it — including `null` when
`availableDayEquivalents` is zero, never coerced to `0`. This is the analytics-endpoint exposure
the capability's own spec named as future work ("wiring it to an analytics endpoint is a separate
change") rather than a change to the calculation contract, which is untouched.

#### Scenario: The endpoint's null contract matches the service's own contract
@e2e exclude endpoint contract assertion, covered by a controller/service unit test asserting the raw series payload against AbsenceRateServiceTest's own fixtures — hrmq's e2e suite does not exist yet (tracked by active change hrmq-test-coverage-baseline)
- **GIVEN** the same zero-availability fixture `AbsenceRateServiceTest`'s "No availability yields null rather than zero" scenario already pins
- **WHEN** `GET /apps/hrmq/api/analytics/trends?metric=absence-rate` resolves the corresponding period
- **THEN** the bucket's value in the JSON response is `null`, matching `AbsenceRateService::absenceRate()['percentage']` for the same inputs
