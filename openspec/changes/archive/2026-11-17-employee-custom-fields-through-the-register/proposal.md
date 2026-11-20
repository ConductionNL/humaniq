---
kind: code
---

# Your own fields on the employee record, through the register

## Why

Every HR suite in the matrix lets an organisation add its own fields to the employee record:
a driving licence, an emergency response role, a badge number. In humaniq the Employee is an
OpenRegister schema, and an administrator can already add a property to it in OpenRegister's
schema screens. The value then saves through the object API. But the employee page lists its
fields by name, so an added property never shows, and no spec describes the route.

The board `humaniq/HuMedewerker` draws an Eigen velden card on the employee page with a
Velden beheren link. The board is the UI canon, so the spec follows it.

### Matrix rows (humaniq `openspec/parity/capabilities.json`)

| row | capability | humaniq today |
|---|---|---|
| `ppl-custom-fields` | Add your own fields to the employee record. | built in OpenRegister (schema screens); the employee page does not show added fields |

## What changes

- **One requirement** in `employee-and-contract-records`: a property an administrator adds to
  the Employee schema shows and saves in the Eigen velden card on the employee page.
- **Eigen velden card.** `EmployeeDetail` gets a data widget in auto-generation mode that
  excludes the shipped Employee properties, so it shows exactly the added ones.
- **Velden beheren.** An administrator gets a link to the Employee schema in OpenRegister.

## Non-goals

- No field builder in humaniq: OpenRegister owns schema editing.
- Added fields on other records (contract, leave) are not in scope; the same pattern applies
  when a row asks for it.
