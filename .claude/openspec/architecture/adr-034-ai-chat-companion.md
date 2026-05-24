# ADR-034: AI Chat Companion — Cross-App Architecture

## Status
Proposed

## Date
2026-05-10

## Context

Conduction maintains ~13 Nextcloud apps (opencatalogi, openconnector, docudesk, decidesk, mydash, softwarecatalog, larpingapp, zaakafhandelapp, procest, pipelinq, openregister itself, plus the ExApp sidecar wrappers). Every one of them repeatedly faces the same product question: *how do users get AI help in-context, on whatever page they're on, without switching to a separate chat app?*

Today each app either:

- has no AI surface at all; or
- has a bespoke AI panel inconsistent with siblings; or
- hard-links to OpenRegister's full-page chat at `/apps/openregister/chat`, losing the user's context (which object, which register, which page) at the redirect boundary.

OpenRegister already owns the heavy machinery:

- **RAG**: `VectorEmbeddings`, `VectorSearchHandler`, `ContextRetrievalHandler` (hybrid semantic + keyword).
- **MCP server**: `McpServerController` exposing JSON-RPC 2.0 at `POST /api/mcp` with static `registers` / `schemas` / `objects` tools.
- **LLM provider abstraction** via LLPhant (OpenAI, Ollama, Fireworks).
- **Persistence** via the `Conversation`, `Message`, `Agent`, `Feedback` entities and a working `POST /api/chat/send` non-streaming chat endpoint.

What's missing is (1) a shared frontend primitive every app can mount in two lines, (2) a way for apps to push "what the user is looking at right now" through to that machinery, and (3) a way for apps to register their own MCP tools so the AI can actually act on the surfaces it sees — while preserving the standing rule that apps like `mydash` MUST NOT acquire an install-time dependency on OpenRegister.

The architecture below was decided in a clarifying-questions session and codified in the openspec change `ai-chat-companion`. This ADR promotes those decisions to the org-wide ADR set so downstream specs and future per-app pilots can reference one canonical source.

This ADR is the **AI / MCP slice** of [adr-022-apps-consume-or-abstractions.md](adr-022-apps-consume-or-abstractions.md). It refines ADR-022 for one specific abstraction (AI chat) without altering the principle ADR-022 codifies.

## Decision

### 1. The widget lives in `@conduction/nextcloud-vue`, mounted via `CnAppRoot`

A new component family in `@conduction/nextcloud-vue` (Vue 2) — `CnAiCompanion`, `CnAiMessageList`, `CnAiInput`, `CnAiHistory`, with a `useAiContext()` composable — ships the floating action button + chat panel. Apps that import the shared library get the widget for free without acquiring an install-time PHP/composer dependency on OpenRegister.

The widget probes a known OpenRegister chat health endpoint at mount and renders nothing (no FAB, no panel, no console warning above `info`) when the probe fails. This preserves the existing rule that consuming apps like `mydash` carry only a runtime HTTP dependency on OR, never an install-time one.

### 2. OpenRegister is the sole orchestrator

The widget calls OR's HTTP API at runtime. OR owns RAG retrieval, MCP tool fan-out, the multi-turn tool loop, LLM provider selection (LLPhant), and conversation persistence. The Nextcloud Task Processing API (`OCP\TaskProcessing\IManager`) is NOT called by the widget directly; if a deployment ever wants Task Processing, OR adds it as a LLPhant-style provider alongside OpenAI / Ollama / Fireworks. The widget contract does not change in that scenario.

### 3. Per-app MCP tools register via an in-process PHP interface — opt-in

OpenRegister publishes a PHP interface `OCA\OpenRegister\Mcp\IMcpToolProvider`. Apps that want to expose tools to the AI implement it and declare the implementation via Nextcloud's standard service container or `info.xml`. OR's `McpToolsService` enumerates implementations in-process per turn (no extra HTTP hops). Tool IDs MUST be namespaced as `{appId}.{toolName}`; `McpToolsService` mechanically rejects any descriptor whose prefix does not match the provider's owning app id, so cross-app collisions are impossible.

The existing static `registers` / `schemas` / `objects` tools migrate onto the same contract as built-in providers (`getAppId() === 'openregister'`, ids `openregister.registers`, `openregister.schemas`, `openregister.objects`).

Apps that publish tools acquire a PHP install-time dependency on OR (the interface lives in OR's namespace). This is opt-in: apps that only consume the widget at runtime — including `mydash` per the standing rule — stay completely decoupled.

### 4. `CnAiContext` flows via Vue 2 provide/inject from `CnAppRoot`

`CnAppRoot` `provide()`s a reactive object under the symbol `cnAiContext`. The shape is:

```ts
interface CnAiContext {
  appId: string
  pageKind: 'index' | 'detail' | 'dashboard' | 'chat' | 'settings' | 'custom'
  objectUuid?: string
  registerSlug?: string
  schemaSlug?: string
  route?: { path: string, name?: string, params?: Record<string, string> }
}
```

`CnIndexPage` / `CnDetailPage` / `CnDashboardPage` push reactive overrides because they already know what they are rendering. The widget injects via a published `useAiContext()` composable that returns the reactive reference. This matches the existing `CnAppRoot` provide pattern (`cnManifest` / `cnCustomComponents` / `cnTranslate` / `cnOpenUserSettings`). Apps that don't use the `Cn*Page` wrappers can provide the override manually.

### 5. One global thread per (user, agent); context is per-message metadata

OR's existing `Conversation` entity is reused as-is — a single thread per `(userId, agentId)` pair. Each `Message` row gains a JSON `context` column that records the active `CnAiContext` snapshot plus a `capturedAt` ISO-8601 timestamp at the moment the message was sent. Context never forks the thread; navigating to a different object continues the same conversation.

RAG and MCP layers MAY consult `Message.context` to pre-filter results — that is an OR-side concern, not enforced by this contract. Manual "Start new chat" + a history browser live in the widget UI.

### 6. Streaming via SSE on a new endpoint; six-event envelope

OpenRegister exposes a new authenticated endpoint `POST /index.php/apps/openregister/api/chat/stream` that accepts the same request body shape as the existing `POST /api/chat/send` and responds with `Content-Type: text/event-stream` (Server-Sent Events). The envelope has exactly six event types:

| Event type | When emitted |
|---|---|
| `token` | Each LLPhant streaming token chunk |
| `tool_call` | LLM requests a tool invocation |
| `tool_result` | After the tool runs (carries `isError` flag for failures) |
| `heartbeat` | Every 15s while the stream is open and no other event has fired — defeats reverse-proxy idle timeouts during slow tool loops |
| `final` | Single terminal event on success |
| `error` | Single terminal event on failure |

Either exactly one `final` or one `error` event closes every HTTP 200 response. Non-streaming providers (Fireworks streaming parity is unverified at the time of this ADR — tracked as a spike in the source change) degrade gracefully: zero `token` events plus one `final` event carrying the full text. The existing `POST /api/chat/send` non-streaming endpoint stays unchanged and serves as the contractual fallback if SSE through Apache + `mod_php` / PHP-FPM proves infeasible end-to-end.

### 7. Authentication flowthrough on every MCP tool call

Every `IMcpToolProvider::invokeTool()` invocation MUST run with the current Nextcloud session user's permissions; `McpToolsService` MUST NOT impersonate, elevate, or substitute a service account. Implementations that return or mutate objects MUST perform per-object authorisation checks before responding — mirrors [adr-005-security.md](adr-005-security.md) Rule 3 (IDOR / OWASP A01:2021). The widget passes the session cookie unchanged; OR's existing controller middleware handles auth as it already does for `/api/chat/send`.

## Consequences

### Positive

- **One AI surface across 13 apps.** Mount the widget once in a shared library; every app gets context-aware AI for free.
- **No fragmentation of the AI stack.** RAG, MCP, LLM provider selection, and persistence remain in one place (OR). Cross-app analytics ("how often does the AI answer questions about meetings?") are answerable because every user message lives in one schema.
- **Apps stay decoupled at install time.** `mydash` and any future BI surface use the widget without acquiring an OR composer dep. The "mydash must not depend on OR" rule is preserved.
- **Per-app MCP tools without HTTP fan-out.** Apps that want to publish tools implement a PHP interface; OR enumerates in-process. No N-HTTP-calls-per-turn cost, no extra auth surfaces.
- **Streaming UX where providers support it.** OpenAI and Ollama stream cleanly; non-streaming providers degrade to a single `final` event without breaking the contract.
- **Mechanically collision-free tool ids.** `{appId}.{toolName}` namespacing enforced by `McpToolsService` means two apps cannot accidentally register the same tool name.

### Negative

- **OR becomes a runtime dependency for the widget.** Apps that import the widget acquire a runtime expectation that OR is reachable. Mitigated by the no-render fallback: a missing OR means an absent widget, not a broken app.
- **SSE through Apache + `mod_php` is unverified at ADR-acceptance time.** Output buffering (`mod_php` flush, `mod_deflate`, FastCGI, reverse-proxy buffering) can collapse the stream. The source change carries a mandatory spike before the orchestrator's spec opens; if SSE proves infeasible end-to-end, the contractual fallback ladder (chunked HTTP → long-poll → degrade to `/api/chat/send`) covers it.
- **LLPhant streaming parity varies by provider.** Confirmed: OpenAI, Ollama. Fireworks: unverified — handled by the non-streaming-provider clause of the envelope.
- **`IMcpToolProvider` is a PHP install-time coupling for opting-in apps.** Apps that publish tools take a composer dep on OR. Acceptable because publishing tools is opt-in and value-add, not a precondition for using the widget. If a future non-OR-dependent app needs to publish tools, the interface can be extracted into a thin standalone composer package without breaking implementers.
- **One global thread per user can grow long.** No automatic thread rotation. Mitigated by the manual "Start new chat" action and the existing history browser.

### Migration

- This ADR ships alongside the openspec change `ai-chat-companion` in hydra. It is `Proposed` at merge of that change and `Accepted` at archive.
- Two downstream specs will land the implementations:
  - `openregister/openspec/changes/ai-chat-companion-orchestrator` (`kind: code`) — `IMcpToolProvider` interface, `McpToolsService` provider-discovery refactor, built-in providers for the existing static tools, the SSE endpoint, the `Message.context` migration.
  - `nextcloud-vue/openspec/changes/ai-chat-companion-widget` (`kind: code`) — the Vue component family, the `useAiContext()` composable, the `CnAppRoot` / `CnIndexPage` / `CnDetailPage` / `CnDashboardPage` wiring.
- Per-app pilots open as their own per-app changes after the two downstream specs ship. Recommended first pilot: `opencatalogi` (richest detail pages, strongest signal for context push).
- Existing OR full-page chat at `src/views/chat/ChatIndex.vue` is untouched in the chain; refactoring it onto the new shared primitives is tracked as a follow-up issue.
- Rollback: a `git revert` on the hydra commit removes the ADR + spec. Downstream specs that reference this ADR pause until the contracts are re-landed; no live system reverts behaviour because no executable code ships at the contract layer.

## Related

- **[adr-022-apps-consume-or-abstractions.md](adr-022-apps-consume-or-abstractions.md)** — the principle this ADR specialises. MCP discovery is already in ADR-022's abstraction table; this ADR defines the cross-app contract for it.
- **[adr-005-security.md](adr-005-security.md)** — Rule 3 (per-object IDOR check) is operationalised by Decision 7 above.
- **[adr-032-spec-sizing-and-chaining.md](adr-032-spec-sizing-and-chaining.md)** — the chain pattern this ADR's source change follows (`kind: config` head, two `kind: code` downstream specs).
- **[adr-004-frontend.md](adr-004-frontend.md)** — Vue 2 + Pinia + `@conduction/nextcloud-vue` rules the widget implementation conforms to.
- **[adr-017-component-composition.md](adr-017-component-composition.md)** — self-contained component rule applies to the new `CnAi*` family.
- **Source change**: `hydra/openspec/changes/ai-chat-companion/` — proposal, spec, design, tasks for this ADR.

## Ownership

- The OR team owns Decisions 2, 3, 5, 6, and 7 (orchestrator, interface, persistence, streaming, auth).
- The `nextcloud-vue` library maintainers own Decisions 1 and 4 (widget package, provide/inject contract).
- Each consuming app's maintainers own mounting the widget and (optionally) implementing `IMcpToolProvider`.
- Hydra reviewers enforce the ADR at code-review time: tool-id namespacing on PRs to OR, runtime-only OR dep on PRs to `nextcloud-vue`, semantic auth on every new `IMcpToolProvider` implementation.
