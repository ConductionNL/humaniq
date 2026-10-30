## ADDED Requirements

### Requirement: The week line names the object's hours in bold

The hours card's week line SHALL draw the hours booked on the object in bold, in the place the translated sentence puts them.

#### Scenario: DqZaak "Uren registreren"
- GIVEN 11,5 hours are booked on the case in week 41
- THEN the week line reads "Week 41 · 5 okt tot en met 11 okt · 11,5 uur op deze zaak" with "11,5 uur" in bold

### Requirement: The board look booking form reads as drawn

On a host's board look the week line SHALL be 14px, and the booking form's labels 14px at 600 on the normal line, 6px above their field, with 12px between the fields.

#### Scenario: DqZaakMeer "Uren registreren"
- GIVEN dossiq shows the hours card on its board look
- THEN the label "Datum" is 14px at 600 and the form's first row sits 16px under the card's title
