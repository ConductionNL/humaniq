# Design: an HR question assistant that answers from the employer's own policies

## Context

Read at `development` af702f78.

- `openspec/specs/humaniq-mcp-surface/spec.md`: a six-schema read-only allowlist (`Vacancy`,
  `OrgUnit`, `Asset`, `AssetAssignment`, `Timesheet`, `Expense`), no write verb, and standing
  refusals: salary, BSN and IBAN never reachable (:64); the leave cluster off because sick leave is
  a `leaveType` value, so the vacation-balance read is refused and the agent must refer the user to
  the self-service UI (:89-110); candidate and worker-monitoring data off (:111).
- `x-openregister-mcp` blocks sit on schemas in `lib/Settings/register.d` (for example
  `hr-org.json:10`).
- Reference data: `LeaveType` (`hr-leave-types.json`: code, label, conditions), CAO reference
  pages `Caos` and `CaoDetail` over `hr-cao.json`, `Normfunctie` (`hr-hr21.json`); policies arrive
  as `Announcement.policyFile` with `self-service-announcements-and-digest`.
- `@conduction/nextcloud-vue` 2.40.0 `CnAiCompanion` talks to hermiq's chat API
  (`/apps/{chatAppId}/api/chat`, default `hermiq`) and has an agent picker (`CnAiAgentPicker`).

## Goals / Non-Goals

**Goals**

- Answers grounded in the employer's own published sources, with the source cited.
- No personal data through the assistant, keeping the MCP refusals intact.

**Non-Goals**

- Training or fine-tuning. The assistant retrieves from the sources at question time.

## Decisions

### D1. Only reference data joins the MCP surface

`Announcement` (published only, `policyFile` and text), `LeaveType`, the CAO display schema of
`hr-cao.json` and `Normfunctie` get `x-openregister-mcp` with `search` and `get`. Each is checked
against the refusals: none carries a salary, a BSN, a health value or an identified employee.
Alternative considered: exposing `LeaveBalance` filtered to holiday. Rejected by the recorded
refusal: the dialect cannot filter on a value.

### D2. The agent definition

Instructions (Dutch and English): answer only from the humaniq policy tools and cite the
announcement, leave type or CAO page used; for questions about the asker's own leave, pay,
absence or contract, answer with the link to `MijnVerlof`, `MijnLoonstroken` or `MijnGegevens`;
otherwise offer to forward to HR. Tool allowlist: the four schemas of D1 plus hermiq's forward-to-
group tool with the HR group from `compliance-roles-and-field-access`.

### D3. Default agent on Mijn HR

On routes under `/mijn`, humaniq passes the HR-vraagbaak as the companion's preselected agent.

## Declarative-vs-imperative decision (ADR-031)

| behaviour | path | why |
|---|---|---|
| which data the assistant may read | declarative `x-openregister-mcp` | the dialect already used |
| the assistant's behaviour | a declared agent definition | hermiq configuration |
| the default agent | a prop on the library component | no humaniq logic |

## Seed data

- The seeded announcement with its policy file, so a question about the expense policy has a
  source.

## Risks / Trade-offs

- [The assistant answers outside its sources] → the instructions require a cited source and a
  hand-off when none applies; an answer without a citation is a defect to report.
- [Policies that are outdated] → only published announcements in their period are exposed.

## Open Questions

- The form in which hermiq accepts an app-contributed agent; named as a cross-app dependency.
