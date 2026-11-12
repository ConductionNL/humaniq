# time-attendance

## ADDED Requirements

### Requirement: A manager SHALL see who is present, absent or late today (REQ-TA-007)

The attendance page SHALL offer a today view for the manager's team. Each person SHALL read as
present (clocked in), late (clocked in after their rostered or pattern start), away (approved
leave or sick) or not clocked in. Only people the caller may read SHALL be listed.

Rows: `tim-attendance-overview` (humaniq matrix).

#### Scenario: A team at 09:30
- **GIVEN** a team of four: one clocked in at 08:00, one at 09:15 with a 09:00 shift, one on approved leave and one not clocked in
- **WHEN** their manager opens the today view at 09:30
- **THEN** they read as present, late, away and not clocked in

#### Scenario: Another team stays hidden
- **WHEN** a manager opens the today view
- **THEN** people outside the units they may read are not listed
