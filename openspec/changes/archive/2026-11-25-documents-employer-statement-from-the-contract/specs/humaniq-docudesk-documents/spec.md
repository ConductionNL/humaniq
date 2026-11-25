# humaniq-docudesk-documents

## ADDED Requirements

### Requirement: HR SHALL make an employer statement from the contract page (REQ-HDD-011)

`EmploymentContractDetail` SHALL offer the action `Werkgeversverklaring maken`. After
confirmation it SHALL call `POST /api/documents/generate` with the contract id and
`documentType: werkgeversverklaring`. The contract SHALL resolve under the caller's rights
before filinq is called. The page SHALL show the outcome and the new document.

Rows: `fil-employer-statement` (humaniq matrix).

#### Scenario: HR makes a statement for a mortgage
- **GIVEN** a permanent contract with filinq's employer statement template installed
- **WHEN** an HR adviser confirms `Werkgeversverklaring maken` on the contract page
- **THEN** a `GeneratedDocument` of type `werkgeversverklaring` with status `generated` is
  created for the contract's employee, and the PDF opens from the contract page

#### Scenario: A user without access to the contract gets nothing
- **WHEN** a user who cannot read the contract calls the endpoint with its id and
  `documentType: werkgeversverklaring`
- **THEN** the response is 404 and filinq is not called

#### Scenario: Without the template the outcome says so
- **GIVEN** filinq is installed without the employer statement template
- **WHEN** HR confirms the action
- **THEN** the document is recorded as `failed` with the reason that no template was found

### Requirement: Each employer statement SHALL be a new document (REQ-HDD-012)

Generation of `werkgeversverklaring` SHALL NOT be deduplicated. Each confirmed action SHALL
create a new `GeneratedDocument`, and earlier statements SHALL stay.

#### Scenario: A second statement a week later
- **GIVEN** a contract with a generated employer statement from last week
- **WHEN** HR confirms the action again
- **THEN** a second `generated` statement exists and the first is unchanged
