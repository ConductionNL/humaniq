---
kind: code
---

# Announcements and policies for employees, and a daily team message in Talk

## Why

HR has no way in humaniq to tell employees something: a new expense policy, the office closing
between Christmas and New Year, a changed working-from-home rule. It goes by email, and nobody
knows who read the policy they are now held to. Every compared suite except OrangeHRM has an
announcements feature on the employee's home page, and several distribute policy documents for
employees to read.

A lighter need sits beside it: a team wants one message each morning in its chat channel saying
who is away today and whose birthday it is, instead of each person opening a calendar. Personio
and OrangeHRM post that to Slack, Teams or Google Chat; in Nextcloud the channel is Talk.

### Matrix rows (humaniq `openspec/parity/capabilities.json`)

| row | capability | humaniq today |
|---|---|---|
| `ess-announcements` | Publish announcements and policies for all employees. | `no`, none |
| `dm-chat-channel-digest` | Post a daily message about birthdays and who is on leave today to a team chat channel such as Slack, Teams or Talk. | `no`, none |

### Demand

- `dm-chat-channel-digest`, changelog: https://github.com/orangehrm/orangehrm/releases/tag/v5.9

### Competitors rated yes

- `ess-announcements`, AFAS Profit: "documents are distributed to employees through InSite and
  the Pocket app" and "the administrator sends ad hoc messages to Pocket users"
  (https://help.afas.nl/help/NL/SE/Ins_DocMan.htm).
- `ess-announcements`, HR2day: "announcements panel on the HIC to create announcements shown on
  the employee portal" (https://data.maglr.com/1697/issues/66612/785293/index.html).
- `ess-announcements`, Loket.nl: "Mededelingen" rebuilt in the new environment, and an API that
  "lists announcements and unread ones per employer" (https://developer.loket.nl/ApiDocs#tag/Announcement).
- `ess-announcements`, Personio: "company-wide announcements that all employees see on their
  homepage, via the Announcements card"
  (https://support.personio.de/hc/en-us/articles/36459341835037-Summary-of-permissions-New-experience).
- `dm-chat-channel-digest`, Personio: channel updates to Slack include "Who is absent today, and
  until when? Who has a birthday today?"
  (https://support.personio.de/hc/en-us/articles/360018734057-Slack).
- `dm-chat-channel-digest`, OrangeHRM: "BIRTHDAY and LEAVE_TODAY registrations delivered to Slack,
  Google Chat or Teams webhooks"
  (orangehrm@v5.9 src/plugins/orangehrmWorkspaceNotificationsPlugin/entity/WorkspaceNotificationRegistration.php:33).

## What Changes

- **Announcements.** A new `Announcement` has a title, a text, an optional policy document, an
  audience (everyone in the administration, or one or more org units), a publication period and a
  flag saying whether employees must confirm they read it. Published announcements appear on the
  employee's `MijnHr` page and on a `MijnMededelingen` list.
- **Read confirmation.** For an announcement that requires it, each employee confirms once; HR
  sees per announcement who confirmed and who did not.
- **A daily team message.** A shipped flow on OpenRegister's engine, disabled by default, runs
  each working morning, composes for a chosen org unit who is away today (and until when,
  without the reason) and who has a birthday today (only for employees who agreed to share it),
  and posts it to a chosen Talk conversation.

## Capabilities

### New Capabilities

- `announcements-and-digest`: targeted announcements and policies with read confirmation, and a
  daily away-and-birthday message to a Talk conversation.

## Impact

- `lib/Settings/register.d/hr-announcements.json` (new): `Announcement`,
  `AnnouncementConfirmation`.
- `lib/Settings/register.d/hr-objects.json`: `Employee.shareBirthday` (opt-in, default false).
- `lib/Flow/TeamDigestNode.php` (new, a `humaniq.team-digest` node registered by
  `HumaniqFlowNodeListener`) and an `x-openregister-flows` "Dagbericht" flow on `OrgUnit`.
- `src/manifest.d/hr-announcements.json` (new): HR pages, `MijnMededelingen`, a widget on `MijnHr`.

## Out of scope

- Instance-wide news for all Nextcloud users; Nextcloud's own Announcement Center does that.
- Slack and Teams. Talk is the Nextcloud channel; a bridge to other chat tools is Talk's own
  matterbridge, not humaniq's.
- Notifications about new announcements beyond the in-app list; they can be added as a rule on
  the schema under `platform-notifications`.
