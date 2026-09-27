# vacancy-multiposting

## ADDED Requirements

### Requirement: HR SHALL choose the job boards a vacancy is posted to (REQ-VMP-001)

`Vacancy` SHALL carry a `channels` list of board codes, set on `VacancyDetail`. A board code
SHALL name the integriq source the board is reached through. humaniq SHALL hold no board
URL, credential or HTTP client.

Rows: `hir-multiposting` (humaniq matrix).

#### Scenario: An adviser ticks two boards
- **GIVEN** a vacancy "Payroll adviser" in status `concept`
- **WHEN** an HR adviser ticks werk.nl and LinkedIn on `VacancyDetail`
- **THEN** the vacancy's `channels` are `werk-nl` and `linkedin`, and nothing is posted yet

### Requirement: Publishing SHALL post to every chosen board and record each outcome (REQ-VMP-002)

On the `publiceren` transition a shipped flow SHALL post the vacancy to each board in
`channels` through integriq and SHALL write one `VacancyPosting` per board with status
`geplaatst` and the board's id and link, or `mislukt` with the reason. One failing board
SHALL NOT stop the others. Running the flow again for the same vacancy and board SHALL
update that posting rather than add one.

Rows: `hir-multiposting` (humaniq matrix).

#### Scenario: One board fails, the other posts
- **GIVEN** an adopted posting flow, a configured werk.nl source and no LinkedIn source
- **WHEN** an HR adviser publishes a vacancy with both boards ticked
- **THEN** `VacancyDetail` lists a werk.nl posting `geplaatst` with its link and a LinkedIn
  posting `mislukt` with the reason "no source configured"

#### Scenario: Before adoption nothing is posted
- **GIVEN** the shipped posting flow has not been enabled by an admin
- **WHEN** an HR adviser publishes a vacancy with boards ticked
- **THEN** the vacancy is `gepubliceerd` as today and no posting is created

### Requirement: Closing a vacancy SHALL withdraw it from every board (REQ-VMP-003)

On the `sluiten` transition a shipped flow SHALL withdraw every posting of that vacancy
with status `geplaatst` and SHALL set it to `ingetrokken` with `withdrawnAt`.

Rows: `hir-multiposting` (humaniq matrix).

#### Scenario: A filled vacancy disappears everywhere
- **GIVEN** a vacancy live on werk.nl and LinkedIn
- **WHEN** an HR adviser closes the vacancy
- **THEN** both postings read `ingetrokken` with today's date on `VacancyDetail`
