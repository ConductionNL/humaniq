---
capability: hours-leaf
status: done
built_by: openspec/changes/archive/2026-09-11-hours-leaf-for-any-object
---

# hours-leaf Specification

**Status**: done
**Scope**: humaniq
**OpenSpec changes**:
- [hours-leaf-for-any-object](../../changes/archive/2026-09-11-hours-leaf-for-any-object/) _(archived 2026-09-11; merged as humaniq#287, #412, #416, #418 and #420, with dossiq#2368, #2494 and #2510)_ - humaniq supplies the hours surface for any object as an OpenRegister integration leaf, so a consuming app places the leaf instead of querying humaniq's register. The continuation adds the leaf bundle that was never built, the KPI shape, the booking dialog and the administration link, and a timer stored as one open time entry.

## Purpose

Answer one question about any object in the fleet: how much time has been spent on
it, by everyone and by you, and let a reader add to that figure without leaving the
page they are on.

Hours belong to humaniq (ADR-107 decision 6), so humaniq renders them. Before this
capability a consuming app had to aggregate humaniq's register from its own manifest,
which reads `0` on an install without humaniq, and `0` is exactly what an object with
no hours reads. The failure and the success looked the same, on every object, for as
long as it shipped.

A leaf whose app is absent is never registered, so that failure mode stops existing
rather than being handled.

## Requirements

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
The leaf SHALL render as a card with its own chrome, and SHALL offer two
controls in its header: a stopwatch that starts and stops a timer, and ONE action
button that opens a menu holding booking hours and opening the hour
administration for that object. The stopwatch sits to the left of the action
button.

The card draws its own border because a mount-mode leaf is handed a bare
element: without chrome it reads as loose text between neighbouring cards that
have it. Its header SHALL be drawn the way the host draws every other card's
header on the page: a coloured icon and a bold title on the left, the controls
on the right, a rule beneath. The host cannot draw that header for a mount-mode
leaf, so the leaf copies the shape rather than inventing one.

The action menu SHALL open over neighbouring content rather than inside the
host's cell. The host places the card in a cell that scrolls, and a list
positioned inside that cell is clipped at its edge.

Booking hours SHALL open a dialog Humaniq renders in its own bundle, on the page
the reader is already on. Sending a reader to another app to book time against
the case in front of them loses the case, and every field the dialog would have
seeded has to be found again by hand.

Opening the administration SHALL be a link into Humaniq's time-entry index,
narrowed to this host object. The card itself lists NO bookings: a KPI answers
one question, and the rows behind the total are one press away for the reader
who wants them.

The stopwatch is its own control rather than a menu item because it is the
shortcut for work happening right now, and a shortcut behind a menu costs two
presses for the one thing that has to be instant.

#### Scenario: Booking hours from a case
- **WHEN** a user activates the book-hours action on a host object
- **THEN** a Humaniq dialog opens over the host page, seeded with that object's
  `domainObjectType` and `domainObjectRef`, so the reference is written by the
  integration rather than typed by an employee, and neither field is offered for
  editing.
- **AND** the dialog asks for the day, a start time and an end time, shows the
  hours it derives from that span before the user books, refuses an end at or
  before the start, and writes the entry in the clocked shape (`startedAt`,
  `endedAt`) so the server derives its hours the way it does for a stopped
  timer.

#### Scenario: The stopwatch shows that it is working
- **WHEN** a user presses the stopwatch and the server has not yet answered
- **THEN** the control shows a spinner in place of its icon and is marked busy,
  so a press that takes a moment to land does not look ignored.

@e2e exclude The spinner lives only between the press and the server's answer, on a consuming app's page. It was verified by hand on dossiq's case page with the start request held back; humaniq has no page that hosts the leaf.

#### Scenario: Opening the hour administration for a case
- **WHEN** a user activates the view-hours action
- **THEN** Humaniq's time-entry index opens filtered to that object's
  `domainObjectType` and `domainObjectRef`, showing every booking behind the
  total rather than the recent few.

#### Scenario: Running a timer against a case
- **WHEN** a user starts the timer and later stops it
- **THEN** a time entry carrying the host object's reference is written, and the
  widget's total reflects it without a page reload.

#### Scenario: The card lists no bookings
- **WHEN** the host object carries several bookings
- **THEN** the card shows only the total and the caller's share, and lists none
  of the bookings behind them, which stay reachable through View hours in the
  action menu.

#### Scenario: The controls in the card header
- **WHEN** the card renders
- **THEN** its header carries the card's icon and title on the left and, on the
  right, a stopwatch and then a single action button whose menu offers Book
  hours and View hours, rather than three separate buttons competing with the
  figure, and that header is drawn with the same rule, weight and spacing as
  the host's other cards on the page.

@e2e exclude The card renders only where a consuming app mounts it. conduction/dossiq `tests/e2e/case-hours-leaf.spec.ts`, test "the tile leads with the hours on the case and the caller's own beneath", asserts the stopwatch, the single action button and the two menu items on a case page; humaniq has no page that hosts the leaf.

#### Scenario: The action menu is not clipped by the host
- **WHEN** the action button is pressed on a card whose host cell scrolls
- **THEN** the whole menu is visible over the neighbouring content, and a press
  outside it, Escape, a scroll or a resize closes it.

@e2e exclude Only visible where a consuming app mounts the leaf. conduction/dossiq `tests/e2e/case-hours-leaf.spec.ts`, test "the tile leads with the hours on the case and the caller's own beneath", opens the menu on a case page and asserts both items are visible; humaniq has no page that hosts the leaf.

#### Scenario: The surface while the timer runs
- **WHEN** the timer is running
- **THEN** the tile shows the elapsed time in place of its figures and the timer
  control reads as a stop, so the reader can tell at a glance that the object is
  being worked on rather than having to remember starting it.

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

### Requirement: The leaf's client half ships as its own bundle
Humaniq SHALL build a `leaves` webpack entry emitting `js/humaniq-leaves.js`,
carrying the leaf registrations and nothing else.

OpenRegister's `LeafScriptListener` enqueues `js/<app>-leaves.js` on the pages of
apps that consume OpenRegister. An app that ships no such artifact is skipped,
by design, so that no page can ever enqueue a 404.

#### Scenario: The providing app ships no leaf bundle
- **WHEN** Humaniq registers the `humaniq-hours` leaf on both halves but builds
  no `leaves` entry
- **THEN** nothing loads the client half on a consuming page, so the surface is
  absent while the descriptor reaches OCS discovery, `getLeaves()` and the parity
  check unchanged. Every instrument reports success and the feature is not there.

#### Scenario: The bundle stays thin
- **WHEN** the `leaves` entry is built
- **THEN** it imports the leaf registrations only, and neither the router, the
  Pinia stores, the app shell nor `manifest.json`, because whatever it imports
  lands on every page of every consuming app.

### Requirement: The hours surface reads as a KPI tile
The leaf SHALL render the hours booked against the host object as its headline
figure, and the hours the CALLER booked against it as a subordinate figure
beneath.

Both figures come from one read. A second request would let the two disagree.

#### Scenario: A case with hours from several people
- **WHEN** three people have booked against the host object and the caller is one
  of them
- **THEN** the headline shows the total of all three and the sub-line shows only
  the caller's share, labelled as the caller's own.

#### Scenario: The caller has booked nothing
- **WHEN** the object carries hours but none of them are the caller's
- **THEN** the sub-line renders `0` rather than being hidden, because an absent
  sub-line and a zero one are not the same claim.

### Requirement: A running timer survives leaving the page
Starting the timer SHALL write a time entry carrying the host object's reference
and NO end, and the surface SHALL resolve the caller's running entry when it
mounts, so a timer started before navigating away is still running on return.

The running entry is the timer. There is no second place the state lives, so
there is nothing that can disagree with it.

#### Scenario: Leaving and returning mid-timer
- **WHEN** a user starts the timer on a host object, navigates away, and later
  opens that object again
- **THEN** the surface mounts showing the timer running, counting from the stored
  start, with a stop control rather than a start one.

#### Scenario: A timer running against a different object
- **WHEN** a user with a timer running against object A opens object B
- **THEN** B's surface says a timer is running elsewhere, and its stopwatch is
  present but disabled with that reason as its accessible name, because the
  constraint is per user and not per object. The control stays visible so the
  card keeps one shape in every state; a stopwatch that vanishes reads as a
  broken card rather than as a rule.

### Requirement: A user has at most one running timer
The server SHALL refuse to start a timer for a caller who already has one
running, and SHALL be the place that refusal is decided.

A guard that lives only in the widget is not a guard: two tabs, two objects, or a
reload mid-request each defeat it, and each writes a second open entry that no
stop will ever close.

#### Scenario: Starting a second timer
- **WHEN** a caller with a running entry asks to start another
- **THEN** the request is refused, naming the object the running timer belongs
  to, and no second open entry is written.

#### Scenario: Stopping someone else's timer
- **WHEN** a caller asks to stop a running entry that is not theirs
- **THEN** the request is refused, because the entry is resolved from the caller
  rather than from an id the caller sends.

### Requirement: An entry without an end is a running timer, not a defective booking
An entry carrying `startedAt`, no `endedAt` and `origin: timer` SHALL be
accepted, stamped and aggregated as zero hours, and SHALL NOT be refused by the
span validation that governs a finished booking.

The `origin` marker is what separates the two. Without it, an entry that simply
lost its end on the way in is indistinguishable from a timer, and it would then
be picked up as one: a permanently running timer nobody started and no stop will
ever close. So a missing end is refused exactly as it is today unless the write
says it is a timer.

#### Scenario: Writing the open entry
- **WHEN** a timer start writes an entry with no end and `origin: timer`
- **THEN** the entry is stamped with its employee, user, administration and
  parent timesheet as any entry is, its `hours` is `0`, and the span checks that
  refuse an end before a start are skipped rather than applied to a missing end.

#### Scenario: A finished booking that lost its end
- **WHEN** a write carries a start, no end, and any origin other than `timer`
- **THEN** it is refused with the message it is refused with today, because it is
  a booking that cannot say how long it lasted rather than one that is still
  running.

#### Scenario: The parent timesheet while a timer runs
- **WHEN** a timesheet holds a running entry alongside finished ones
- **THEN** its total counts the running entry as zero, so a timer in progress
  never inflates a total that has not been worked yet.

#### Scenario: Stopping the timer
- **WHEN** the end is written
- **THEN** the ordinary derivation computes `hours` from the span and the parent
  timesheet's total takes it up, without the entry having been anything other
  than one row throughout.
