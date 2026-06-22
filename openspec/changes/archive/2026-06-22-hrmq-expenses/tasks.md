## 1. Preconditions (dependency)

- [x] 1.1 Confirm `hrmq-timesheet-approval` ships `OCA\\Hrmq\\Lifecycle\\NoSelfApprovalGuard` (registered in `Application.php`) and the SPA shell (CnAppRoot bootstrap, PageController, routes, `<navigations>`, seed fragment) that this change extends

## 2. Expense schema + lifecycle (config)

- [x] 2.1 Add `Expense` schema to `lib/Settings/register.d/hr-expense.json` (employeeId, title, description, amount, currency, category enum, expenseDate, receiptFile, status, submittedAt, approvedBy, approvedAt, rejectionReason, reimbursedAt; schema:Invoice)
- [x] 2.2 Declare the `x-openregister-lifecycle` state machine under `configuration`: field `status`, initial `draft`, terminal `reimbursed`, transitions `submit` (draft/rejected→submitted), `approve` (submitted→approved), `reject` (submitted→rejected), `reimburse` (approved→reimbursed)
- [x] 2.3 Reference the shared guard on `approve` and `reject` via `requires: OCA\\Hrmq\\Lifecycle\\NoSelfApprovalGuard`
- [x] 2.4 Do NOT port pipelinq's shillinq-AP fields/services/listener/views — reimbursement is the HR payout, not an AP/GL posting

## 3. Declarative manifest pages + menu (config)

- [x] 3.1 Add `Expenses` (`type:"index"`), `ExpenseApproval` (`type:"index"`, `defaultFilters.status=submitted`), and `ExpenseDetail` (`type:"detail"`) pages to `src/manifest.json` — all generic, no `type:"custom"`
- [x] 3.2 Add the "Onkosten" menu group with `Declaraties` + `Declaratiegoedkeuring` entries
- [x] 3.3 Validate `src/manifest.json` against the library's app-manifest-v2 schema

## 4. Seed + verify

- [x] 4.1 Add three `Expense` seed objects (submitted/approved/reimbursed) to `lib/Settings/register.d/hr-seed.json` with `@self` envelopes
- [x] 4.2 Deploy to :8080, `occ maintenance:repair`, and confirm the `Expense` schema + lifecycle annotation land in the OpenRegister `hrmq` register and the seed objects are queryable
- [x] 4.3 `npm run build` succeeds with the expense pages in the manifest

- Tier: MVP. ADR-022 (consume OR), ADR-031/ADR-001 (declarative-first), ADR-036 (manifest pages). Pure config: schema + manifest + seed; the only PHP (the self-approval guard) is shipped by the dependency change.
