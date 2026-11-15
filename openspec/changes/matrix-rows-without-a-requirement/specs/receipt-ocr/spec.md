# receipt-ocr

## ADDED Requirements

### Requirement: A claim SHALL record its VAT and finance SHALL see the VAT to reclaim per period (REQ-RCPT-008)

An expense claim SHALL record the VAT amount on its receipt, typed by the employee or read from
the receipt. Finance SHALL see the VAT of the reimbursed claims per period and per
administration, so the employer can reclaim it in its VAT return. The payroll journal SHALL
book that VAT on the VAT account instead of on the cost account.

Rows: `dm-expense-vat` (humaniq matrix).

#### Scenario: An employee records the VAT on a receipt
- **GIVEN** a hotel receipt of 121.00 euros with 21.00 euros VAT
- **WHEN** the employee submits the claim with the VAT amount filled in
- **THEN** `ExpenseDetail` shows a VAT amount of 21.00 on the claim

#### Scenario: Finance reads the VAT to reclaim for May
- **GIVEN** three claims reimbursed in May with 21.00, 9.00 and no VAT
- **WHEN** finance opens the VAT overview for May
- **THEN** it lists the two claims with VAT and a total of 30.00 to reclaim

#### Scenario: The journal books the VAT apart
- **GIVEN** the May payroll run that reimburses the hotel claim
- **WHEN** the journal is posted to shillinq
- **THEN** it carries 100.00 on the cost account and 21.00 on the VAT account
