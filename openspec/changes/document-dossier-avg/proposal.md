---
kind: code+config
---

# document-dossier-avg — the personnel-dossier gaps left after subtracting three shipped capabilities

> **Revised 2026-07-18 (hrmq#99 consume-not-rebuild correction).** This proposal's item 3
> (`GeneratedDocument.retainedUntil`, a new bespoke field so `AvgDsrRetentionClassifier` would pick
> it up) and item 4's ORIGINAL framing (a new standalone check file) are SUPERSEDED by hrmq#99, which
> landed 2026-07-18: `AvgDsrRetentionClassifier` is deleted outright (it duplicated OpenRegister's own
> retention/legal-hold machinery), `AvgDsrService` now consumes OpenRegister's guarded
> `Gdpr\DataSubjectRequestService::erase()` directly, and the "generated PDF evades the guard its
> retained source carries" gap is closed via `PayrollRetentionGuardService::inheritLegalHold()`
> (consumed by `HrDocumentService::generateLoonstrook()`/`generateJaaropgaaf()`) — a real
> OpenRegister legal hold on the `GeneratedDocument` object, never a `retainedUntil` field. hrmq#99
> also already ships `lib/Standards/Checks/NlDossierRetentionChecks.php`'s
> `nl-bewaartermijn-verstreken` reading OpenRegister's own `retention.archiefactiedatum`, scoped to
> the payroll/loonadministratie family + `GeneratedDocument`. **This change's remaining scope is
> items 1 and 2 only** (the `EmployeeDetail` dossier list, and the loonbelastingverklaring retention
> sibling rule on `Employee`) **plus a narrowed item 4** (the SAME `nl-bewaartermijn-verstreken` rule
> id, extended to cover `Employee.identityDocumentRetainedUntil`/`loonheffingenVerklaringRetainedUntil`
> — a different object type, so no collision with hrmq#99's provider). See the revised design.md D3/D4
> and tasks.md for what is now DONE (by hrmq#99) vs. still open.

## Why

**Verified against HEAD 2026-07-17.** The May-2026 draft (`spec/document-dossier-avg`) proposed six new
OpenRegister schemas (`dossier-document`, `document-category`, `retention-policy`, `acl-grant`,
`signature-request`, `destruction-certificate`), a bespoke ACL layer, an eIDAS e-signature workflow, a nightly
destruction job, and a faceted search surface. Re-grounded against current shipped code, most of that premise no
longer holds:

- **Document generation + storage is DONE.** `openspec/specs/humaniq-docudesk-documents/spec.md` ships
  `GeneratedDocument` (`lib/Settings/register.d/hr-documents.json`) — one record per rendered
  arbeidsovereenkomst/aanbiedingsbrief/werkgeversverklaring/getuigschrift/loonstrook/jaaropgaaf, the PDF stored via
  OpenRegister's `FileService` on the object's own folder, idempotency keys, an audit trail (the platform's
  standard `audit-trail` sidebar widget every OpenRegister object already carries), and RBAC via the object's own
  register/schema access — the draft's `acl-grant`/`document-category` schemas duplicate mechanisms OpenRegister
  already provides generically. No new ACL layer, no e-signature workflow, and no bespoke document catalogue are
  respec'd here.
- **AVG data-subject rights (export/erase/rectify) is DONE**, including a real retention guard.
  `openspec/specs/avg-dsr/spec.md` ships `AvgDsrService`/`AvgDsrRetentionClassifier`
  (`lib/Service/AvgDsrRetentionClassifier.php`): every object `DsarService::findObjectsForSubject()` returns is
  classified retention-locked or erase-eligible, reading a populated `retainedUntil`/`identityDocumentRetainedUntil`
  first and falling back to the existing AWR art. 52 lid 4 seven-year derivation
  (`NlWageTaxFilingChecks::retainedYearsAfterPeriod()`) for the named payroll/loonadministratie schema family
  (`Payslip`, `PayrollRun`, `LoonaangifteFiling`, `PayrollMutationReport`, `WkrDeclaration`, `WkrAssessment`). A
  retention-locked object is never passed into a wholesale erase. **This engine is not respec'd here.**
- **Two of the draft's three named retention examples are ALREADY machine-checked, sourced, and shipped.**
  `lib/Standards/Checks/NlPayrollChecks.php`'s `nl-id-bewaarplicht-5jaar` (Handboek Loonheffingen) already flags
  an `Employee` whose `identityDocumentRetainedUntil` is not populated at least 5 full calendar years after
  employment end. `lib/Settings/register.d/hr-ats.json`'s `Application.retentionExpiryDate`/`talentPoolOptIn`
  plus `NlAtsChecks.php`'s `nl-ats-retentie-derivatie`/`nl-ats-retentie-verlopen` already derive and enforce
  exactly the sollicitatiedossier period the task brief named as an example: `rejectedDate` + 4 weeks, or +1 year
  with explicit consent (Autoriteit Persoonsgegevens sollicitatie-richtlijn, AVG art. 5 lid 1 sub e). **Neither is
  respec'd here.**

**What is left, after subtracting all of the above, is genuinely narrow:**

1. **No dossier VIEW exists.** `GeneratedDocument` has no `employeeId`-scoped list anywhere on `EmployeeDetail`
   (`grep -n GeneratedDocument src/manifest.json` shows it only as a standalone global index page) — an HR admin
   who wants "every document humaniq generated for this one person" must leave the personnel record and hand-filter
   a separate list. Every sibling record type (Contracts, Payslips, Expenses, Timesheets…) already gets this
   FK-scoped treatment on `EmployeeDetail`; `GeneratedDocument` does not.
2. **The third named example — loonbelastingverklaring — has presence tracking but no retention tracking.**
   `Employee.loonheffingenVerklaringOnFile` (boolean) is checked for *presence* at onboarding
   (`NlOnboardingChecks`), but no field or check verifies it is retained long enough. Retention obligation
   confirmed against Uitvoeringsregeling loonbelasting 2011 art. 12.1 lid 5 (via Belastingdienst Handboek
   Loonheffingen): the wage-tax declaration must be retained at least 5 full calendar years after the end of the
   calendar year employment ended — the exact sibling rule to the shipped `nl-id-bewaarplicht-5jaar`, using the
   exact same `retainedAtLeastYearsAfterEnd()` helper, just unbuilt for this second field.
3. **A `GeneratedDocument` of type `loonstrook`/`jaaropgaaf` carries no retention signal at all, so it is
   erase-eligible by default.** Re-reading `AvgDsrRetentionClassifier::populatedRetentionDate()`
   (`lib/Service/AvgDsrRetentionClassifier.php`) shows it already reads a `retainedUntil` field generically, for
   ANY schema — it does not gate on its family list. The gap is not in the classifier; it is that
   `GeneratedDocument` has no `retainedUntil` field to populate. A DSR erase request today could wholesale-erase a
   `loonstrook` PDF whose underlying `Payslip` is correctly retention-locked, purely because the PDF record itself
   carries nothing the (already-correct) classifier can read. This is a real, narrow gap, not a hypothetical.
4. **No check flags a record still present past its OWN retention ceiling.** `nl-id-bewaarplicht-5jaar` (and this
   change's new sibling rule) check that a `retainedUntil`-style field is populated FAR ENOUGH in the future — a
   floor (AVG storage-limitation minimum has been honoured). Nothing in this codebase checks the opposite
   direction: is TODAY already past a populated `retainedUntil` date while the record is still present and
   un-anonymised — the AVG art. 5 lid 1 sub e storage-limitation ceiling. `RuleAuditService`'s corpus has no such
   rule for any schema.

## What Changes

- **NEW `EmployeeDetail` dossier list**: a `GeneratedDocument` FK-scoped `object-list` widget on `EmployeeDetail`
  (`filter: {employeeId: "@objectId"}`, the exact `Contracts`/`Payslips` precedent already on that page) — pure
  manifest config, zero backend change.
- **NEW `Employee.loonheffingenVerklaringRetainedUntil`** (nullable date, mirrors `identityDocumentRetainedUntil`'s
  exact shape) + **NEW `nl-loonbelastingverklaring-bewaarplicht-5jaar`** check (`NlPayrollChecks`, reusing the
  existing `retainedAtLeastYearsAfterEnd()` helper against the new field, `years: 5`) — the sibling of
  `nl-id-bewaarplicht-5jaar`, sourced to Uitvoeringsregeling loonbelasting 2011 art. 12.1 lid 5.
- **DONE by hrmq#99, not this change's diff**: a generated `loonstrook`/`jaaropgaaf` `GeneratedDocument` now
  inherits a real OpenRegister legal hold from its source `Payslip` (`PayrollRetentionGuardService
  ::inheritLegalHold()`, consumed by `HrDocumentService::generateLoonstrook()`/`generateJaaropgaaf()`) whenever
  that source is currently under active retention — never a `GeneratedDocument.retainedUntil` field. This closes
  the exact gap this proposal originally named (item 3), via OpenRegister's own retention primitive instead of a
  bespoke classifier-readable field.
- **DONE by hrmq#99, not this change's diff, for the payroll/loonadministratie family + GeneratedDocument**:
  `lib/Standards/Checks/NlDossierRetentionChecks.php` (auto-discovered, recommended severity) ships
  `nl-bewaartermijn-verstreken`, reading OpenRegister's own `retention.archiefactiedatum` — never a bespoke field
  — for `Payslip`/`PayrollRun`/`LoonaangifteFiling`/`PayrollMutationReport`/`WkrDeclaration`/`WkrAssessment`/
  `PensionFiling`/`GeneratedDocument`. **This change's remaining, narrower item 4**: extend the SAME rule id
  (a different object-type key, `Employee` — no collision with hrmq#99's provider) to also cover
  `Employee.identityDocumentRetainedUntil`/`loonheffingenVerklaringRetainedUntil`, the two fields this change's
  item 2 concerns. Implemented as an addition alongside item 2's `NlPayrollChecks` work, not a second standalone
  check file.

### Non-goals (named exclusions, not deferred follow-ups)

`dossier-document`/`document-category`/`retention-policy`/`acl-grant`/`signature-request`/
`destruction-certificate` schemas, a bespoke ACL layer, eIDAS e-signature, a nightly automated destruction job,
and faceted dossier search are explicitly out of scope: `GeneratedDocument` + OpenRegister's generic RBAC/audit
already cover the catalogue-and-access need; a destruction job that hard-deletes on a timer with no human
confirmation is a materially different (and materially riskier) capability than a recommended-severity flag an
HR admin acts on — this change surfaces the "past retention" fact, it does not act on it unattended. Standing up
a destruction pipeline is a legitimately separate, larger proposal if ever wanted, not a silent scope-narrowing
here.

## Capabilities

### New Capabilities

- `document-dossier-avg`: the `EmployeeDetail` dossier list and the loonbelastingverklaring retention
  field+check (items 1-2). Items 3-4 as originally proposed are superseded by hrmq#99 (see above); item 4's
  narrower Employee-scoped remainder ships alongside item 2.

### Modified Capabilities

- `humaniq-docudesk-documents`: `EmployeeDetail` gains a `GeneratedDocument` FK-scoped list; no schema/service change.
- `avg-dsr`: superseded (not by this change) by hrmq#99 — `AvgDsrRetentionClassifier` is deleted;
  `AvgDsrService` now consumes OpenRegister's guarded `Gdpr\DataSubjectRequestService` directly. Not touched by
  this change's own diff.
- `dga-payroll-mode`/payroll checks: `NlPayrollChecks` gains one sibling rule on `Employee`; the shipped
  `nl-id-bewaarplicht-5jaar` predicate and its field are unchanged.

## Impact

- `src/manifest.json` — `EmployeeDetail` gains one `object-list` widget. `npm run check:manifest` passes.
- `lib/Settings/register.d/hr-objects.json` — `Employee.loonheffingenVerklaringRetainedUntil` (nullable date).
- `lib/Standards/Checks/NlPayrollChecks.php` — `nl-loonbelastingverklaring-bewaarplicht-5jaar`.
- `lib/Standards/rules/payroll.json` — the new rule's corpus entry (recommended severity, source citation).
- `lib/Standards/Checks/NlDossierRetentionChecks.php` — EXTEND (shipped by hrmq#99, NOT new in this change's
  diff): add an `Employee` entry to `checks()` for the same `nl-bewaartermijn-verstreken` rule id, covering
  `identityDocumentRetainedUntil`/`loonheffingenVerklaringRetainedUntil`.
- `tests/Unit/Standards/Checks/NlPayrollChecksTest.php`,
  `tests/Unit/Standards/Checks/NlDossierRetentionChecksTest.php` — NEW/extended coverage for the two items above.
- `README.md` — the remaining two-item delta, a note on what hrmq#99 already closed, and the explicit non-goals
  list (so the destruction-job/e-sign/ACL scope boundary is not silently rediscovered as a gap).
- **No longer in this change's diff** (hrmq#99 already shipped it): `lib/Settings/register.d/hr-documents.json`
  `GeneratedDocument.retainedUntil` field, and any `HrDocumentService` retention-population code — see
  `PayrollRetentionGuardService`/`HrDocumentService` instead.
