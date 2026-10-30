# Tasks

### Task 1: Only a reader of the object
- **spec_ref**: `openspec/changes/hours-week-host-access/specs/hours-leaf/spec.md#requirement-the-week-is-answered-only-to-a-reader-of-the-object`
- [x] `HostObjectAccess::canRead()`, asked before any read in `ObjectHoursController::week()`, 404 when no
- [x] Caller-scoped reads page through every row
