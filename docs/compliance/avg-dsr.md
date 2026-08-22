---
sidebar_position: 2
description: AVG (GDPR) data-subject rights — inzage, portabiliteit, vergetelheid, and rectificatie — orchestrated over OpenRegister, with a hard statutory-retention guard.
---

# AVG data-subject rights

Humaniq orchestrates all four AVG (GDPR) data-subject rights — Art 15
inzage, Art 20 portabiliteit, Art 17 vergetelheid, and Art 16
rectificatie — as a thin layer over OpenRegister's own `DsarService`.
Humaniq owns no entity-matching, soft-delete, or anonymisation logic of its
own: locating every object referencing a subject, erasing a subject's
data, and rectifying a single object are all OpenRegister primitives.

## The statutory retention guard — read this before erasing anyone

**A vergetelheid (right-to-be-forgotten) request cannot erase payroll
data that is still inside its statutory fiscal retention window.** Dutch
tax law (AWR art. 52 lid 4) imposes a 7-year retention duty on
loonadministratie records. Before any erase runs, every object matching
the subject is classified into two buckets:

- **`retained`** — carries a populated retention date on or after today
  (or, for the payroll/loonadministratie schema family specifically —
  `Payslip`, `PayrollRun`, `LoonaangifteFiling`,
  `PayrollMutationReport`, `WkrDeclaration`, `WkrAssessment` — falls
  back to the AWR 7-year derivation when no explicit date is set).
  Every retained object is **excluded from erasure and reported**,
  labelled `"retained (wettelijke bewaarplicht)"`, with its retention
  date — never silently skipped, never quietly erased.
- **`eligible`** — everything else, which is actually erased or
  anonymised.

When nothing for a subject is retained, one efficient wholesale erase
runs. **The moment anything is retained, that wholesale erase path is
never used for that subject at all** — a slower, per-object
anonymisation loop runs over the eligible set only, so a retained
object is structurally never passed to the same call that erases
everything else.

This retention predicate covers the payroll/loonadministratie family
above, plus any schema carrying a populated retention field. A schema
outside that family with a real but currently unmodelled legal
retention duty would not be protected by this guard — a named, stated
scope boundary, not a silent gap.

## Dry-run always precedes execute

Previewing an erasure performs **zero writes**. Actually executing an
erase refuses any request whose preview was not first recorded against
it — there is no path that skips straight to a destructive write.

```bash
occ humaniq:avg:erase --dsr-request-id <id>
occ humaniq:avg:erase --dsr-request-id <id> --confirm
```

Defaults to preview-only; `--confirm` is required to actually execute,
and only against a request whose preview already ran.

## Rectification and inzage/portabiliteit

Rectificatie (Art 16) is a straight pass-through to OpenRegister's own
single-object update, with the same subject/retention framing as
erasure. Inzage and portabiliteit (Art 15/20) render the same
"everything referencing this subject" lookup two ways — a human-readable
view and an exportable one.

## No raw BSN is ever persisted by this feature

A data-subject request references the subject only through its
`Employee` link. The employee's BSN is read transiently, in memory, at
lookup time only — it is never written back onto the request, onto the
retained-object list, onto the outcome summary, or into any log line
(Wet BSN).

## Admin-only, deliberately never widened to HR

The endpoint gate for this capability requires actual Nextcloud admin
rights — not the broader admin-or-HR check some other guarded
endpoints (loonbeslag, payroll mutations) use elsewhere in Humaniq, and
that distinction is intentional: this is the one surface in Humaniq where
widening the gate to admit HR callers would be a real regression, not
just a convenience trade-off.

```bash
occ humaniq:avg:erase --dsr-request-id <id>
```
