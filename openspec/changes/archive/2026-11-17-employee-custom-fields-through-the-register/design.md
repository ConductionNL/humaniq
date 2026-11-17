# Design: your own fields on the employee record

## Context

Read at humaniq `development` 0c6d973.

- `Employee` is defined in `lib/Settings/register.d/hr-objects.json`; its shipped properties
  are the list REQ-ECR-001 names plus the later additions of other changes.
- `EmployeeDetail` (`src/manifest.d/hr-objects.json`) renders named fields in its data widgets
  (Person, Employment and compliance).
- `CnInfoWidget` in `@conduction/nextcloud-vue` has an auto-generation mode that renders every
  schema property, with `includeFields` and `excludeFields`.

## Decisions

- **D1. Show what is not shipped.** The Eigen velden widget runs in auto-generation mode with
  `excludeFields` set to every property the shipped Employee schema declares. A unit test reads
  `hr-objects.json` and fails when a shipped property is missing from the list, so a new shipped
  property never shows twice.
- **D2. Empty card.** With no added property the card shows a short empty line and, for an
  administrator, the Velden beheren link.
- **D3. Edit in place.** Values edit and save through the same object API call as the other
  widgets; OpenRegister validates them against the property's type.
- **D4. Link, not a builder.** Velden beheren opens OpenRegister's schema page for Employee and
  is shown to administrators only.

## Risks

- A property added in OpenRegister is lost when the register import overwrites the schema on an
  app update. The build must check the import keeps properties it does not ship; if it does
  not, the fix belongs in OpenRegister's import (merge, not replace) and is built there first.
