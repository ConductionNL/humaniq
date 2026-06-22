---
kind: config
depends_on:
  - hrmq-timesheet-approval   # ships the shared NoSelfApprovalGuard, the SPA shell (CnAppRoot bootstrap, PageController, routes, navigation) and the seed fragment that this change extends. Expenses adds only declarative schema + manifest pages + seed objects.
---

## Why

Expense reimbursement is an HR/labour concern and belongs in hrmq. Today it lives in pipelinq
(the CRM) as a bespoke feature: an `expense` schema plus three hand-written Vue views
(`ExpenseList.vue`, `ExpenseDetail.vue`, `ExpenseShillinqApCard.vue`), a `ShillinqApService`
that one-way-syncs approved expenses to shillinq accounts-payable, an approval listener, and an
admin retry controller. The HR claim workflow (submit → approve → reimburse) is sound, but it is
mis-homed and built bespoke.

This change re-homes the **HR-side** expense claim to hrmq as a declarative feature: an `Expense`
object with a `submit → approve → reimburse` lifecycle, surfaced through declarative manifest
pages rendered generically by the `@conduction/nextcloud-vue` library — no bespoke Vue views. The
separation-of-duties rule (no self-approval) reuses the `NoSelfApprovalGuard` shipped by
`hrmq-timesheet-approval`. The pipelinq-specific shillinq accounts-payable sync is intentionally
NOT ported: reimbursement here is the HR claim payout; any downstream AP/GL posting is a separate
integration concern that OpenConnector / shillinq own. This keeps the centre of mass declarative
(`kind: config`): schema + manifest + seed, with the only PHP being the already-shipped shared
guard.

## What Changes

- **New `Expense` schema** in the OpenRegister `hrmq` register
  (`lib/Settings/register.d/hr-expense.json`) with a declarative `x-openregister-lifecycle` state
  machine: `draft → submitted → approved/rejected`, then `approved → reimbursed` (terminal). The
  `approve`/`reject` transitions declare `requires: OCA\Hrmq\Lifecycle\NoSelfApprovalGuard`
  (shared with the timesheet change). Fields: employeeId, title, description, amount, currency,
  category (enum), expenseDate, receiptFile, status, submittedAt, approvedBy, approvedAt,
  rejectionReason, reimbursedAt.
- **Declarative manifest pages** (`src/manifest.json`): an `Expenses` `type:"index"` list, an
  `ExpenseApproval` `type:"index"` queue defaulting its filter to `status == submitted`, and an
  `ExpenseDetail` `type:"detail"` page — all rendered generically from `{register, schema}`
  config, with menu entries under an "Onkosten" group. No `type:"custom"` page, no bespoke
  component (unlike pipelinq's three hand-written views).
- **Seed data** (`lib/Settings/register.d/hr-seed.json`): three realistic `Expense` objects
  (submitted / approved / reimbursed) with `@self` envelopes.
- **No port of**: pipelinq's `ShillinqApService`, `ApSyncNotifier`, `ExpenseApprovedEvent`,
  `ExpenseApprovalListener`, `ShillinqApController`, the `apSyncStatus`/`apSyncedAt` fields, or the
  three bespoke Vue views — those are pipelinq integration plumbing, not the HR claim model.

## Capabilities

### New Capabilities
- `hrmq-expenses`: submit, approve/reject and reimburse employee expense claims, with a
  declarative reimbursement lifecycle and the shared separation-of-duties guard.

## Impact

- **`lib/Settings/register.d/hr-expense.json`** (new) — `Expense` schema + lifecycle.
- **`lib/Settings/register.d/hr-seed.json`** — adds three `Expense` seed objects (shared fragment).
- **`src/manifest.json`** — adds the "Onkosten" menu group + `Expenses` / `ExpenseApproval` /
  `ExpenseDetail` declarative pages.
- **Reuses** the `NoSelfApprovalGuard` + SPA shell from `hrmq-timesheet-approval` (no new PHP).
- **Re-homing**: replaces pipelinq's Expenses feature. Pipelinq Phase-2 deletes
  `lib/Settings/register.d/30-expense-shillinq-ap.json`, `src/manifest.d/30-expenses.json`,
  `src/views/expenses/` (3 views), `lib/Service/ShillinqApService.php`,
  `lib/Service/ApSyncNotifier.php`, `lib/Controller/ShillinqApController.php`,
  `lib/Event/ExpenseApprovedEvent.php`, `lib/Listener/ExpenseApprovalListener.php`, the
  `shillinqAp#retry` route, and the listener/config registration in `Application.php`; the
  Expenses menu entry is replaced by a deep-link to `/index.php/apps/hrmq/expenses`.
- **No new external dependency, no DB table, no direct SQL, no custom endpoint, no nextcloud-vue
  change** (ADR-022). The Expense objects live in the OpenRegister `hrmq` register and the pages
  read/write them via the library's object store.
