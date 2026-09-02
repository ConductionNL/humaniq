# payroll-run-as-a-flow

## ADDED Requirements

### Requirement: humaniq contributes the payroll orchestration steps as flow nodes (REQ-PRF-001)

humaniq SHALL contribute four flow node types to OpenRegister's node catalogue, registered
through `RegisterFlowNodesEvent`: `humaniq.payroll-calculate`, `humaniq.payroll-approve`,
`humaniq.payroll-glpost` and `humaniq.payroll-netpay`. Each node SHALL be a thin adapter over
the existing domain service (`PayrollRunService`, ObjectService for the approve write,
`PayrollGLPostService`, `PayrollNetPayService`) and SHALL NOT re-implement any payroll
computation. Registration SHALL happen at boot behind a `class_exists()` guard so an instance
without OpenRegister still boots, and a node that cannot be constructed SHALL be logged and
skipped without costing the other nodes their registration.

#### Scenario: nodes register when OpenRegister is present

- **GIVEN** OpenRegister is installed and dispatches `RegisterFlowNodesEvent`
- **WHEN** humaniq's listener handles the event
- **THEN** all four `humaniq.payroll-*` node types are registered on the catalogue

#### Scenario: a failing node does not silence its siblings

- **GIVEN** one node class cannot be resolved from the container
- **WHEN** the listener handles the event
- **THEN** the failure is logged and the remaining nodes are still registered

@e2e exclude backend flow-node registration; exercised by unit tests against the vendored engine event stubs, no browser surface

### Requirement: the calculate node drives the existing idempotent run generation (REQ-PRF-002)

`humaniq.payroll-calculate` SHALL call `PayrollRunService::runFor()` with a period resolved
from its config (`{{ }}` templated against the item, defaulting to the current month UTC), an
optional administration id, and `recalculate` defaulting to true. Outcomes `calculated` and
`exists` SHALL be placed on each outgoing item under the `payroll` key; outcomes `failed` and
`refused-not-draft` SHALL throw so the engine's `onError` policy decides. The node SHALL NOT
write any object itself.

#### Scenario: a draft run is calculated and the outcome travels with the item

- **GIVEN** a flow item and a period with computable employees
- **WHEN** the calculate node executes
- **THEN** `PayrollRunService::runFor()` is called once and the outgoing item's `payroll` key
  carries the outcome including `runId`

#### Scenario: an already committed run refuses loudly

- **GIVEN** the period's run is already `approved`
- **WHEN** the calculate node executes
- **THEN** it throws instead of returning a success-shaped item

@e2e exclude backend node adapter; behaviour pinned by unit tests over a service double built from the real outcome vocabulary

### Requirement: approval is a recorded human decision and the only new write (REQ-PRF-003)

The shipped flow SHALL place an `openregister.user-task` review step between calculation and
approval, with outcomes `approved` and `rejected` landing under the `review` key.
`humaniq.payroll-approve` SHALL load the run named by its config (default
`{{ payroll.runId }}`), SHALL refuse (throw) when the run's status is not `draft`, and SHALL
write `status: approved` and no other field. On any review outcome other than `approved` the
flow SHALL end with the run still `draft`.

#### Scenario: an approved review commits the run

- **GIVEN** a draft run and a review task completed with outcome `approved`
- **WHEN** the approve node executes
- **THEN** the run is saved with `status: approved` and every other field unchanged

#### Scenario: a non-draft run cannot be approved twice

- **GIVEN** a run whose status is already `approved` or `posted`
- **WHEN** the approve node executes
- **THEN** it throws and writes nothing

@e2e exclude backend node adapter over ObjectService; pinned by unit tests against the ObjectService stub signatures

### Requirement: the downstream commit steps reuse the shillinq handoff services unchanged (REQ-PRF-004)

`humaniq.payroll-glpost` SHALL call `PayrollGLPostService::postRun()` and
`humaniq.payroll-netpay` SHALL call `PayrollNetPayService::processRun()` on the run named by
their config. The services' duck-typed shillinq degradation (`skipped-no-shillinq`) and their
idempotency outcomes SHALL pass through as item data under `glpost` / `netpay`; only the
services' `failed` outcome SHALL throw.

#### Scenario: absent shillinq degrades without failing the flow

- **GIVEN** shillinq is not installed
- **WHEN** the glpost node executes on an approved run
- **THEN** the item carries the `skipped-no-shillinq` outcome and the flow continues

@e2e exclude backend node adapters; outcome routing pinned by unit tests

### Requirement: the Loonrun flow ships declaratively and arrives inert (REQ-PRF-005)

humaniq SHALL declare one flow named `Loonrun` in `x-openregister-flows` on the `PayrollRun`
schema: manual trigger, calculate, the review user task, an `openregister.switch` gate whose
`approved` exit is conditioned on `review.outcome` with an unconditioned else, then approve,
glpost, netpay and end nodes. Every declared `humaniq.*` node type SHALL be a type the
listener registers, every edge endpoint SHALL resolve to a declared node, every `fromExit`
SHALL name a declared exit on its source node, and the conditioned gate SHALL carry an
unconditioned else. The declaration SHALL NOT set an owner, an enabled state or a schedule
trigger.

#### Scenario: the declaration and the code cannot drift apart

- **GIVEN** the shipped register fragment and the listener's node list
- **WHEN** the declaration test runs
- **THEN** it fails on any `humaniq.*` type the listener does not register, any dangling edge
  endpoint, any `fromExit` naming an undeclared exit, and any conditioned node without an else

@e2e exclude declarative register fragment; structural pinning is a unit-level concern (the dossiq CaseFlowDeclarationTest precedent)

### Requirement: contributed nodes rely on the dispatcher's runAs scoping (REQ-PRF-006)

The humaniq flow nodes SHALL NOT resolve or impersonate an acting identity themselves: no
`runAs()` calls, no `IFlowSelfScopedNode` declaration, no app-local scope wrapper. The engine's
`RegistryStepDispatcher` applies `FlowRunAsScope` to every contributed node.

#### Scenario: no self-scoping ships

- **GIVEN** the four node classes
- **WHEN** they are inspected
- **THEN** none references `runAs`, `runAsSystem` or `IFlowSelfScopedNode`

@e2e exclude structural constraint on backend classes; asserted by a unit test scanning the node sources
