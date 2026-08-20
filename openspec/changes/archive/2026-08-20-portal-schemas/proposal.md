---
kind: config
---

# Proposal: portal-schemas

## Summary

Add the two declarative register pieces the portaliq fleet review
(`PORTALIQ-FLEET-REVIEW-2026-07-06.md`) flagged as missing before hrmq can
contribute to the shared external portal (hydra ADR-046 + 2026-07-06
amendment, contribution contract v2): a new **`LeaveRequest`** schema with a
declarative `x-openregister-lifecycle` approval state machine (ADR-031), and
an optional **`clientRef`** UUID scoping property on the **`Timesheet`**
schema so a client can review the billable hours booked against them. Pure
register configuration — no PHP, no frontend.

**Chain (ADR-032):** this change is the **config head** of a two-change
chain. The follow-up `portal-contribution` change (kind: code,
`depends_on: [portal-schemas]`) ships the ADR-046 provider class whose
manifest references the `LeaveRequest` schema and the `clientRef` scope
field declared here. Tracking issue: Conduction/hrmq#4.

## Motivation

hrmq is a Wave-1 portaliq contributor ("commerce — JWT edge suffices"). Its
external-employee story (payslips, contracts, timesheets, expenses) is
already schema-complete, but the fleet review found two gaps:

1. **Leave/Absence schema MISSING** — external employees must be able to
   request and track leave through the portal; hrmq has no leave object at
   all today.
2. **Client reference on timesheet MISSING** — the "client approves billable
   hours" story needs a scoping property that identifies the client on a
   timesheet; without it the `client` audience has nothing to be scoped by.

Both are declarative schema work and must land before the provider class can
reference them, hence the config-head → code chain (ADR-032).

## Affected Projects

- [x] Project: `hrmq` — new `lib/Settings/register.d/hr-leave.json` fragment (`LeaveRequest` schema + lifecycle), `clientRef` property + gate-28 titles on `lib/Settings/register.d/hr-timesheet.json` (schema 0.1.0 → 0.2.0), register version bump in `lib/Settings/hrmq_register.json` (0.1.0 → 0.2.0).

## Scope

### In Scope

- New `LeaveRequest` schema (fragment `hr-leave.json`, ADR-037 modular
  fragment style): `employeeId` (UUID ref to the Employee domain object),
  `leaveType` (enum `holiday`/`sick`/`unpaid`/`special`/`care`/`parental`,
  aligned with Dutch HR practice — Wet arbeid en zorg / BW 7),
  `startDate`, `endDate`, `hours` (optional), `reason` (optional), `status`,
  plus the repo-standard workflow stamps (`submittedAt`, `approvedBy`,
  `approvedAt`, `rejectionReason`).
- Declarative `x-openregister-lifecycle` on `LeaveRequest`
  (draft → submitted → approved/rejected) reusing the shared
  `OCA\Hrmq\Lifecycle\NoSelfApprovalGuard` on `approve`/`reject` — the exact
  shape the Timesheet and Expense schemas already use (ADR-031).
- `clientRef` (`type: string`, `format: uuid`, optional, title "Client") on
  the `Timesheet` schema — a domain-object reference (client
  contact/organisation), never a Nextcloud user id (ADR-046 A4).
- Gate-28 compliance on the touched fragment: human-friendly `title` +
  `description` on every property of the changed `Timesheet` schema and the
  new `LeaveRequest` schema (ADR-011).
- Version bumps so OpenRegister's version-gated `importFromApp` re-imports:
  register `0.1.0 → 0.2.0`, `Timesheet` `0.1.0 → 0.2.0`, `LeaveRequest` new
  at `0.1.0`.

### Out of Scope

- The ADR-046 provider class, manifest, and unit tests — the chained
  `portal-contribution` change ships those.
- A `clientRef` on any project schema — hrmq has **no** Project schema
  (verified at HEAD: `Timesheet.projectId` is a plain string reference and
  the only project-shaped field in the register), so there is no natural
  second home for the client reference.
- Seed/demo `LeaveRequest` objects — hrmq seeds via `register.d` fragment
  objects (`hr-seed.json`) and fragment objects go **LIVE** on import, so
  demo objects are deliberately not added (see design.md, Seed Data).
- hrmq manifest pages (`src/manifest.json`) for leave — internal back-office
  UI for leave approval is a separate feature; this wave only unlocks the
  external portal surface.
- Any imperative leave business rules (balances, statutory entitlement
  calculations) — out of scope for the portal wave.

## Approach

Pure ADR-037 fragment work: one new fragment (`hr-leave.json`) and one edited
fragment (`hr-timesheet.json`), both deep-merged onto
`hrmq_register.json` by `SettingsService::loadRegisterConfigData()` and
imported version-gated (the fragment-content signature is folded into the
version, and the explicit register/schema bumps make the intent reviewable).
The lifecycle is declarative per ADR-031; the only referenced PHP is the
already-shipped `NoSelfApprovalGuard`. Details in design.md.

## New Dependencies

None. The guard referenced by the lifecycle already ships with hrmq.

## Impact

- `lib/Settings/register.d/hr-leave.json` — new fragment, `LeaveRequest`
  schema + lifecycle.
- `lib/Settings/register.d/hr-timesheet.json` — additive `clientRef`
  property, per-property titles (gate-28), version 0.1.0 → 0.2.0.
- `lib/Settings/hrmq_register.json` — `info.version` 0.1.0 → 0.2.0.
- No PHP, routes, frontend, or info.xml changes.

## Cross-Project Dependencies

None at build time. At runtime the chained `portal-contribution` change (and
portaliq, when installed) consumes these schemas; portaliq's contract-v2
implementation lands in its own repo in parallel.

## Risks

### Risk 1: `format: uuid` on new properties vs slug-style legacy references

**Severity:** Low — **Mitigation:** existing `employeeId` properties (plain
`type: string`, no format) are left untouched because the live seed objects
use slug-style references (`employee-jansen`); only the NEW
`LeaveRequest.employeeId` and `Timesheet.clientRef` declare `format: uuid`,
and `clientRef` is optional + nullable so every existing Timesheet object
(including the three live seeds) stays valid.

### Risk 2: Register re-import misses the changes

**Severity:** Low — **Mitigation:** the import is double-gated on the
register version AND the fragment-content signature
(`SettingsService::loadRegisterConfigData()` folds `+frag.<md5>` into the
version), and both the register and the touched schema versions are bumped
explicitly in the same edit. JSON validity is verified mechanically.

## Rollback Strategy

Delete `hr-leave.json` and revert `hr-timesheet.json` /
`hrmq_register.json`. `clientRef` is additive and optional, so no object
data is lost; existing Timesheet objects simply keep no client reference. A
re-import with the reverted (lower) version is forced once via
`loadConfigurationForced()` if needed.
