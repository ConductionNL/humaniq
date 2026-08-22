---
sidebar_position: 3
description: The accountant multi-client model — administrations, the active-administration switch, and why scoping is a convenience, not a security boundary.
---

# Multi-administratie (accountant multi-client)

One Humaniq instance can carry **multiple administraties** (companies or
clients) — the dominant Dutch payroll distribution channel, where one
accountant's office runs payroll for many SMBs.

## The tenant model

An `Administration` (name, KvK, loonheffingennummer) is the client
record. `AdministrationAccess` is the membership — which user can act
on which administratie, with a role (`accountant`/`hr`/`employee`).
Every core HR/payroll object carries an optional, plain-string
`administrationId` (the same convention `PayrollRun` already used —
deliberately never a `$ref`, since the manifest filter grammar cannot
reach a two-hop relationship from a record to its employee's
administration).

## Switching administration

Each user has a per-user active-administratie pointer, set and read
through a guarded endpoint that refuses any administratie the caller
has no `AdministrationAccess` row for — unknown or inaccessible both
collapse to the same 404, so the switcher never leaks which
administraties exist. The `Configuratie › Administraties` switcher
drives it, and every administration-scoped list and detail page
re-scopes to the active administratie automatically from the moment it
is selected, with no page reload required.

A consistency rule (`nl-administratie-scope-consistency`, recommended
severity) flags a child object whose `administrationId` disagrees with
its parent's — vacuous when the field is absent, so a single-administratie
install is entirely unaffected by this rule.

```bash
occ humaniq:rules:audit
```

## Scoping is NOT a security boundary — read this before relying on it

**This is the single most important thing to understand about
multi-administratie: the active-administratie pointer and the per-page
filtering it drives are a *convenience* scoping layer, not an isolation
guarantee.** OpenRegister still serves objects according to the app's
own RBAC — and a user with register access can read across
administraties via the API, regardless of which administratie is
currently "active" in the UI.

Hard tenant isolation — per-administratie OpenRegister organisation
ownership, so one client's data is genuinely inaccessible from another's
context, not merely hidden by a filter — is a named security fast-follow,
tracked separately. **Do not rely on administratie scoping to keep one
client's payroll data from another client's eyes** in any environment
where that separation actually matters; treat it as a UX convenience for
an office that already trusts everyone with instance access, not as a
tenant-isolation control.
