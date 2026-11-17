# Design: time-entry-domain-object-ref

Kind: config. A register edit, a form field pair, one guard test.

## D1. The two properties

On `TimeEntry`, next to `projectId` (REQ-TEC-001):

| property | type | notes |
|---|---|---|
| `domainObjectType` | string, pattern `^[a-z0-9-]+:[a-zA-Z0-9-]+$` | `<app>:<schema>`, e.g. `dossiq:case` |
| `domainObjectRef` | string, uuid | the host object's id |

Both optional; an entry without them is plain worked time. Both carry
`x-openregister-index: true` so a filter on the pair is a query, not a scan.
Titles in English; Dutch labels through l10n only (dossiq decision D13).

The names are the ones ADR-107 and `hours-leaf-for-any-object` already use.
Nothing is renamed.

## D2. Where the values come from

- From the leaf: the booking dialog and the timer are seeded with the host
  object's pair (hours-leaf spec, "Hours can be added from the surface that
  shows them"). The user does not type them.
- From humaniq's own timesheet page: not offered. A worker who books hours
  there books them on a project, not on a case.
- Existing entries: untouched, the properties are optional.

## D3. The guard

`tests/unit/Settings/RegisterParityTest.php`: load the mock register used by
the timesheet tests and the shipped register; for `TimeEntry` assert the
mock's property set is a subset of the shipped set. Today it is not, and the
test is what would have caught B25.

## Risks

- A consumer that writes `domainObjectType` in another casing. The pattern
  refuses it, so the error is loud.
