---
kind: code
---

# Training on the personnel file, and people in the learning app

## Why

humaniq knows nothing about training. An HR adviser who sends three employees on a first-aid
course, or pays for a colleague's post-graduate study with a repayment agreement, records it
in a spreadsheet. The personnel file shows no course, no attendance and no certificate, so
the question "who followed the mandatory privacy training" goes to the learning platform, if
there is one, and to memory if there is not.

The learning platform has the opposite problem. learniq, the fleet's learning app, holds
courses, sessions, enrolments, attendance and credentials, but it has to be told who works
here, in which team, in which role and under which manager. Today nothing tells it:
`FleetAppId` lists `learniq`, and no humaniq code calls it. A municipal tender asks for exactly
this link, with Studytube as its example of an external learning platform.

This change gives humaniq its half. The personnel file keeps a training record per
employee, planned, attended or not, with its cost and any study-cost agreement. humaniq
publishes one people feed (employees, roles, org units, managers) that learniq and an
external platform read, and takes completed credentials back from learniq into the training
record and the employee's competences.

### Matrix rows (humaniq `openspec/parity/capabilities.json`)

| row | capability | humaniq today |
|---|---|---|
| `tal-training` | Plan training and register who attended. | `no`: no training or course schema or page |
| `td-lms-sync` | Keep employees, roles and organisation data in sync with a learning management system. | `no`: `learniq` is a resolvable id in `FleetAppId`; nothing calls it |

### Demand

- `td-lms-sync`, tender: https://www.tenderned.nl/aankondigingen/overzicht/415227
  (Sudwest-Fryslan E17.1 and E17.2, koppeling met een LMS zoals Studytube, automatische
  synchronisatie).

### Competitors rated yes

- `tal-training`, AFAS Profit: "employees are enrolled (also collectively) in courses of the
  course administration" and a report shows "attendance per course session"
  (https://help.afas.nl/help/NL/SE/Hrm_Employ_Train.htm,
  https://help.afas.nl/content/NL/SE/138132.htm).
- `tal-training`, Visma Raet Youforce: "learning management and experience platform
  (PlusPort) with progress tracking" (https://youforce.nl/product/opleiden-en-ontwikkelen).
- `tal-training`, HR2day: "training requests and followed training records with approval
  processes" (https://data.maglr.com/1697/issues/53019/641822/index.html).
- `tal-training`, Loket.nl: "record all trainings in the HR software"
  (https://loket.nl/hr-software/, https://developer.loket.nl/ApiDocs#tag/Education).
- `tal-training`, Personio: "plan courses and sessions, invite participants and keep
  participation records"
  (https://support.personio.de/hc/en-us/articles/4426926314269-Add-courses-and-sessions-in-Personio).
- `td-lms-sync`, Visma Raet Youforce: "the Learning API gives learning systems employee,
  role and organisation data and accepts certificates back"
  (https://vr-api-integration.github.io/youforce-api-documentation/learning_api_intro.html).

### Recorded follow-ups this change picks up

- `2026-07-13-performance-reviews-mvp` keeps "career/functie frameworks and competency
  matrices" out as "separate draft territory"; this change adds no competency matrix and
  writes only the existing `EmployeeCompetence`.
- No recorded non-goal names training administration or an LMS feed.

## What Changes

- **A training record per employee.** A new `TrainingRecord` schema holds the training's
  title and provider, the planned date, whether the employee attended (`gepland`,
  `gevolgd`, `niet-gevolgd`), the completion date and validity, the cost, a study-cost
  agreement flag with its repayment terms, and where the record came from (HR, learniq or an
  external platform). `EmployeeDetail` lists them; a `Trainingen` index plans and registers
  attendance for many employees at once.
- **A completed training can grant a competence.** A record with a `competenceCode` that is
  `gevolgd` writes or extends the employee's `EmployeeCompetence`, so the roster check and
  the dossier see it.
- **One people feed.** `GET /api/learning/people` returns active employees with name,
  Nextcloud account, current org unit and role, manager and employment dates, and nothing
  more: no BSN, no salary. It supports `modifiedSince` so a platform fetches only changes.
- **Credentials back from learniq.** When learniq issues a credential to a learner whose
  Nextcloud account belongs to an employee, humaniq writes a `TrainingRecord` from it
  (source learniq, with the credential's id), once per credential.

## Capabilities

### New Capabilities

- `training-and-lms-sync`: the training record on the personnel file, the people feed for
  learning platforms, and completed credentials from learniq.

## Impact

- `lib/Settings/register.d/hr-training.json` (new fragment): `TrainingRecord`.
- `lib/Service/LearningPeopleFeed.php`, `lib/Service/TrainingCompetenceWriter.php`,
  `lib/Listener/LearniqCredentialListener.php` (new).
- `lib/Controller/LearningController.php` (new) and `appinfo/routes.php`:
  `GET /api/learning/people`.
- `src/manifest.d/hr-training.json` (new): `Trainingen`, `TrainingRecordDetail`, the list on
  `EmployeeDetail`, a menu entry.

## Cross-app dependencies

- **learniq**: reads the people feed to keep its learner and staff records in line (name,
  account, department, manager, role), and issues `Credential` objects whose learner resolves
  to a Nextcloud account. Courses, sessions, enrolment and attendance registration for its
  own courses stay learniq's.
- **integriq**: a Studytube (or other LMS) source that reads the people feed and writes
  completed trainings back as `TrainingRecord` objects through the objects API.

## Out of scope

- Course catalogues, sessions, e-learning and attendance lists for internal courses. They
  are learniq's.
- Training budgets per department and approval of training requests.
- Calculating a study-cost repayment. The record holds the agreement; the amount is typed.
