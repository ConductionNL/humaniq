# hours-row-surface

dossiq's "Close out your day" (board DqDagAfsluiten) places the hours leaf on each row beside the note field, with
`surface: "row"`. The leaf drew its whole card there (header, week line, timer, menu, form) in a flex item that
collapsed to 2px wide and about 480px tall, so every row was about 540px high. The board draws one "Uren" field per
row, recorded together with the note by the row's button.

On a row the leaf is now one "Hours" field. It reads nothing up front and books what is typed, on this object today
with the note as the description, when the host sends `cn-record-notes:recorded` for that object (nextcloud-vue
feat/record-notes-recorded-event).
