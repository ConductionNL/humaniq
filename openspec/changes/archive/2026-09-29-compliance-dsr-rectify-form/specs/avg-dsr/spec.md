# avg-dsr

## ADDED Requirements

### Requirement: A rectification started from the request page SHALL carry its changes (REQ-DSR-R01)

A `DsrRequest` SHALL hold the requested corrections as field and value pairs limited to the
employee fields a data subject may have corrected. The Rectify action on `DsrRequestDetail`
SHALL send those pairs, the endpoint SHALL apply them to the employee, and the outcome SHALL
be recorded on the request.

Rows: `cmp-dsr-rectify` (humaniq matrix).

#### Scenario: HR corrects a surname from the page
- **GIVEN** a rectification request whose requested changes set the last name to
  "de Vries-Jansen"
- **WHEN** an HR administrator presses Rectify on `DsrRequestDetail` and confirms
- **THEN** the employee's last name is "de Vries-Jansen", the request records the change and
  who applied it, and no 400 is returned

@e2e exclude the correction is applied server side by the guarded endpoint; covered by AvgDsrControllerTest::testThePagesListOfPairsIsAppliedAsAMap and DsrRectifyDeclarationTest::testTheRectifyActionSendsTheRequestedChanges

#### Scenario: A field outside the list is refused
- **GIVEN** a request whose requested changes include `grossMonthlySalary`
- **WHEN** Rectify is pressed
- **THEN** the endpoint refuses with 400 and the employee is unchanged

@e2e exclude the refusal is server side; covered by AvgDsrControllerTest::testAFieldOutsideTheListIsRefused and DsrRectifyDeclarationTest::testTheSeededRequestFitsAndAForbiddenFieldDoesNot
