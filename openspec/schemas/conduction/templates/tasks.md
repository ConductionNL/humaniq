# Tasks: {{change_name}}

<!-- HYDRA CAP: The supervisor rejects specs with more than 20 lines matching `^- \[ \]`
     (unindented checkboxes). Count before writing: each `- [ ] Implement` / `- [ ] Test`
     costs 1. Acceptance criteria MUST be plain text bullets (- text), NOT checkboxes.
     If your task list + the standard sections below would exceed 20, split the change
     per ADR-032 or consolidate small tasks. -->

## Implementation Tasks

<!-- Each task should be small enough for one builder iteration.
     Include spec_ref and files for the JSON export.
     Order by dependency — foundations first. -->

### Task 1: {{task_title}}
- **spec_ref**: `openspec/changes/{{change_name}}/specs/{{capability}}/spec.md#requirement-{{name}}`
- **files**: `lib/Controller/...`, `lib/Service/...`
- **acceptance_criteria**:
  - GIVEN ... WHEN ... THEN ...
  - GIVEN ... WHEN ... THEN ...
- [ ] Implement
- [ ] Test

### Task 2: {{task_title}}
- **spec_ref**: `openspec/changes/{{change_name}}/specs/{{capability}}/spec.md#requirement-{{name}}`
- **files**: `lib/...`
- **acceptance_criteria**:
  - GIVEN ... WHEN ... THEN ...
- [ ] Implement
- [ ] Test

## Quality checklist

<!-- These are reminders for the builder, not tracked checkboxes.
     Keeping them as plain text avoids inflating the Hydra cap count. -->

- All new/changed business logic covered by PHPUnit unit tests (`tests/Unit/`)
- New/changed API endpoints covered by Newman/Postman tests
- UI changes covered by Playwright browser tests
- All tests pass (`composer test`, `newman run`)
- Feature documentation updated in `docs/` if user-facing (ADR-010)
- Dutch (`nl_NL`) and English (`en_US`) translation strings added for any new user-facing strings (ADR-007)
- `openspec validate` passes
