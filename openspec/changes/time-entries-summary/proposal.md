# Proposal: time-entries-summary

## Why

dossiq's Close out your day (board DqDagAfsluiten) shows a tile "Uren 2,75, nu al in humaniq": the hours the reader
booked today. humaniq stores time entries in OpenRegister and has no read that answers "my hours of this day"; the hours
leaf's endpoints answer per host object or about the running timer.

## What changes

`GET /api/time-entries/summary?date=YYYY-MM-DD` answers the caller's own hours of that day (today without a date):
`{date, hours, entries}`. It resolves from the session user only, takes no user parameter and drops any row that is not
the caller's. An entry without hours (a running timer) adds nothing. No session is 401, a date that is not a day 400, a
failed read 500 with a sentence, never a zero.

## Scope

humaniq; dossiq reads it on its Close out your day page when humaniq is installed.
