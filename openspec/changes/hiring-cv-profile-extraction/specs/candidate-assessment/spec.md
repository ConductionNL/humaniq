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

@e2e exclude The page step is the generic api-call action; what the scenario asserts is the server-side read through filinq, which needs filinq and a CV with text on the instance. `CvProfileReaderTest::testACvFillsAnEmptyProfile` asserts it against filinq's own classes, `ApplicationCvControllerTest::testEachOutcomeHasItsStatus` the answer.

#### Scenario: A profile HR typed is left alone
- **GIVEN** an application whose profile HR filled by hand
- **WHEN** HR chooses Read CV
- **THEN** the profile is unchanged

@e2e exclude A refusal that writes nothing. `CvProfileReaderTest::testAProfileHrTypedIsLeftAlone` asserts it; `ApplicationCvControllerTest::testEachOutcomeHasItsStatus` asserts the 422 the page shows.
