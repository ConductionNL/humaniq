# Design: a working calendar per person

## D1. Two calendars with one name

| Question | Answer lives in | Example |
|---|---|---|
| Is 5 mei a working day for counting a termijn? | openregister `working-calendar-admin` | Bevrijdingsdag, every five years |
| Does Fatima work on Wednesdays? | humaniq, this change | 0.6 fte, Monday to Wednesday |
| Is the balie open on Saturday? | openregister, per case type (`C-deadlines-14`) | burgerzaken counter hours |
| Is Fatima on leave on 12 June? | humaniq `leave-management` | approved verlof |

Four questions, three owners, one word. D19 draws the line at the person: anything
true of an individual is humaniq's, anything true of the organisation or of a record
type is openregister's.

The practical test: if the answer changes when a person's contract changes, it is
here. If it changes when the gemeente decides something, it is openregister's.

## D2. A contract change is a new pattern, not an edit

If `WorkingPattern` were editable in place, a capacity read over last quarter would
divide by this quarter's contract, and every historic percentage would silently move
whenever anybody went from four days to five.

So a pattern carries `validFrom` and an optional `validUntil`, and a change writes a
new one. The resolution query picks the pattern in force on the date asked about.
This is the same shape `rostering` uses for a published roster and `leave-management`
uses for a balance: the record of what was true then survives what is true now.

Overlapping patterns for one employee are refused. Two answers to "how many hours on
Tuesday" is worse than none, because nothing looks wrong.

## D3. Non-working time is not leave

Three things keep a person away from work, and merging them loses information a
gemeente needs.

- **Leave** is requested, approved and drawn from a balance. `leave-management`.
- **Sickness** is a case with a Wet verbetering poortwachter timeline. `verzuim-wvp`.
- **A non-working time** is neither. Nobody requests a vaste vrije Woensdag and
  nothing is deducted from it.

Filing the third as leave would draw down a balance that should not move, and would
put "verlof" on a calendar for a day the person was never contracted to work.

So `NonWorkingTime` is its own object: an employee, a period or a recurring weekday,
and a reason from a short administered list.

## D4. Hours per weekday, not an fte number

An fte fraction is a payroll figure. It answers "what fraction of full time", not
"which days". A 0.6 fte who works three long days and a 0.6 fte who works five short
ones plan completely differently, and a scheduler that only knows 0.6 gets both
wrong.

So the pattern stores hours per weekday: seven numbers, most of them often zero. The
fte figure stays where it already is, in the employment record, and this change does
not touch it or derive it.

## D5. Reading openregister's calendar, and working without it

The pattern says Fatima works eight hours on Monday. Second Whitsun is a Monday. The
answer for that date must be zero, and the fact that it is a feestdag is
openregister's, not humaniq's.

humaniq resolves the calendar the way it resolves everything of openregister's:
duck-typed, container-resolved, behind a `class_exists()` guard, exactly as
`leave-calendar-nc` resolves `CalDavBackend` and `hours-leaf` resolves
`ObjectService`.

When the calendar cannot be resolved, the answer SHALL say it is pattern-only rather
than silently returning eight hours for a national holiday. A degradation that is
recorded can be fixed; a wrong number that looks right cannot.

## D6. One query, because everything divides by it

`agenda-rostering-and-resource-booking` needs this number in three places: the
availability answer, the forward capacity read and the leaf. `absence-rate` already
computes a denominator of its own.

One resolution point, `contractedHoursOn(employee, date)` and its range form, keeps
those four from drifting. The alternative is four subtly different definitions of a
working day, which is how an absence percentage and a capacity percentage come to
disagree about the same person in the same week.
