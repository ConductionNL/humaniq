# Design: shipped HR workflows beyond payroll

## Context

Read at `development` af702f78.

- `PayrollRun` in `lib/Settings/register.d/hr-objects.json` declares `x-openregister-flows`
  with one flow, "Loonrun": `trigger: manual`, nodes `openregister.trigger-manual`,
  `humaniq.payroll-calculate`, `openregister.user-task` (candidate group `admin`, outcomes
  approved and rejected), `openregister.switch`, the humaniq approve, glpost and netpay nodes,
  `openregister.end`. Its description states the adoption contract: the flow arrives disabled
  and ownerless.
- `lib/Repair/RepublishLoonrunFlow.php` republishes that one flow on upgrade.
- OpenRegister's flow nodes include `openregister.trigger-object` (one event on one register
  and schema, `openregister/lib/Service/Flow/Nodes/TriggerObjectNode.php`),
  `openregister.user-task`, `openregister.wait`, `openregister.switch`,
  `openregister.object-read`, `openregister.set-fields`, `openregister.send-notification`
  and `openregister.end`.
- humaniq's manifest already carries the engine's pages: `Flows` and `FlowDetail`
  (`src/manifest.json:795` and `:805`).
- `Onboarding` (`hr-onboarding.json`): `employeeId`, `startDate`, `status`, checklist
  booleans `contractSigned`, `widCheckDone`, `bsnValidated`, `ibanVerified`, `itProvisioned`,
  `pensioenAangemeld`. `Offboarding`: `employeeId`, `lastWorkingDay`, `reason`, `status`,
  `exitGesprekDone` (a date), `assetsIngeleverd`, `toegangIngetrokken` and the settlement
  fields.
- `LeaveRequest` (`hr-leave.json`): `status`, `managerUserId`, `submittedAt`.

## Goals / Non-Goals

**Goals**

- Three HR flows an employer can switch on without writing anything.
- Every task a flow creates, once done, is visible on the case it belongs to.

**Non-Goals**

- New humaniq nodes. Every step here is an OpenRegister node.
- Guarding lifecycle transitions from a flow. The case lifecycles stay as they are.

## Decisions

### D1. Object triggers, one per flow

"Indiensttreding": `openregister.trigger-object` on `created` of `Onboarding`;
"Uitdiensttreding": on `created` of `Offboarding`; "Verlofaanvraag blijft liggen": on
`updated` of `LeaveRequest`, followed by a `switch` that continues only when `status` is
`submitted` (the trigger names one event, the switch narrows it).

### D2. Tasks tick the checklist

Each `user-task` has outcomes `gedaan` and `niet-van-toepassing`; on `gedaan` a
`set-fields` node writes the matching field on the case: true for the booleans
`itProvisioned`, `assetsIngeleverd` and `toegangIngetrokken`, and the completion date for
`exitGesprekDone`, which is a date field. Candidate groups default to
`humaniq-hr` (from `compliance-roles-and-field-access`) or `admin` until that change lands; the
manager task uses the case employee's `managerUserId` as performer.

### D3. Escalation by waiting

The leave flow `wait`s `escalationDays` (default 5, a flow variable), re-reads the request
with `object-read`, and when still `submitted` sends a notification to the HR group and opens
an HR `user-task`. The notification rides `openregister.send-notification`; the rules of
`platform-notifications` stay the channel for the ordinary submit and decision messages.

### D4. Shipped disabled, republished on upgrade

Every flow carries a description with the adoption steps and arrives disabled, ownerless and
published, as Loonrun does. The republish repair step is generalised from one flow to every
flow humaniq declares.

## Declarative-vs-imperative decision (ADR-031)

| behaviour | path | why |
|---|---|---|
| the three processes | declarative `x-openregister-flows` | the engine's shipping format |
| ticking checklist fields | `openregister.set-fields` node | no humaniq code |
| republish on upgrade | the existing repair step, generalised | reuse |

## Seed data

No objects. The flows themselves are shipped definitions; a live check on a dev instance
creates an onboarding case after enabling "Indiensttreding".

## Risks / Trade-offs

- [A flow enabled with the wrong candidate group] → the description names the setting, and a
  task nobody can see is visible on `FlowDetail` as waiting.
- [Leave escalation fires on every update] → the switch stops it unless the request is still
  submitted, and the wait makes it one escalation per request.

## Open Questions

- Should the onboarding flow also start the preboarding portal access from
  `hiring-portal-audiences`? Left for when that change is built.
