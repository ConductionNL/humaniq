---
kind: config
depends_on: [self-service-announcements-and-digest]
---

# An HR question assistant that answers from the employer's own policies

## Why

An employee with a question about the rules (how much notice do I give for leave, what does the
CAO say about working on a public holiday, can I claim a train ticket) asks HR by email, and HR
answers the same questions every week from the same documents. Visma Raet, HR2day, Loket and
Personio now ship an assistant that answers such questions from the organisation's own HR
documents and CAO and hands complex questions to HR.

The fleet has the pieces: hermiq is the assistant engine and nextcloud-vue's `CnAiCompanion` its
chat panel on every page, and humaniq exposes a read-only MCP surface. What is missing is humaniq
giving the assistant its policies to answer from, and the boundary the MCP surface already
records: humaniq refuses to expose leave balances, sickness and pay through MCP, because the leave
schemas carry sick leave as a value (`humaniq-mcp-surface`, "The vacation-balance read is refused
for the same reason ... the agent MUST refer the user to the humaniq self-service UI"). So the
assistant answers the rules and points to the right Mijn HR page for the person's own figures.

### Matrix rows (humaniq `openspec/parity/capabilities.json`)

| row | capability | humaniq today |
|---|---|---|
| `dm-ai-policy-assistant` | Ask an assistant an HR question and get an answer based on your employer's own policies, collective agreement and your own data. | `no`, none: only a six-schema read-only MCP surface |

### Demand

- `dm-ai-policy-assistant`, changelog: https://www.hr2day.com/nieuws/nieuwe-release-kiwi/

### Competitors rated yes

- `dm-ai-policy-assistant`, Visma Raet Youforce: the Youforce Assistent "answers from the
  organisation's own HR documents and cao and forwards complex questions to HR"
  (https://youforce.nl/product/youforce-assistent).
- `dm-ai-policy-assistant`, HR2day: Daisy "reads notifications, leave, absence, claims and the
  latest payslip and can read answers aloud"
  (https://data.maglr.com/1697/issues/66612/785292/index.html).
- `dm-ai-policy-assistant`, Loket.nl: "the voice driven HR-buddy in MijnLoket that answers HR
  questions and performs HR tasks for employees"
  (https://loket.nl/nieuws/loket-brengt-hr-van-morgen-dichterbij-met-spraakgestuurde-hr-buddy/).
- `dm-ai-policy-assistant`, Personio: "connect Confluence and Google Drive as knowledge sources so
  employees get quick answers to HR questions without opening a ticket"
  (https://www.personio.com/whats-new-q2-26/).

## What Changes

- **Policies on the MCP surface.** humaniq adds read-only MCP exposure for the reference data an
  assistant may answer from: published `Announcement` policies (from
  `self-service-announcements-and-digest`), `LeaveType` definitions, the CAO reference pages
  (`Caos`), and `Normfunctie`. None holds personal data.
- **A shipped assistant profile.** humaniq ships the definition of an "HR-vraagbaak" agent for
  hermiq: its instructions (answer from these sources and cite them; for your own leave, pay or
  absence, give the link to the Mijn HR page; hand anything else to HR) and its tool allowlist,
  which is exactly the policy tools above.
- **Hand-off to HR.** When the assistant cannot answer, it offers to send the question to HR,
  which lands as a task for the HR group.
- **On Mijn HR.** The companion opens with the HR-vraagbaak selected on humaniq's Mijn HR pages.

## Capabilities

### New Capabilities

- `hr-policy-assistant`: an assistant profile answering HR questions from the employer's
  published policies and CAO, referring personal questions to Mijn HR.

## Impact

- `lib/Settings/register.d/hr-announcements.json`, `hr-leave-types.json`, `hr-cao.json`,
  `hr-hr21.json`: `x-openregister-mcp` read-only blocks (search and get only).
- `lib/Settings/hermiq-agents.json` (new) or the form hermiq takes for app-contributed agents:
  the HR-vraagbaak definition.
- `src/main.js` or `CnAppRoot` props: the companion's default agent on Mijn HR routes.

## Cross-app dependencies

- hermiq: loading an agent definition contributed by an app, and the hand-off to a group as a
  task. If hermiq has no app-contributed agent path yet, hermiq's lane owns adding it; this
  change supplies the definition.

## Out of scope

- Answering questions about the employee's own balances, pay or sickness through the assistant.
  The MCP surface's recorded refusal stands; the assistant links to the self-service page.
- Performing HR tasks (submitting leave) from the chat.
