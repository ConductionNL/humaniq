# Delta — mileage-rules

Dutch mileage/commute reimbursement rules (reiskosten) as machine-checkable corpus rules on the
existing `Expense` schema: a versioned onbelast-tarief rule, its check predicate, and the two
additive Expense fields the predicate needs. Reuses the existing Expense approval workflow; adds
no new lifecycle.

## ADDED Requirements

### Requirement: Expense SHALL carry optional distanceKm and travelType fields for mileage-based travel claims (REQ-MILE-001)

The Expense schema (lib/Settings/register.d/hr-expense.json, version 0.3.0 to 0.4.0) SHALL gain two additive, nullable properties outside required: travelType (string, enum business or commute, distinguishing zakelijke kilometers from woon-werkverkeer) and distanceKm (number, nullable, minimum 0, kilometers driven). Neither property changes the category enum, the required list, or the existing x-openregister-lifecycle state machine, so every previously stored Expense stays valid without migration. The hrmq register info.version (lib/Settings/hrmq_register.json) bumps from 0.8.0 to 0.9.0.

#### Scenario: Existing Expense stays valid without the new fields
- **GIVEN** an Expense object created before this change with no travelType or distanceKm
- **WHEN** the schema is reloaded at the new version
- **THEN** the object still validates, since both new properties are nullable and absent from required

#### Scenario: A mileage claim carries the new fields
- **GIVEN** an employee submits an Expense with category travel, travelType business, amount 46,00 and distanceKm 200
- **WHEN** the claim is saved
- **THEN** it validates against the 0.4.0 Expense schema with both new fields populated

### Requirement: The 2026 onbelaste kilometervergoeding SHALL be versioned rule-corpus data, not code (REQ-MILE-002)

lib/Standards/rules/payroll.json SHALL gain rule nl-reiskosten-onbelast-tarief (domain tax, jurisdiction NL, framework nl-loonheffingen, source Wet LB 1964 art. 31a lid 2 gerichte vrijstelling reiskosten, severity mandatory, machineCheckable true, effectiveDate 2026-01-01, sourceUrl the Belastingdienst kilometervergoeding-verhoging page) carrying parameters.rateEurPerKm: 0.23, the onbelaste kilometervergoeding for 2026 for both zakelijke and woon-werk kilometers. Because the rate lives in parameters and not in PHP, a later change to the figure — including the Belastingdienst's own mid-2026 increase to EUR 0,25 per km, retroactive to 1 January 2026 — is a pure JSON edit to this one number, with no code change and no predicate rewrite. RuleCatalogue::VERSION bumps per SCHEMA.md's bump-on-any-change rule.

#### Scenario: The rate is read from the catalogue, not hardcoded
- **GIVEN** the nl-reiskosten-onbelast-tarief rule with parameters.rateEurPerKm 0.23
- **WHEN** a check predicate needs the onbelast rate
- **THEN** it reads RuleCatalogue::all() for that rule id's parameters.rateEurPerKm rather than a literal 0.23 in source

#### Scenario: A later rate change is data-only
- **GIVEN** the Belastingdienst raises the 2026 rate to EUR 0,25 per km
- **WHEN** parameters.rateEurPerKm is edited to 0.25 in payroll.json
- **THEN** every Expense audited afterwards is checked against the new rate with no code change and no deploy of lib/Standards/Checks

### Requirement: NlReiskostenChecks SHALL enforce the onbelast-tarief rule on Expense, auto-discovered by RuleEngine (REQ-MILE-003)

lib/Standards/Checks/NlReiskostenChecks.php SHALL implement CheckProvider, registering checks()['Expense']['nl-reiskosten-onbelast-tarief'] as a pure fn(array $o, array $context): bool predicate that computes amount divided by distanceKm and compares it to the catalogue's rateEurPerKm, violating only when category is travel, travelType is business or commute, distanceKm is a positive number, amount is numeric, and the computed per-km reimbursement exceeds the rate; every other combination — wrong category, missing travelType, absent or non-positive distanceKm, non-numeric amount, or unreadable catalogue parameters — is vacuously satisfied, never a false violation. No change to RuleEngine.php is needed: RuleEngine::providers() globs lib/Standards/Checks/*.php and instantiates any class implementing CheckProvider, so dropping this one file into that directory is the entire wiring.

#### Scenario: Over-rate mileage claim violates
- **GIVEN** an Expense with category travel, travelType business, distanceKm 100 and amount 30,00 (EUR 0,30 per km)
- **WHEN** occ hrmq:rules:audit runs
- **THEN** an nl-reiskosten-onbelast-tarief violation is reported for that Expense

#### Scenario: At-or-under-rate mileage claim passes
- **GIVEN** the same claim with amount 23,00 instead (EUR 0,23 per km)
- **WHEN** the audit runs
- **THEN** no nl-reiskosten-onbelast-tarief violation is reported for it

#### Scenario: Non-mileage Expense is vacuously out of scope
- **GIVEN** an Expense with category meals and no travelType or distanceKm
- **WHEN** the audit runs
- **THEN** the rule reports no violation for it and does not count it as a checked-and-failed object

#### Scenario: New provider needs no RuleEngine registration
- **GIVEN** NlReiskostenChecks.php is added under lib/Standards/Checks/
- **WHEN** RuleEngine::providers() runs its discovery glob
- **THEN** the class is discovered and its Expense predicate is merged into the engine with no edit to RuleEngine.php itself

### Requirement: The existing Expense approval workflow SHALL be reused; no new lifecycle SHALL be introduced (REQ-MILE-004)

This change SHALL NOT add, remove, or alter any state or transition of Expense's existing x-openregister-lifecycle (draft, submitted, approved, rejected, reimbursed via submit, approve, reject, reimburse, guarded by the existing NoSelfApprovalGuard). The onbelast-tarief rule is an audit-time compliance signal surfaced by occ hrmq:rules:audit and the RuleAuditService coverage report, not a write-time guard on any transition; a mileage Expense that violates the rate can still be submitted, approved, and reimbursed through the unchanged workflow. Grossing up the bovenmatige (excess) vergoeding through loonheffing on the employee's payslip is explicitly out of MVP scope and named as a follow-up in design.md.

#### Scenario: An over-rate claim still completes the unchanged workflow
- **GIVEN** an Expense that violates nl-reiskosten-onbelast-tarief in status submitted
- **WHEN** a manager approves and then reimburses it through the existing submit, approve and reimburse transitions
- **THEN** every transition succeeds exactly as it does today, and the violation remains visible only in the compliance audit, never blocking the transition

#### Scenario: No new lifecycle field or transition exists
- **GIVEN** the Expense schema after this change
- **WHEN** its configuration.x-openregister-lifecycle is inspected
- **THEN** it declares exactly the same field, initial state, terminal state and four transitions as before this change
