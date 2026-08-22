---
capability: app-identity
status: done
---

# app-identity Specification

**Status**: done
**Scope**: humaniq

## Purpose

The app's Nextcloud identity — `<id>`, display name, PHP namespace, route
prefix, `occ` command prefix and l10n domain — was renamed from `hrmq` to
`humaniq` as part of the 2026-08 fleet rename. Nextcloud has no in-place
app-id upgrade: the new id is discovered as a **different app**, so every
store Nextcloud namespaces by app id is cut off at the moment of the rename.
This capability owns the two repair steps that carry that state across, and
the boundary of what the rename deliberately does **not** touch.

Two stores are namespaced by app id and neither follows `<id>`:

- `oc_appconfig` — reached through `IAppConfig`. Holds the admin settings and
  the imported register/schema-id bookkeeping.
- `oc_preferences` — reached through `IConfig`'s user values. Holds this app's
  per-user state.

The second is the dangerous one. Per-user reads carry a default, so a lost
preference does not surface as an error — it surfaces as the app quietly
behaving as though the user had never chosen anything.

## Requirements

### Requirement: Stored app configuration SHALL survive the app-id rename (REQ-AID-001)

`lib/Repair/MigrateAppConfigKeys.php` SHALL be an `OCP\Migration\IRepairStep`
that copies every value stored under the `hrmq` `IAppConfig` namespace to
`humaniq`. It SHALL enumerate `IAppConfig::getKeys('hrmq')` rather than a
hardcoded key list, so the step cannot drift out of date as new settings are
added. It SHALL skip the Nextcloud-reserved keys `enabled`,
`installed_version` and `types`; copying `enabled` through `setValueString()`
stores type STRING against the type MIXED that `AppManager::enableApp()`
writes, and the resulting `AppConfigTypeConflictException` is permanent
because it is hit before the app can run anything that would repair it.

The step SHALL be idempotent and non-destructive: a key is copied only when
the old value is non-empty AND the new namespace holds no value, the old rows
are never deleted, and any `Throwable` is logged and skipped rather than
thrown — a repair step that throws aborts the install.

### Requirement: Per-user preferences SHALL survive the app-id rename (REQ-AID-002)

`lib/Repair/MigrateUserPreferences.php` SHALL be an
`OCP\Migration\IRepairStep` that copies every per-user value stored under the
`hrmq` app id to `humaniq`.

The migrated key set SHALL be declared in a `MIGRATED_KEYS` constant. At the
time of the rename that set is exactly:

| key | written by | default on read | consequence if lost |
| --- | --- | --- | --- |
| `active_administration_id` | `AdministrationService::setActiveAdministration()` | `''` | the user is silently returned to the fallback administration — in a multi-administratie install that means their HR and payroll surface silently re-scopes to a **different legal employer** |

Enumeration SHALL be by user, not by value. `IConfig::getUsersForUserValue()`
requires the caller to already know the value, which is workable only for a
closed set such as a boolean flag. `active_administration_id` holds an
open-ended administration identifier, so the step SHALL walk
`IUserManager::callForSeenUsers()` and read each user's value under the old
app id. A key whose possible values are not enumerable by construction MUST
NOT be migrated by value.

The step SHALL be idempotent and non-destructive on the same terms as
REQ-AID-001: a value is copied only when the user has nothing stored under
the new app id, the old rows are never deleted, and any `Throwable` is logged
and skipped.

### Requirement: Both migration steps SHALL run on install as well as on upgrade (REQ-AID-003)

`appinfo/info.xml` SHALL register both steps under **both**
`repair-steps/post-migration` and `repair-steps/install`, and both SHALL be
ordered before `InitializeRegister`.

`<install>` is not a redundancy — it is the path the rename actually takes.
Nextcloud discovers `humaniq` as a different app from `hrmq`, so enabling it
is a fresh install with an empty `installed_version`, and
`Installer::installAppLastSteps()` guards the pre- and post-migration blocks
with `if ($previousVersion !== '')`. Declared only under `<post-migration>`,
neither step would ever run on the one install that needs them.

The ordering before `InitializeRegister` is load-bearing for REQ-AID-001:
`InitializeRegister` writes the imported register/schema-id bookkeeping keys
itself, so running it first would leave those keys already present under
`humaniq` and the migration would skip them as "already present", stranding
the old values.

### Requirement: Identifiers owned by other systems SHALL NOT be renamed (REQ-AID-004)

The rename SHALL stop at this app's own boundary. The following remain
literally `hrmq` and each carries a comment at its definition explaining why:

- **The OpenRegister register slug** — `x-openregister.app`, the
  `components.registers` key and `slug` in
  `lib/Settings/humaniq_register.json`, the `register` app-config default in
  `SettingsService::getRegisterSlug()` and its peers, and `registerSlug` in
  `src/manifest.json`. OpenRegister's
  `ImportHandler::autoCreateRegisterIfApplication()` looks the register up by
  slug; renaming it would not move the register, it would create a second
  empty one and orphan every existing employee, contract, payslip and payroll
  run.
- **The docudesk template namespace** — `TEMPLATE_NAMESPACE` in
  `HrDocumentService` and `OfferLetterService`. docudesk stores templates
  under this namespace; renaming it would make the app's own templates
  unreachable and document generation would return nothing rather than fail.
- **The docudesk signing provenance key** — `sourceApp` in `OfferEsignService`,
  which is both written onto new signing requests and used as the lookup key
  for orphan recovery of in-flight ones.
- **`OLD_APP_ID` in the two migration steps**, which is the one place in the
  app that is supposed to still say `hrmq`.
- **Archived `openspec/changes/archive/**` directories** and the `@spec` paths
  pointing at them, which are historical records.
