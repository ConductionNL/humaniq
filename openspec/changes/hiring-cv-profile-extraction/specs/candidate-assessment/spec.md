# candidate-assessment

## ADDED Requirements

### Requirement: HR SHALL be able to read a candidate's profile from their CV (REQ-CAS-004)

`ApplicationDetail` SHALL offer Read CV, which asks filinq to extract the education level,
years of experience and competence codes from the application's CV file and SHALL fill them
only when the profile is empty. When filinq is absent or cannot read the file, the
application SHALL be left unchanged and the answer SHALL say why.

Rows: `dm-vacancy-matching` (humaniq matrix).

#### Scenario: A CV fills an empty profile
- **GIVEN** an application with a CV and no profile, and filinq installed
- **WHEN** HR chooses Read CV
- **THEN** the education level, years and competences are filled from the CV

#### Scenario: A profile HR typed is left alone
- **GIVEN** an application whose profile HR filled by hand
- **WHEN** HR chooses Read CV
- **THEN** the profile is unchanged
