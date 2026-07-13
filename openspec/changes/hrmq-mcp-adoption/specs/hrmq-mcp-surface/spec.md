# hrmq-mcp-surface

hrmq's agent-facing MCP tool surface under ADR-063: a deliberately thin, read-only allowlist
and the standing refusals over remuneration, health, recruitment and worker-monitoring data.

## ADDED Requirements

### Requirement: hrmq declares a six-schema read-only MCP allowlist
hrmq MUST expose exactly six schemas to MCP via `configuration.x-openregister-mcp` —
`Vacancy`, `OrgUnit`, `Asset`, `AssetAssignment`, `Timesheet`, `Expense` — each with
`enabled: true` and the `search` and `get` verbs only. All twelve declared verb configs MUST
carry `scope: "read"`, `readOnlyHint: true`, and an agent-facing `description`. No other hrmq
schema may carry an `x-openregister-mcp` block.

#### Scenario: The six allowlisted schemas expose derived read tools
- **WHEN** OpenRegister derives its tool surface from hrmq's register
- **THEN** exactly twelve derived tools exist — `hrmq.{schema}.search` and
  `hrmq.{schema}.get` for each of the six allowlisted schemas
- **AND** each reports `readOnlyHint: true` and `scope: read`

#### Scenario: No other hrmq schema is reachable
- **WHEN** an agent lists hrmq's tool surface
- **THEN** no tool exists for `Employee`, `EmploymentContract`, `Payslip`, `PayrollRun`,
  `PayrollGLPost`, `PayrollPaymentBatch`, `PensionFiling`, `LoonaangifteFiling`,
  `SickLeaveCase`, `LeaveRequest`, `LeaveBalance`, `Application`, `AttendanceRecord`,
  `Onboarding`, `Offboarding`, `OrgAssignment` or `GeneratedDocument`

### Requirement: No hrmq schema exposes any write verb
The `create`, `update` and `delete` verbs MUST NOT be enabled on any hrmq schema, and no
hrmq service may carry `#[McpTool]`. An hrmq write is a wage payment, a tax filing to the
Belastingdienst, an employment-contract term, or an approval decision with legal effect.
`Timesheet`, `Expense` and `LeaveRequest` additionally run declarative approval lifecycles
guarded by `OCA\Hrmq\Lifecycle\NoSelfApprovalGuard` — a separation-of-duties control that an
agent acting on the requester's behalf would be positioned to sidestep. Approval is a human
act by a named human.

#### Scenario: An agent cannot write any HR object
- **WHEN** an agent enumerates hrmq's derived tools
- **THEN** no tool name ends in `.create`, `.update` or `.delete`
- **AND** no curated two-segment hrmq tool exists at all

#### Scenario: An agent cannot approve its own request
- **WHEN** an employee asks an agent to approve their own leave, timesheet or expense
- **THEN** no MCP tool performs the transition, and the agent MUST report that approval is a
  manager action in the hrmq UI, where `NoSelfApprovalGuard` applies

#### Scenario: An agent cannot touch payroll
- **WHEN** an agent is asked to correct a salary, run payroll, or file a loonaangifte
- **THEN** no MCP tool exists for any of it

### Requirement: Salary, BSN and IBAN are never reachable through MCP
The `Employee`, `EmploymentContract` and `Payslip` schemas MUST NOT be exposed for reading.
`Employee` carries `bsn`, `iban`, `tenaamstelling`, `dateOfBirth` and `grossMonthlySalary`
on the same object as the person's name; `EmploymentContract` carries `hourlyWage` and
`cao`; `Payslip` carries every per-employee gross, withholding, tax and net figure. The
`x-openregister-mcp` dialect has **no field-level projection** — a `get` returns the whole
object — so there is no way to expose an employee directory without also exposing the BSN,
the IBAN and the salary. Until the dialect can project, these schemas stay OFF.

#### Scenario: An employee directory lookup is refused
- **WHEN** a user asks an agent "what department is Jansen in?"
- **THEN** no MCP tool returns an `Employee` object
- **AND** the agent MUST report that employee records are not available to it, rather than
  guessing

#### Scenario: Salaries cannot be enumerated
- **WHEN** an agent attempts to list, rank or aggregate what anyone is paid
- **THEN** no MCP tool returns `grossMonthlySalary`, `hourlyWage`, `grossPay` or `nettoPay`
  from any schema

#### Scenario: The BSN never enters an agent context window
- **WHEN** any allowlisted tool returns results
- **THEN** none of the six allowlisted schemas declares a `bsn` or `iban` property, so no
  national identifier or bank account number can be returned

### Requirement: The leave cluster is OFF because sick leave is a leaveType value
The `SickLeaveCase`, `LeaveRequest` and `LeaveBalance` schemas MUST NOT be exposed.
`SickLeaveCase` is health data under AVG art. 9 outright (`firstSickDay`, `recoveredDate`,
`loondoorbetalingPercentage`, and the Wet verbetering poortwachter milestones). The trap is
that `LeaveRequest` and `LeaveBalance` are health data too: both model sick leave as a
*value* of the shared `leaveType` enum — `holiday | sick | unpaid | special | care |
parental` — so a `LeaveRequest` with `leaveType: "sick"` plus its free-text `reason`, and a
`LeaveBalance` with `leaveType: "sick"` and a non-zero `usedHours`, are records of an
identified employee's health. The dialect filters at the property level, never at the value
level, so there is no way to expose "holiday leave but not sick leave" from these schemas.

#### Scenario: Sick leave cannot be enumerated
- **WHEN** an agent is asked who is currently off sick, or who was sick most often this year
- **THEN** no MCP tool exposes `SickLeaveCase`, `LeaveRequest` or `LeaveBalance`, so the
  question cannot be answered from the MCP surface

#### Scenario: The vacation-balance read is refused for the same reason
- **WHEN** a user asks an agent how many holiday hours they have left
- **THEN** no MCP tool exposes `LeaveBalance` — because the same schema, filtered on
  `leaveType: "sick"`, would report sick-leave hours
- **AND** the agent MUST refer the user to the hrmq self-service UI

### Requirement: Candidate data and worker-monitoring data are OFF
The `Application` and `AttendanceRecord` schemas MUST NOT be exposed. `Application` holds a
candidate's `candidateName`, `email`, `phone`, `cvFile`, `motivation`, `rejectedDate` and
`retentionExpiryDate` — bulk applicant personal data held under an explicit AVG retention
regime, and a surface on which an LLM could de facto screen candidates, which is the AI
Act's high-risk employment use case. `AttendanceRecord` holds `clockIn`, `clockOut` and
`location` per employee per day: exposing it to a general-purpose agent turns it into an
automated worker-monitoring instrument.

#### Scenario: Candidates cannot be screened via MCP
- **WHEN** an agent is asked to review, rank or shortlist applicants for a vacancy
- **THEN** no MCP tool returns an `Application` object
- **AND** the agent MUST report that applicant review is a human decision in the hrmq UI

#### Scenario: Vacancies are still readable
- **WHEN** a user asks which positions are open
- **THEN** `hrmq.Vacancy.search` answers from `title`, `department`, `status` and
  `closingDate` — the posting, never the applicants

#### Scenario: Attendance cannot be mined
- **WHEN** an agent is asked who arrived late, or where someone clocked in from
- **THEN** no MCP tool exposes `AttendanceRecord`

### Requirement: Search filters name only real schema properties
Every `filters` list declared on a `search` verb MUST name properties that exist on that
schema, because OpenRegister's `McpAnnotationValidator` rejects a schema with an unknown
filter and the failure takes down the whole register import. The declared filters MUST be:
`Vacancy` → `status`, `department`; `OrgUnit` → `type`, `parentUnitId`, `active`; `Asset` →
`category`, `status`, `active`; `AssetAssignment` → `assetId`, `employeeId`; `Timesheet` →
`employeeId`, `period`, `status`, `projectId`; `Expense` → `employeeId`, `status`,
`category`.

#### Scenario: The register imports cleanly
- **WHEN** hrmq's register and its `register.d` fragments are imported into OpenRegister
- **THEN** `McpAnnotationValidator` returns zero errors
- **AND** no `mcp-unknown-filter`, `mcp-unknown-verb`, `mcp-unknown-key` or `mcp-bad-scope`
  error is raised

#### Scenario: Every declared filter resolves to a property
- **WHEN** each declared `search.filters` entry is cross-checked against its schema's
  `properties` map
- **THEN** every entry is present in that map

### Requirement: The dialect block never disturbs an existing lifecycle declaration
The `x-openregister-mcp` block MUST be added as a sibling key inside each schema's
`configuration` object, leaving any existing `x-openregister-lifecycle` declaration byte-for-byte
intact. `Vacancy`, `Asset`, `Timesheet` and `Expense` already carry a lifecycle state machine
under `configuration`; `OrgUnit` and `AssetAssignment` have no `configuration` object today and
gain one containing only the MCP block.

#### Scenario: Lifecycles survive the edit
- **WHEN** the five touched `register.d` fragments are re-validated after the edit
- **THEN** every pre-existing `configuration.x-openregister-lifecycle` block is unchanged
- **AND** `python3 -m json.tool` reports valid JSON for each file
- **AND** the total hrmq schema count is still 23
