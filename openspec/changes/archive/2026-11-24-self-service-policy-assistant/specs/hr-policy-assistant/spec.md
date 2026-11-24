# hr-policy-assistant

## ADDED Requirements

### Requirement: The assistant SHALL answer from the employer's published policies and cite them (REQ-HPA-001)

humaniq SHALL make its published policy announcements, leave type definitions, CAO reference data
and job framework readable to the fleet assistant through read-only MCP tools, and SHALL ship an
assistant profile that answers HR questions only from those sources, citing the source used, and
offers to forward a question it cannot answer to HR.

Rows: `dm-ai-policy-assistant` (humaniq matrix).

#### Scenario: An employee asks about the train ticket rule
- **GIVEN** a published expense policy saying public transport is reimbursed in full
- **WHEN** an employee on `MijnHr` asks the assistant whether a train ticket to a training course
  is reimbursed
- **THEN** the answer says yes and cites the expense policy announcement

#### Scenario: An unanswerable question goes to HR
- **GIVEN** no source covers the question
- **WHEN** the employee asks it
- **THEN** the assistant says so and offers to forward it, and a forwarded question reaches the
  HR group as a task

### Requirement: The assistant SHALL NOT read personal leave, pay or sickness data (REQ-HPA-002)

The assistant SHALL NOT be given any tool reading leave balances, leave requests, sickness cases,
payslips or employee records. A question about the asker's own figures SHALL be answered with a
link to the matching Mijn HR page.

Rows: `dm-ai-policy-assistant` (humaniq matrix).

#### Scenario: How many holiday hours do I have left
- **GIVEN** an employee asking the assistant for their remaining holiday hours
- **WHEN** the assistant answers
- **THEN** it gives no figure and links to `MijnVerlof`, and no MCP call reads `LeaveBalance`
