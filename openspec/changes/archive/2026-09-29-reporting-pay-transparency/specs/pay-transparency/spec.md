# pay-transparency

## ADDED Requirements

### Requirement: humaniq SHALL compute the gender pay gap per category of equal work (REQ-PTR-001)

For an administration and a year humaniq SHALL compute from the payslips the mean and median
gender pay gap in hourly pay, overall and per category of equal work or work of equal value
(`Normfunctie.payCategory`), the gap in variable and complementary pay, the share of each gender
receiving it, and the gender split per pay quartile.

Rows: `dm-pay-transparency` (humaniq matrix).

#### Scenario: HR prepares the yearly report
- **GIVEN** an administration whose functions are grouped into three pay categories and whose
  employees carry a gender
- **WHEN** an HR adviser opens the "Pay transparency" report for 2026
- **THEN** it shows the overall mean and median gap and the gap per category, and exports to CSV

@e2e exclude the indicators are computed server-side and shown by library widgets; covered by PayTransparencyServiceTest::testTheGapsOfASmallAdministrationByHand and PayTransparencyControllerTest::testHrReadsLastYearOfItsOwnAdministration and ::testTheExportIsACsv

### Requirement: Small groups SHALL NOT be reported as figures (REQ-PTR-002)

A category with fewer women or men than the threshold (by default five) SHALL be reported as too
small, without figures, and the report SHALL be readable only by the HR and accountant roles.

Rows: `dm-pay-transparency` (humaniq matrix).

#### Scenario: A category of four women
- **GIVEN** a category with four women and twelve men
- **WHEN** the report is read
- **THEN** that category shows "too small to report" and no gap

@e2e exclude suppression and access are decided server-side; covered by PayTransparencyServiceTest::testACategoryOfFourWomenIsTooSmall and PayTransparencyControllerTest::testAnyoneElseIsRefused
