## ADDED Requirements

### Requirement: Every portal page names its menu group in Dutch
Every page humaniq contributes MUST carry a `group`: "Werk en uren" (external employee, client, manager),
"Vacatures" (candidate), "Uw nieuwe baan" (new hire), "Uw vroegere baan" (former employee). The contribution label
MUST be that group, not the app name. Every collection and action label MUST be Dutch. A collection shown on a page
MUST carry the page's name and the intro text MUST NOT repeat it as a heading.

#### Scenario: A client reviews hours
- **GIVEN** a client contact signed in on the site
- **WHEN** the site builds the menu
- **THEN** "Te beoordelen urenstaten" MUST sit under "Werk en uren"

#### Scenario: A manager's team hours
- **GIVEN** a manager signed in on the site
- **WHEN** they open "Urenstaten van uw team"
- **THEN** the page MUST show that name once
