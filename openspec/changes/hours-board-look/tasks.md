# Tasks

### Task 1: Board look for the hours card
- **spec_ref**: `openspec/changes/hours-board-look/specs/hours-leaf/spec.md`
- [x] `objectNoun` in the week line, nl "deze zaak"
- [x] `compactHeader`: no clock icon, stopwatch in the menu and as "Start timer" under the form, "Stop the timer" while running
- [x] `sourceNote` band
- [x] Live check on DqZaak once dossiq sets the props (dossiq case-kpis-hours props)
  Done 2026-11-25 on a sandbox Nextcloud 34.0.6 (MariaDB 10.11, OpenRegister main 7997381d, humaniq
  development 89e5e1bf, dossiq development 8d9de5d9, whose simple layout sets `objectNoun`,
  `compactHeader` and `sourceNote` on `case-kpis-hours`), reader language Dutch, on an
  Omgevingsvergunning case: the card is titled "Uren registreren", the header carries no clock
  icon and holds the week line and "Acties"; the week line read "Week 48 · 23 nov tot en met
  29 nov · 0 u op deze zaak", so the Dutch catalogue's `{hours} h` now reads `{hours} uur` as
  the spec and the board say ("… · 0,01 uur op deze zaak" after the fix); "Timer starten" under
  the form started a timer on this case (GET /api/time-entries/timer: running, then stopped);
  the source note band reads "Uren registreren werkt via humaniq. Zonder humaniq staat dit blok
  uit." The pixel comparison with board DqZaakMeer is not part of this check.
