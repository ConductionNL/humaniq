# Design: personnel data leaves when its retention period ends

## Context

Read at `development` 76f27f20, openregister `development` 910471dc.

- `lib/Service/PayrollRetentionGuardService.php` places a legal hold whose reason starts with
  `Statutaire bewaarplicht (hrmq#99)` and names the floor date (`tot YYYY-MM-DD`), and never
  releases it (its docblock says so). `placeStatutoryFloorHold()` runs at payslip creation
  (`PayrollRunService::savePayslip()`); `syncLegalHold()` reads `retainedUntil` or
  `retention.archiefactiedatum`. Its docblock also records why a schema `archive`
  configuration cannot express "31 December of period year + 7" for a `YYYY-MM` period.
- `lib/Standards/Checks/NlDossierRetentionChecks.php` flags `nl-bewaartermijn-verstreken`
  and calls automated destruction out of scope for that check. This change is that scope.
- openregister: `RetentionService::isEligibleForDestruction()` lists a record when its
  `retention.archiefnominatie` means destroy, its `archiefactiedatum` has passed, its record
  state is live and no legal hold is active. `DestructionCheckJob` puts eligible records on a
  destruction list for review, once the archival settings name a list register and schema;
  `DestructionExecutionJob` deletes an approved list and re-checks every hold.
  `RetentionService::releaseLegalHold()` moves a hold to its history. There is one hold slot
  per record.
- humaniq registers `LeaveAccrualJob` and `PollCalendarSubscriptionsJob` in
  `appinfo/info.xml`; no retention job exists.

## Decisions

### D1. Consume the destruction list, never delete in humaniq

humaniq marks and releases. OpenRegister lists, asks a person, and deletes. Alternative
considered: a humaniq job that deletes expired objects. Rejected: it would bypass the review
step and the hold re-check OpenRegister already does (ADR-022, consume, do not rebuild).

### D2. Mark with the date humaniq already computed

On release, humaniq writes `archiefnominatie: vernietigen` and `archiefactiedatum: <floor
date>` into the record's retention block, unless the block already carries an appraisal, which
then wins. Alternative considered: a schema `archive` configuration. Rejected for now: the
guard's docblock shows OpenRegister's derivation cannot express the year-end floor from a
`YYYY-MM` period without padding.

| schema | floor | source of the date |
|---|---|---|
| `Payslip`, `PayrollRun`, `LoonaangifteFiling`, `PensionFiling` | 7 years (AWR art. 52) | the date in humaniq's hold reason |
| `Employee` with an `endDate` | 31 December of the end year + 7 | computed, no hold is involved |

### D3. Release only humaniq's own lapsed holds

A hold is released only when its reason starts with `Statutaire bewaarplicht (hrmq#99)` and
its `tot` date lies before today. A hold with any other reason (a person placed it, or an
inherited hold on a generated document) is left alone and the record is not marked.

### D4. Off by default

The app setting `retention_expiry_enabled` defaults to `false`. While off, the job logs what
it would release and mark, and changes nothing, so an admin reads the effect before switching
it on.

## Risks

- Deleting personnel data is irreversible. The approval step, the hold re-check and the
  default-off switch are the three guards; each has a red test.
