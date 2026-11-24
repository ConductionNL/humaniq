# payroll-wage-tax-remittance-shillinq

## ADDED Requirements

### Requirement: A confirmed wage tax return SHALL become one draft payable in shillinq (REQ-PWR-001)

For a payable payroll run (status `approved` or `posted`) whose wage tax return was made from it
and is `bevestigd` or `verzonden`, humaniq SHALL write one `APTransaction` in state `draft` into
shillinq. It SHALL carry the return's `TotGen` as `totalAmount` and as its single line, the
configured Belastingdienst payee as `vendorId`, the return's `betalingskenmerk` as
`invoiceNumber`, its `aangiftenummer` as `invoiceReference` and its `deadline` as `dueDate`. The
payload SHALL be valid against shillinq's `APTransaction` schema. humaniq SHALL drive no shillinq
transition.

#### Scenario: The May return is handed to shillinq
- **GIVEN** an approved May run and a confirmed May return made from it, with a total to pay of 1,840 euros
- **AND** shillinq's Belastingdienst payee is named in humaniq's settings
- **WHEN** the payroll officer runs `occ humaniq:wagetax:remit --period 2026-05`
- **THEN** shillinq holds one draft payable of 1,840 euros to that payee, due on 30 June, with the May payment reference
- **AND** humaniq logs the hand-off as `created` with the payable's id

@e2e exclude occ command and cross-app write, no page drives it; covered by WageTaxRemittanceServiceTest::testAConfirmedReturnBecomesOneDraftPayableThatFitsShillinqsSchema and WageTaxRemitCommandTest::testTheCommandHandsTheRunsReturnToShillinq

### Requirement: The payable SHALL include the employee insurance premiums, with no separate UWV payee (REQ-PWR-002)

The amount SHALL be the return's `TotGen`, which includes the withheld wage tax, the employee
insurance premiums and the Zvw contribution the return declares. humaniq SHALL NOT write a
separate payable to UWV. A `TotGen` of zero or less SHALL write no payable and SHALL be logged as
`nothing-to-pay`.

#### Scenario: Premiums travel in the same payment
- **GIVEN** a confirmed return whose total to pay is wage tax plus AWf, Aof and Zvw premiums
- **WHEN** the remittance is handed off
- **THEN** shillinq holds one payable for the whole total, to the Belastingdienst payee
- **AND** no payable names any other creditor

#### Scenario: A return with nothing to pay
- **GIVEN** a confirmed return whose total to pay is 0
- **WHEN** the remittance is handed off
- **THEN** shillinq holds no new payable and humaniq logs `nothing-to-pay`

@e2e exclude server-side amount selection; covered by WageTaxRemittanceServiceTest::testThePremiumsArePaidWithTheWageTaxToOneCreditor and ::testNothingToPayWritesNoPayable

### Requirement: The hand-off SHALL fail closed, happen once, and wait for a confirmed return (REQ-PWR-003)

humaniq SHALL write nothing into shillinq when the return has no `betalingskenmerk`, when no
payee is configured, or when the configured payee does not exist in shillinq; it SHALL log
`failed` with the reason. A run without a confirmed or sent return SHALL get no record. A
second hand-off of the same return SHALL write nothing new, and a payable that already exists in
shillinq for the same payee and payment reference SHALL be adopted, not duplicated.

#### Scenario: The payment reference is missing
- **GIVEN** a confirmed return without a payment reference
- **WHEN** the remittance is handed off
- **THEN** shillinq holds no new payable and humaniq logs `failed`, naming the missing reference

#### Scenario: Running the command twice
- **GIVEN** a return already handed to shillinq
- **WHEN** the payroll officer runs the command again
- **THEN** shillinq still holds exactly one payable for that return

@e2e exclude fail-closed and idempotency checks are server-side; covered by WageTaxRemittanceServiceTest::testAMissingPaymentReferenceWritesNothing, ::testAnUnknownPayeeWritesNothing, ::testADraftReturnIsNotPaidYet, ::testASecondHandOffIsANoOp and ::testAPayableWrittenBeforeACrashIsAdopted

### Requirement: Without shillinq the hand-off SHALL be skipped and retried later (REQ-PWR-004)

When shillinq is not installed or its `APTransaction` schema cannot be read, humaniq SHALL log
`skipped-no-shillinq` and raise no error. The next call SHALL try again.

#### Scenario: shillinq is not installed
- **GIVEN** an instance without shillinq
- **WHEN** the payroll officer runs the command
- **THEN** humaniq logs `skipped-no-shillinq` and the command exits 0

@e2e exclude depends on app presence; covered by WageTaxRemittanceServiceTest::testWithoutShillinqTheHandOffIsSkipped
