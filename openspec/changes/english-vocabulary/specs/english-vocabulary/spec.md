## ADDED Requirements

### Requirement: Statutory concepts SHALL be given an English name plus a statute marker

A concept defined by national law SHALL be renamed to English and SHALL carry a marker
recording its jurisdiction and legal instrument. It SHALL NOT be preserved in Dutch on
the grounds of being the standardised legal term.

#### Scenario: An NL-specific entitlement is renamed and marked

- **WHEN** a property records a statutory severance entitlement under the WWZ
- **THEN** it SHALL be renamed to an English name such as `statutorySeveranceAmount`
- **AND** it SHALL carry a marker naming the jurisdiction and the instrument

#### Scenario: The statutory meaning is preserved as data, not as language

- **WHEN** an English name is less legally specific than the Dutch term it replaces
- **THEN** the specificity SHALL be recorded in the statute marker
- **AND** the marker SHALL be machine-readable rather than expressed only in prose

#### Scenario: Being a standardised term is not accepted as an exemption

- **WHEN** a Dutch term is the official standardised name for a legal concept
- **THEN** it SHALL still be renamed to English
- **AND** only an external product's proper name or a wire field inside an adapter SHALL
  be exempt

### Requirement: Concepts with international counterparts SHALL be abstracted, not marked

A concept that exists in other jurisdictions SHALL be renamed to the ordinary
international term rather than treated as NL-specific. The statute marker SHALL be
reserved for concepts that genuinely have no counterpart.

#### Scenario: A universal concept is abstracted plainly

- **WHEN** a schema models wage garnishment, a purchase value, or a certificate expiry
- **THEN** it SHALL be renamed to the ordinary English term
- **AND** it SHALL NOT carry a statute marker

#### Scenario: Abstraction stops short of producing an unreadable name

- **WHEN** mechanically applying the fleet abstraction dictionary would produce an
  identifier that obscures the concept
- **THEN** the concept SHALL be named for what it is, with the statute marker carrying
  the jurisdictional detail
- **AND** readability SHALL take precedence over mechanical dictionary application

#### Scenario: An agency name is abstracted only where it means the institution

- **WHEN** a Dutch agency abbreviation appears as the counterparty to a filing
- **THEN** it SHALL be abstracted to the international term for that institution
- **AND** where the abbreviation instead forms part of a named statutory milestone, the
  milestone SHALL be named for the milestone rather than for the agency

### Requirement: Published filing payloads SHALL keep their wire field names

Renaming a schema property SHALL NOT change the field names in a payload submitted to an
external authority. The schema name is ours; the submitted field name belongs to the
published message format.

#### Scenario: A filing reference is renamed in the schema but not on the wire

- **WHEN** a payroll or pension filing property is renamed to English
- **THEN** the payload submitted to the external authority SHALL still use the published
  field name
- **AND** the mapping SHALL happen at the adapter boundary

#### Scenario: A filing is exercised rather than assumed

- **WHEN** the rename touches a schema used in a filing
- **THEN** a filing SHALL be exercised end to end after the change
- **AND** a passing unit test SHALL NOT be accepted as sufficient evidence

### Requirement: Cross-app consumers SHALL be enumerated before any rename lands

shillinq reads hrmq properties. The set of properties it reads SHALL be enumerated
before renaming begins, and both apps SHALL land in the same window.

#### Scenario: The consuming reads are enumerated first

- **WHEN** the change begins
- **THEN** every shillinq read of an hrmq property SHALL be enumerated
- **AND** no rename SHALL land before that enumeration exists

#### Scenario: A desynchronised property is recognised as silent

- **WHEN** hrmq renames a property that shillinq still reads by its old name
- **THEN** the value SHALL be understood to read as absent rather than to raise an error
- **AND** neither app's passing test suite SHALL be treated as evidence of correctness

### Requirement: The change SHALL rename identifiers without altering any calculation

This change SHALL NOT modify any rate, threshold, formula or calculated result. Payroll
output SHALL be identical before and after.

#### Scenario: Payroll output is unchanged

- **WHEN** the same payroll inputs are processed before and after the rename
- **THEN** the resulting figures SHALL be identical
- **AND** any difference SHALL be treated as a defect introduced by the rename

#### Scenario: A rate constant is left untouched

- **WHEN** a renamed property holds a statutory rate or threshold
- **THEN** its value SHALL be unchanged
- **AND** only its name SHALL differ
