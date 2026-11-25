## ADDED Requirements

### Requirement: A host can name the object in the week line

With `objectNoun` set the week line SHALL end "on <noun>" with the noun in the
reader's language when humaniq knows it, else as written; without it the line
SHALL end "on this item".

#### Scenario: a dossiq case
- GIVEN the hours card on a case with `objectNoun: "this case"` and 11.5 h this week
- WHEN a Dutch reader opens it
- THEN the week line ends "11,5 uur op deze zaak"

### Requirement: A compact header keeps the timer

With `compactHeader` the header SHALL show the title, the week and the Actions
menu only. The timer SHALL stay reachable as an Actions menu item and as a
"Start timer" button under the form, and while it runs on this object as a
"Stop the timer" button.

#### Scenario: starting a timer from under the form
- GIVEN the hours card with `compactHeader` and no timer running
- WHEN the reader presses "Timer starten" under the form
- THEN a timer starts on this object, as the header stopwatch did

### Requirement: The card can say where its hours come from

With `sourceNote` the card SHALL end with a note that hours are recorded
through humaniq and that the card is left out without it.
