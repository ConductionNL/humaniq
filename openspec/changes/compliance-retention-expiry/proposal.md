---
kind: code
---

# Personnel data leaves when its retention period ends

## Why

humaniq keeps payroll and dossier records at least as long as the law says, and it protects
them from being erased too early with a legal hold (`PayrollRetentionGuardService`). The other
end of the same clock is not handled. Once a retention period has run out, the AVG storage
limitation (art. 5(1)(e)) says the record should go, and today nothing removes it: the rule
`nl-bewaartermijn-verstreken` only flags it in `occ humaniq:rules:audit`, and the hold
humaniq placed is never released, so even OpenRegister's own destruction workflow would skip
the record forever.

### Matrix rows (humaniq `openspec/parity/capabilities.json`)

| row | capability | humaniq today |
|---|---|---|
| `cmp-retention` | Delete personnel data automatically when its retention period ends. | `no`, building: floor holds and an audit flag, no deletion |

### Competitors rated yes

- HR2day: "automatic compliance with retention periods and automatic expiry dates"
  (https://www.hr2day.com/features/digitaal-dossier/), and batch deletion of expired
  documents (https://data.maglr.com/1697/issues/64244/760000/index.html).
- Loket.nl: "retention and destruction periods are monitored automatically and documents are
  deleted automatically once the retention period expires"
  (https://loket.nl/functionaliteiten/digitaal-dossier/).
- AFAS, Visma Raet and Personio rate partial: retention terms and deletion sets exist, with a
  person deciding.

## What changes

- **An expired record is marked for destruction with the date humaniq already knows.** When a
  statutory floor has passed, humaniq writes the appraisal `vernietigen` and that floor date
  into the record's OpenRegister retention block, the fields OpenRegister's destruction check
  reads. An ended employee's record gets the same mark seven full years after the year the
  employment ended, the longest floor that applies to the payroll that refers to it.
- **OpenRegister's destruction list does the deleting.** humaniq does not delete anything
  itself. OpenRegister's daily destruction check puts expired objects on a destruction list;
  a person with the archivist role approves the list, and OpenRegister deletes, re-checking
  every legal hold and writing the audit trail.
- **humaniq releases its own floor holds when the floor has passed.** A daily job releases
  the statutory holds `PayrollRetentionGuardService` placed once their date has passed, and
  only those, so the destruction list can pick the record up. A hold a person placed for
  another reason stays.
- **An admin turns it on.** The release job does nothing until an admin enables it in the
  humaniq settings, so an existing installation does not start losing records on upgrade.

## Capabilities

### New capabilities

- `personnel-retention-expiry`: expiry of retained personnel data through OpenRegister's
  destruction list.

## Impact

- `lib/Service/PayrollRetentionGuardService.php`: release a lapsed floor hold and mark the
  record for destruction.
- `lib/Service/RetentionExpiryService.php` (new): walks the retained schemas.
- `lib/BackgroundJob/RetentionHoldReleaseJob.php` (new), registered in `appinfo/info.xml`.
- Admin settings: one switch, off by default.

## Cross-app dependencies

- openregister: the destruction list, its approval and `RetentionService::releaseLegalHold()`
  already exist; nothing is owed. The destruction check runs only once an admin has set the
  destruction list register and schema in the OpenRegister archival settings.

## Out of scope

- Deleting without a person approving the list. The approval step is OpenRegister's and stays.
