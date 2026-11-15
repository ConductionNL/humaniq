---
kind: code
---

# Every record shows who viewed or changed it

## Why

The matrix row `cmp-audit-trail` asks whether HR can see who viewed or changed an employee's
data and when. It is built: OpenRegister writes an audit entry for every change and, while the
instance keeps audit trails, a `read` entry for every read a user makes
(`ReadHistoryService::registerAuditRead()`, OpenRegister requirement "Every user-facing read of
an object is logged as a read"). humaniq detail pages show that history in their sidebar
(`sidebar.showMetadata`). No humaniq spec says so, so the row counts as unspecified.

Reads are logged, so the row keeps its wording "viewed or changed". Who may see read entries is
OpenRegister's rule (open change `audit-read-events-visible-to`: administrators, plus groups an
administrator names); humaniq follows it and adds no gate of its own.

The board `humaniq/HuInstellingen` draws an Audittrail tab: the latest entries across employee
data in plain sentences, a link to the full audit trail and a download. The board is the UI
canon, so the spec follows it.

### Matrix rows (humaniq `openspec/parity/capabilities.json`)

| row | capability | humaniq today |
|---|---|---|
| `cmp-audit-trail` | See who viewed or changed an employee's data and when. | built: audit sidebar on every detail page; no Audittrail tab |

## What changes

- **New capability `record-audit-history`** with three requirements: reads and changes are
  recorded, every detail page shows the record's history, and the admin settings show an
  Audittrail tab across the humaniq register.
- **Audittrail tab** in the humaniq admin settings, as drawn: who, when and what, the full trail
  in OpenRegister, and a download.

## Non-goals

- No audit storage in humaniq: OpenRegister keeps the trail and its retention.
- Which groups see read entries is set in OpenRegister, not in humaniq.
- Export entries ("exporteerde het personeelsbestand" on the board) show when OpenRegister
  records them; recording exports is not part of this change.
