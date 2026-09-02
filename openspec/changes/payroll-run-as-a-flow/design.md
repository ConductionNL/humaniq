# Design — payroll run as a flow

## D1. What the payroll orchestration actually is today

Measured on HEAD (post humaniq#289):

| Step | Entry point | Service | State effect |
| --- | --- | --- | --- |
| Simulate | `occ humaniq:payroll:proforma` | `ProformaPayslipService` | none (stateless) |
| Calculate | `occ humaniq:payroll:run` | `PayrollRunService::runFor()` | creates/recalculates the `draft` PayrollRun + payslips |
| Review | `occ humaniq:payroll:mutations` + UI | `PayrollMutationService` | one idempotent report object |
| Approve | a human edits the object | none | `status: draft → approved` (plain enum, deliberately no lifecycle map) |
| Post | `occ humaniq:glpost:run` | `PayrollGLPostService::postRun()` | shillinq journal + `status → posted` |
| Pay | `occ humaniq:netpay:run` | `PayrollNetPayService::processRun()` | shillinq PaymentRun (run status untouched) |

There is no cron, no TimedJob, no timer and no app-local stepper: `PayrollRun.status` is domain
truth, and the sequencing between the rows above is operator memory. That is the whole gap this
change closes — the flow IS the missing orchestrator, and nothing else moves.

## D2. Orchestration vs computation (the dossiq line)

Everything inside `PayrollRunService::generate()` — sick-pay substitution, bijtelling,
30%-regeling, retro folds, loonbeslag clamping, the calculator itself — is computation and stays
byte-identical. The nodes are adapters: `node = adapter, service = domain`. A node never
constructs `PayrollCalculator`, never reads a tax table, and never writes a Payslip.

The ONE write a node adds that no service performed before is the approve flip (D4). It is
orchestration by definition: it records a decision, computes nothing.

## D3. The contributed nodes

All four implement `OCA\OpenRegister\Service\Flow\IFlowNode` (openregister@246222d) and are
registered by `HumaniqFlowNodeListener` on `RegisterFlowNodesEvent` — the dossiq listener
pattern: a class-string list, container-resolved, one unresolvable node logged and skipped
rather than costing the others their palette place.

**runAs: the dispatcher owns it.** OpenRegister's `RegistryStepDispatcher` executes every
contributed node inside `FlowRunAsScope` (engine-owned nodes scope themselves; contributed ones
are wrapped). The humaniq nodes therefore do NOT self-wrap, do not resolve the acting user, and
do not implement `IFlowSelfScopedNode`. The services they call already pass
`_rbac: false` to ObjectService, matching the occ path.

| Node id | Calls | Config | Outcome handling |
| --- | --- | --- | --- |
| `humaniq.payroll-calculate` | `PayrollRunService::runFor(period, administrationId, recalculate)` | `period` (template, default: current month UTC), `administrationId` (template, default service convention), `recalculate` (default `true`) | `calculated`/`exists` pass the outcome onto the item under `payroll`; `failed`/`refused-not-draft` THROW so the step's `onError` policy decides |
| `humaniq.payroll-approve` | ObjectService (lazy, container) | `runId` (template, default `{{ payroll.runId }}`) | loads the run; refuses (throws) when it is not `draft`; writes `status: approved` and nothing else |
| `humaniq.payroll-glpost` | `PayrollGLPostService::postRun(run)` | `runId` (template, default `{{ payroll.runId }}`) | `posted`/`already-posted`/`skipped-no-shillinq` pass through under `glpost` (the duck-typed shillinq degradation is a documented non-error); `failed` throws |
| `humaniq.payroll-netpay` | `PayrollNetPayService::processRun(run)` | `runId` (template, default `{{ payroll.runId }}`) | `created`/`already-created`/`skipped-no-shillinq` pass through under `netpay`; `failed` throws |

Templating uses OpenRegister's `{{ dotted.path }}` convention resolved against the item's
`json`, via a small local renderer in the base class (the engine's `FlowValueTemplate` is not a
published seam for leaf apps; the two-line resolver is not worth a cross-app class dependency).

**Approve writes only `status`.** The schema declares no `approvedBy`/`approvedAt`, and
OpenRegister silently drops undeclared properties on save — so the node deliberately writes none.
WHO approved and WHEN is engine state: the task row (`completedBy`, `completedAt`) and the flow
run's step history carry it. Duplicating it onto the run object is a schema change this phase
does not make.

**Error posture is the engine's, not ours.** Nodes throw on failure instead of catching: the
engine reads the step's `onError` policy, and a swallowed failure is a silent pass-through
(the `DossiqFlowNodeBase` rationale, verbatim).

## D4. The shipped flow

Declared in `x-openregister-flows` on the `PayrollRun` schema (hr-objects.json), because that is
where a shipped flow lives (the SchemaFlowImportListener contract: imported on schema save,
arrives DISABLED and ownerless, published v1, re-import updates in place keyed on
(app, name, trigger schema)).

```
trigger-manual → calculate → review (user-task) ─approved→ approve → glpost → netpay → end
                                        └─(else)→ end-rejected
```

- `review` is `openregister.user-task`: title and description in Dutch (the audience is the
  HR admin the occ output already speaks Dutch to), `candidateGroups: ["admin"]`,
  `outcomes: ["approved", "rejected"]`, `outcomeKey: "review"`. The adopting organisation
  re-points the candidate group in the flow editor; `admin` is the only group every Nextcloud
  ships.
- Branching lives on the `review` node's `exits`: one exit conditioned
  `{"==": [{"var": "review.outcome"}, "approved"]}`, one unconditioned else — the builder
  refuses a conditioned node without an else, and the else path must NOT approve anything, so
  it ends the run with the PayrollRun still `draft` (recalculate and re-run is the retry).
- Edges bind to the exits via `fromExit` (the FlowTokenRouter contract).
- `failOnReject` stays false: a rejected review is a normal outcome (the accountant said no),
  not a step failure.

## D5. What is deliberately NOT shipped, and the runAs decision

**No `openregister.trigger-schedule`.** The node hard-requires an explicit `runAs` naming an
existing, enabled user — correctly, since a schedule has nobody by construction. A SHIPPED flow
is ownerless by the same contract, and humaniq cannot mint or assume a customer's payroll
service account. Shipping a schedule trigger with an empty `runAs` would import a flow that
fails validation the moment anyone edits it, or worse, runs as nobody. So the decision is:
the flow ships manual-triggered, and the documented adoption recipe for a monthly cadence is
"add an `openregister.trigger-schedule` node (cron `0 6 1 * *` or your own), set `runAs` to the
payroll administration's service account, and enable the flow" — making the identity choice the
deliberate act the engine's own adoption model requires.

**No FlowTimers.** dossiq armed timers on dates its domain PERSISTS (TermijnInstance
`endDateCurrent`). humaniq's payroll domain persists no pay date and no cutoff: grep confirms no
`payDate`/`betaaldag`/`cutoff` field exists on PayrollRun, Administration or the settings
surface. Arming a timer needs a real date; inventing the field is a domain change staged as a
named follow-up (D7), not smuggled into an orchestration change.

**No oversight registration.** OR's oversight discovery guards agent hops and scheduled
autonomous work. This change ships neither (no agent nodes, no schedule), so there is nothing
to register. The follow-up that adds the schedule trigger inherits that question.

## D6. Test substrate

The humaniq#289 pattern, applied to flow classes: `tests/stubs/OpenRegisterFlowStub.php`
declares `IFlowNode`, `FlowNodeRegistry` and `RegisterFlowNodesEvent` with signatures mirroring
openregister@246222d, loaded from `tests/bootstrap.php` behind `class_exists()` so the real
classes always win on a live instance. The stubs let the standalone suite construct the nodes
and drive the listener; they encode the ENGINE's signatures, not the callers' assumptions
(the fake-agrees-with-caller trap).

`PayrollFlowDeclarationTest` pins the shipped JSON against the code: every `humaniq.*` node
type in the declaration is a type the listener registers (present-is-not-wired guard), every
edge endpoint resolves, every `fromExit` names a declared exit, and the conditioned `review`
node carries an unconditioned else (the engine builder's own refusal, asserted at unit time so
it never fires at import time).

## D7. Staged follow-ups (deliberately out of this change)

1. **Pay-date wait**: land a `payDate`/`paymentDayOfMonth` field on the administration surface,
   then insert an `openregister.wait` (or a FlowTimer on the run) between approve and glpost.
2. **Schedule adoption**: the D5 recipe as documentation on the admin surface, plus oversight
   registration if the scheduled flow qualifies as autonomous work.
3. **Retire the manual approve edit**: once adopted flows carry the approval everywhere, guard
   the raw `status` edit behind the same decision trail (a lifecycle map or guard), so the flow
   is the only approver. Not before: today the raw edit IS the production path.
