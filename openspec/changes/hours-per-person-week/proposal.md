# Proposal: the hours surface shows the week per person, and takes its host's title

## Why

dossiq's case page (board DqZaakMeer, "Uren registreren") draws the hours on a case as the week's hours per person, each with a bar, under a header that reads "Week 41 · 5 tot en met 11 okt · 11,5 uur op deze zaak", beside a form to book hours without opening a dialog. The leaf drew one total, the reader's own share and the estimate, and always titled itself "Hours booked", whatever the host called the card.

## What changes

- `GET /api/time-entries/week?domainObjectType&domainObjectRef&date`: the ISO week holding `date` (today when left out) on one object: week number, first and last day, total, and per person the hours, display name and team (the unit of the person's single active placement). The session is checked first. The entries are read AS THE CALLER, so OpenRegister's access rules decide which bookings count; a booking the caller may not see is not in the answer.
- `CnHoursWidget` takes a `title` from its host and falls back to "Hours booked".
- The leaf draws the week header, one row per person with a bar against the week's largest share, and an inline form (date, hours, description, "Record hours") beside them. The timer, the actions menu and the estimate line stay.
