---
kind: config
depends_on: []
---

## Why

ADR-063 ("MCP as Platform Abstraction", 2026-07-12, hydra #102) rules that apps MUST NOT
hand-write MCP tool code. An app declares a per-schema `x-openregister-mcp` block under
`schema.configuration` and OpenRegister derives `{appId}.{schema}.{verb}` tools; genuine
non-CRUD behaviour goes on a service method with `#[McpTool]`.

hrmq has **no MCP surface at all** today — no provider, no dialect, no
`IMcpScannableServices` (verified at HEAD `274c5d3`, 2026-07-13). This change is hrmq's
greenfield adoption.

**hrmq is the sharpest privacy case in the fleet.** Its 23 schemas hold BSNs, IBANs, gross
monthly salaries, payslips with per-employee tax withholdings, employment contracts,
dismissal reasons, severance amounts, candidate CVs, clock-in/clock-out locations, and
**sick-leave cases with Wet verbetering poortwachter milestones** — health data under AVG
art. 9. The derived-tool surface has **no field-level projection**: a `get` returns the
whole object. So the design question is not "what can we expose" but "what is left once
everything special-category, remunerative, and identity-bearing is removed".

The honest answer is: **not much, and that is the correct answer.** This change exposes
**6 of 23 schemas, all read-only, zero writes**. A thin, defensible surface beats a broad
one, and every one of the 17 exclusions is argued.

## What Changes

- **Declare `x-openregister-mcp` on 6 schemas** — `Vacancy` (`register.d/hr-ats.json`),
  `OrgUnit` (`hr-org.json`), `Asset` + `AssetAssignment` (`hr-assets.json`), `Timesheet`
  (`hr-timesheet.json`), `Expense` (`hr-expense.json`) — each `enabled: true`, **`search` +
  `get` only**, `scope: "read"`, `readOnlyHint: true`, per-verb agent-facing descriptions
  and real-property `filters`.
- **Zero write verbs. Zero curated `#[McpTool]`s. No PHP changes at all** — hence
  `kind: config`. hrmq's back-office actions are payroll runs, tax filings, contract
  generation and approval transitions, and none of them may be agent-initiated.
- **REFUSE the entire remuneration domain**: `Employee` (holds `bsn`, `iban`,
  `grossMonthlySalary`), `EmploymentContract` (`hourlyWage`, `cao`), `Payslip`,
  `PayrollRun`, `PayrollGLPost`, `PayrollPaymentBatch`, `PensionFiling`,
  `LoonaangifteFiling` — **OFF for read as well as write**.
- **REFUSE the health domain**: `SickLeaveCase` (art. 9 AVG) — and, on the same ground,
  **`LeaveRequest` and `LeaveBalance`**, because hrmq models sick leave as a *value* of the
  shared `leaveType` enum (`holiday | sick | unpaid | special | care | parental`). The
  dialect cannot filter at the value level, so a search over the leave cluster is a query
  surface over health data. See design.md.
- **REFUSE the recruitment domain**: `Application` (candidate name, e-mail, phone, CV,
  motivation) — bulk applicant PII, and exposing it to a general assistant drags the
  deployment toward the AI Act's high-risk employment/recruitment regime.
- **REFUSE worker monitoring**: `AttendanceRecord` (clock-in/out + `location`).
- **REFUSE** `Offboarding` (dismissal `reason`, `transitievergoedingBedrag`), `Onboarding`,
  `OrgAssignment`, `GeneratedDocument`.
- CHANGELOG entry.

## Capabilities

### New Capabilities
- `hrmq-mcp-surface` — hrmq's agent-facing tool surface: the six-schema read-only allowlist,
  the zero-write posture, and the standing refusals over remuneration, health, recruitment
  and worker-monitoring data.

### Modified Capabilities
_None._ The dialect is additive metadata on existing schemas. No hrmq requirement changes
behaviour, and no PHP is touched. `leave-management`, `payroll-core-schema`, `verzuim-wvp`
and `recruiting-applications` are *referenced* by this change as the capabilities it
deliberately does not expose, but none of their requirements move.

## Impact

- **Config only:** `lib/Settings/register.d/hr-ats.json`, `hr-org.json`, `hr-assets.json`,
  `hr-timesheet.json`, `hr-expense.json`. Six schemas gain a
  `configuration.x-openregister-mcp` block; `AssetAssignment` and `OrgUnit` gain a
  `configuration` object (they have none today), the other four already have one carrying
  `x-openregister-lifecycle` and get the MCP block as a sibling key. No property, no
  `required`, no lifecycle declaration is touched.
- **No code.** No new class, no attribute, no `IMcpScannableServices` implementation.
- **Runtime dependency:** derived tools materialise only on an OpenRegister shipping
  `SchemaDerivedToolProvider` + `McpAnnotationValidator`. On an older OR the block is inert
  metadata and does not break the register import.
- **Consumers:** Hermiq, via OpenRegister's registry. No hrmq controller, route or frontend
  change.
- **Data protection:** see design.md §AVG — this change's substance *is* the exclusion set.
