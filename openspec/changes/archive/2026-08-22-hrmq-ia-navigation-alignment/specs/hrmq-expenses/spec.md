## MODIFIED Requirements

### Requirement: Declarative expense pages

The system SHALL surface expense claims through declarative manifest pages rendered generically by
the `@conduction/nextcloud-vue` library — NOT bespoke Vue components (unlike pipelinq's
hand-written `ExpenseList.vue` / `ExpenseDetail.vue`). There SHALL be an `Expenses` `type:"index"`
list page, an `ExpenseApproval` `type:"index"` page whose default filter is `status == submitted`
(the pending-approval queue), and an `ExpenseDetail` `type:"detail"` page, each configured only
with `{ register: "hrmq", schema: "Expense", … }`, reached from the "Declaraties & assets"
top-level menu group per `adr-001-information-architecture.md`'s frozen navigation (not a
standalone "Onkosten" top-level group).

**Feature tier**: MVP

#### Scenario: The approval queue lists pending claims

- GIVEN submitted, approved and reimbursed claims exist in the `hrmq` register
- WHEN a manager opens the "Declaratiegoedkeuring" (ExpenseApproval) page
- THEN the page MUST default its filter to `status == submitted`
- AND MUST render the list generically from the `Expense` schema without a bespoke component

#### Scenario: Expense pages are reachable under Declaraties & assets

- GIVEN the hrmq app menu is rendered
- WHEN a user looks for expense claims
- THEN the `Expenses` and `ExpenseApproval` entries MUST be found under the "Declaraties & assets"
  top-level menu group, not under a standalone "Onkosten" top-level group
