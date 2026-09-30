---
kind: code
---

# Read a candidate's profile from their CV

## Why

`hiring-candidate-assessment` built the vacancy match score on a candidate profile
(`job-application.educationLevel`, `experienceYears`, `competenceCodes`) that HR fills by
hand. The row `dm-vacancy-matching` asks for the score to be "based on education, level and
competences from their CV". Reading the CV was task 2.4 of that change, duck-typed to filinq
the way receipt OCR is, and it could not be built: filinq has no CV or profile extraction.
Its extractors at `development` are financial only (amount, date, IBAN, KvK, totals, in
`lib/Service/Extraction/`), and `DocumentTextExtractor` returns plain text, not a profile.
Calling a service that does not exist would be a guard with no counterpart.

### Matrix rows (humaniq `openspec/parity/capabilities.json`)

| row | capability | humaniq today |
|---|---|---|
| `dm-vacancy-matching` | Have applicants and existing employees matched to open vacancies with a score based on education, level and competences from their CV. | `building`: the explained score, the ranked list on the vacancy and the hand-filled profile are built (`hiring-candidate-assessment`); reading the profile from the CV is not |

## What Changes

- **Read CV** on `ApplicationDetail` asks filinq to extract the profile from the application's
  CV file and fills only an empty profile, marking it as read from the CV. Without filinq, or
  when filinq cannot read the file, the action says so and HR types the profile.

## Cross-app dependencies

- **filinq**: a profile extraction contract (education level on the NLQF scale, years of
  experience, competence codes) over a stored file. Drafted for Ruben in
  `for-ruben/filinq-cv-profile-extraction.md`; this change waits on it.

## Out of scope

- Any ranking or rejection by the extracted profile beyond the existing arithmetic score.
