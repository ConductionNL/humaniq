---
kind: feature
---

## Why

Hours belong to Humaniq. ADR-107 decision 6 says so plainly: "hours logged on a
case are humaniq time entries carrying the case reference." Ownership settled;
rendering did not.

dossiq wanted to show hours on a case, so it did the obvious thing and
aggregated Humaniq's register from its own manifest:

```json
{ "id": "case-kpis-hours", "type": "stats-block",
  "content": { "entries": [{ "register": "humaniq", "schema": "TimeEntry",
                             "metric": "sum" }] } }
```

On an install without Humaniq that endpoint 404s and the tile renders **`0`**.

`0` is what a real zero renders. A case with no hours booked and a case whose
hours cannot be read are the same pixels — no error, no empty state, nothing a
reader would notice. It looked correct on every case, in every such install, for
as long as it shipped.

That is one instance of a class ADR-113 now names, and the class was measured
four times on that single page in a week. The general half is solved in the
library: a widget declaring `requiredApp` renders chrome and a set-up state
instead of a number. This change closes the specific half — the reason dossiq
had to reach into another app's register at all.

## What this change does

Humaniq supplies the hours surface as an OpenRegister integration leaf,
`humaniq-hours`. A consuming app PLACES the leaf and passes the object context;
it never queries Humaniq's register.

The failure mode disappears rather than being handled. A leaf whose app is
absent is not registered, so there is nothing to render wrongly.

The widget answers, for any object: how many hours are booked against it, the
recent bookings that explain the total, and the two ways to add more — log
hours, or start and stop a timer. It shows a dash rather than a zero when it
cannot read, which is the same reasoning one level down.

Both halves are declared: the JS half mounts the surface, the PHP half makes the
descriptor visible to server-side consumers that never load an app bundle
(ADR-066 decision 1). Registering only one is an orphan registration.

## What this change does not do

It does not move dossiq onto the leaf — that is dossiq's own change. Until then
dossiq's tile declares `requiredApp: humaniq`, which makes it honest but still
leaves it reading another app's register.

It does not add a timesheet UI for the leaf to link to beyond the existing
Timesheets page.
