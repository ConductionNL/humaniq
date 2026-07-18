# Design — document-dossier-avg

## Context

**Verified against HEAD 2026-07-17.** Read read-only, directly grounding this design:

- `openspec/specs/hrmq-docudesk-documents/spec.md` — `GeneratedDocument` (`lib/Settings/register.d/
  hr-documents.json`, v0.2.0): `documentType` (enum `arbeidsovereenkomst|aanbiedingsbrief|
  werkgeversverklaring|getuigschrift|loonstrook|jaaropgaaf`), `employeeId`, `contractId`, `payslipId`,
  `jaaropgaafId`, `templateRef`, `status`, `filePath`, `errorMessage`, `generatedAt` — **no retention field**.
  `lib/Service/HrDocumentService.php::findPayslip(string $payslipId): ?array` (called from
  `generateLoonstrook()`) resolves the full `Payslip` object via `ObjectService` before rendering — its
  `period`/`retainedUntil` fields are already in memory at generation time, not a new resolve this change would
  add. `generateJaaropgaaf(string $employeeId, int $year, ...)` receives `$year` as a direct local parameter — no
  resolve needed to know it.
- `src/manifest.json` `EmployeeDetail` page: `object-list` widgets for `EmploymentContract`/`Timesheet`/
  `Payslip`/`Expense`/`OrgAssignment`/`PerformanceReview`/`Objective`/`CompAdjustment`/`LeaveTransaction`, each
  `filter: {employeeId: "@objectId"}` — the exact, repeated FK-scoped-list pattern this change's dossier view
  follows. `grep -n GeneratedDocument src/manifest.json` shows it only on the standalone `GeneratedDocuments`
  index/detail pages — no widget on `EmployeeDetail`.
- `lib/Standards/Checks/NlPayrollChecks.php` — `nl-id-bewaarplicht-5jaar` on `Employee`: `identityDocumentVerified
  === true && present(identityDocumentRetainedUntil) && retainedAtLeastYearsAfterEnd(o,
  'identityDocumentRetainedUntil', 5)`. `retainedAtLeastYearsAfterEnd(array $o, string $key, int $years): bool`
  (private, same class, line ~414): `strtotime($o[$key]) >= mktime(0,0,0,12,31, endYear + $years)` where `endYear`
  is derived from `Employee.endDate` (still-employed → vacuously true, "retention clock has not started"). This
  change's new sibling rule calls this exact helper against a second field — no new date-math is written.
- `lib/Settings/register.d/hr-objects.json` — `Employee.loonheffingenVerklaringOnFile` (boolean, presence only,
  checked at onboarding by `NlOnboardingChecks`) carries **no** retention-deadline field.
- `lib/Settings/register.d/hr-ats.json` — `Application.retentionExpiryDate`/`talentPoolOptIn` +
  `lib/Standards/Checks/NlAtsChecks.php`'s `nl-ats-retentie-derivatie`/`nl-ats-retentie-verlopen` — sollicitatie-
  dossier retention (4 weeks / 1 year with consent, AP sollicitatie-richtlijn) is **fully shipped**. Not touched.
- `lib/Service/AvgDsrRetentionClassifier.php` — `classifyOne()` calls `populatedRetentionDate($object) ??
  derivedRetentionDate($object, $schema)`. **Critically**, `populatedRetentionDate()` (lines 162-178) reads
  `$object['retainedUntil']`/`$object['identityDocumentRetainedUntil']` **unconditionally, for ANY schema** — it
  does not gate on `PAYROLL_FAMILY_SCHEMAS`. Only `derivedRetentionDate()` (the AWR-formula fallback, used when
  no field is populated) is family-gated. This means: **a schema outside the named family that has a populated
  `retainedUntil` value is already classified correctly by the shipped classifier, with zero code change** — the
  gap is that `GeneratedDocument` has no `retainedUntil` field to populate, not that the classifier ignores it.
  This directly changes this change's shape from "extend the classifier" to "add and populate a field the
  classifier already reads" (D3).
- `lib/Standards/Checks/NlWageTaxFilingChecks.php::retainedYearsAfterPeriod()` and `AvgDsrRetentionClassifier
  ::derivedRetentionDate()` both independently implement "31 December of (period year + 7)" — `avg-dsr`'s own
  design.md D4 documents this as a deliberate replication (the formula lives on a private method of a different
  `CheckProvider`, not reusable across classes). This change's `HrDocumentService` computation (D3) is a third,
  equally deliberate replication of the identical formula, following that established precedent rather than
  inventing a fourth shape.

## Goals / Non-Goals

**Goals:** give HR a dossier view; close the loonbelastingverklaring retention gap with the exact sibling of the
shipped identity-document rule; make a generated loonstrook/jaaropgaaf PDF visible to the AVG-DSR retention guard
it currently evades; flag a record still present past its own retention ceiling.

**Non-Goals (binding, from the proposal):** `dossier-document`/`document-category`/`retention-policy`/
`acl-grant`/`signature-request`/`destruction-certificate` schemas, a bespoke ACL layer, eIDAS e-signing, an
automated destruction job, faceted dossier search. `GeneratedDocument.retainedUntil` is populated ONLY for
`loonstrook`/`jaaropgaaf` — the four letter types (`arbeidsovereenkomst`/`aanbiedingsbrief`/
`werkgeversverklaring`/`getuigschrift`) get no retention signal in this MVP: no single statutory bewaartermijn
for the employment-contract document itself (distinct from the loonadministratie's already-enforced 7-year AWR
duty) was found with confidence during this pass — see Sourcing below. Left `retainedUntil: null`, a named scope
boundary, not a silent gap.

## Decisions

### D1 — The dossier view is a pure manifest addition, no backend change

`EmployeeDetail` gains one `object-list` widget: `filter: {employeeId: "@objectId"}`, `register: hrmq, schema:
GeneratedDocument`, `sort: {field: generatedAt, dir: desc}`, columns `documentType`/`status`/`generatedAt`,
`rowRoute: GeneratedDocumentDetail` — the identical shape as the page's existing `Contracts`/`Payslips`/
`Expenses` widgets. Placed as a further full-width row (the page's own established growth pattern per its `_note`
history), after the existing `Verlof kopen/verkopen` row and before the `Personnel file` Files/integration leaf,
since a generated document is a register object, not a raw Nextcloud file — the same distinction
`GeneratedDocumentDetail`'s own `_note` already draws (`hiddenTabs` keeps its Files tab for the ONE stored PDF on
THAT object; this new widget lists the CATALOGUE of such objects for one employee, a different concern).

### D2 — Loonbelastingverklaring retention: the exact sibling of nl-id-bewaarplicht-5jaar

`Employee.loonheffingenVerklaringRetainedUntil` (nullable date) mirrors `identityDocumentRetainedUntil`'s shape
exactly — HR-entered, trusted input, same as the identity-document field. `NlPayrollChecks::checks()['Employee']`
gains:

```
'nl-loonbelastingverklaring-bewaarplicht-5jaar' => static fn(array $o): bool =>
    (($o['loonheffingenVerklaringOnFile'] ?? false) === true)
    ? (self::present($o, 'loonheffingenVerklaringRetainedUntil')
       && self::retainedAtLeastYearsAfterEnd($o, 'loonheffingenVerklaringRetainedUntil', 5))
    : true,
```

Vacuous when `loonheffingenVerklaringOnFile` is not `true` (the existing onboarding presence check governs that
case; a statement that was never on file has nothing to retain). `retainedAtLeastYearsAfterEnd()` is the existing
private helper, called with a second field name and the same `years: 5` — zero new date logic.

**Sourcing.** Confirmed via Uitvoeringsregeling loonbelasting 2011 art. 12.1 lid 5: the inhoudingsplichtige must
retain the loonbelastingverklaring (or the "gegevens voor de loonheffingen") for at least 5 full calendar years
after the end of the calendar year in which the dienstverband ended — the Belastingdienst Handboek Loonheffingen
states the identical period for the ID-document copy and the loonbelastingverklaring in the same bewaarplicht
section (the shipped `nl-id-bewaarplicht-5jaar`'s own source). Corpus rule entry
(`lib/Standards/rules/payroll.json`): `source: "Uitvoeringsregeling loonbelasting 2011 art. 12.1 lid 5 /
Belastingdienst Handboek Loonheffingen — bewaarplicht loonbelastingverklaring"`, `severity: recommended`,
`verified: true`. This is the ONE retention period this change adds with full confidence — see D4 for the one it
does not.

### D3 — SUPERSEDED by hrmq#99 (2026-07-18): GeneratedDocument inherits a real legal hold, not a retainedUntil field

**This decision as originally written below is superseded and NOT implemented as described.** hrmq#99 (a
consume-not-rebuild correction, landed 2026-07-18) deleted `AvgDsrRetentionClassifier` outright — it duplicated
OpenRegister's own retention/legal-hold machinery — and switched `AvgDsrService` to consume OpenRegister's
guarded `Gdpr\DataSubjectRequestService::erase()` directly. That guarded service refuses an object under an
active legal hold or an immutable archival status; it does **not** read a `retainedUntil` field at all. Adding
`GeneratedDocument.retainedUntil` as originally planned below would therefore have done NOTHING to protect the
object under the new (correct) architecture — a second reason, beyond "don't duplicate OpenRegister's ceiling
math", to not build it.

The gap this decision names (a generated `loonstrook`/`jaaropgaaf` PDF evading the guard its retained source
carries) is closed instead by `PayrollRetentionGuardService::inheritLegalHold()`, consumed by
`HrDocumentService::generateLoonstrook()`/`generateJaaropgaaf()`: when the source `Payslip` (or, for jaaropgaaf,
any Payslip the aggregate covers) is currently under active OpenRegister retention
(`PayrollRetentionGuardService::isUnderActiveRetention()` — an active legal hold, an immutable archival status,
or a still-open ceiling from a populated `retainedUntil` field or OpenRegister's own computed
`retention.archiefactiedatum`), the SAME legal hold is placed on the `GeneratedDocument` object. This is a real
OpenRegister write (a legal hold persisted on the object), not a classifier-readable date field — see
`lib/Service/PayrollRetentionGuardService.php` and `openspec/specs/avg-dsr/spec.md` REQ-DSR-005 for the shipped
design. **Not part of this change's diff** — already landed.

<details>
<summary>Original (superseded) D3 text, kept for history</summary>

Re-reading `AvgDsrRetentionClassifier::populatedRetentionDate()` (Context) shows it already reads
`$object['retainedUntil']` for **any** schema, unconditionally — the classifier does not need to learn about
`GeneratedDocument`; `GeneratedDocument` needs a `retainedUntil` field to populate. This is a materially smaller,
safer change than the proposal's first-draft framing ("extend the classifier's family list") — named here so a
future reader does not assume the classifier itself changed.

`lib/Settings/register.d/hr-documents.json`: `GeneratedDocument.retainedUntil` (nullable date, same shape as
`Payslip.retainedUntil`). `HrDocumentService`:

- **`generateLoonstrook()`** (already resolves the full `Payslip` via `findPayslip()`, Context): before persisting
  the new `GeneratedDocument`, sets `retainedUntil` to the resolved `Payslip.retainedUntil` when populated, else
  derives it with the identical "31 December of (period year + 7)" formula applied to `Payslip.period` (the third
  deliberate replication, Context) — never left null when the underlying Payslip has a derivable period.
- **`generateJaaropgaaf()`** (already has `$year` as a local parameter): sets `retainedUntil` to 31 December of
  `($year + 7)` directly — no resolve needed, `Jaaropgaaf.year` is authoritative.
- The four letter types: `retainedUntil` stays `null` (D — Non-Goals; no sourced period).

**Effect, not a classifier code change:** once populated, `AvgDsrService::classifyForErasure()` — unchanged —
now correctly retention-locks a `loonstrook`/`jaaropgaaf` `GeneratedDocument` whenever its underlying payroll
period is still within the AWR window, closing the gap the proposal names (a DSR erase could previously delete
the PDF record while the classifier correctly protected the underlying `Payslip`).

</details>

### D4 — A storage-limitation ceiling check, complementary to (not a duplicate of) the floor checks

**Narrowed by hrmq#99 (2026-07-18).** `lib/Standards/Checks/NlDossierRetentionChecks.php` already exists,
shipped by hrmq#99, contributing `nl-bewaartermijn-verstreken` for the payroll/loonadministratie family +
`GeneratedDocument`, reading OpenRegister's own `retention.archiefactiedatum` (never a bespoke field). This
change does NOT create a new file: it adds an `Employee` entry to that SAME provider's `checks()` array, under
the SAME rule id — a different object-type key, so no collision with hrmq#99's registration — covering the two
fields D2 concerns.

`nl-id-bewaarplicht-5jaar` and D2's new sibling check that a retention field is populated FAR ENOUGH ahead (a
floor — "you have not under-retained"). Nothing checked the opposite direction until hrmq#99 shipped
`nl-bewaartermijn-verstreken` for the payroll/loonadministratie family + `GeneratedDocument`, reading
OpenRegister's own `retention.archiefactiedatum` (never a bespoke field). **This change adds one more entry to
that SAME, already-shipped `lib/Standards/Checks/NlDossierRetentionChecks.php` provider** (not a new file):

| Schema | Fields checked | Violates when | Shipped by |
|---|---|---|---|
| `Employee` (this change) | `identityDocumentRetainedUntil`, `loonheffingenVerklaringRetainedUntil` | either populated field's date is before today | this change |
| `Payslip`/`PayrollRun`/`LoonaangifteFiling`/`PayrollMutationReport`/`WkrDeclaration`/`WkrAssessment`/`PensionFiling`/`GeneratedDocument` | `retention.archiefactiedatum` (OpenRegister-computed) | populated, before today, and `archiefstatus` is not `vernietigd` | hrmq#99, already shipped |

Vacuous whenever the relevant field is unpopulated (nothing to check — the same posture every other check in
this corpus takes for an absent field). This is a **recommended-severity flag**, surfaced only through `occ
hrmq:rules:audit` (the existing, only audit surface) — it does not delete, anonymise, or destroy anything (the
proposal's explicit Non-Goal: no automated destruction job). An HR admin acts on the flag manually, exactly the
posture `nl-administratie-scope-consistency` and `nl-single-person-mode-employee-count` (a sibling change) both
already take for a soft compliance signal.

**Why not reuse a classifier-style method for this check:** `RuleAuditService`'s auditing loop calls
`CheckProvider::checks()` closures per-object; a DSR-request-scoped classification method is a different calling
convention. Re-deriving the two-line "is this populated date in the past" comparison directly in
`NlDossierRetentionChecks` is the smaller, more honest reuse — the *field* it reads
(`identityDocumentRetainedUntil`/`loonheffingenVerklaringRetainedUntil`) is shared vocabulary with
`nl-id-bewaarplicht-5jaar`/D2's sibling rule, not shared code, and that is enough: both readers agree on what the
field means because those checks are the ones that populate/validate it.

### Declarative vs imperative (ADR-031)

| Behaviour | Path | Rationale |
|---|---|---|
| `EmployeeDetail` dossier list (D1) | declarative manifest widget | ordinary FK-scoped object-list, no computation |
| `Employee.loonheffingenVerklaringRetainedUntil` field (D2) | declarative schema | HR-entered trusted input, same as its sibling |
| `nl-loonbelastingverklaring-bewaarplicht-5jaar` (D2) | imperative pure predicate, reused helper | one new corpus-rule registration calling an existing helper |
| `nl-bewaartermijn-verstreken` `Employee` entry (D4) | imperative pure predicate, extends an existing auto-discovered `CheckProvider` | reads existing fields; the `nl-administratie-scope-consistency` posture applied to a new direction (ceiling, not floor) |

## Seed Data (ADR-001)

1. **Loonbelastingverklaring retention, compliant**: the anchor `employee-jansen` gains
   `loonheffingenVerklaringOnFile: true`, `loonheffingenVerklaringRetainedUntil` set far enough past `endDate`
   (still-employed → vacuously satisfied per `retainedAtLeastYearsAfterEnd()`'s own "clock has not started" rule,
   mirroring the existing `identityDocumentRetainedUntil` seed value shape) — `occ hrmq:rules:audit` reports zero
   `nl-loonbelastingverklaring-bewaarplicht-5jaar` violations for this employee.
2. **Loonbelastingverklaring retention, violated**: a second seeded `Employee` with `loonheffingenVerklaringOnFile:
   true`, `endDate` set in the past, and `loonheffingenVerklaringRetainedUntil` left null — the dev-container
   verification gate: `occ hrmq:rules:audit` reports exactly one `nl-loonbelastingverklaring-bewaarplicht-5jaar`
   violation for this employee, and (since the field is unpopulated) zero `nl-bewaartermijn-verstreken` violations
   for the same field on the same record (D4 is vacuous on an unpopulated field — the two checks report distinct
   facts, never overlapping on the same cause).
3. **Superseded — already verified live by hrmq#99, not this change's seed diff**: a loonstrook
   `GeneratedDocument` generated for a legal-hold-protected Payslip inherits the SAME hold
   (`PayrollRetentionGuardService::inheritLegalHold()`); `AvgDsrService::previewErasure()` lists it in `retained`,
   not `wouldErase`. See hrmq#99's live-verification evidence, not a seed fixture here.
4. **Storage-limitation ceiling, violated (Employee, this change's own seed addition)**: a NEW seeded `Employee`
   with `identityDocumentRetainedUntil` set to a date in the past and still on file — `occ hrmq:rules:audit`
   reports exactly one `nl-bewaartermijn-verstreken` violation naming it (the `Employee`-scoped entry this change
   adds to `NlDossierRetentionChecks`). The `GeneratedDocument`/payroll-family ceiling case is already covered by
   hrmq#99's own seed/tests, not duplicated here.

## Risks / Trade-offs

- **D3 is superseded (see above) — its original risk (a retainedUntil copy going stale after a Payslip
  correction) no longer applies.** The hrmq#99 replacement (`PayrollRetentionGuardService::inheritLegalHold()`)
  has its own, different trade-off instead: it is called once, at generation time; if a Payslip's retention state
  changes AFTER its loonstrook was generated, the `GeneratedDocument`'s inherited hold does not automatically
  re-sync. Tracked in `openspec/specs/avg-dsr/spec.md`, not re-litigated here.
- **`nl-bewaartermijn-verstreken` is a flag, never an action** (the proposal's explicit Non-Goal on automated
  destruction) — a flagged document/record does not get destroyed by this change (or by hrmq#99); an HR admin
  must act on the audit report manually, same posture as every other recommended-severity check in this corpus
  today.
- **No retention signal for the four letter-type documents (D — Non-Goals)** — arbeidsovereenkomst/
  aanbiedingsbrief/werkgeversverklaring/getuigschrift carry no retention signal (no `retainedUntil`, no `archive`
  schema config), so `nl-bewaartermijn-verstreken`'s `GeneratedDocument` entry (hrmq#99) never fires for them.
  This is intentional (Sourcing), not an oversight, but it does mean the dossier view (D1) will show these
  documents with no visible retention status — a plainly named limitation, not hidden inside the widget.

## Open Questions

- **Statutory retention for the employment-contract document itself** (arbeidsovereenkomst and its sibling letter
  types) was NOT confirmed with the same confidence as D2's loonbelastingverklaring citation during this pass.
  Practitioner HR-advisory sources commonly suggest "duration of employment + 2 years" (the general BW art. 3:307
  civil-claim limitation period), but this is guidance, not a codified bewaartermijn parallel to AWR art. 52 lid 4
  or Uitvoeringsregeling loonbelasting 2011 art. 12.1 lid 5. **Marked `verified: false`, `checkAgainst:
  "Rijksoverheid/Belastingdienst arbeidsrecht guidance — confirm before adding a retention field or check for the
  four letter-type GeneratedDocuments"** — not implemented in this change (D — Non-Goals) precisely because it
  could not be confirmed to the bar this codebase's other sourced rules meet. A future change may add it once
  sourced; inventing a number here to look complete would be the compliance harm the brief warned against.
