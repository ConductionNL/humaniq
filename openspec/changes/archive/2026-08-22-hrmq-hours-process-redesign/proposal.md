---
kind: code+config
---

## Why

hrmq's hours process is one schema doing two jobs. `Timesheet`
(`lib/Settings/register.d/hr-timesheet.json`) is simultaneously the thing an employee books
("urenregistratie": one period, one `hours` number, one project) and the thing a manager approves
("urenstaat": the submit → approve/reject lifecycle). That conflation produces four concrete
defects, all verified against this checkout:

1. **No individual bookings.** An employee cannot book "Tuesday 09:00–17:30 on project Alpha" —
   only a single per-period total. Every downstream consumer (cost allocation via
   `hr-cost-rate.json`'s Timesheet extension, the `nl.conduction.hrmq.timeentry.approved`
   CloudEvent) pretends a period has exactly one project and one cost centre.
2. **Process fields are form fields.** `status`, `submittedAt`, `approvedBy`, `approvedAt` and
   `rejectionReason` are ordinary writable schema properties. The auto-generated create/edit forms
   render them, so an employee can hand-type their own approval. The `TimesheetDetail` page's
   "Approval" data widget already papers over this with `editable: false` overrides
   (`src/manifest.json`, `TimesheetDetail`), but the index-page create dialog and the generic edit
   form do not — and nothing server-side stops a direct API write to `approvedBy`.
3. **Stamping never actually happens on the sanctioned path.** The schema promises "approvedBy and
   approvedAt are stamped on the carrying write", but OpenRegister's transition endpoint
   (`openregister/lib/Controller/TransitionController.php:71`,
   `openregister/lib/Service/Lifecycle/TransitionEngine.php:246`) accepts only `{action}` and
   mutates only the lifecycle field. A timesheet approved through
   `CnLifecycleActions` today ends `approved` with `approvedBy: null`, `approvedAt: null` — and the
   approved-time-entry CloudEvent ships empty provenance
   (`lib/Service/TimeEntryEventService.php:209-210` falls back to `''`/`now()`).
4. **Denormalized identity fields are hand-maintained.** `userId`, `managerUserId` and
   `administrationId` are documented as "populated by HR/back-office". Two of the three seeded
   timesheets carry no `userId` (`hr-seed.json`: `timesheet-devries-2026-05`,
   `timesheet-bakker-2026-05`), so they are invisible on every `@me` self-service page — the
   fail-closed behaviour REQ-MHS-002 specifies, doing exactly the wrong thing for real rows.

## What Changes

- **New `TimeEntry` schema** (English, per fleet decision; NL label "urenregistratie" via l10n):
  an individual booking with `startedAt`/`endedAt` timestamps, break, description, optional
  project, billable flag — plus server-stamped denormalizations. Verb surface: "uren boeken".
- **`Timesheet` becomes the period aggregate** ("urenstaat"): keeps `period` and the
  submit/approve/reject/reopen lifecycle (reusing `x-openregister-lifecycle` +
  `OCA\Hrmq\Lifecycle\NoSelfApprovalGuard` unchanged); its `hours`, `projectId`, `costCenter` and
  `billable` become server-recomputed aggregates over its entries. A new `TimesheetNotEmptyGuard`
  refuses submitting an empty timesheet.
- **Approval becomes a process, not form fields.** A pre-save stamping listener makes
  `status`/`submittedAt`/`approvedBy`/`approvedAt`/`rejectionReason` inert to client input and
  stamps them on the lifecycle edges of the carrying write — fixing defect 3 for the CloudEvent
  too. Rejection reason is captured by the approver at reject time (needs small nc-vue +
  OpenRegister enhancements, flagged as explicit dependency work items — Vue logic belongs in
  nc-vue, transition payloads belong in OpenRegister).
- **Server-side auto-stamping** of `userId` (from `employeeId` → `Employee.nextcloudUserId`),
  `managerUserId` (from the org structure: active `OrgAssignment` → `OrgUnit.managerId` →
  manager's `nextcloudUserId` — the exact chain `lib/Standards/Checks/NlOrgChecks.php` audits),
  `administrationId` and derived `costCenter` (active `OrgAssignment` → `OrgUnit.costCenter`).
  None of these is ever a form field again.
- **Every hours form is an allowlist.** The redesigned pages configure their forms via
  `config.includeFields` / `fieldOverrides` (supported by `CnIndexPage.vue:288-305` and
  `fieldsFromSchema()` in `@conduction/nextcloud-vue/src/utils/schema.js:464`), so each form shows
  exactly the process-relevant fields — nothing inherited by accident from the schema.
- **Page redesign on the fragment pipeline**: `MijnUren` becomes the TimeEntry booking page; new
  `MijnUrenstaten`, `TimeEntries` (HR), `TimeEntryDetail` pages; `TimesheetDetail` gains an
  entries list and read-only process panels; the approval queues keep their filters and gain a
  clear open-to-approve flow. All page work lands as manifest fragments against the structure the
  `hrmq-manifest-fragment-pipeline` change installs (modified pages edited in their domain
  fragment `src/manifest.d/hr-timesheet.json`; new pages in a per-change fragment, per ADR-037).
- **Idempotent migration** (repair step) backfills the denormalizations on existing Timesheet rows
  and synthesizes one `origin: "migration"` TimeEntry per legacy timesheet, with warn-once
  summary logging.

## Capabilities

- **Modified**: `time-entry-capture` (entry granularity, aggregation, event-contract
  clarification), `hrmq-timesheet-approval` (stamping, inert process fields, rejection-reason
  capture, form allowlists), `mss-team-scope` (`managerUserId` becomes a server-maintained cache),
  `mijn-hr-self-service` (`userId` auto-stamped; Mijn pages redesigned),
  `employer-hourly-cost-rate` (cost-allocation references live on the entry, never hand-typed).
- **Added**: none (no new capability directory; TimeEntry lives inside `time-entry-capture`,
  which has owned this domain since its inception).

## Impact

- `lib/Settings/register.d/hr-timesheet.json` (TimeEntry schema, Timesheet rework, version bumps),
  `hr-cost-rate.json` (allocation extension moves to TimeEntry), `hr-seed.json` (seed entries).
- New `lib/Listener/` stamping + aggregation listeners, new `lib/Lifecycle/TimesheetNotEmptyGuard`,
  new `lib/Repair/` migration step; unit tests for each.
- `src/manifest.d/hr-timesheet.json` (modified pages) + new per-change fragment (new pages).
- `tests/e2e/spec-coverage/` gains `hours-process.spec.ts`; `core-journeys.spec.ts`'s
  "Add Timesheet" assertion changes (timesheets are no longer hand-created).
- **External dependencies (separate repos, flagged work items — see design.md Decision 6):**
  `@conduction/nextcloud-vue` (transition input prompt; optional row-level queue actions) and
  `openregister` (transition endpoint accepts allowlisted input data). hrmq degrades gracefully
  until they land.
- Event contract `nl.conduction.hrmq.timeentry.approved` and the typed `TimesheetApprovedEvent`:
  **envelope unchanged**; provenance fields become reliably populated (they are empty today on the
  endpoint path); aggregate-field semantics documented in the spec delta. No version bump needed.

## Non-Goals

- **Menu restructure.** The ADR-097 budget work and any relocation of the hours pages in the
  navigation tree stay out — that is the separate dashboard/navigation change. This change edits
  page content and adds leaf entries under the existing groups only.
- Changing the CloudEvent envelope or the typed event's fields (additive-only guarantee kept).
- Cost-centre bookkeeping semantics (shillinq domain) — hrmq only derives and forwards.
- Rostering/attendance (`hr-attendance.json`, `hr-roster.json`) — clock-in/out devices and shift
  planning are adjacent capabilities, untouched.
- Collapsing the role-lens approval-queue duplicates (`TimesheetApproval` vs
  `TeamUrengoedkeuring`) — ADR-097 §5 work, owned by the navigation change.
