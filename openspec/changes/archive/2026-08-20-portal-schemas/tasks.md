# Tasks: portal-schemas

<!-- HYDRA CAP: max 20 unindented `- [ ]` lines. This file uses 8.
     Acceptance criteria are plain bullets, not checkboxes. -->

## Implementation Tasks

### Task 1: Ship the LeaveRequest schema fragment with its declarative lifecycle

- **spec_ref**: `openspec/changes/portal-schemas/specs/portal-schemas/spec.md#requirement-leaverequest-schema-exists-in-the-hrmq-register-req-psch-001`
- **files**: `lib/Settings/register.d/hr-leave.json`
- **acceptance_criteria**:
  - GIVEN the new fragment WHEN parsed THEN `LeaveRequest` defines employeeId (uuid ref to Employee), leaveType (enum holiday/sick/unpaid/special/care/parental), startDate, endDate, hours (optional), reason (optional), status (default draft) plus submittedAt/approvedBy/approvedAt/rejectionReason, with required = employeeId, leaveType, startDate, endDate, status
  - GIVEN the lifecycle (REQ-PSCH-002) WHEN inspected THEN it is draft → submitted → approved/rejected with `submit` re-allowed from `rejected`, and `approve`/`reject` require `OCA\Hrmq\Lifecycle\NoSelfApprovalGuard` — no imperative transition PHP anywhere
  - GIVEN gate-28 WHEN the helper runs THEN every property has a title + description
  - GIVEN the fragment WHEN searched THEN it contains no seed/demo objects (fragment objects go live)
- [x] Implement
- [x] Test

### Task 2: Add the clientRef scoping property to Timesheet (+ gate-28 titles)

- **spec_ref**: `openspec/changes/portal-schemas/specs/portal-schemas/spec.md#requirement-timesheet-carries-an-optional-clientref-uuid-domain-reference-req-psch-003`
- **files**: `lib/Settings/register.d/hr-timesheet.json`
- **acceptance_criteria**:
  - GIVEN the edited fragment WHEN parsed THEN `clientRef` is `type: string`, `format: uuid`, `nullable: true`, title "Client", NOT required — existing objects stay valid
  - GIVEN gate-28 WHEN the helper runs THEN every Timesheet property (pre-existing ones included) now carries a title + description
  - GIVEN the repo at HEAD WHEN searched for a Project schema THEN none exists, so clientRef lands on Timesheet only (verified)
- [x] Implement
- [x] Test

### Task 3: Bump the version gates

- **spec_ref**: `openspec/changes/portal-schemas/specs/portal-schemas/spec.md#requirement-version-bumps-gate-the-re-import-req-psch-004`
- **files**: `lib/Settings/hrmq_register.json`, `lib/Settings/register.d/hr-timesheet.json`, `lib/Settings/register.d/hr-leave.json`
- **acceptance_criteria**:
  - GIVEN the diff WHEN compared THEN register `info.version` 0.1.0 → 0.2.0, Timesheet 0.1.0 → 0.2.0, LeaveRequest new at 0.1.0
  - GIVEN each edited JSON WHEN loaded with `python3 -c "import json; json.load(...)"` THEN it parses without error
- [x] Implement
- [x] Test

### Task 4: Register the capability spec and validate

- **spec_ref**: `openspec/changes/portal-schemas/specs/portal-schemas/spec.md`
- **files**: `openspec/specs/portal-schemas/spec.md`, `openspec/changes/portal-schemas/*`
- **acceptance_criteria**:
  - GIVEN the capability WHEN the change is in flight THEN `openspec/specs/portal-schemas/spec.md` exists with status `in-progress` pointing at this change
  - GIVEN the CLI WHEN `openspec validate` runs THEN this change passes
- [x] Implement
- [x] Test

## Quality checklist

- No PHP touched → PHPUnit N/A for this change (the chained code change carries the unit tests); JSON gates + gate-28 helper are the mechanical checks
- No API endpoints → no Newman collection; no UI change → no Playwright (the portal renders in portaliq)
- All property titles/descriptions and enum values are ENGLISH source (i18n policy)
- `openspec validate` passes
