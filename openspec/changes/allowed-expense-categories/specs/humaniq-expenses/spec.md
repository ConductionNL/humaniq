# humaniq-expenses

## ADDED Requirements

### Requirement: The employer SHALL choose which expense categories employees may claim

An admin SHALL switch each expense category on or off on the humaniq admin settings page. The
categories are travel, accommodation, meals, supplies, software, training and other. Every
category SHALL be on until an admin switches it off. The setting SHALL be stored as the list of
categories that are off (app config `expense_categories_disabled`), so a category added later
is on by default.

The server SHALL refuse an expense claim in a category that is off, with a message that names
the problem. It SHALL refuse when the claim is created, when its category is changed to one
that is off, and when it is submitted. It SHALL NOT refuse the approve, reject or reimburse of
a claim that was already submitted, or any other edit of an existing claim that keeps its
category. humaniq's own writes SHALL NOT be refused.

`GET /api/expense-categories` SHALL list every category and whether it is on, for any signed-in
user. `PUT /api/expense-categories` SHALL save the list of categories that are on. Only an admin
may save, and an unknown category SHALL be refused with a 400 response.

#### Scenario: Every category is on by default

@e2e exclude Settings read with no fixture of its own; covered by ExpenseCategoryPolicyTest::testEveryCategoryIsOnByDefault
- **GIVEN** a fresh install
- **WHEN** anyone reads the expense categories
- **THEN** all seven categories are on

#### Scenario: A claim in a switched-off category is refused

@e2e exclude Pre-save refusal with no screen of its own; covered by ExpenseCategoryGuardListenerTest::testACreateInASwitchedOffCategoryIsRefused
- **GIVEN** the admin switched off software
- **WHEN** an employee creates a software claim
- **THEN** the claim is not saved, and the employee reads that this category cannot be claimed

#### Scenario: A submit in a switched-off category is refused, a decision is not

@e2e exclude Pre-save refusal; covered by ExpenseCategoryGuardListenerTest::testASubmitIsRefusedAndADecisionIsNot
- **GIVEN** a draft meals claim and a submitted meals claim
- **AND** the admin then switches off meals
- **WHEN** the employee submits the draft, and the manager approves the submitted claim
- **THEN** the submit is refused and the approval is saved

#### Scenario: Only an admin saves the switches

@e2e exclude Endpoint authorization; covered by ExpenseCategoryControllerTest
- **WHEN** an admin saves meals and training as off
- **THEN** reading the categories shows meals and training off and the rest on
- **AND** saving an unknown category is refused with a 400 response
