# Tasks — hrmq-mcp-adoption

## 1. Declare the dialect (config only — no PHP)

- [ ] 1.1 `lib/Settings/register.d/hr-ats.json` — add `configuration.x-openregister-mcp` to `Vacancy` (`enabled: true`; `search` + `get`; `scope: read`; `readOnlyHint: true`; filters `status`, `department`), as a **sibling** of the existing `x-openregister-lifecycle`. `Application` gets **no** block.
- [ ] 1.2 `lib/Settings/register.d/hr-org.json` — add a `configuration` object to `OrgUnit` containing only the MCP block (filters `type`, `parentUnitId`, `active`). `OrgAssignment` gets **no** block.
- [ ] 1.3 `lib/Settings/register.d/hr-assets.json` — `Asset` (filters `category`, `status`, `active`; sibling of its lifecycle) and `AssetAssignment` (new `configuration` object; filters `assetId`, `employeeId`).
- [ ] 1.4 `lib/Settings/register.d/hr-timesheet.json` — `Timesheet` (filters `employeeId`, `period`, `status`, `projectId`; sibling of its lifecycle).
- [ ] 1.5 `lib/Settings/register.d/hr-expense.json` — `Expense` (filters `employeeId`, `status`, `category`; sibling of its lifecycle).
- [ ] 1.6 Write a genuinely useful agent-facing `description` on each of the 12 verb configs (this string is what the LLM reads to choose the tool).

## 2. Prove the exclusions hold

- [ ] 2.1 Assert **no** `x-openregister-mcp` block exists on `Employee`, `EmploymentContract`, `Payslip`, `PayrollRun`, `PayrollGLPost`, `PayrollPaymentBatch`, `PensionFiling`, `LoonaangifteFiling`, `SickLeaveCase`, `LeaveRequest`, `LeaveBalance`, `Application`, `AttendanceRecord`, `Onboarding`, `Offboarding`, `OrgAssignment` or `GeneratedDocument` — `grep -rn "x-openregister-mcp" lib/Settings/` must hit exactly the 6 allowlisted schemas.
- [ ] 2.2 Assert no write verb and no curated tool anywhere: no `create` / `update` / `delete` key under any `x-openregister-mcp.tools`, and `grep -rn "McpTool" lib/` returns nothing.

## 3. Verify

- [ ] 3.1 `python3 -m json.tool` on each of the five touched fragments; all valid, hrmq schema count still 23, every pre-existing `x-openregister-lifecycle` block byte-for-byte unchanged.
- [ ] 3.2 Cross-check all 6 `search.filters` lists against each schema's `properties` map (an unknown filter fails the whole register import, not just the tool).
- [ ] 3.3 Import into OpenRegister: `McpAnnotationValidator` returns zero errors; derived surface is exactly 12 tools, all `readOnlyHint: true`; negative-assert that no tool exists for `Employee`, `Payslip`, `SickLeaveCase`, `LeaveRequest`, `LeaveBalance` or `Application`.
- [ ] 3.4 CHANGELOG entry (ADR-063 adoption: 6 read-only schemas, zero writes; salary/BSN/IBAN, sick-leave and candidate data explicitly not exposed).
