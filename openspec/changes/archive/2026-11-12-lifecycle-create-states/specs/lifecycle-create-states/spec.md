# lifecycle-create-states

## ADDED Requirements

### Requirement: The register declares the states humaniq creates objects in

Every lifecycle state humaniq's own code creates an object in SHALL be the
schema's `initial` state or listed in `x-openregister-lifecycle.createStates`.

#### Scenario: a collective cycle proposes adjustments

- **WHEN** a collective cycle proposes an adjustment for an employee
- **THEN** the `CompAdjustment` is created in `proposed`, which the schema lists in `createStates`
- @e2e exclude {register declaration; covered by `LifecycleCreateStatesTest`}

#### Scenario: a learniq certificate becomes a training record

- **WHEN** learniq issues a certificate to an employee
- **THEN** the `TrainingRecord` is created as `gevolgd`, which the schema lists in `createStates`
- @e2e exclude {register declaration; covered by `LifecycleCreateStatesTest`}
