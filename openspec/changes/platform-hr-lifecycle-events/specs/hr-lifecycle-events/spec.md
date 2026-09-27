# hr-lifecycle-events

## ADDED Requirements

### Requirement: humaniq SHALL emit an event for each HR moment (REQ-HLE-001)

humaniq SHALL emit a CloudEvent through OpenRegister's webhook service, and a typed Nextcloud
event, when an employee joins, leaves or changes job, when leave is approved or withdrawn, and
when sickness is reported or ends. Each event SHALL be emitted once per moment, on the change
that causes it.

Rows: `td-event-webhooks` (humaniq matrix), tender
https://www.tenderned.nl/aankondigingen/overzicht/415227.

#### Scenario: The identity manager hears about a leaver
- **GIVEN** an endpoint subscribed to `nl.conduction.hrmq.employee.left`
- **WHEN** HR completes an offboarding case for an employee whose last day is 31 October
- **THEN** the endpoint receives one event naming the employee, their Nextcloud user id and the
  last working day

#### Scenario: Saving twice does not send twice
- **GIVEN** an approved leave request that already produced `leave.approved`
- **WHEN** someone saves the request again without changing its status
- **THEN** no second event is sent

### Requirement: Event payloads SHALL carry no more than the moment needs (REQ-HLE-002)

A sickness event SHALL carry only the employee, the administration and the absence dates, and a
leave event SHALL NOT carry the leave type or reason. No event SHALL carry salary, BSN or
medical data.

Rows: `td-event-webhooks` (humaniq matrix).

#### Scenario: A sick report reaches rostering without detail
- **GIVEN** an endpoint subscribed to `nl.conduction.hrmq.sickness.reported`
- **WHEN** HR registers a sickness case
- **THEN** the event carries the employee and the first sick day, and no reason, percentage or
  note
