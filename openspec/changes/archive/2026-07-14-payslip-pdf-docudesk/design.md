# Design — payslip-pdf-docudesk

## Context

**What already exists (verified against development HEAD, 2026-07-13):**

- **The docudesk leaf is live** (`hrmq-docudesk-documents`, archived 2026-07-13): `HrDocumentService` (796 lines) implements the full pipeline — duck-typed probe (`IAppManager::isInstalled('docudesk')` + guarded container resolve of the `DocumentService`/`TemplateService` FQCNs), config-first/discovery-second/fail-closed template selection in `namespace: hrmq`, `generateDocument(templateId, dataRefs, options)` same-instance, binary stored via OpenRegister `FileService::addFile()` on the `GeneratedDocument` object, `skipped-no-docudesk` degradation, and the at-most-one-active idempotency pre-check. The verified docudesk contract: `dataRefs = [{register, schema, id}, ...]` and docudesk's `DataResolverService` keys each resolved object **by the schema name as passed** — a ref `{register: hrmq, schema: Payslip, id}` surfaces in the template as `Payslip.grossPay`; `options.adHocData` merges on top (the `employer.*` block from `SettingsService::getDocumentsEmployerBlock()` — currently name/address/kvkNumber).
- **`Payslip` is the loonstrook data record** (hr-objects.json v0.2.0): `employeeId` ($ref), `period` (YYYY-MM), `grossPay`, `nettoPay`, `loonheffing`, `zvw` + `zvwMode` (`werkgeversheffing`|`inhouding`), `pensionContribution`, `vakantiegeldReserved`, `statementProvided`, `userId` (@me self-service) and the DE/FR/US jurisdiction fields. There is **no** arbeidskorting, reiskosten, or cumulatieven field. Seeded: exactly one payslip, `payslip-jansen-2026-05` (gross 3800.00, loonheffing 1102.00, netto 2698.00).
- **The rule corpus already states the loonstrook obligation**: `nl-loonstrook-verstrekken` (payroll.json, BW 7:626, `machineCheckable: false`) and `nl-loonstrook-inhoud` (checkable, reads the `shows*` booleans). `nl-jaaropgaaf` (machineCheckable: false) states the annual-statement obligation. `RuleCatalogue::VERSION = '2026-07.5'`. `RuleAuditService::audit()` already builds a `documents` context (`generatedArbeidsovereenkomstByContract`) and `NlDocumentChecks` already exists (EmploymentContract-keyed).
- **Manifest**: `PayrollGroup` ("Loonadministratie", order 120) holds Payslips/PayrollRuns/LoonaangifteFilings/PensionFilings/PayrollGLPosts/PayrollPaymentBatches. `PayslipDetail` has NO `actions`; `EmploymentContractDetail` shows the exact api-call action shape to mirror. `GeneratedDocumentDetail` uses `hiddenTabs` to keep the Files tab and excludes `employeeId`/`contractId` from `gd-data` (Related resolves them).
- **`DocumentController::generate()`** takes `(string $contractId, string $documentType='arbeidsovereenkomst')` behind `#[NoAdminRequired]` with the `authorizeContract()` RBAC-scoped resolve; one POST route exists before the SPA catch-all.

**The superseded draft** (`origin/spec/payslip-generation`): Dompdf + Twig inside hrmq, own `Loonstrook`/`Jaaropgaaf` schemas with publish lifecycles, a post-SalarisRun QueuedJob, and a werknemer portal, `depends_on: payroll-core-basic`. Routed away — rendering goes through docudesk (the `hrmq-docudesk-documents` D1 division of authority), the data record already exists as `Payslip`, and the portal shipped as `mijn-hr-self-service`. What survives from the draft is the jaaropgaaf field *intent* (annual totals + loonheffingennummer), reconciled below against what `Payslip` actually carries.

**Market**: Spectr canons `hrmq-canon-payslip-pdf` (7/9 competitors) and `hrmq-canon-jaaropgaaf` (4/9).

## Goals / Non-Goals

**Goals:** a downloadable loonstrook PDF per Payslip and a jaaropgaaf PDF per employee-year, both through the existing docudesk pipe (zero new rendering machinery); an honest `Jaaropgaaf` aggregate derived only from real Payslip fields; machine-checked document evidence for BW 7:626 (`nl-loonstrook-verplicht`); occ + PayslipDetail triggers; Jaaropgaven pages.

**Non-Goals:** template layout authoring (docudesk's), delivery/notification, payslip computation (no payroll engine — `verrekendeArbeidskorting` stays null rather than fabricated), YTD cumulatieven, bulk/background generation, a JaaropgaafDetail generate action (occ-only in MVP — see D6), publish lifecycles on documents.

## Decisions

### D1 — loonstrook and jaaropgaaf are two more documentTypes through the existing leaf, not a new service

No new service class. `HrDocumentService` gains the two types; everything the leaf already settled (probe, template selection, storage, degradation, statuses) applies unchanged. The only structural novelty is that a loonstrook's subject is a **Payslip** (not a contract) and a jaaropgaaf's subject is a **Jaaropgaaf** aggregate — carried as new nullable `$ref` fields on `GeneratedDocument` (`payslipId`, `jaaropgaafId`; in-register targets, so real `$ref`s per ADR-062 rule 7, unlike the cross-register `templateRef`). Enum extension is append-only → `GeneratedDocument` bumps 0.1.0 → 0.2.0, non-breaking.

### D2 — `Jaaropgaaf` lives in `hr-documents.json`, and it is a data record, not a render log

**Home**: `hr-documents.json`, not `hr-objects.json`. Precedent: `PayrollGLPost` lives in `hr-glpost.json` and `PayrollPaymentBatch` in `hr-paybatch.json` — a schema lives in the fragment of the leaf that **writes** it (ADR-037 fragments are feature-cohesive). `Jaaropgaaf` is created and updated exclusively by `HrDocumentService`'s aggregation step; putting it here keeps this change to a single fragment (enum bump + new schema + seeds) and spares `hr-objects.json` a version churn it has no behavioural stake in.

**Field reconciliation against the draft (honesty rule: only aggregates derivable from existing Payslip fields):**

| Draft field | Verdict | This change |
|---|---|---|
| `totaalBrutoLoon` | derivable | `totalGrossPay` = Σ `Payslip.grossPay` (the jaaropgaaf "loon" column) |
| `totaalLoonheffing` | derivable | `totalLoonheffing` = Σ `Payslip.loonheffing` |
| — (netto not on the draft's jaaropgaaf) | derivable, useful | `totalNettoPay` = Σ `Payslip.nettoPay` |
| `totaalZvwWerknemersaandeel` | derivable **with a mode filter** | `totalZvwWithheld` = Σ `Payslip.zvw` **where `zvwMode == 'inhouding'` only** — `werkgeversheffing` is an employer levy, never withheld from the employee, so summing it would fabricate a werknemersaandeel |
| `totaalPensioenpremie` | derivable | `totalPensionContribution` = Σ `Payslip.pensionContribution` |
| `totaalVakantiegeld` | approximated honestly | `totalVakantiegeldReserved` = Σ `Payslip.vakantiegeldReserved` (Payslip records the reservation, not the payout — named accordingly) |
| `totaalReiskosten` | **dropped** | no Payslip field carries reiskosten |
| `aantalLoonperioden` | derivable | `payPeriodCount` = count of aggregated payslips |
| (arbeidskorting / verrekende arbeidskorting — statutory jaaropgaaf line) | **not derivable** | `verrekendeArbeidskorting`: nullable, stays `null` until a payslip-level arbeidskorting exists (payroll-core follow-up); the template renders a dash — never a fabricated 0.00 presented as fact |
| `werkgeverLoonheffingsnummer` | config snapshot | `loonheffingennummer`: copied from the new `documents_employer_loonheffingennummer` config at aggregation time |
| `werkgeverNaam` | **dropped from the schema** | already in every render via `adHocData.employer.name` — one source of truth, no drift |
| `status` lifecycle | **dropped** | render state lives on `GeneratedDocument` (D1); `Jaaropgaaf` is pure data + `aggregatedAt` timestamp |

Required: `[employeeId, year, totalGrossPay, totalLoonheffing]`. `x-schema-org: schema:Report`, icon `FileCertificateOutline`, v0.1.0.

### D3 — dataRefs per documentType (schema-name-keyed, verified contract)

- **loonstrook**: `dataRefs = [{register: hrmq, schema: Employee, id}, {register: hrmq, schema: Payslip, id}]` → templates read `Employee.lastName`, `Payslip.grossPay`, `Payslip.period`, `employer.*`. `contractId` stays null on the record.
- **jaaropgaaf**: aggregate FIRST (D4), then `dataRefs = [{Employee}, {Jaaropgaaf}]` → templates read `Jaaropgaaf.totalGrossPay`, `Jaaropgaaf.year`. Rendering from the persisted aggregate (not an in-memory array) keeps docudesk's re-resolve-via-OpenRegister contract intact — `adHocData` still carries only the employer/document blocks, never flattened object data.
- The four existing letter types keep `[Employee(, EmploymentContract)]` unchanged.
- `adHocData.employer` gains `loonheffingennummer` (new `SettingsService` getter, obvious placeholder default like the existing name/address/kvk getters) — needed on both new document types and harmless on the letters.

### D4 — Aggregation and idempotency: the Jaaropgaaf object is upserted per (employee, year); documents key on their subject

**Aggregation** (imperative, in `HrDocumentService`): select the employee's Payslips whose `period` starts with `{year}-`, sum per the D2 table, count periods. **Idempotent per (employeeId, year)**: an existing `Jaaropgaaf` for that key is UPDATED in place (fresh sums, fresh `aggregatedAt`), never duplicated — re-running after a new payslip lands refreshes the totals. Zero payslips in the year → `failed` outcome with a diagnostic, no empty aggregate is written.

**Document idempotency keys** extend the leaf's D6 invariant per documentType family: letters keep (contractId|employeeId, documentType); **loonstrook keys on (payslipId, documentType)** — two payslips of the same employee each get their own loonstrook, which the old (employeeId, type)-when-contractId-null fallback would have wrongly collapsed; **jaaropgaaf keys on (jaaropgaafId, documentType)** — one active document per aggregate, and since the aggregate is upserted per (employee, year), transitively one per employee-year. Same pre-check semantics: `generated` → no-op, stale `pending` → superseded, `failed`/`skipped-no-docudesk` → retryable.

### D5 — Triggers: occ options are type-scoped; misuse fails as usage-error

`hrmq:documents:generate` gains `--period <YYYY-MM>` and `--year <YYYY>`:

- `--type loonstrook [--period][--employee]`: backlog = every `Payslip` lacking an active loonstrook `GeneratedDocument` (the `nl-loonstrook-verplicht` backlog), narrowed by `--period`/`--employee`. Unlike the letter types, loonstrook has REAL backlog semantics, so `--employee` is optional.
- `--type jaaropgaaf --year <YYYY> [--employee]`: `--year` is REQUIRED (an annual statement without a year is meaningless); default scope = every employee with ≥1 payslip in that year; per employee: upsert aggregate (D4) then render.
- Guards: `--period` with any type but loonstrook, `--year` with any type but jaaropgaaf, or `--type jaaropgaaf` without `--year` → single `usage-error` outcome, exit 1, nothing generated (the existing employee-level-type guard pattern).
- Default no-option run stays the arbeidsovereenkomst backlog — unchanged, no regression.

### D6 — Endpoint stays singular: optional `payslipId` on the existing route

`POST /api/documents/generate` gains an optional `payslipId` param instead of a new route: the action shape, CSRF posture, guard pattern, and controller are identical to the contract variant — a second route would duplicate all of it for one branch (`appinfo/routes.php` keeps its "no domain routes" note at one entry). Dispatch: `documentType == 'loonstrook'` → `payslipId` required, resolved via `authorizePayslip()` (same RBAC-scoped ObjectService `find` as `authorizeContract`; unknown/unauthorized → 404 before any docudesk call, no-admin-idor guard); `employeeId` is taken from the resolved payslip, `contractId` stays null. Letter types keep the contract path unchanged; mismatched params (loonstrook without payslipId, letters without contractId) → 400. **No jaaropgaaf endpoint in MVP**: the aggregate-then-render flow is a batch operation with a required year — occ-only until a real UI need appears (follow-up).

`PayslipDetail` gains the page action `{id: "generate-loonstrook", label: "Genereer PDF", type: "api-call", icon: "FileDocumentPlusOutline", url: "/api/documents/generate", method: "POST", params: {payslipId: "@objectId", documentType: "loonstrook"}, confirm: true}` with pre-translated toasts — the verbatim `EmploymentContractDetail` action shape.

### D7 — `nl-loonstrook-verplicht`: the evidence companion of `nl-loonstrook-verstrekken`

New row in **payroll.json** (next to its sibling, domain `reporting`, framework `nl-loonheffingen`, source BW 7:626), NOT a duplicate: `nl-loonstrook-verstrekken` states the legal obligation and stays `machineCheckable: false` (whether paper reached the employee is outside the system); `nl-loonstrook-verplicht` is `machineCheckable: true` and checks what IS in the system — every `Payslip` SHOULD have an active `generated` loonstrook `GeneratedDocument` referencing it. Severity `recommended`, the `nl-contract-schriftelijk` calibration: `statementProvided` may be honestly true via an out-of-band document (the stricter predicate deliberately ignores the self-asserted boolean), so this must not hard-fail an audit. Plumbing is the established pattern: `RuleAuditService::buildDocumentsContext()` gains `generatedLoonstrookByPayslip` (payslipId → true) in the SAME pre-pass; `NlDocumentChecks::checks()` gains the `Payslip`-keyed pure predicate; `RuleCatalogue::VERSION` → `2026-07.6`.

### Declarative vs imperative (ADR-031)

| Behaviour | Path | Rationale |
|---|---|---|
| `Jaaropgaaf` data model + `GeneratedDocument` enum/$ref extension | declarative schema (`hr-documents.json`) | ADR-031 default |
| Jaaropgaaf aggregation (Σ over a year's Payslips) | **imperative, in `HrDocumentService`** | the draft itself documented the gap: `x-openregister-aggregations` cannot filter a date-range/year slice; revisit when OR grows `@filter` on aggregations |
| Render call + file store (both new types) | imperative `HrDocumentService` (existing) | ADR-031 exception already granted to the leaf: cross-app integration with binary handling |
| Triggers | imperative occ options + the ONE existing controller (D5/D6) | no lifecycle on Payslip; the endpoint exists solely for the manifest api-call action |
| Loonstrook-evidence audit | imperative CheckProvider predicate | the app's established rule-corpus exception |
| Jaaropgaven pages + PayslipDetail action | declarative manifest | ADR-031 default (`api-call` is a declarative action type) |

### Mixed-spec rationale (kind: code)

`kind: code`: the PHP surface dominates (service aggregation + two generation paths + command options + controller guard + check provider + context index + unit tests) while the config surface (one schema + one enum bump, one rule row, two pages + one action, two seeds) rides along — the same yellow-flag precedent as `hrmq-docudesk-documents` and `payroll-glpost-shillinq`; splitting the fragment edit into a `kind: config` change would only create an artificial ordering dependency.

## Schema delta (`lib/Settings/register.d/hr-documents.json`)

**`GeneratedDocument` 0.1.0 → 0.2.0**: `documentType` enum `arbeidsovereenkomst|aanbiedingsbrief|werkgeversverklaring|getuigschrift|loonstrook|jaaropgaaf`; new `payslipId` (string, uuid, `$ref` Payslip, nullable — set only on loonstrook records) and `jaaropgaafId` (string, uuid, `$ref` Jaaropgaaf, nullable — set only on jaaropgaaf records).

**`Jaaropgaaf` v0.1.0** (`icon: FileCertificateOutline`, `x-schema-org: schema:Report`); required `[employeeId, year, totalGrossPay, totalLoonheffing]`:

| Field | Type | Notes |
|---|---|---|
| `employeeId` | string, uuid, `$ref` Employee | required |
| `year` | integer | required — the kalenderjaar |
| `totalGrossPay` | number | required — Σ grossPay (jaaropgaaf "loon") |
| `totalLoonheffing` | number | required — Σ loonheffing |
| `totalNettoPay` | number | Σ nettoPay |
| `totalZvwWithheld` | number | Σ zvw where `zvwMode == 'inhouding'` only (D2) |
| `totalPensionContribution` | number | Σ pensionContribution |
| `totalVakantiegeldReserved` | number | Σ vakantiegeldReserved |
| `payPeriodCount` | integer | number of payslips aggregated |
| `verrekendeArbeidskorting` | number, nullable | statutory line; null until payroll-core computes arbeidskorting (D2 honesty) |
| `loonheffingennummer` | string, nullable | employer config snapshot at aggregation time |
| `aggregatedAt` | string, date-time, nullable | when the aggregation last ran (upsert refresh) |

## New corpus rule (`lib/Standards/rules/payroll.json`)

| id | domain | jurisdiction | framework | severity | machineCheckable | statement (short) |
|---|---|---|---|---|---|---|
| `nl-loonstrook-verplicht` | reporting | NL | nl-loonheffingen | recommended | true | Every Payslip should have its loonstrook document on file — an active `GeneratedDocument` of type `loonstrook` in status `generated` referencing the payslip — the machine-checkable evidence companion of `nl-loonstrook-verstrekken` (BW 7:626), so `statementProvided` is evidenced, not merely asserted. |

Source: BW 7:626, `sourceUrl: https://wetten.overheid.nl/BWBR0005290` — matching the sibling rows. `RuleCatalogue::VERSION` → `2026-07.6`.

## Manifest delta (`src/manifest.json`)

- Menu: `PayrollGroup` ("Loonadministratie") gains child `{id: Jaaropgaven, label: "Jaaropgaven", icon: FileCertificateOutline, route: Jaaropgaven}` after `Payslips`.
- `Jaaropgaven` (index): columns `employeeId`, `year`, `totalGrossPay`, `totalLoonheffing`, `payPeriodCount`; filters `year`; sort `year` desc.
- `JaaropgaafDetail`: the EmploymentContractDetail two-panel shape — data widget (excludes `employeeId`; Related resolves the Employee) + related widget; no lifecycleActions (no x-openregister-lifecycle — D2 dropped the draft's status machine), no files widget (the PDF lives on the GeneratedDocument record, not here), audit sidebar tab.
- `PayslipDetail`: page-level `actions` gains `generate-loonstrook` (D6 shape).
- `GeneratedDocumentDetail`: `gd-data` exclude list gains `payslipId`/`jaaropgaafId` (Related resolves the new `$ref`s); `GeneratedDocuments` index unchanged (documentType filter already present).
- `npm run check:manifest` MUST keep passing.

## Seed Data (ADR-001)

`hr-documents.json` `components.objects` gains (placeholder convention of `hr-seed.json`):

1. `Jaaropgaaf` slug `jaaropgaaf-jansen-2026` — `employeeId: "employee-jansen"`, `year: 2026`, totals arithmetically consistent with the ONE seeded payslip `payslip-jansen-2026-05`: `totalGrossPay: 3800.00`, `totalLoonheffing: 1102.00`, `totalNettoPay: 2698.00`, `totalZvwWithheld: 0`, `totalPensionContribution: 0`, `totalVakantiegeldReserved: 0`, `payPeriodCount: 1`, `verrekendeArbeidskorting: null`, `loonheffingennummer: "0000.00.000.L00"` (obvious placeholder), `aggregatedAt: "2026-07-01T08:00:00Z"`.
2. `GeneratedDocument` slug `gendoc-loonstrook-jansen-2026-05` — `documentType: loonstrook`, `employeeId: "employee-jansen"`, `contractId: null`, `payslipId: "payslip-jansen-2026-05"`, `jaaropgaafId: null`, `templateRef: "docudesk-template-placeholder-uuid"`, `status: generated`, `filePath: "loonstrook-EMP-0001-2026-06-01.pdf"`, `generatedAt: "2026-06-01T09:00:00Z"` — the `nl-loonstrook-verplicht` green example (the audit predicate reads the record, not the folder; no real file behind the placeholder path — the existing `gendoc-arbeidsovereenkomst-jansen` convention).

The two existing seeded GeneratedDocuments stay untouched (union, no regression).

## Risks / Trade-offs

- **The seeded payslip has no loonstrook until this seed lands** — by design the new rule would flag `payslip-jansen-2026-05` without seed 2; the seed is the green example, and a second undocumented payslip is exercised in unit tests, not seeds (seeds stay minimal).
- **Aggregate staleness**: a Jaaropgaaf rendered in January goes stale if a December payslip is corrected afterwards. The upsert refreshes totals on every run, but an already-`generated` jaaropgaaf document no-ops (D4). Accepted for MVP: re-issuing a corrected jaaropgaaf is an operator decision (mark superseded/retry), mirrored on how the letters treat regeneration; documented on the occ output.
- **Template-author contract widens**: `Payslip.*` and `Jaaropgaaf.*` variable names join `Employee.*`/`EmploymentContract.*`/`employer.*` as conventions between this spec and docudesk template authors; a renamed field renders empty (docudesk Twig, `strict_variables: false`). Same mitigation as the leaf: config-key docs state the contract.
- **Header/body drift on REQ-HDD-006**: the main-spec requirement title says "per (contract|employee, documentType)"; this change widens the keying in the requirement BODY while keeping the title stable (MODIFIED matches by header — a title rename would be a spurious RENAME for archive tooling).
- **`verrekendeArbeidskorting` null on legal paper**: a jaaropgaaf normally states it. MVP renders a dash with the field name — visibly incomplete beats silently wrong; the field exists so payroll-core can fill it without a schema break.

## Open Questions

- None blocking. Follow-ups tracked in Non-Goals: cumulatieven + arbeidskorting (payroll-core), delivery/notification, a JaaropgaafDetail/year-batch UI trigger, corrected-jaaropgaaf reissue flow.
