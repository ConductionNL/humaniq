# Design: estimate, spent and remaining

## D1. Why one of the three candidates costs nothing

The sweep scores dossiq. `hours-leaf` is humaniq's, archived 2026-09-11, and it
already carries:

- "A running timer survives leaving the page": starting the timer writes a time entry
  with no end, and the surface reads it back on mount.
- "A user has at most one running timer": the server refuses a second start.
- "An entry without an end is a running timer, not a defective booking".

That is C-reporting-12 in full, including the part Forgejo's `/user/stopwatches`
implies: one running stopwatch per user, addressable. dossiq inherits it by placing
the leaf. Writing a second timer would put two open entries in one register and make
"how much did this cost" ambiguous, which is the failure the single-running-entry
requirement exists to prevent.

So the work here is two candidates, not three, and the change says so out loud
because a reader comparing the cluster count to the requirement count would otherwise
assume a gap.

## D2. Where an estimate lives

Three options for holding expected hours.

1. **On the host object.** dossiq's case would carry `estimatedHours`. It would put
   the same field on every app that ever wants one, and humaniq could not read it
   without knowing each app's schema.
2. **On the time entry.** An estimate is not a booking. Putting it there means the
   first booking invents the estimate.
3. **A `TimeEstimate` against a host object reference**, in the shape `hours-leaf`
   already uses.

Option 3. The reference shape is settled: `domainObjectType` is the `<app>:<schema>`
literal, `domainObjectRef` is the uuid. An estimate then works for a case, a project,
a vacancy or a payroll run without any of them knowing about it.

## D3. Per role, because a bezwaar costs two kinds of hour

The lane's clause on C-reporting-24 in the neighbouring pipelinq cluster says it
plainly: a bezwaar costs juridisch time and vakafdeling time and today both are one
number. The same is true of the estimate. So `TimeEstimate` carries an optional
`role`, and an object may hold several: one per role, plus at most one unroled total.

Remaining is then answerable per role and in total, and a juridisch ceiling does not
block a vakafdeling booking.

## D4. Remaining is derived, always

Storing remaining would mean recomputing it on every booking, every edit, every
deletion and every timer stop, and being wrong in between. `hours-leaf` already reads
the total by query. Remaining is one subtraction on top of that read.

The cost is one more aggregate per read. The gain is that a deleted entry changes the
remainder immediately and nothing has to be repaired.

## D5. The empty state is not zero

`hours-leaf` already argues this for the total: a failure and a real zero must not
look the same. The same trap sits one step further in. An object with no estimate has
a remaining of nothing, not a remaining of zero, and a tile reading "0 remaining"
says the work is finished.

So: no estimate means the tile shows spent and says no estimate is set, and the
remaining figure is absent rather than zero.

## D6. The ceiling is opt-in, and it refuses on the write

Easy Redmine's documented behaviour is a restriction an administrator switches on.
Two reasons to keep it opt-in here rather than always on:

- A gemeente that estimates for planning and then discovers the work is harder needs
  the booking to land. Time already worked is a fact, and refusing to record a fact
  makes the register lie.
- A ceiling nobody asked for turns every under-estimate into a support call.

So `TimeEstimate.enforced` defaults to false. When it is true the write path refuses
a booking that would carry the matching total past the estimate, and the refusal
names the estimate, the ceiling and the hours left. A running timer stopped past the
ceiling is the awkward case: the hours are real, so the stop SHALL land and the
overrun SHALL be recorded rather than discarded. The ceiling refuses a new booking,
never the recording of time already spent.

## D7. What the consuming app decides

The expected effort for a case type is dossiq's policy: it knows a bezwaar is
budgeted at eight hours and a Woo-verzoek at three. humaniq holds the object, derives
the remainder and enforces the flag. Neither app learns the other's model, which is
the same line `hours-leaf` drew when it refused to let a consuming app query the
register.
