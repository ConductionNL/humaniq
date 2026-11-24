---
kind: code
---

## Why

The fleet audit's wave-5 line for humaniq reads "payroll flow + waits + date timers": the monthly
payroll run is flow-shaped orchestration living outside any engine. Today the pipeline is a
manually stepped occ chain — `occ humaniq:payroll:run` computes the draft run and payslips
(`PayrollRunService`), an accountant reads the mutation diff (`PayrollMutationService`), a human
flips `PayrollRun.status` from `draft` to `approved` by editing the object (the schema keeps the
enum deliberately lifecycle-less), `occ humaniq:glpost:run` posts the journal to shillinq and
stamps `posted`, and `occ humaniq:netpay:run` raises the SEPA batch. The ORDER and the TIMING of
those steps live entirely in the operator's head: nothing records that a run is waiting on
review, nothing carries the reviewer's decision, and a forgotten glpost is invisible until the
books do not reconcile.

OpenRegister now owns the flow engine (ADR-065, ADR-022): `FlowRun`/steps, the
`openregister.user-task` node for human waits, dispatcher-native runAs scoping for contributed
nodes, and `x-openregister-flows` as the way an app SHIPS a flow in its register file. dossiq is
the reference adopter (its `termijnbewaking-op-engine-timers` change moved deadline watching onto
FlowTimers, keeping the AWB arithmetic domain-side; its `72-committees-to-decidiq` fragment ships
a declared flow with human steps). humaniq itself already consumes the engine's decision-table
half (`rules-onto-or-decision-tables`, humaniq#289). This change moves the payroll run's
ORCHESTRATION onto the same engine, and only the orchestration.

## What Changes

- **humaniq contributes four payroll flow nodes** — thin adapters over the existing services,
  registered through OpenRegister's `RegisterFlowNodesEvent` (the dossiq
  `DossiqFlowNodeListener` pattern):
  - `humaniq.payroll-calculate` → `PayrollRunService::runFor()` (idempotent draft
    create/recalculate);
  - `humaniq.payroll-approve` → the one guarded write the flow adds: flips a `draft` run to
    `approved`, refusing any other starting status;
  - `humaniq.payroll-glpost` → `PayrollGLPostService::postRun()`;
  - `humaniq.payroll-netpay` → `PayrollNetPayService::processRun()`.
- **A shipped flow, "Loonrun"**, declared in `x-openregister-flows` on the `PayrollRun` schema
  (`lib/Settings/register.d/hr-objects.json`): manual trigger → calculate → an
  `openregister.user-task` review step (outcomes `approved`/`rejected`) → on `approved` the
  approve, glpost and netpay steps in sequence; on any other outcome the run stays `draft` and
  the flow ends. Per the engine's contract the flow arrives DISABLED, ownerless and published;
  adopting it (enabling, owning) stays a deliberate admin act.
- **The human checkpoint becomes engine state.** The review wait is an
  `openregister.user-task`: who was asked, when, and what they decided is recorded on the
  task and the flow run, instead of nowhere.

## What does NOT change

- **The payroll arithmetic stays domain.** `lib/Payroll/` (the DSL, `PayrollCalculator`,
  `SickPayCalculator`, `TaxTables`, jurisdiction packs) and every fold inside
  `PayrollRunService::generate()` are computation, not orchestration — the same line dossiq drew
  when it kept the AWB dwangsom arithmetic. No node re-implements a cent of it.
- **`PayrollRun.status` stays the domain truth.** The flow reads and advances the same enum the
  occ chain does; no parallel run-state machine is introduced, so there is nothing to migrate.
  A draft run that exists before the flow is adopted is simply picked up by the calculate
  step's existing idempotency probe (`exists` / recalculate).
- **The occ commands stay.** `humaniq:payroll:run`, `humaniq:glpost:run`, `humaniq:netpay:run`
  remain the scriptable seam; the nodes call the same services, so there is one implementation
  either way.
- **No schedule and no timers ship in this phase.** humaniq persists no pay date, no cutoff
  date and runs no payroll cron today, so there is no real date to arm a FlowTimer on and no
  existing schedule to port onto `openregister.trigger-schedule`. Shipping either would invent
  orchestration that never existed. The design documents the adoption recipe (schedule trigger
  with an explicit `runAs`; a wait/timer on a pay date once one lands in the domain) as staged
  work.

## Capabilities

### New Capabilities

- `payroll-run-as-a-flow`: the payroll run's step order, its human approval wait and its
  downstream commit steps run as an OpenRegister flow built from humaniq-contributed adapter
  nodes; the payroll computation itself stays in the domain services.

## Impact

- `lib/Flow/PayrollFlowNodeBase.php` (new) — shared metadata, config validation and run loading
  for the four nodes.
- `lib/Flow/PayrollCalculateNode.php`, `lib/Flow/PayrollApproveNode.php`,
  `lib/Flow/PayrollGlPostNode.php`, `lib/Flow/PayrollNetPayNode.php` (new) — the adapters.
- `lib/Flow/HumaniqFlowNodeListener.php` (new) — registers them on `RegisterFlowNodesEvent`.
- `lib/AppInfo/Application.php` — boot-time listener registration behind a `class_exists()`
  guard (the existing OpenRegister-optional posture).
- `lib/Settings/register.d/hr-objects.json` — the `PayrollRun` schema gains
  `configuration.x-openregister-flows` carrying the "Loonrun" flow.
- `tests/stubs/OpenRegisterFlowStub.php` (new, test-only) — `IFlowNode`, `FlowNodeRegistry`,
  `RegisterFlowNodesEvent` mirroring the real openregister@246222d signatures, loaded by
  `tests/bootstrap.php` only when the real classes are absent (the Dmn-stub pattern from
  humaniq#289).
- `tests/Unit/Flow/` (new) — node unit tests plus a declaration test that pins the shipped flow
  JSON to the contributed node ids and the engine's graph rules.
- No routes, no frontend, no data migration.
