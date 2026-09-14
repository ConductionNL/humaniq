## 1. Competence on the roster

- [ ] 1.1 Add `requiredCompetences` to the `Shift` schema in
      `lib/Settings/register.d/`, as a set of competence codes.
- [ ] 1.2 Add an `EmployeeCompetence` schema: employee, competence code, `issuedOn`,
      optional `validUntil`.
- [ ] 1.3 Add the competence cross-check to `RosterCheckService`, beside the three
      Arbeidstijdenwet rules, with its own finding kind and the never-throw posture.
- [ ] 1.4 Extend `occ humaniq:roster:check` and `POST /api/roster/check` to report
      the new finding kind without a second call.
- [ ] 1.5 Manifest pages for competences, under the existing menu group.

## 2. Resources and bookings

- [ ] 2.1 Add the `Resource` schema: name, kind, optional org unit, `quantity`,
      `active`.
- [ ] 2.2 Add the `ResourceBooking` schema: resource, period, booking employee,
      `domainObjectType` and `domainObjectRef` in the `hours-leaf` shape.
- [ ] 2.3 Refuse an overlapping booking past `quantity` in the write path, naming the
      blocking booking.
- [ ] 2.4 Refuse a booking on an inactive resource.
- [ ] 2.5 Manifest pages for resources and bookings.

## 3. The agenda read model

- [ ] 3.1 Add the agenda composer reading roster assignments, approved leave, open
      sick leave, interviews, resource bookings and cached feed busy time.
- [ ] 3.2 Inherit the `leave-calendar-nc` AVG boundary: kind and name, never a
      reason.
- [ ] 3.3 Add the agenda endpoint for a subject and a date range.

## 4. Availability

- [ ] 4.1 Add the availability query: period, optional org unit, optional competence
      set, answering free hours per employee.
- [ ] 4.2 Subtract rostered assignments, approved leave, open sick leave, resource
      bookings and cached feed busy time.

## 5. Forward capacity

- [ ] 5.1 Add the capacity read: planned against contracted, forward from a date, per
      employee and per org unit.
- [ ] 5.2 Read contracted hours from `a-working-calendar-per-person`; report "no
      contracted hours" rather than substituting the instance default.

## 6. External calendars

- [ ] 6.1 Add the per-employee iCalendar subscription and the poll job.
- [ ] 6.2 Cache busy periods only: no title, no location, no attendees.
- [ ] 6.3 Record a degradation on a failed poll and keep the previous cache.
- [ ] 6.4 Verify no write ever leaves humaniq toward a subscribed URL.

## 7. The leaf

- [ ] 7.1 Register the `humaniq-agenda` OpenRegister integration leaf, following the
      `RegisterHoursLeafListener` posture.
- [ ] 7.2 Ship the leaf bundle, so the surface is not dark on a consuming page.
- [ ] 7.3 Confirm the leaf is not registered when humaniq is absent.

## 8. Verification

- [ ] 8.1 Unit tests for the competence check, the overlap refusal and the
      availability subtraction.
- [ ] 8.2 e2e coverage or a reason-bearing exclusion per scenario, per gate 19.
- [ ] 8.3 `npm run check:manifest` exits 0.
