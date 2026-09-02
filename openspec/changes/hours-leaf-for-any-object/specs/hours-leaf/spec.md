## ADDED Requirements

### Requirement: Humaniq supplies the hours surface for any object
Humaniq SHALL register an OpenRegister integration leaf `humaniq-hours` that
renders the hours booked against an arbitrary host object, so a consuming app
places the leaf rather than querying Humaniq's `TimeEntry` register itself.

The leaf SHALL identify the host object the way Humaniq stores it:
`domainObjectType` is the `<app>:<schema>` literal and `domainObjectRef` is the
object's uuid.

#### Scenario: A case detail page shows hours without reading Humaniq's register
- **WHEN** a consuming app places the `humaniq-hours` leaf on an object detail page
- **THEN** the widget reads time entries filtered on that object's
  `domainObjectType` and `domainObjectRef`, and the consuming app's own manifest
  contains no query against the `humaniq` register.

#### Scenario: The surface is absent when Humaniq is
- **WHEN** the consuming app is installed and Humaniq is not
- **THEN** no `humaniq-hours` leaf is registered, so the host renders no hours
  surface at all — rather than a tile showing `0`, which is what a real zero
  shows.

### Requirement: An unreadable total is not rendered as a number
The widget SHALL distinguish "no hours booked" from "hours could not be read".

#### Scenario: The read fails
- **WHEN** the time-entry query returns an error
- **THEN** the headline figure renders a dash and an error line, and never `0`.

#### Scenario: The object genuinely has no hours
- **WHEN** the query succeeds and returns no entries
- **THEN** the headline renders `0` with an explicit empty line, which is a claim
  about the data the widget actually read.

### Requirement: Hours can be added from the surface that shows them
The leaf SHALL offer both ways of booking time against the host object: a
direct hour booking, and a timer that can be started and stopped.

#### Scenario: Logging hours from a case
- **WHEN** a user activates the log-hours action on a host object
- **THEN** Humaniq's booking surface opens seeded with that object's
  `domainObjectType` and `domainObjectRef`, so the reference is written by the
  integration rather than typed by an employee.

#### Scenario: Running a timer against a case
- **WHEN** a user starts the timer and later stops it
- **THEN** a time entry carrying the host object's reference is written, and the
  widget's total reflects it without a page reload.

### Requirement: Both halves of the leaf agree
The leaf SHALL be declared on both its JS and PHP halves, and the values that
bind them SHALL agree: `id`, `label`, `icon`, `group`, `referenceType`,
`renderMode` and the `surfaces` list.

Both halves SHALL write the `surfaces` list out explicitly rather than relying on
a default, because a set declared by omission cannot be compared.

#### Scenario: A half is missing
- **WHEN** only one half declares the leaf
- **THEN** `scripts/check-integration-parity.sh` fails, naming the orphan — a
  PHP-only leaf is invisible in the UI and a JS-only leaf is invisible to every
  server-side consumer, and neither errors at runtime.

#### Scenario: The halves drift
- **WHEN** a bound value differs between the halves
- **THEN** the parity check fails naming the field and both values, because every
  way they drift is silent: a changed `renderMode` blanks the surface, a changed
  `label` makes one leaf look like two.
