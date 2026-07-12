# Design — payroll-glpost-shillinq

## Context

**hrmq side (verified against HEAD):** `PayrollRun` (`lib/Settings/register.d/hr-objects.json`) carries `period` (YYYY-MM), `administrationId` (plain string — no Administration schema in hrmq, ADR-062 rule 7), `status` (`draft|approved|posted|paid`), the totals `totalGross`, `totalLoonheffing`, `totalEmployerCharges`, `totalWithholdings`, `totalNet`, and the reconciliation fields `glExpensePosted` / `glLiabilityPosted` — **numbers** (amounts posted), not booleans. `NlPayrollChecks` (`lib/Standards/Checks/NlPayrollChecks.php`) already audits them: `xc-payroll-gl-reconciliation` passes when `glExpensePosted == totalGross + totalEmployerCharges` and `glLiabilityPosted == totalGross + totalEmployerCharges − totalNet` (cents-equal). Nothing writes these fields today.

**shillinq side (verified against `apps-extra/shillinq` HEAD):** register slug `shillinq`, schema slug `JournalEntry`, declared in `lib/Settings/register.d/add-shillinq-bookkeeping-foundation.json` (merged into `shillinq_register.json`, register version 0.6.1). Required fields: `journalNumber`, `entryDate` (date), `description`, `lines` (minItems 2; items require `accountNumber`, `side` ∈ `debit|credit`, `amount` ≥ 0, optional `description`), `journalType` (`manual|recurring|reversing`), `approvalState` (`not-required|pending|approved|rejected`, default `not-required`), `administrationId`, `state` (`draft|pending|posted|voided`, default `draft`). Lifecycle: posting (`postDirect` draft→posted, or submit→post via approval) materialises exactly one balanced GLTransaction with `idempotencyKey: journalNumber` (REQ-JE-007). Authoritative contract: `shillinq/openspec/specs/bookkeeping-journal-entries/spec.md` (REQ-JE-001…-JE-010).

**Integration pattern precedent:** `lib/Portal/PortalContributionProvider.php` (ADR-046) — duck-typed, zero hard dependency, inert when the sibling app is absent. `RuleAuditService` shows the ObjectService access idiom: `container->get('OCA\OpenRegister\Service\ObjectService')` → `setRegister(...)->setSchema(...)`, wrapped in try/catch.

## Goals / Non-Goals

**Goals:** one balanced loonjournaalpost per approved PayrollRun landing in shillinq's journal without re-keying; idempotent + crash-safe; graceful no-shillinq degradation; the existing GL-reconciliation rules turn green; auditable hrmq-side record with pages.

**Non-Goals:** SEPA net-pay batch (shillinq PaymentRun — follow-up spec), per-employee splits, vakantiegeld accrual (no run-level field exists — verified; only `Payslip.vakantiegeldReserved`), reversal/correction postings (manual void-in-shillinq is the escape hatch), driving shillinq's post transition (D3), automatic lifecycle trigger (D5).

## Decisions

### D1 — hrmq is a LEAF: it writes a shillinq object, it does not do bookkeeping

hrmq holds **no** account, GL, or journal machinery. The only bookkeeping artefact hrmq owns is `PayrollGLPost` — a *log of the handoff* (what was sent, when, outcome). The journal itself is a shillinq `JournalEntry` created through OpenRegister's ObjectService on the same instance (NOT HTTP), exactly the ecosystem rule: financial machinery lives in shillinq; hrmq reintegrates it.

### D2 — The balancing equation (explicit)

Given an approved run's totals, the service builds four lines:

| # | Side | Account (config key → placeholder default) | Amount |
|---|---|---|---|
| 1 | debit | `glpost_account_gross` → `4001` (loonkosten bruto, RGS-coded expense) | `totalGross` |
| 2 | debit | `glpost_account_employer_charges` → `4002` (werkgeverslasten sociale premies) | `totalEmployerCharges` |
| 3 | credit | `glpost_account_wage_tax_liability` → `1701` (loonheffing-schuld) | `totalLoonheffing` |
| 4 | credit | `glpost_account_net_wages_liability` → `1702` (netto-loonschuld) | `totalNet + R` |

with the **remainder** `R = (totalGross + totalEmployerCharges) − (totalLoonheffing + totalNet)`, so that debits = credits = `totalGross + totalEmployerCharges` by construction. `R` absorbs the employer charges payable plus any non-loonheffing employee withholdings (pension etc.); booking it into netto-loonschuld is a documented MVP simplification (per the session decision) — a follow-up may split it to dedicated liability accounts. Guards: all four totals must be present and numeric and `R ≥ 0`, else the attempt is recorded `failed` with a diagnostic `errorMessage` (negative `R` means the run totals are internally inconsistent). Zero-amount lines are dropped (shillinq requires `minItems: 2`, which any run with `totalGross > 0` satisfies). Amounts are rounded to cents, matching `NlPayrollChecks::centsEqual` semantics.

Worked example (the seeded 2026-01 run): gross 3800.00 + charges 649.80 = 4449.80 debit; credit 1102.00 (loonheffing) + 2698.00 + R 649.80 = 3347.80 → 4449.80. Balanced.

### D3 — hrmq creates the JournalEntry in `draft`; shillinq posts it

The created object: `journalType: manual`, `state: draft`, `approvalState: not-required`, `entryDate` = last day of the wage period, `description` = "Loonjournaalpost {period} — hrmq loonrun {payrollRunId}", `administrationId` passed through verbatim from the run, `journalNumber` = deterministic `HRMQ-LOON-{period}-{administrationId}`. hrmq does NOT drive shillinq's `postDirect` transition: GLTransaction materialisation sits behind shillinq's lifecycle guard and per-administration approval policy, and hrmq must not bypass either. `PayrollGLPost.status: posted` therefore means "journal entry delivered to shillinq's journal"; the bookkeeper posts it there (one click, or their approval flow). This is deliberate division of authority, mirrored from how shillinq itself separates JournalEntry from GLTransaction (REQ-JE-001).

### D4 — Success effects on the PayrollRun (adjusted to the real schema)

On success the service writes to the run: `glExpensePosted = totalGross + totalEmployerCharges`, `glLiabilityPosted = totalGross + totalEmployerCharges − totalNet` (the exact amounts `xc-payroll-gl-reconciliation` in `NlPayrollChecks` expects — these fields are **numbers**; the earlier per-session sketch said booleans and is corrected here), and advances `status: approved → posted` (the enum's `posted` state finally gets its meaning: posted to the GL). `withholdingsClearedToZero` / `withholdingLiabilityBalance` (rule `xc-withholding-liability-clearing`) are NOT touched — clearing happens at remittance, out of scope.

### D5 — Trigger is an occ command, not a lifecycle hook (MVP)

`hrmq:glpost:run [--period YYYY-MM]` selects runs with `status: approved` (optionally period-filtered) and posts each. Rationale: PayrollRun transitions are plain data edits today — no payroll engine drives `draft→approved`, and the compliance-checked schemas carry no `x-openregister-lifecycle` (verified by the active `hrmq-rule-compliance-enforcement` change). An event/guard-driven hook is the follow-up once that change wires lifecycle enforcement. Exit code: `0` when every selected run ends `posted` or `skipped-no-shillinq`, `1` when any ends `failed` (consistent with the audit-command exit-code direction).

### D6 — Idempotency and crash-safety (two layers)

- **hrmq layer:** invariant — at most one PayrollGLPost in `{pending, posted}` per `payrollRunId`, enforced by the service before creating a new attempt. `failed` and `skipped-no-shillinq` records are terminal-but-superseded: the run stays `approved`, so the next `hrmq:glpost:run` retries (a skip must not become permanent once shillinq gets installed). This refines the session sketch's "exactly one non-failed per run" — skipped counts as retryable, not as the one.
- **shillinq layer:** the deterministic `journalNumber` (`HRMQ-LOON-{period}-{administrationId}`). Before creating, the service searches the shillinq register for a JournalEntry with that number; if found (crash after create, before record update), it adopts it — records its id and completes the PayrollGLPost instead of double-posting. shillinq's own posting uses `journalNumber` as `idempotencyKey` (REQ-JE-007), so even a duplicate draft could never double-materialise.
- A stale `pending` PayrollGLPost (crash before any outcome) is resolved on the next command run via the same journalNumber probe: found → complete as `posted`… actually as delivered; not found → mark the stale record `failed` (superseded) and retry.

### D7 — Duck-typed degradation (ADR-046 philosophy, ADR-031 exception)

Availability probe: `IAppManager::isInstalled('shillinq')` AND a try/catch-guarded ObjectService resolve of register `shillinq` + schema `JournalEntry`. Any miss → record `PayrollGLPost` with `status: skipped-no-shillinq` and a human message in `errorMessage`; no exception, no log spam above INFO. hrmq keeps zero composer/info.xml dependency on shillinq.

### Declarative vs imperative (ADR-031)

| Behaviour | Path | Rationale |
|---|---|---|
| `PayrollGLPost` data model + statuses | declarative schema (`hr-glpost.json` fragment) | ADR-031 default |
| Journal construction + cross-register JournalEntry creation | **imperative `PayrollGLPostService`** | **ADR-031 exception: external/cross-app integration** — a multi-step, duck-typed write into ANOTHER app's register with crash-recovery probing cannot be expressed as a declarative lifecycle action on an hrmq schema; same exception class as the corpus CheckProviders this app already documents |
| Trigger | imperative occ command (D5) | no lifecycle exists on PayrollRun to hang a declarative action on; NOT a queue-walking background job (runs on operator demand) |
| Idempotency audit | imperative CheckProvider predicate (`NlGlPostChecks`) | the app's established rule-corpus exception |
| GL-post pages | declarative manifest | ADR-031 default |

### Mixed-spec rationale (kind: code)

This change is `kind: code`: the PHP surface dominates (service + command + check provider + RuleAuditService context enrichment + unit tests) while the config surface (one schema fragment, one rule row, two manifest pages) rides along. Repo precedent allows a mixed change with this yellow-flag note (cf. `hrmq-rule-compliance-enforcement`, also `kind: code` with corpus edits riding along); splitting the fragment into its own `kind: config` change would create an artificial ordering dependency for ~80 lines of JSON.

## Schema delta (new fragment `lib/Settings/register.d/hr-glpost.json`)

`PayrollGLPost` v0.1.0, icon `BookEditOutline` (mirrors shillinq's JournalEntry icon), `x-schema-org: schema:AccountingTransaction`; required `[payrollRunId, period, status]`:

| Field | Type | Notes |
|---|---|---|
| `payrollRunId` | string, format uuid, `$ref` PayrollRun | required — the run this attempt belongs to |
| `period` | string | YYYY-MM, copied from the run for listing/filtering |
| `status` | enum `pending\|posted\|failed\|skipped-no-shillinq`, default `pending` | outcome |
| `journalEntryId` | string, nullable | shillinq JournalEntry object id — plain string, NOT `$ref` (cross-register target; ADR-062 rule 7) |
| `journalNumber` | string, nullable | the deterministic idempotency key sent (D6) |
| `errorMessage` | string, nullable | failure/skip diagnostic |
| `postedAt` | string, date-time, nullable | when the JournalEntry was created in shillinq |
| `lines` | array of `{accountNumber, side, amount, description}` | snapshot of exactly what was sent (audit evidence, survives shillinq-side edits) |

## New corpus rule (`lib/Standards/rules/payroll.json`)

| id | domain | jurisdiction | framework | severity | machineCheckable | statement (short) |
|---|---|---|---|---|---|---|
| `nl-glpost-idempotent-per-run` | ledger-integrity | NL | payroll-core | recommended | true | Each approved payroll run shall flow into the general ledger through at most one active (pending or posted) GL-post record, and a posted record shall be complete (journal id + timestamp) with balanced lines; failed or skipped attempts are superseded by retries, never duplicated. |

Source: "Bookkeeping control (payroll-to-GL posting)", `sourceUrl: https://www.ifac.org/` — matching the sibling `xc-payroll-gl-reconciliation` / `xc-withholding-liability-clearing` rows it complements.

**Check plumbing:** predicates are `fn(array $o, array $context): bool` — cross-object counting needs context. `RuleAuditService::audit()` pre-loads PayrollGLPosts and injects `$context['glpost']['activeCountByRun']` (payrollRunId → count of pending/posted records) before evaluating; the new `NlGlPostChecks` provider keys its check on `PayrollGLPost` (RuleEngine auto-discovers the provider and the type). Predicate: active ⇒ count ≤ 1; `posted` ⇒ `journalEntryId` and `postedAt` present and lines debits cents-equal credits.

## Manifest delta (`src/manifest.json`)

- Menu: `PayrollGroup` ("Loonadministratie") gains child `{id: PayrollGLPosts, label: "Loonjournaalposten", icon: BookEditOutline, route: PayrollGLPosts}`.
- `PayrollGLPosts` (index): columns `period`, `status` (badge), `journalEntryId`, `postedAt`; default sort `postedAt` desc.
- `PayrollGLPostDetail`: data card (all schema fields incl. `errorMessage`), the `lines` snapshot as a table, and the `journalEntryId`/`journalNumber` displayed as the pointer into shillinq (plain display — no cross-app deep link in MVP). Structure mirrors `PayrollRunDetail`.

## Seed Data (ADR-001)

`hr-glpost.json` `components.objects` gains one `PayrollGLPost` (slug `glpost-2026-01-adm-001`) in `posted` status, aligned with the **only existing PayrollRun seed** — the `NlPayrollChecks::seedObjects()` run (period `2026-01`, `ADM-001`, status `posted`, gross 3800.00 / loonheffing 1102.00 / charges 649.80 / net 2698.00, `glExpensePosted` 4449.80, `glLiabilityPosted` 1751.80 — already reconciliation-green, i.e. exactly the post-GLPost end state). Fields: `payrollRunId: "payroll-run-2026-01-adm-001"` (slug-style placeholder ref, same convention as `hr-seed.json`'s `employeeId: "employee-jansen"`), `journalNumber: "HRMQ-LOON-2026-01-ADM-001"`, `journalEntryId: "je-hrmq-loon-2026-01-placeholder"`, `postedAt: "2026-02-05T09:00:00Z"`, and the balanced 4-line snapshot (4001 D 3800.00, 4002 D 649.80, 1701 C 1102.00, 1702 C 3347.80). All identifiers are obvious placeholders.

## Risks / Trade-offs

- **administrationId vocabulary mismatch**: hrmq seeds use `ADM-001`, shillinq seeds use `adm-consultancy-nl`. MVP passes the run's value through verbatim; the operator must keep the two apps on one administration vocabulary. Surfaced in the config docs; a mapping table is a follow-up if real deployments need it.
- **`R` into netto-loonschuld** (D2) is accounting-simplified; documented on the account-config keys so a bookkeeper can see exactly what lands on 1702.
- **`posted` ≠ materialised**: hrmq's `posted` means delivered-to-journal (D3). The detail page copy must say "aangeboden aan shillinq" semantics to avoid overclaiming.
- **Config drift**: the four account defaults are placeholders; a wrong account number produces a journal on the wrong account, not an error (shillinq validates account existence at ITS posting time). Acceptable — that is precisely the bookkeeper's review step D3 preserves.

## Open Questions

- None blocking. SEPA net-pay batch (shillinq PaymentRun consumption) and per-employee/kostenplaats splits tracked as follow-up specs; event-driven trigger tracked by `hrmq-rule-compliance-enforcement`.
