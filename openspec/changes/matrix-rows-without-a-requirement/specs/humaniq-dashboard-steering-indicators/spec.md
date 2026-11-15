# humaniq-dashboard-steering-indicators

## ADDED Requirements

### Requirement: The billable share SHALL be readable per team and per person (REQ-DSI-010)

Next to the one ratio per administration (REQ-DSI-002), humaniq SHALL show the billable share
of approved hours per org unit and per employee for a period, on the project hours page. The
figures SHALL come through the guarded analytics endpoint, so a manager reads only the units they
lead and their own people, and HR and the accountant role read every unit of the administration.

Rows: `rep-billable-ratio` (humaniq matrix).

#### Scenario: A manager compares the teams they lead
- **GIVEN** a manager who leads Team Vergunningen and Team Burgerzaken, with 120 approved hours
  in October of which 84 are billable for Vergunningen and 60 of 100 for Burgerzaken
- **WHEN** they open the billable share per team on the project hours page for October
- **THEN** Vergunningen shows 70 percent and Burgerzaken 60 percent, each with its hours

#### Scenario: The share per person in one team
- **GIVEN** the same October in Team Vergunningen
- **WHEN** the manager opens the team's row
- **THEN** each employee of the team is listed with their approved hours and billable share

#### Scenario: Another team stays closed
- **GIVEN** a manager who does not lead Team Belastingen
- **WHEN** they request the billable share for Team Belastingen
- **THEN** the request is refused and no figure is returned
