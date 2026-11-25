# hours-leaf-delivery Specification (delta)

## Purpose

The client half of humaniq's hours leaf reaches the page of an app that
consumes it. Both halves of `humaniq-hours` exist and agree, but the client
half is compiled only into humaniq's own main bundle, so OpenRegister's leaf
script loader skips humaniq and the surface renders nothing on any other app's
page.

## Requirements

### Requirement: Humaniq ships a leaves bundle (REQ-HLD-001)

Humaniq SHALL build a dedicated `leaves` webpack entry to
`js/humaniq-leaves.js`. The entry SHALL carry the leaf registrations and their
widgets only, and SHALL NOT carry the router, the store or the app shell,
because OpenRegister loads it onto another app's page. The entry filename
SHALL match `<app id>-leaves.js`, which is the name OpenRegister's loader
looks for.

#### Scenario: A build produces the bundle the loader looks for

- **GIVEN** a production build of humaniq
- **WHEN** the build finishes
- **THEN** `js/humaniq-leaves.js` exists
- **AND** it does not import the router, the store or the app shell
- **AND** it is an order of magnitude smaller than the main bundle
- **AND** @e2e exclude Build artefact; asserted by a build test, not a browser.

#### Scenario: A renamed entry is a failure, not a silent skip

- **GIVEN** the leaves entry is renamed or removed
- **WHEN** the build test runs
- **THEN** it fails naming the expected filename
- **AND** @e2e exclude Build artefact; asserted by a build test, not a browser.

### Requirement: Every registered leaf is reachable from the bundle (REQ-HLD-002)

For every leaf humaniq registers on OpenRegister's leaf-provider event, the
leaves bundle SHALL carry a client registration with the same id. A leaf
registered on one side only SHALL fail the test rather than ship, because a
server-side descriptor with no client half reports success everywhere and
renders nothing.

#### Scenario: Both halves of every leaf agree and both ship

- **GIVEN** humaniq registers `humaniq-hours` server-side
- **WHEN** the parity test runs
- **THEN** the leaves bundle carries a client registration for `humaniq-hours`
- **AND** @e2e exclude Build and registration parity; asserted by unit test.

#### Scenario: A leaf added on one side only is caught

- **GIVEN** a second leaf is registered server-side with no client half
- **WHEN** the parity test runs
- **THEN** it fails naming the leaf id that has no client registration
- **AND** @e2e exclude Build and registration parity; asserted by unit test.

### Requirement: The leaf states what a host page must pass (REQ-HLD-003)

The hours leaf SHALL document and enforce its host contract: the host object's
register slug, schema slug and uuid. When the host supplies fewer than all
three, the widget SHALL say it cannot resolve a host object and SHALL NOT
render a total. It SHALL NOT render zero, because zero hours booked and no
host to book them against look identical to a caseworker and only one of them
is a fact.

#### Scenario: A complete host renders the hours for that host

- **GIVEN** a host page passing a register slug, a schema slug and a uuid
- **WHEN** the leaf mounts
- **THEN** it shows the hours booked against that host object
- **AND** e2e: `tests/e2e/hours-leaf-on-a-host-page.spec.ts`

#### Scenario: An incomplete host is named, not rendered as zero

- **GIVEN** a host page passing no uuid
- **WHEN** the leaf mounts
- **THEN** it says it cannot resolve a host object
- **AND** it does not display a total of zero
- **AND** e2e: `tests/e2e/hours-leaf-on-a-host-page.spec.ts`

### Requirement: Demo data lets the leaf be seen working (REQ-HLD-004)

Humaniq's demo data SHALL seed `TimeEntry` rows whose `domainObjectType` is a
real `register:schema` pair and whose `domainObjectRef` is the uuid of an
object the demo data also creates, with non-zero `hours`. Placeholder strings
SHALL NOT be used for either field, because a row that matches no host filter
demonstrates nothing and hides the fact that the leaf has never carried a
record.

#### Scenario: Seeded hours appear on the seeded host

- **GIVEN** demo data is imported
- **WHEN** the leaf mounts on the seeded host object
- **THEN** it shows the seeded hours
- **AND** e2e: `tests/e2e/hours-leaf-on-a-host-page.spec.ts`

#### Scenario: No seeded row carries a placeholder reference

- **GIVEN** demo data is imported
- **WHEN** the seeded `TimeEntry` rows are read
- **THEN** every `domainObjectRef` is a uuid the demo data created
- **AND** @e2e exclude Seed-data shape; asserted by unit test.

### Requirement: An hour booked from the leaf lands on the host (REQ-HLD-005)

Hours booked through the leaf, by the log-hours path or the timer, SHALL carry
the host's `domainObjectType` and `domainObjectRef`, and SHALL be visible in
the same surface after the booking without a full page reload.

#### Scenario: A caseworker books an hour and sees the total move

- **GIVEN** the leaf mounted on a host object with two hours booked
- **WHEN** the caseworker books one more hour from the leaf
- **THEN** the surface shows three hours
- **AND** the new entry carries the host's type and reference
- **AND** e2e: `tests/e2e/hours-leaf-on-a-host-page.spec.ts`
