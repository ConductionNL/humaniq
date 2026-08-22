---
sidebar_position: 7
description: Projecting scheduled interview rounds onto a configured shared Nextcloud calendar — a one-way sync with an AVG-scoped event.
---

# Interview scheduling

Before this capability, inviting a candidate to interview was a bare
status flip on the `Application` pipeline with no artifact — nobody on
the recruiting team could see, from a calendar, when and with whom a
`gesprek` was actually happening. `Interview` closes that gap: a
scheduling record per interview round (an Application can go through
several rounds, each its own `Interview`), synced onto a real calendar.

## A one-way projection onto a configured shared calendar

`InterviewCalendarService` upserts one calendar event per `scheduled`
Interview into a **configured shared Nextcloud calendar** — a
principal/URI pair set once in configuration, kept independent of any
other calendar sync (such as [leave & absence
sync](/docs/hr/leave-and-verzuim)) so interviews and absence can target
different calendars.

**This sync is one-way, and it is important to understand what that
means in practice: the sync derives the event's fields from the
Interview record every time it runs. A hand edit made directly on the
calendar event — moving the time, changing the location — is
overwritten by the next sync, not preserved.** Reschedule the
Interview itself, not the calendar entry.

- **First sync** creates the event and stamps a derived event-id marker
  back onto the Interview — an inspectable "has this ever reached the
  calendar" record.
- **Rescheduling** (changing `scheduledStart`/`scheduledEnd`) updates
  that same event by its stored id — never a duplicate.
- **A no-op resync writes nothing**: running the sync twice with no
  data change makes zero calendar writes on the second run.
- **Cancelling** an Interview removes its calendar event. **Completing**
  one leaves the event in place as history — it is not re-synced or
  deleted.
- **Deleted Interviews are reconciled**: the sync also removes any
  orphaned interview event whose underlying Interview no longer exists
  in the register, while leaving every other, manually-created calendar
  event on the same calendar untouched.

## AVG boundary

Unlike absence sync (where even the *kind* of leave is excluded from
the calendar), an interview event's whole purpose is telling the panel
who and what role — so the event's summary carries the candidate's name
and the vacancy title. Everything else Application-sensitive **never
reaches the render path at all**: email, phone, CV file, motivation
letter, talent-pool opt-in, and rejection/retention dates are excluded
by construction, not filtered after the fact. Interviewers are listed
as plain text in the event description, never as calendar attendees —
so scheduling one never triggers an automatic invitation email.

## Degradation and triggers

An unconfigured instance (interview calendar principal/URI unset)
skips cleanly — `skipped-no-calendar` — never an error:

```bash
occ humaniq:interview:sync
occ humaniq:interview:sync --from 2026-07-01
```

`--from` bounds which Interviews are pushed; omitted, every `scheduled`
Interview syncs. A guarded "Sync naar agenda" action on an Interview's
own detail page triggers a single-Interview sync for an admin or HR
caller.

## Where it lives

Interview pages nest under the existing Onboarding & ATS menu group —
no new top-level menu entry — and an Application's own detail page
lists its interview rounds directly, so a round is reachable without a
separate navigation path.
