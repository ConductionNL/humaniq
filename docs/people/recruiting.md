---
sidebar_position: 2
description: Vacancies and the candidate application pipeline, offer letters with e-signature, and an AVG-driven retention clock.
---

# Recruiting

The recruiting MVP lives under the same `Onboarding & ATS` menu group as
[onboarding and offboarding](/docs/people/onboarding-offboarding), split
into `Vacancy` (the job posting) and `Application` (the candidate).
External multiposting (werk.nl/LinkedIn) and a public career page are
explicitly out of scope — HRMQ manages the internal pipeline.

## Vacancies

`Vacancy` has a simple, terminal publish lifecycle:

```
concept → gepubliceerd → gesloten
```

`publiceren` stamps `publishedDate` on the carrying write; `sluiten`
closes the vacancy permanently — there is no re-open or
publish-from-closed edge. A closed vacancy is re-created, not
resurrected.

## Applications: candidate data lives inside the application

There is **no `Candidate` schema anywhere in the register**. Candidate PII
— name, email, phone, CV, motivation letter — lives directly on the
`Application` object. This is a deliberate AVG data-minimisation choice:
one object, one retention clock, one delete. Deleting the application
deletes the candidate's data.

The pipeline is a declarative lifecycle:

```
nieuw → screening → gesprek → aanbod → aangenomen
                                      ↘ afgewezen (from any active stage)
```

`afwijzen` is reachable from `nieuw`, `screening`, `gesprek`, or `aanbod`
— rejection can happen at any stage. `aannemen` is a **manual hand-off**:
creating the actual `Employee` record from the application's data is a
manual follow-up action in the MVP, not automatic.

## The AVG retention clock

The Autoriteit Persoonsgegevens sollicitatie-bewaartermijn requires
deleting rejected candidate data at the latest 4 weeks after rejection —
or up to 1 year with the candidate's explicit consent to stay in a
talent pool. HRMQ makes this a **stored, machine-checked fact**, not a
background deletion job (the automatic deletion job itself is out of
scope for this MVP — the rule surfaces what needs deleting):

- `talentPoolOptIn` — explicit candidate consent to extended retention
- `retentionExpiryDate` — the derived deletion deadline

Two rules in a new `privacy.json` corpus file (domain
`gdpr-recruitment`, framework `gdpr`, source AVG art. 5 lid 1 sub e —
opslagbeperking — plus the Autoriteit Persoonsgegevens richtlijn
sollicitatiegegevens) enforce the clock:

1. **`nl-ats-retentie-derivatie`** — for a rejected application,
   `retentionExpiryDate` must equal `rejectedDate` plus 28 days (or 365
   days with `talentPoolOptIn: true`) — the offsets are rule parameters,
   not hard-coded constants. Non-rejected applications pass vacuously.
2. **`nl-ats-retentie-verlopen`** — a rejected application whose
   `retentionExpiryDate` has already passed is flagged: the data should
   have been deleted or anonymised.

```bash
occ hrmq:rules:audit
```

## Offer letters and e-signature

For an Application at the `aanbod` stage, HRMQ can generate a real
offer-letter PDF from the Application/Vacancy data and raise a real
signing request, both through [docudesk](https://docudesk.conduction.nl)
— tracked directly on the Application
(`offerLetterFileId`/`offerSigningRequestId`/`offerSigningStatus`).

**Requesting a signature does not hire the candidate.** Neither
requesting a signature nor syncing its status ever writes
`Application.status` or advances the pipeline — not even when the
signature reaches `COMPLETED`. Hiring stays an explicit, separate HR
action; syncing is a read-only poll of the signing status only.

```bash
occ hrmq:offer:request-signature --application <id>
occ hrmq:offer:sync-signature
```

When docudesk is not installed, requesting a signature degrades to
`skipped-no-docudesk` rather than throwing. Re-requesting for an
Application whose signature is already `COMPLETED` is a no-op
(`already-signed`); a stale pending request is superseded, never
duplicated, before a fresh one is raised.

Candidate self-service signing completion and webhook-driven status
updates are both out of scope for this MVP — status changes are
observed by polling, not pushed.

Scheduling an actual interview round for a candidate at the `gesprek`
stage is a separate capability — see [Interview
scheduling](/docs/people/interview-scheduling) — reachable from an
Application's own detail page.

## Pages

`Applications` lists `candidateName`, `vacancyId`, `status`,
`talentPoolOptIn`, and `retentionExpiryDate`, filterable by pipeline
stage — each stage is one filter value; a kanban board is a deferred
follow-up. `ApplicationDetail` separates the application data from a
dedicated "Privacy & retention" widget, resolves the related vacancy, and
exposes exactly the pipeline's lifecycle actions with Dutch labels.

Automatic Employee creation on hire remains a manual hand-off, not
automatic, and is explicitly out of scope for this MVP.
