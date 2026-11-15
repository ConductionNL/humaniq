# hours-leaf Delta

**Status**: proposed
**Scope**: humaniq

## ADDED Requirements

### Requirement: The hours leaf on a row is one hours field booked with the row's note

With `surface: "row"` the hours leaf SHALL render one labelled "Hours" field and no card, and SHALL read no hours,
timer or estimate up front. When the host dispatches the document event `cn-record-notes:recorded` whose
`detail.objectId` is the leaf's object, and the field holds a positive number of hours of at most 24 (a decimal
comma read as a point), the leaf SHALL book those hours on the object for today with `detail.note` as the
description, and clear the field. An event for another object, or an empty or invalid field, SHALL book nothing.

#### Scenario: DqDagAfsluiten Uren

- **GIVEN** the "Advies boomdeskundige beoordelen" row with "1" in Uren and a note
- **WHEN** the reader presses Vastleggen
- **THEN** the note is recorded and 1 hour is booked on that task, described by the note
