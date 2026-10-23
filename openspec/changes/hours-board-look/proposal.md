# Proposal: hours-board-look

## Why

dossiq's board DqZaakMeer draws "Uren registreren" with a header holding only
the title and the week ("Week 41 · 5 tot en met 11 okt · 11,5 uur op deze
zaak"), a "Timer starten" button under the form, and a note that the hours
come from humaniq. The leaf drew a clock icon and a stopwatch in its header,
said "op dit item", and had no note.

## What changes

Three props a host sets, all off by default so other hosts keep today's card:

- `objectNoun`: what the host calls the object ("this case"), used in the week
  line; humaniq translates the nouns it knows ("this case", "this item").
- `compactHeader`: the header keeps the title, the week and the Actions menu.
  The stopwatch is not removed: it becomes a "Start timer"/"Stop the timer"
  item in the Actions menu and a "Start timer" button under the form, and the
  running view gets a "Stop the timer" button.
- With `compactHeader` the header also reads as the host's card header (no rule, the host's title size and inset), and the empty required fields are not drawn red before anything is typed (they stay `aria-required`; Record hours stays disabled until they are filled).
- `sourceNote`: a band under the card saying hours come through humaniq and the
  card is left out without it.
