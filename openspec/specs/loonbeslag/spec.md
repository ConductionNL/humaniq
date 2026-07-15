---
capability: loonbeslag
status: done
built_by: openspec/changes/archive/2026-07-14-loonbeslag
---

# loonbeslag Specification

**Status**: done
**Scope**: hrmq (`depends_on: []`)
**OpenSpec changes**:
- [loonbeslag](../../changes/archive/2026-07-14-loonbeslag/) _(archived 2026-07-14)_
  — court/deurwaarder-ordered wage garnishment: the `Loonbeslag` schema, the floor-clamped
  post-tax payslip fold (a fourth current-run component alongside sick-pay/retro-adjustments/
  leave-buy-sell), the admin/HR-gated activate/settle/withdraw endpoints, and the
  `NlLoonbeslagChecks` corpus enforcement of the beslagvrije-voet floor and the
  single-active-beslag MVP scope.

## Purpose

hrmq computes NL gross-to-net (`PayrollCalculator`, pure, table-driven) and already folds
current-run, post-tax components onto `Payslip.nettoPay` without ever re-invoking the calculator
(retro-adjustments, leave-buy-sell). A wage garnishment (derdenbeslag / loonbeslag) is the same
shape: a court or deurwaarder orders a periodic deduction from an employee's NET pay to satisfy a
debt, but Dutch law (Wetboek van Burgerlijke Rechtsvordering art. 475b–475e, and from 2021 the Wet
vereenvoudiging beslagvrije voet) guarantees the employee keeps at least the *beslagvrije voet* —
the protected minimum. Before this change hrmq had no representation of this: nothing modeled a
garnishment order, nothing computed the floor-clamped deduction, and nothing folded it into a
payslip. This change adds exactly that, as a fourth current-run post-tax component — never a fifth
code path in `PayrollCalculator`, which stays pure and untouched. Because a garnishment order is
legally sensitive (it exposes an employee's debt situation and a wrong floor computation is a
labour-law violation), the surface is admin/HR-only, and the state changes that matter (activating
a garnishment, marking it settled) go through a guarded controller endpoint, never a bare
`x-openregister-lifecycle` `lifecycleActions` button.

## Requirements

### Requirement: A Loonbeslag schema SHALL model a court/deurwaarder-ordered wage garnishment (REQ-BESLAG-001)

`lib/Settings/register.d/hr-loonbeslag.json` SHALL define a `Loonbeslag` schema carrying:
`employeeId` (`$ref` Employee, the garnished employee), `creditor` (the creditor/deurwaarder name),
`dossierRef` (the deurwaarder's dossier/case reference), `totalClaim` (the total ordered claim,
euro), `orderedAmount` (the periodic deduction amount the order specifies, euro), `beslagvrijeVoet`
(the protected minimum, euro), a plain `status` enum (`concept`/`actief`/`voldaan`/`ingetrokken`,
default `concept`, no `x-openregister-lifecycle` map), and an effective period range
(`effectiveFrom` required, `effectiveTo` nullable).

#### Scenario: A Loonbeslag record carries every legally-required field
- **GIVEN** a deurwaarder's garnishment order for an employee
- **WHEN** a `Loonbeslag` object is created with `employeeId`, `creditor`, `dossierRef`,
  `totalClaim`, `orderedAmount`, `beslagvrijeVoet`, and `effectiveFrom`
- **THEN** the object validates against the schema and `status` defaults to `concept`

### Requirement: The garnishment deduction SHALL never push net pay below the beslagvrije voet (REQ-BESLAG-002)

The garnishment deduction folded into an employee's payslip SHALL be computed as `deduction = min(orderedAmount, max(0, nettoPay − beslagvrijeVoet))` for the one `actief` Loonbeslag
covering that employee's period (REQ-BESLAG-005), where `nettoPay` is the employee's net pay after
every other current-run fold (sick-pay substitution already reflected in the engine result,
retro-adjustment and leave-buy-sell already folded — REQ-BESLAG-004). This formula SHALL hold
exactly, in integer cents, with no exception. A machine-checkable rule
(`nl-loonbeslag-beslagvrije-voet-floor`, auto-discovered and RuleEngine-reachable per
REQ-BESLAG-007) SHALL flag any Payslip referencing a Loonbeslag whose resulting `nettoPay` is below
that Loonbeslag's `beslagvrijeVoet`.

#### Scenario: A large ordered deduction is clamped at the beslagvrije voet
- **GIVEN** an employee whose folded `nettoPay` before garnishment is €3.081,17, an `actief`
  Loonbeslag with `orderedAmount` €800,00 and `beslagvrijeVoet` €2.950,00
- **WHEN** the payslip for this period is generated
- **THEN** `Payslip.loonbeslag` is €131,17 (not €800,00) and `Payslip.nettoPay` is exactly
  €2.950,00

#### Scenario: A small ordered deduction is never clamped
- **GIVEN** the same employee and Loonbeslag but `orderedAmount` €50,00
- **WHEN** the payslip for this period is generated
- **THEN** `Payslip.loonbeslag` is €50,00 (the full ordered amount) and `Payslip.nettoPay` is
  €3.031,17

#### Scenario: Zero headroom deducts nothing
- **GIVEN** an employee whose folded `nettoPay` before garnishment already equals their
  `beslagvrijeVoet` (e.g. after a large terugvordering retro-adjustment)
- **WHEN** the payslip for this period is generated
- **THEN** `Payslip.loonbeslag` is `null` and `nettoPay` is unchanged by the garnishment

#### Scenario: A tampered payslip violates the floor check
- **GIVEN** a Payslip referencing an `actief` Loonbeslag whose `nettoPay` was edited to fall below
  that Loonbeslag's `beslagvrijeVoet`
- **WHEN** the RuleEngine audits it
- **THEN** `nl-loonbeslag-beslagvrije-voet-floor` reports a violation for that Payslip

### Requirement: The beslagvrije voet SHALL be a stored input for the MVP; computing it is a named follow-up (REQ-BESLAG-003)

`Loonbeslag.beslagvrijeVoet` SHALL be a required, HR-entered field trusted as the authoritative
figure communicated on the garnishment order (the deurwaarder is legally required to state it).
This change SHALL NOT compute the beslagvrije voet from income or household composition per the
*Wet vereenvoudiging beslagvrije voet* — that computation is a named fast-follow, stated in the
README, not silently implied as covered.

#### Scenario: The stored value is used as-is, with no derived recomputation
- **GIVEN** a `Loonbeslag` with `beslagvrijeVoet` €2.950,00 entered by HR
- **WHEN** the floor-clamp fold (REQ-BESLAG-002) runs
- **THEN** €2.950,00 is used directly as the floor — no income/household-composition inputs are
  read or required anywhere in this change

### Requirement: The deduction SHALL fold into the current-run payslip as a new nullable Payslip.loonbeslag field, post-tax; PayrollCalculator SHALL never be invoked for it (REQ-BESLAG-004)

`lib/Settings/register.d/hr-objects.json` SHALL add `Payslip.loonbeslag` (nullable number, euro,
null when no deduction applies) and `Payslip.loonbeslagId` (nullable `$ref` Loonbeslag).
`PayrollRunService::generate()` SHALL fold the floor-clamped deduction (REQ-BESLAG-002) into
`nettoPay` AFTER the existing retro-adjustment and leave-buy-sell folds — against the fully-folded
net pay, never against an intermediate figure. `lib/Payroll/PayrollCalculator.php` SHALL gain zero
new call sites and zero diff for this change; the deduction is entirely post-tax arithmetic in
`PayrollRunService` and never touches `loonheffing`, `premies`, or any taxable-wage figure.

#### Scenario: An unaffected payslip stays byte-identical
- **GIVEN** an employee with no `actief` Loonbeslag covering the period
- **WHEN** the payslip generates
- **THEN** `loonbeslag` and `loonbeslagId` are both `null` and every other field is unchanged from
  before this change

#### Scenario: Loonbeslag folds after retro-adjustment and leave-buy-sell, not before
- **GIVEN** an employee with an applied retro-adjustment, a settled leave-buy-sell transaction, AND
  an `actief` Loonbeslag all settling into the same period
- **WHEN** the payslip generates
- **THEN** the garnishment's floor-clamp arithmetic uses `nettoPay` AFTER retroAdjustment and
  leaveBuySell are already added, not the bare engine-computed `nettoPay`

### Requirement: The garnishment fold SHALL be idempotent per (loonbeslagId, period); the MVP SHALL handle at most one active beslag per employee-period (REQ-BESLAG-005)

Recalculating a draft run SHALL re-derive `Payslip.loonbeslag` from scratch every time (no
accumulator on `Loonbeslag`, no drift across repeated `--recalculate`). The MVP SHALL select at
most one `actief` Loonbeslag per employee per period (`effectiveFrom`/`effectiveTo` covering the
period); priority/preferente-vordering ordering across multiple concurrent garnishments for the
same employee is a named fast-follow, not implemented here. A machine-checkable rule
(`nl-loonbeslag-single-active`) SHALL flag any employee with more than one `actief` Loonbeslag
whose effective ranges overlap, so the MVP's single-active assumption is enforced rather than a
silent doc note.

#### Scenario: Recalculating a draft run reproduces the identical deduction
- **GIVEN** a draft PayrollRun already generated with a garnishment-affected Payslip
- **WHEN** `hrmq:payroll:run --period 2026-08 --recalculate` runs again with no change to the
  Loonbeslag or the employee's other figures
- **THEN** `Payslip.loonbeslag` and `nettoPay` are byte-identical to the first generation

#### Scenario: A second overlapping active beslag is flagged, not silently combined
- **GIVEN** an employee with two `actief` Loonbeslag records whose effective ranges overlap
- **WHEN** the RuleEngine audits the employee's garnishments
- **THEN** `nl-loonbeslag-single-active` reports a violation naming both records

### Requirement: Loonbeslag SHALL be an admin/HR-only surface with a guarded settle, not a bare lifecycle button (REQ-BESLAG-006)

`Loonbeslag.status` SHALL carry no `x-openregister-lifecycle` map. `lib/Controller/
LoonbeslagController.php` SHALL implement `activate()` (`concept` → `actief`), `settle()`
(`actief` → `voldaan`), and `withdraw()` (`concept`/`actief` → `ingetrokken`), each with `#
[NoAdminRequired]` and, in this exact order: (1) an admin/HR membership check returning 403 BEFORE
any ObjectService resolve — an unauthorized caller SHALL NOT be able to probe garnishment existence
via this endpoint; (2) RBAC-resolve the posted `loonbeslagId` through ObjectService under the
caller's ambient RBAC, collapsing unknown and unauthorized to the same 404; (3) a status
precondition check (400 on the wrong current status) before any write. `src/manifest.json`'s
`LoonbeslagDetail` page SHALL wire its transition actions as `api-call` page actions against these
three endpoints, never as a `lifecycleActions` widget.

#### Scenario: A non-admin/HR caller is refused before any resolve
- **GIVEN** a caller who is not a member of the admin/HR group
- **WHEN** they POST to `/api/loonbeslag/activate` with any `loonbeslagId`, including one that does
  not exist
- **THEN** the response is 403 and no ObjectService resolve, status check, or write occurs

#### Scenario: An unauthorized or unknown loonbeslagId never reaches the write
- **GIVEN** an admin/HR caller whose RBAC cannot see Loonbeslag X, or X does not exist
- **WHEN** they POST to `/api/loonbeslag/settle` with `loonbeslagId: X`
- **THEN** the response is 404 and no write occurs

#### Scenario: settle refuses a non-actief Loonbeslag
- **GIVEN** an admin/HR caller and a Loonbeslag in status `concept`
- **WHEN** they POST to `/api/loonbeslag/settle` with its id
- **THEN** the response is 400 and `status` is unchanged

### Requirement: NlLoonbeslagChecks SHALL be auto-discovered and enforce the floor and single-active rules (REQ-BESLAG-007)

`lib/Standards/Checks/NlLoonbeslagChecks.php` SHALL implement `CheckProvider` and require zero
manual registration — `RuleEngine::providers()`'s auto-discovery is sufficient for both predicates
to become reachable the moment the file exists. It SHALL register `nl-loonbeslag-beslagvrije-voet-
floor` (Payslip; vacuous when `loonbeslagId` is null; else, resolving the referenced Loonbeslag via
`$context['payroll']['loonbeslagenById']`, asserts cents-exact `nettoPay ≥ beslagvrijeVoet`) and
`nl-loonbeslag-single-active` (Loonbeslag; vacuous when the employee has zero or one `actief`
record; else flags every record in an overlapping-effective-range group of two or more `actief`
records for the same employee). `RuleAuditService::audit()` SHALL enrich its context with
`payroll.loonbeslagenById`.

#### Scenario: The provider is reachable with no registration code
- **GIVEN** `NlLoonbeslagChecks.php` exists under `lib/Standards/Checks/` implementing
  `CheckProvider`
- **WHEN** `occ hrmq:rules:audit` runs
- **THEN** both `nl-loonbeslag-beslagvrije-voet-floor` and `nl-loonbeslag-single-active` are listed
  among the enforced rules, with no edit to `Application.php` or any other registration file

#### Scenario: Hand-entered or unaffected records stay out of scope
- **GIVEN** a Payslip with `loonbeslagId` null
- **WHEN** the audit runs
- **THEN** `nl-loonbeslag-beslagvrije-voet-floor` reports no violation for it
