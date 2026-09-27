# payroll-pack-and-table-updates

## ADDED Requirements

### Requirement: An administrator SHALL upload a pack together with its tax tables (REQ-PKU-001)

The pack upload SHALL accept a tax tables document alongside the pack. humaniq SHALL validate
the tables (required parameter groups, and every leaf carrying a value, a source and a
verified flag, with a `checkAgainst` note on every unverified leaf) and SHALL then run every
existing pack gate, including the pack's own golden vectors, against those tables. Neither
document SHALL be stored unless both pass. An uploaded tables id that a bundled tables file
already owns SHALL be refused.

Rows: `pay-year-transition`, `ppl-cao-updates` (humaniq matrix).

#### Scenario: A payroll administrator loads 2027
- **GIVEN** a humaniq instance that ships only the 2026 pack and tables
- **WHEN** a payroll administrator uploads `nl-2027.pack.json` with `nl-2027.json` on the
  "Payroll packs" page and every gate passes
- **THEN** the page lists the 2027 pack as active and a draft run for 2027-01 is calculated
  with engine version `nl-2027@<packVersion>`

#### Scenario: A table that breaks the golden vectors is refused whole
- **GIVEN** a 2027 tables document with a mistyped bracket percentage
- **WHEN** the payroll administrator uploads it with its pack
- **THEN** the dialog shows the self-test gate's message, and neither the pack nor the tables
  exist afterwards

#### Scenario: The bundled year cannot be shadowed by an upload
- **GIVEN** the bundled `nl-2026` tables
- **WHEN** an administrator uploads a tables document whose id is `nl-2026`
- **THEN** the upload is refused with a message naming the bundled id

### Requirement: The page SHALL answer which pack and tables a tax year will use (REQ-PKU-002)

For a chosen jurisdiction and year, humaniq SHALL show the pack and tables that year resolves
to, whether each is bundled or uploaded, the provenance of unverified leaves, and whether the
pack's self-test passes. The occ year-transition preflight SHALL report the same answer.

Rows: `pay-year-transition` (humaniq matrix).

#### Scenario: Checking January before the first run
- **GIVEN** an uploaded 2027 pack and tables
- **WHEN** the payroll administrator enters 2027 in the year-transition check
- **THEN** the page shows pack `nl-2027`, origin uploaded, tables `nl-2027`, origin uploaded,
  and self-test passed

#### Scenario: A year with nothing to pay it with
- **GIVEN** no pack for 2028
- **WHEN** the payroll administrator checks 2028
- **THEN** the page states that no pack resolves for NL 2028

### Requirement: An uploaded pack SHALL be deactivatable without touching calculated runs (REQ-PKU-003)

An administrator SHALL be able to deactivate an uploaded pack through a guarded action.
Deactivation SHALL NOT change any run that is not a draft. A draft run recalculated afterwards
SHALL resolve its pack again.

Rows: `pay-year-transition` (humaniq matrix).

#### Scenario: Withdrawing a wrong pack
- **GIVEN** an active uploaded 2027 pack, an approved 2027-01 run calculated with it and a
  draft 2027-02 run
- **WHEN** the administrator deactivates the pack
- **THEN** the 2027-01 run keeps its engine version, and recalculating 2027-02 reports that no
  pack resolves for NL 2027

#### Scenario: A non-administrator cannot deactivate
- **GIVEN** an HR adviser without administrator rights
- **WHEN** they call the deactivate action
- **THEN** the response is 403 and the pack stays active
