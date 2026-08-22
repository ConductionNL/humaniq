# Design — humaniq-mcp-adoption

Context: ADR-063 (MCP as Platform Abstraction, hydra #102) + the fleet MCP wave, 2026-07-13.
humaniq at HEAD `274c5d3` has **no** MCP provider, **no** dialect, **no** `IMcpScannableServices`
— greenfield adoption, so ADR-063's "stale hand-written tool shadows the derived one" hazard
does not apply.

## Where the dialect goes

OpenRegister reads the block from `schema.configuration['x-openregister-mcp']`
(`SchemaMapper::validateMcpAnnotation()`, `SchemaDerivedToolProvider`), **not** the schema
root. humaniq's schemas live in `lib/Settings/register.d/*.json` fragments. Four of the six
allowlisted schemas already have a `configuration` object carrying
`x-openregister-lifecycle`; the MCP block is added as a sibling key. `OrgUnit` and
`AssetAssignment` have no `configuration` object and gain one containing only the MCP block.

Verbs are a closed set (`search | get | create | update | delete`), scopes a closed set
(`read | create | update | delete`), hints are `readOnlyHint | destructiveHint |
idempotentHint`, and `filters` is legal on `search` only — **every filter must be a real
property of that schema** or `McpAnnotationValidator` fails the register import.

## The governing constraint: the dialect has no field-level projection

A derived `get` returns the **whole object**. There is no per-verb property allowlist. This
single fact decides almost every call below. It means:

- You cannot expose an **employee directory** without also exposing that employee's `bsn`,
  `iban` and `grossMonthlySalary`, because they are properties of the same `Employee` object.
- You cannot expose a **holiday balance** without also exposing a **sick-leave balance**,
  because humaniq models both as `LeaveBalance` rows distinguished only by the *value* of
  `leaveType`.

Both of humaniq's most-wanted assistant reads are therefore blocked — not by policy squeamishness
but by the shape of the model plus the shape of the dialect. Both are recorded as
DEFERRED_QUESTIONS rather than forced through.

## Curation table — 6 ON of 23

All six are **read-only** (`search` + `get`, `scope: read`, `readOnlyHint: true`). No write
verb anywhere in humaniq.

| # | Schema | Fragment | Verbs | Search filters (all verified real properties) | Why it is safe *and* worth exposing |
|---|--------|----------|-------|-----------------------------------------------|--------------------------------------|
| 1 | `Vacancy` | `hr-ats.json` | search, get | `status`, `department` | Job postings are **published** by design (`title`, `description`, `department`, `status`, `closingDate`). Zero personal data — the applicants live on the excluded `Application`. "Which positions are open?" is the one humaniq question with no privacy cost at all. |
| 2 | `OrgUnit` | `hr-org.json` | search, get | `type`, `parentUnitId`, `active` | The org chart itself: afdeling/team/kostenplaats, parent, cost centre. Structural, not personal. `managerId` is an opaque reference the agent cannot resolve (`Employee` is OFF), so it leaks nothing. |
| 3 | `Asset` | `hr-assets.json` | search, get | `category`, `status`, `active` | "Do we have a spare laptop?" — asset register: category, serial, status. Corporate property, no personal data. |
| 4 | `AssetAssignment` | `hr-assets.json` | search, get | `assetId`, `employeeId` | "Who has laptop L-114, and was the uitgiftebon signed?" — the custody ledger. Links an asset to an `employeeId` UUID; it is personal data (an employee is identifiable *to humaniq*), but it is custody metadata, not special-category, not remunerative, and it is the only way asset queries are answerable. Read-only. |
| 5 | `Timesheet` | `hr-timesheet.json` | search, get | `employeeId`, `period`, `status`, `projectId` | "Is my timesheet for June approved?" — hours, project, cost centre, approval status. No salary (hourly wage lives on the excluded `EmploymentContract`), no health, no location. A self-service approval workflow the employee themselves drives. |
| 6 | `Expense` | `hr-expense.json` | search, get | `employeeId`, `status`, `category` | "Is my declaratie approved / reimbursed yet?" — the employee's own claim: amount, category, status. Financial but not remunerative; it is a reimbursement, not pay. |

## Exclusions — 17 OFF of 23

### Remuneration and identity (8 schemas) — OFF for read as well as write

| Schema | Why OFF |
|--------|---------|
| `Employee` | `bsn` (national identifier, strictly regulated), `iban` + `tenaamstelling`, `dateOfBirth`, **`grossMonthlySalary`**, `identityDocument*`, `thirtyPercentRuling*` — all on the same object as the person's name. No projection ⇒ exposing the directory exposes the BSN and the salary. **The single hardest exclusion in the fleet, and the most costly.** |
| `EmploymentContract` | `hourlyWage`, `cao`, `hoursPerWeek`, `overtimeMultiplier` — the terms of employment. |
| `Payslip` | Per-employee `grossPay`, `loonheffing`, `nettoPay`, and ~60 jurisdiction-specific tax and social-security fields. The most sensitive object in the app after `Employee`. |
| `PayrollRun` | Aggregate `totalGross` / `totalNet` / `totalEmployerCharges`. Aggregate — but in a small organisation `totalGross ÷ headcount` is an average salary, and there is no assistant question worth that. |
| `PayrollGLPost` | Payroll journal lines. |
| `PayrollPaymentBatch` | The SEPA payment run: `totalAmount`, `lineCount`, `shillinqPaymentRunRef`. |
| `PensionFiling` | Fund, aanleverkenmerk, deadlines, response status. |
| `LoonaangifteFiling` | The wage-tax return: liabilities, deposit schedule, deadlines, `betalingskenmerk`. |

### Health / special category (3 schemas) — the leaveType trap

| Schema | Why OFF |
|--------|---------|
| `SickLeaveCase` | AVG **art. 9** special category outright: `firstSickDay`, `recoveredDate`, `loondoorbetalingPercentage`, plus the Wet verbetering poortwachter milestones (probleemanalyse, plan van aanpak, UWV 42-weken melding). A search here answers "who is off sick and for how long" in one call. **Absolutely OFF.** |
| `LeaveRequest` | Looks innocuous — but `leaveType` is an enum of `holiday \| sick \| unpaid \| special \| care \| parental`. A `LeaveRequest` with `leaveType: "sick"` plus its free-text `reason` **is** a health record about an identified employee. The dialect filters on *properties*, never on *values*, so "expose holiday leave but not sick leave" is not expressible. OFF. |
| `LeaveBalance` | Same enum, same problem: a `LeaveBalance` row with `leaveType: "sick"` and `usedHours: 96` is a statement about an identified employee's health. This is painful — "how many vacation days do I have left" is *the* archetypal HR self-service question and we cannot serve it. See DEFERRED_QUESTIONS #2. |

### Recruitment, monitoring, and person-centric case files (6 schemas)

| Schema | Why OFF |
|--------|---------|
| `Application` | `candidateName`, `email`, `phone`, `cvFile`, `motivation` — bulk applicant PII under an explicit retention regime (`retentionExpiryDate`). Exposing it to a general assistant also creates a de-facto candidate-screening surface, which is the **AI Act's high-risk employment use case** (Annex III, recruitment/selection). Not a boundary to cross by accident through a config key. |
| `AttendanceRecord` | `clockIn`, `clockOut`, `location`. Agent-queryable attendance is an automated worker-monitoring instrument ("who was late this month"). Works-council territory; OFF. |
| `Offboarding` | Dismissal `reason` and `transitievergoedingBedrag` (severance amount) — the two most sensitive facts about a departure. OFF. |
| `Onboarding` | Checklist booleans only (`bsnValidated`, `widCheckDone`, `ibanVerified`) — no actual BSN. But it carries `proeftijdEndDate` (a "who is still in probation" query has employment consequences) and a free-text `notes` field that is an uncontrolled leak channel. Bias to fewer: OFF. |
| `OrgAssignment` | The employee ↔ org-unit edge, with `role` and start/end dates. With `Employee` OFF the `employeeId` is an unresolvable UUID, so the schema is near-useless to an agent while still being a person-linkage table. Low value, non-zero risk: OFF. |
| `GeneratedDocument` (hr-documents) | `documentType`, `employeeId`, `contractId`, `filePath` — pointers to generated employment contracts and payslip PDFs. Metadata *about* the remuneration documents we already refused. OFF. |

## Refusals, stated plainly

**No agent writes to payroll, salary or contract objects.** Not `create`, not `update`, not
`delete`, not a curated `#[McpTool]`. The reasons are cumulative:
- A wrong number in a `Payslip` or `PayrollRun` is a wrong wage payment to a real person;
  a wrong `LoonaangifteFiling` is a false statement to the Belastingdienst.
- An `EmploymentContract` change alters the legal terms of someone's employment.
- These objects sit behind approval and filing workflows precisely so that a human signs off.

**No agent approvals.** `Timesheet`, `Expense` and `LeaveRequest` run declarative lifecycles
guarded by `OCA\Humaniq\Lifecycle\NoSelfApprovalGuard` — a separation-of-duties control that
rejects self-approval. An agent acting *as* the requesting user is exactly the principal that
guard exists to stop, and MCP audit logging today records the **session user, not the agent
principal** (a known gap from the ADR-063 wave). Enabling `update` on these schemas would put
an agent in a position to walk a request through its own approval. Reads only.

**Nothing that lets an agent enumerate salaries or sick leave.** Concretely, the following
queries are unanswerable from humaniq's MCP surface *by construction*, and that is the design:
- "What does X earn?" / "Rank the team by salary" → `Employee`, `EmploymentContract`,
  `Payslip` all OFF.
- "Who is off sick?" / "Who has been sick most this year?" → `SickLeaveCase`,
  `LeaveRequest`, `LeaveBalance` all OFF.
- "Who was late this month?" → `AttendanceRecord` OFF.
- "Shortlist these candidates" → `Application` OFF.

## AVG / sensitivity analysis

humaniq is, materially, a processing register of employment data — the most asymmetric
controller/subject relationship the AVG contemplates. Four grounds shape the allowlist:

1. **Art. 9 — special categories.** Health data may be processed only under a narrow
   exception, and an employer may not even record the *nature* of an illness. humaniq's
   sick-leave data spans `SickLeaveCase` **and** the `sick`/`care` values of `leaveType` on
   `LeaveRequest`/`LeaveBalance`. Because the dialect has no value-level filter, the only
   compliant posture is to exclude all three schemas. Exposing `LeaveBalance` "for holidays"
   would in fact expose sick-leave hours — the kind of silent, structural leak that looks
   fine in review and is a breach in production.

2. **National identifiers and financial data.** The `bsn` is regulated beyond the AVG's
   general regime (Wet algemene bepalingen burgerservicenummer); an `iban` plus a
   `tenaamstelling` is a payment instrument. Both sit on `Employee`, alongside
   `grossMonthlySalary`. A single `humaniq.Employee.get` would place all three in an LLM context
   window. **Data minimisation (art. 5(1)(c))** forbids it when the answer the user wanted
   was "which team is she on".

3. **Purpose limitation (art. 5(1)(b)) and automated decision-making (art. 22 + AI Act).**
   `Application` data was collected to run a hiring procedure. Exposing it to a general
   assistant creates a screening surface — the AI Act's high-risk employment/recruitment
   category — with no DPIA, no human-in-the-loop guarantee and no transparency notice to the
   candidate. `AttendanceRecord` was collected for time registration, not for behavioural
   monitoring; making it agent-queryable repurposes it.

4. **Proportionality of the exposed six.** `Vacancy`, `OrgUnit` and `Asset` carry no personal
   data at all. `AssetAssignment`, `Timesheet` and `Expense` do carry an `employeeId` and are
   therefore personal data — but they are (a) non-special-category, (b) non-remunerative,
   (c) records the employee themselves created or is the subject of in a self-service flow,
   and (d) read-only. OpenRegister RBAC remains the authoritative gate at invoke time: the
   dialect declares *what a tool is*, never *who may call it*. The honest residual risk is
   that an **HR-admin principal's** agent can `search` `Timesheet`/`Expense`/`AssetAssignment`
   across the whole workforce — that is the same reach that principal has in the UI, but it is
   now reachable in one tool call, so it is stated here rather than glossed. This is the
   weakest part of the surface and the first thing to revisit if the exposure proves broader
   than intended.

**Net result: 6 of 23 schemas, 12 tools, zero writes, zero special-category data, zero
salary, zero BSN, zero IBAN.** A thin, defensible surface.

## Verification

- `python3 -m json.tool` on each of the five touched `register.d` fragments after every edit;
  schema count across humaniq MUST stay 23 and no `x-openregister-lifecycle` block may move.
- Cross-check every `search.filters` entry against that schema's `properties` map before
  import — an unknown filter fails the **whole register import**, not just the tool.
- After import: assert the derived surface is exactly 12 tools, that all 12 report
  `readOnlyHint: true`, that no tool name ends in `.create` / `.update` / `.delete`, and that
  `grep -rn "McpTool" lib/` returns nothing.
- Negative assertion: no tool exists for `Employee`, `Payslip`, `EmploymentContract`,
  `SickLeaveCase`, `LeaveRequest`, `LeaveBalance`, `Application` or `AttendanceRecord`.

## DEFERRED_QUESTIONS

1. **A safe employee-directory read is the biggest gap.** "Who is X, what team, when did they
   start" is the most common HR assistant question and we cannot serve it, because `Employee`
   welds the directory fields to the BSN, the IBAN and the salary. Two ways out, both
   deferred: (a) OpenRegister gains **field-level projection** in `x-openregister-mcp` (a
   per-verb `properties` allowlist) — the highest-leverage fix and it unlocks several
   exclusions in docudesk too; or (b) humaniq adds a curated
   `HrDirectoryService::lookupEmployee()` with `#[McpTool]` returning only name, department,
   role and start date. (b) is achievable today without OR changes but hand-rolls a
   projection the platform should own — raise it against ADR-063 before building it.

2. **Split the holiday balance from the sick balance.** `LeaveBalance` and `LeaveRequest`
   are contaminated by the `sick`/`care` values of the shared `leaveType` enum. If humaniq
   modelled sick leave exclusively on `SickLeaveCase` (it largely already does — the
   verzuim-wvp capability owns that lifecycle) and removed `sick` from the leave enum, then
   `LeaveBalance` would become a clean, non-special-category self-service read and the
   single most-wanted HR question ("how many vacation days do I have left") would become
   answerable. This is a **schema-model question for the leave-management capability**, not
   an MCP question, and it is the right place to fix it.

3. **Is `Timesheet.search` too broad for an HR-admin principal?** See §AVG point 4. If the
   dialect ever gains a "must supply at least one filter" constraint, `Timesheet` and
   `Expense` should require `employeeId`.

4. **Payroll *status* reads (`LoonaangifteFiling.status`, `PensionFiling.status`).** "Is the
   June aangifte in, and what did the Belastingdienst say?" is a legitimate compliance
   question with hard deadlines. It is refused today only because the objects also carry
   liability amounts and there is no projection. Revisit with DEFERRED_QUESTION #1(a).
