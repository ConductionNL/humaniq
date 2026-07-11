## 1. Employee seed objects (config)

- [ ] 1.1 Add an `Employee` seed block to `lib/Settings/register.d/hr-seed.json` (or a new
      `hr-employee-seed.json` fragment) with three objects matching the existing demo employees
      (Jansen, De Vries, Bakker) — `@self.register: "hrmq"`, `@self.schema: "Employee"`, a stable
      `@self.slug`, and the `Employee` schema's own required fields (`lastName`, `startDate` per
      `hr-objects.json`).
- [ ] 1.2 Confirm the three new `Employee` objects import cleanly via
      `SettingsService::loadConfigurationForced()` (or `occ maintenance:repair`) with no schema
      validation errors.

## 2. Declare the relation on Timesheet/Expense (config)

- [ ] 2.1 Update `employeeId` in `lib/Settings/register.d/hr-timesheet.json` to declare an
      OpenRegister object reference to the `Employee` schema (follow the fleet's declarative
      `$ref`/`inversedBy` reference pattern rather than a bare `type: string`, per ADR-022).
- [ ] 2.2 Update `employeeId` in `lib/Settings/register.d/hr-expense.json` the same way.
- [ ] 2.3 Update the six existing seed rows in `hr-seed.json` (three `Timesheet`, three `Expense`)
      to reference the real seeded `Employee` slugs/UUIDs from task 1.1 instead of the placeholder
      strings `"employee-jansen"` / `"employee-devries"` / `"employee-bakker"`.

## 3. Verify the widget actually resolves (manual + browser)

- [ ] 3.1 Re-seed a clean local environment (`occ maintenance:repair` / re-import), open a seeded
      `TimesheetDetail` page, and confirm the `ts-related` widget's Objects section lists the
      linked `Employee` (not empty).
- [ ] 3.2 Repeat for a seeded `ExpenseDetail` page and `ex-related`.
- [ ] 3.3 Confirm `occ hrmq:rules:audit` and `occ hrmq:rules:seed-testdata` still run cleanly
      against the changed schemas (no regression in the compliance-check pipeline, which loads
      these same object types).

## 4. Documentation

- [ ] 4.1 Update the `_note` fields in `src/manifest.json` (`ts-related`/`ex-related`) if the
      relation wiring changes what "related" surfaces (e.g. if `projectId`/`costCenter` remain
      unlinked business keys, say so explicitly rather than implying full relation coverage).
