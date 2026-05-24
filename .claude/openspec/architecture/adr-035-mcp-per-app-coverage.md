# ADR-035: MCP per-app coverage is a fleet expectation

## Status
Proposed

## Date
2026-05-11

## Context

[ADR-034](adr-034-ai-chat-companion.md) defined the cross-app AI chat companion. Decision 3 of that ADR established `OCA\OpenRegister\Mcp\IMcpToolProvider` — the opt-in PHP interface apps implement when they want to expose tools to the in-app AI. The interface is mechanically sound, ids are namespace-enforced, auth flowthrough is mandatory.

What ADR-034 does **not** say is whether implementing it is *expected*. As written, an app maintainer can mount the widget and ship — and the widget will work, talking only to OR's built-in `registers` / `schemas` / `objects` tools. The user sees a chat that can describe their data but can't act on the surfaces the app actually renders. The companion feels generic; the AI never knows about meetings, action items, contracts, klantcontacten, software listings — the things the user is looking at.

We have already proven the gap is real. The decidesk pilot's first end-to-end test (2026-05-11, qwen3.5 via Ollama, browser-2 Playwright) succeeded because decidesk had a `DecideskToolProvider` with five tools (`listOpenActionItems`, `listRecentMeetings`, `getMeetingDetails`, `startMeeting`, `addActionItem`). Without that provider, the answer would have been "I can list registers" — useless on a meetings page.

Two complementary forces push the same way:

1. **Hydra's spec pipeline plans features per app.** When the spec planner (Specter + team-architect + team-po) lays out a roadmap for opencatalogi, the question "what tools should the AI be able to call on this surface?" should be answered at *spec time*, not after the widget ships. Currently the spec templates do not prompt for MCP coverage — the question is invisible.
2. **Hydra's reviewer pipeline accepts feature PRs that add user-actionable surfaces.** A merge of "add bulk-publish action to opencatalogi listing" should at least *consider* whether `opencatalogi.bulkPublish` is now a callable tool. The reviewer currently has no rule asking the question — it's left to the human reviewer to notice.

The fix is a fleet-level expectation backed by spec-time prompts and reviewer-time soft-flags, not a hard gate. Per-app provider implementations are still opt-in, but the *decision* to opt in or out becomes a deliberate, recorded one rather than an absence.

This ADR specialises [ADR-022](adr-022-apps-consume-or-abstractions.md) (apps consume OR abstractions) for the MCP-discovery abstraction. ADR-022 already names MCP discovery as an OR-owned abstraction; this ADR codifies the consumption side: *every app with a user-actionable surface is expected to publish at least one MCP tool, or to record why not*.

## Decision

### 1. Per-app provider is the default expectation, not an opt-in surprise

Every Conduction app with a user-actionable surface — pages where a user reads, writes, or acts on objects (index views, detail pages, dashboards, settings forms with non-trivial side effects) — is expected to ship an `IMcpToolProvider` implementation, namespaced `OCA\{AppNamespace}\Mcp\{AppName}ToolProvider`, registered in DI under the alias `OCA\OpenRegister\Mcp\IMcpToolProvider::{appId}`.

Apps with no user-actionable surface (pure utility apps, ExApp sidecar wrappers that proxy another product's UI, headless integration apps) MAY opt out. Opt-out is recorded as a one-paragraph `mcp-coverage:` block in the app's `openspec/project.md` listing (a) the rationale, (b) the date, (c) the human who decided. Absence of either a provider OR a recorded opt-out is the soft-flag condition in Decision 3.

### 2. Every feature proposal answers "Does this add an MCP-callable surface?"

`openspec/changes/*/proposal.md` adds one new section under `## Impact` (or equivalent in chained-config heads): `### MCP coverage`. Three answers are valid:

- **"Adds tool: `{appId}.{toolName}`"** — the change ships a new entry in the app's `IMcpToolProvider::listTools()` return + an `invokeTool()` branch + a unit test of the tool descriptor.
- **"Extends existing tool: `{appId}.{toolName}`"** — the change widens an existing tool's parameters or return shape; the proposal explains the wire compatibility.
- **"No MCP surface — {reason}"** — the change is purely declarative (config kind), internal refactor, schema-only, or a workflow that has no user-callable action the AI would help with. The reason MUST be one sentence; "not applicable" alone is rejected by team-po.

The `team-po` skill enforces the section's presence; the `team-architect` skill reviews the chosen answer during design review (Step 6b of the skill).

### 3. Hydra reviewer soft-flags missing coverage; never hard-blocks

When a Hydra reviewer pass runs on a feature PR that touches a user-actionable surface (heuristic: controller routes annotated `@PublicPage`/`@NoAdminRequired` on non-CLI endpoints, or Vue components in `src/views/` or `src/components/index/` or `src/components/detail/`), and the app has no `IMcpToolProvider` implementation AND no recorded opt-out in `openspec/project.md`, the reviewer emits a `mcp-coverage` advisory:

> This app does not yet publish an `IMcpToolProvider` and has no opt-out recorded in `openspec/project.md`. Per ADR-035, consider whether this PR's surface should be MCP-callable. If yes, file a follow-up issue. If the app should opt out, add an `mcp-coverage:` block to project.md in a follow-up PR.

The advisory is informational only — it does **not** set `code-review:requires-changes`, does **not** block merge, and does **not** consume a reviewer turn budget beyond a single check. The Hydra reviewer surfaces it in the PR comment summary under the heading "Advisories". Decision 1's expectation is enforced socially (team review) and at spec time (Decision 2), not by reviewer gating.

### 4. Tool curation: providers list everything; agents whitelist what they expose

An app's `IMcpToolProvider` SHOULD expose every safe-to-call tool its UI exposes — there is no reason to keep an action callable from the index page but hidden from the AI. To prevent a noisy 50-tool catalog on agents that only need three, the per-agent decision lives on the `Agent` entity, not on the provider. A future OR schema change (tracked as a follow-up issue from this ADR's source change) adds an `Agent.toolWhitelist: string[]` field; `McpToolsService` filters the discovered tool list by that whitelist when a non-empty whitelist is set on the active agent. Empty whitelist (today's behaviour) means "all discovered tools allowed".

This decision lets providers be exhaustive (the right code-side default — surfaces aren't accidentally invisible) and agents be selective (the right ops-side default — a customer-service agent doesn't need internal admin tools). The follow-up issue tracks the schema change; until it ships, agents see all discovered tools, which is the current ADR-034 behaviour.

### 5. Specter prompt-builders and Hydra agent prompts include MCP coverage

The Specter spec pipeline (in `concurrentie-analyse/`) and Hydra's builder + reviewer + team-* agent prompts incorporate Decision 2's expectation:

- **Specter prompt-builders** add an `MCP coverage` paragraph to the per-app spec-generation prompt: "When proposing features for `{app}`, for each feature consider whether it should be an MCP-callable tool on the app's `IMcpToolProvider`. Default-yes for user-actionable surfaces."
- **Hydra builder agent** (`images/builder/CLAUDE.md`) gains a one-line rule: when implementing a `kind: code` change whose proposal declares "Adds tool: ...", the builder MUST add the tool to the app's `IMcpToolProvider` and add a unit test of the descriptor before opening the PR. Linked to ADR-031's declarative-fit pattern (a tool is declarative behaviour exposed to the AI; treat it the same way).
- **Hydra reviewer agent** (`images/reviewer/CLAUDE.md`) gains the soft-flag rule from Decision 3.
- **`team-architect` skill** adds Step 6b: confirm `## MCP coverage` is present in proposal and the chosen answer is appropriate for the change scope.
- **`team-po` skill** adds an MCP Coverage Check subsection to Step 2 (proposal review): reject proposals missing the section.
- **`team-backend` skill** adds an MCP Tool Wiring subsection: when the proposal declares a new tool, ensure `listTools()`, `invokeTool()`, and the tool descriptor test land in the same PR.

The container-side prompt changes (builder, reviewer) trigger an image rebuild via the existing ADR-033 (`images-pick-up-adr-033`) workflow on the next ADR/`images/` PR.

## Consequences

### Positive

- **The AI gets useful on every app, not just the loudest.** Every app's chat companion can act on the surfaces the app renders, not just describe registers.
- **Coverage decisions are visible and dated.** A future maintainer reading `openspec/project.md` for any app sees either "ships these tools" (provider exists) or "opted out on YYYY-MM-DD because ..." — no silent absences.
- **Spec time, not ship time.** The "should this be a tool?" question is asked while the feature is being designed, when the answer is cheap to act on. Today it's asked retroactively, if at all.
- **No new hard gates.** The reviewer soft-flag avoids weaponising MCP coverage as a merge blocker; the rule moves the conversation, doesn't block the PR.
- **Reuses existing machinery.** The ADR-033 image-rebuild flow already exists; team-* skills already exist; openspec proposal template already has impact sections. The only new artifact per app is the provider class itself.

### Negative

- **More to write at proposal time.** Every proposal carries one more section. Mitigated by the three-answer template — a `kind: config` schema-only change writes `"No MCP surface — schema-only change with no new user action"` in one line.
- **Apps that legitimately have no surface (e.g. ExApp wrappers) need the opt-out paragraph.** Mitigated by the one-time nature of the entry — once recorded, the soft-flag stops firing for that app.
- **Provider classes proliferate.** ~13 apps × one provider class each. Acceptable: a provider that lists 0 tools is trivial (today decidesk's provider lists 5 tools in ~150 LOC). The class is small even when comprehensive.
- **Reviewer's surface-detection heuristic is approximate.** A controller annotated `@NoAdminRequired` is not strictly the same as "user-actionable" — admin-page settings forms qualify too, jobs-only services do not. The reviewer is best-effort; false positives are cheap because the advisory is informational. The exact heuristic is being refined via a follow-up issue (see Decision 2 of this ADR's design.md companion).

### Migration

- This ADR ships alongside `openspec/changes/ai-mcp-per-app-coverage/` (`kind: config`). Markdown only; no code; image rebuild happens via existing flow on the next ADR/`images/` PR.
- decidesk already complies (`DecideskToolProvider`, merged in the ADR-034 chain). No retroactive work needed for the pilot.
- Other apps adopt as their next openspec proposal lands. The first proposal in each app to reference ADR-035 either adds a provider or records the opt-out — whichever is correct for that app. No big-bang rollout.
- The `Agent.toolWhitelist` schema field ships as a follow-up issue on `openregister`. Until it ships, agents see all discovered tools (current ADR-034 behaviour, no regression).
- Rollback: `git revert` removes the ADR + change. Existing providers (decidesk's) keep working — the runtime contract is ADR-034's `IMcpToolProvider`, which this ADR does not touch. No live system reverts behaviour.

## Related

- **[adr-034-ai-chat-companion.md](adr-034-ai-chat-companion.md)** — defines `IMcpToolProvider` and `McpToolsService` (Decision 3). This ADR turns that opt-in interface into a fleet-level expectation.
- **[adr-022-apps-consume-or-abstractions.md](adr-022-apps-consume-or-abstractions.md)** — MCP discovery is in ADR-022's abstraction table. This ADR codifies the consumption side: every app participates by default.
- **[adr-031-schema-declarative-business-logic.md](adr-031-schema-declarative-business-logic.md)** — "declare behaviour, don't class-encode it". MCP tools are the same idea exposed to the AI: declare what the AI can do, don't hide it in opaque service classes.
- **[adr-033-features-roadmap-menu.md](adr-033-features-roadmap-menu.md)** — the image-rebuild workflow this ADR's prompt patches piggyback on.
- **Source change**: `hydra/openspec/changes/ai-mcp-per-app-coverage/` — proposal, spec, design, tasks for this ADR.

## Ownership

- Hydra owns the builder / reviewer prompt patches + the team-* skill updates. Changes live in this repo.
- Specter / `concurrentie-analyse` owns the spec-pipeline prompt-builder patches. Cross-repo follow-up tracked in this change's tasks.
- The `openregister` team owns the `Agent.toolWhitelist` schema field. Cross-repo follow-up tracked in this change's tasks.
- Each app's maintainers own the per-app provider class or the `openspec/project.md` opt-out paragraph. Adopted on each app's next proposal as it lands.
