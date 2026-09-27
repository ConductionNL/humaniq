# education-staff-deployment

## ADDED Requirements

### Requirement: Each teacher SHALL have a yearly deployment plan against the norm for their contract (REQ-EDU-001)

humaniq SHALL provide a `DeploymentPlan` per teacher and school year with lines of planned
hours per category (teaching, lesson-related tasks, school tasks, professional development,
sustainable employability), a teaching line naming its subject and education level. The
plan SHALL show its planned total, its norm and the difference, and SHALL flag an overload.
The norm SHALL be the CAO's annual norm prorated to the teacher's contracts in the school
year, used only when that CAO value is verified; otherwise HR SHALL enter the norm and the
plan SHALL say it was entered by hand. A teacher SHALL NOT confirm their own plan.

Rows: `tt-staff-deployment` (planninq matrix, owned by humaniq), tender https://www.tenderned.nl/aankondigingen/overzicht/271977.

#### Scenario: A school leader sees an overload
- **GIVEN** a full-time teacher with a norm of 1,659 hours and plan lines totalling 1,700
  hours
- **WHEN** the school leader opens `DeploymentPlanDetail`
- **THEN** the plan shows 1,700 planned, 1,659 norm and an overload of 41 hours

#### Scenario: An unverified CAO figure is not used
- **GIVEN** the CAO PO normjaartaak is still marked unverified
- **WHEN** a plan is created for a CAO PO teacher
- **THEN** the plan asks for the norm to be entered and shows its source as entered by hand

#### Scenario: A part-time contract halfway through the year
- **GIVEN** a teacher on a 24-hour contract starting 1 February and a verified annual norm of
  1,659 hours
- **WHEN** the norm is computed for the school year
- **THEN** it is 1,659 times 0.6 times the share of the school year from 1 February, and the
  plan shows that figure

### Requirement: A school leader SHALL see every teacher's plan for their school (REQ-EDU-002)

For an org unit and school year humaniq SHALL list every teacher placed there with planned,
norm and difference, and every placed teacher without a plan. It SHALL include only people
the caller may read.

Rows: `tt-staff-deployment` (planninq matrix, owned by humaniq).

#### Scenario: Room in the team
- **GIVEN** a school with one overloaded teacher and one 0.6 teacher planned 120 hours under
  the norm
- **WHEN** the school leader opens `Taakbeleid` for the school
- **THEN** both are listed with their difference, the overloaded teacher first

### Requirement: The IPTO staff count file SHALL be produced from the confirmed plans (REQ-EDU-003)

`Export IPTO` on an administration SHALL produce, for a reference week, the staff count file
in the layout OCW publishes for the IPTO, from the teaching lines of the confirmed plans
active in that week, identifying the school by its BRIN number, and SHALL record which
version of the specification it followed. It SHALL be available to admins and HR only.

Rows: `tt-staff-count-export` (planninq matrix, owned by humaniq).

#### Scenario: The November export
- **GIVEN** a secondary school with confirmed plans for 40 teachers and a BRIN number on the
  administration
- **WHEN** an HR adviser exports IPTO for the second week of November
- **THEN** a file with one row per teacher, subject and education level with weekly hours is
  stored on the administration, naming the specification version

#### Scenario: A draft plan is left out
- **GIVEN** a teacher whose plan is still `concept`
- **WHEN** the export runs
- **THEN** that teacher's hours are not in the file and the export lists them as not
  confirmed
