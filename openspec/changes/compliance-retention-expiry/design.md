# Design: personnel data leaves when its retention period ends

## Context

Read at `development` 76f27f20, openregister `development` 910471dc.

- `lib/Service/PayrollRetentionGuardService.php` places a legal hold with the reason prefix
  `Statutaire bewaarplicht (hrmq#99) tot ` and never releases it (its docblock says so).
  `placeStatutoryFloorHold()` runs at payslip creation (`PayrollRunService::savePayslip()`).
- `lib/Standards/Checks/NlDossierRetentionChecks.php` flags `nl-bewaartermijn-verstreken`
  from OpenRegister's `retention.archiefactiedatum` and calls automated destruction "out of
  scope" for that check. This change is the scope it pointed at.
- openregister: `RetentionService::applyArchivalMetadata()` computes `archiefactiedatum` for a
  schema whose `archive.enabled` is true; `DestructionCheckJob` lists eligible objects on a
  destruction list for review; `DestructionExecutionJob` deletes an approved list and skips
  any object under an active legal hold; `RetentionService::releaseLegalHold()` releases one.
- humaniq registers `LeaveAccrualJob` and `PollCalendarSubscriptionsJob` in
  `appinfo/info.xml`; no retention job exists.

## Decisions

### D1. Consume the destruction list, never delete in humaniq

humaniq declares retention and releases its own holds. OpenRegister lists, asks a person, and
deletes. Alternative considered: a humaniq job that deletes expired objects. Rejected: it
would bypass the review step and the hold re-check OpenRegister already does, the
consume-not-rebuild rule (ADR-022).

### D2. Periods per schema

| schema | period | counted from |
|---|---|---|
| `Payslip`, `PayrollRun`, `LoonaangifteFiling`, `PensionFiling` | 7 years (AWR art. 52) | end of the period's year |
| `GeneratedDocument` | inherits its source's period | the source |
| `Employee` dossier | 5 years | end of the year of `endDate` |

Where OpenRegister's derivation cannot express "end of that year", the period is padded to
the next 31 December and the design records it; a record is then kept a little longer, never
shorter.

### D3. Release only humaniq's own lapsed holds

`releaseLapsedFloorHolds()` releases a hold only when its reason starts with the humaniq
prefix and the date in the reason has passed. Any other hold is left alone.

### D4. Off by default

The admin setting `retention_release_enabled` defaults to false. The job logs what it would
release while off, so an admin can read the effect before switching it on.

## Risks

- Deleting personnel data is irreversible. The approval step, the hold re-check and the
  default-off switch are the three guards; the release job has a red test for each.
