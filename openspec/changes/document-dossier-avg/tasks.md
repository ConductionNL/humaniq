# Tasks — document-dossier-avg

> Verify against HEAD, not this brief — `hrmq-docudesk-documents`, `avg-dsr`, `dga-payroll-mode`, and
> `nl-id-bewaarplicht-5jaar` are already merged at HEAD; this change composes/extends them, it does not depend on
> any pending change.

- [ ] 1. Manifest: `EmployeeDetail` gains a `GeneratedDocument` `object-list` widget (`filter: {employeeId:
  "@objectId"}`, sort `generatedAt` desc, `rowRoute: GeneratedDocumentDetail`) per REQ-DOSS-001; `npm run
  check:manifest` passes
- [ ] 2. Schema: `lib/Settings/register.d/hr-objects.json` — `Employee.loonheffingenVerklaringRetainedUntil`
  (nullable date, mirrors `identityDocumentRetainedUntil`) per REQ-DOSS-002
- [ ] 3. Standards: `NlPayrollChecks::checks()['Employee']` — `nl-loonbelastingverklaring-bewaarplicht-5jaar`,
  reusing the existing `retainedAtLeastYearsAfterEnd()` helper per REQ-DOSS-002
- [ ] 4. Standards: `lib/Standards/rules/payroll.json` — corpus entry for the new rule, `severity: recommended`,
  source citation (Uitvoeringsregeling loonbelasting 2011 art. 12.1 lid 5) per REQ-DOSS-002
- [ ] 5. Schema: `lib/Settings/register.d/hr-documents.json` — `GeneratedDocument.retainedUntil` (nullable date)
  per REQ-DOSS-003
- [ ] 6. Service: `HrDocumentService::generateLoonstrook()` — populate `retainedUntil` from the resolved
  `Payslip.retainedUntil`, or derive it from `Payslip.period` (31 December of period-year + 7) when unpopulated,
  per REQ-DOSS-003
- [ ] 7. Service: `HrDocumentService::generateJaaropgaaf()` — populate `retainedUntil` as 31 December of ($year +
  7) directly from the local `$year` parameter per REQ-DOSS-003
- [ ] 8. Verify (no code change expected): `AvgDsrRetentionClassifier::classify()` correctly retention-locks a
  loonstrook/jaaropgaaf `GeneratedDocument` once `retainedUntil` is populated — confirm by tracing
  `populatedRetentionDate()`, not by assuming it from this brief, per REQ-DOSS-003
- [ ] 9. Standards: NEW `lib/Standards/Checks/NlDossierRetentionChecks.php` — `nl-bewaartermijn-verstreken` on
  `Employee` (`identityDocumentRetainedUntil`, `loonheffingenVerklaringRetainedUntil`) and `GeneratedDocument`
  (`retainedUntil`), auto-discovered, recommended severity per REQ-DOSS-004
- [ ] 10. Seed: `employee-jansen` gains `loonheffingenVerklaringRetainedUntil` (compliant); a NEW seeded
  `Employee` with the field unpopulated and `endDate` in the past (violated); a NEW seeded loonstrook
  `GeneratedDocument` with a computed `retainedUntil` referencing the anchor's existing Payslip; a NEW seeded
  `GeneratedDocument` with `retainedUntil` in the past and `status: generated` (ceiling violation) per design.md
  Seed Data
- [ ] 11. Tests: `NlPayrollChecksTest` — `nl-loonbelastingverklaring-bewaarplicht-5jaar` vacuous/satisfied/
  violated cases per REQ-DOSS-002
- [ ] 12. Tests: `HrDocumentServiceTest` — `retainedUntil` populated correctly for loonstrook (populated-field
  and derived-fallback cases) and jaaropgaaf; `null` for the four letter types per REQ-DOSS-003
- [ ] 13. Tests: `NlDossierRetentionChecksTest` — vacuous on unpopulated fields, violated on a past date,
  satisfied on a future date, for both `Employee` fields and `GeneratedDocument.retainedUntil` per REQ-DOSS-004
- [ ] 14. README: the dossier list, the loonbelastingverklaring retention sibling rule, the
  `GeneratedDocument.retainedUntil` population point, the ceiling check, and the explicit non-goals (no ACL/
  e-sign/destruction-job schemas; no retention signal for the four letter-type documents, sourced or unsourced)
  per REQ-DOSS-001/-002/-003/-004
- [ ] 15. Quality gates: `composer check:strict` ALL CHECKS PASSED; `npm run check:manifest` PASS (0 errors);
  `npm run build` green

Acceptance criteria (plain reminders, not tasks):
- no new schema (`dossier-document`/`document-category`/`retention-policy`/`acl-grant`/`signature-request`/
  `destruction-certificate`) exists anywhere in the diff — verify by grepping `lib/Settings/register.d/*.json`
  after implementation; their absence is intentional
- `AvgDsrRetentionClassifier.php`'s own source is byte-identical before and after this change (design.md D3) —
  verify with a diff, not by assuming it from this brief
- every new/changed retention period cites a specific legal source (Uitvoeringsregeling loonbelasting 2011 art.
  12.1 lid 5) in the corpus rule's `source` field — no new period is added without one
- the four letter-type `GeneratedDocument`s keep `retainedUntil: null` — verify no code path sets it for
  `arbeidsovereenkomst`/`aanbiedingsbrief`/`werkgeversverklaring`/`getuigschrift`
- SPDX + `@spec` tags on every new/changed PHP method (gate-16); i18n keys ENGLISH (ADR-007)
