# Design: post a vacancy to several job boards at once

## Context

Read at `development` af702f78.

- `Vacancy` (`lib/Settings/register.d/hr-ats.json:5`, 0.2.1) carries a lifecycle on
  `status` with two transitions: `publiceren` (`concept` to `gepubliceerd`, "inside humaniq
  only: no external channel wiring in the MVP") and `sluiten` (`gepubliceerd` to `gesloten`,
  terminal). No `channels` field and no posting schema exist.
- humaniq already ships a declared engine flow: `Loonrun` in `configuration`
  `x-openregister-flows` on `PayrollRun` (`lib/Settings/register.d/hr-objects.json:193`),
  arriving disabled and ownerless per the engine's adoption contract. It is the shape this
  change copies.
- integriq contributes flow nodes through `RegisterFlowNodesEvent`, among them
  `openconnector.source-call` (one governed outbound request through a configured source,
  with integriq's rate limit, circuit breaker, credential broker and call log) and
  `openconnector.apply-mapping` (`integriq lib/Flow/SourceCallNode.php`,
  `lib/Flow/ApplyMappingNode.php`).
- openregister's engine provides `openregister.trigger-object`, `openregister.iterate`,
  `openregister.object-read`, `openregister.object-write` and `openregister.switch`.
- `humaniq` calls no job board and holds no board credential anywhere (`grep -rn integriq lib`
  outside `lib/Support/FleetAppId.php`: no hit).

## Goals / Non-Goals

**Goals**

- An adviser picks boards once per vacancy; publishing posts to all of them, closing
  withdraws from all of them.
- Every board's outcome is a record HR can read, including a failure and its reason.
- No outbound HTTP code in humaniq (ADR-067, ADR-091).

**Non-Goals**

- Importing applications from the boards.
- Board-specific budgets, sponsored posts or analytics.

## Decisions

### D1. The orchestration is a declared flow, the transport is integriq's

`Vacature plaatsen` is an `x-openregister-flows` entry on `Vacancy`:
`openregister.trigger-object` on the `publiceren` transition, `openregister.iterate` over
`channels`, then per board `openconnector.apply-mapping` and `openconnector.source-call`,
then `openregister.object-write` of the board's `VacancyPosting` with `geplaatst`, the
returned id and link. A failed call takes the node's error exit to an `object-write` with
`mislukt` and the error text. `Vacature intrekken` triggers on `sluiten`, reads the postings
with `geplaatst` and withdraws each.

Alternative considered: a humaniq `MultipostingService` calling integriq's `CallService`
duck-typed. Rejected: it is "when X happens, do A then B then C", which ADR-031 assigns to
`x-openregister-flows`, and integriq already governs the call.

### D2. A board code is the integriq source name

`Vacancy.channels` holds strings such as `werk-nl`, `linkedin` and `indeed`. The flow
resolves each code to the integriq source and mapping of the same name. A code with no
configured source fails that one posting with `mislukt` and the reason "no source
configured", and the other boards still post.

Alternative considered: an enum of boards on the schema. Rejected: which boards exist is
integriq configuration, not humaniq data; an enum would need a humaniq release per board.

### D3. One posting per vacancy and board

`VacancyPosting`: `vacancyId` (`$ref` `Vacancy`), `channel`, `status` (`te-plaatsen`,
`geplaatst`, `mislukt`, `ingetrokken`), `externalId`, `externalUrl`, `postedAt`,
`withdrawnAt`, `lastError`. A re-run of the flow updates the posting for the same
vacancy and channel instead of adding one, so republishing after a failure does not
double-post.

### D4. Disabled until adopted

Both flows arrive disabled and ownerless. The `Vacancy` description and the flow
descriptions say what to configure first. Until adoption, publishing behaves exactly as
today.

## Declarative-vs-imperative decision (ADR-031)

| behaviour | path | why |
|---|---|---|
| post on publish, withdraw on close | declarative `x-openregister-flows` | event-triggered chain of steps |
| outbound call and credentials | integriq `openconnector.source-call` | ADR-067 egress plane |
| vacancy to board format | integriq `openconnector.apply-mapping` | mapping is connector configuration |
| posting outcome | declarative `openregister.object-write` | a plain object write |
| postings on the vacancy page | declarative `object-list` on `VacancyDetail` | existing widget |

## Seed data

The seeded published vacancy gains `channels: ["werk-nl", "linkedin"]` and two postings:
one `geplaatst` with an `externalUrl` of `https://example.org/vacature/1` and one `mislukt`
with `lastError` "no source configured". No credential or token appears in the seed.

## Risks / Trade-offs

- [Flows are inert until adopted] → the vacancy page shows no postings at all rather than
  pretending, and the flow descriptions name the adoption steps.
- [A board changes its API] → the mapping and source live in integriq, so the fix is
  connector configuration and not a humaniq release.

## As built (2026-09-30)

Read against openregister and integriq at `development` on 2026-09-30; every node config was
validated with the real node classes and both graphs were built with openregister's real
`FlowDefinitionBuilder` (`work/vmp/validate.php`, `work/vmp/build.php` in the lane directory).
Three points of this design did not fit the engine as it is, and changed:

- **D1, trigger.** `openregister.trigger-object` accepts only `object.created`, `object.updated`
  and `object.deleted`; there is no trigger on a lifecycle transition. Both flows therefore fire
  on `object.updated` of `Vacancy` and filter: `Vacature plaatsen` on `status` `gepubliceerd`,
  boards ticked and `postedToChannels` not set; `Vacature intrekken` on `status` `gesloten` with
  `postedToChannels` set. The posting flow sets `Vacancy.postedToChannels` before it posts, so a
  later save of a published vacancy does not post it again. Clearing the flag posts it again,
  and the postings are updated, not duplicated.
- **D1 and D2, one source per step.** `openconnector.source-call` names its source in its own
  config and does not template it. So the flow explodes `channels` into one item per board,
  routes each item with `openregister.route` (per-item routing; `openregister.switch` decides on
  the first item only) to a branch per shipped board (werk-nl, linkedin, indeed), and each branch
  maps with `humaniq-vacancy-<code>` and calls source `<code>`. A code with no branch goes to the
  `no-source` output and is written `mislukt` with "no source configured". A branch whose source
  does not exist in integriq fails that step, so an administrator deletes the branch of a board
  they do not use when adopting the flow (said in the flow description).
- **D1, failures.** Each call runs with `onError: continue`; integriq then carries the error on
  the item under `error`, and a second router writes `mislukt` with `error.message` or
  `geplaatst` with the board's reference and link, read from the answer by the step's
  `responseMapping` (`id` and `url`; an administrator adjusts them per board). The moment comes
  from `openregister.set-fields` with the JsonLogic `now` operation, since the write step has no
  clock. The request body is `{"vacancy": <mapped advert>}`: the source-call step sends an
  object body as JSON and a whole-value placeholder cannot be the body itself.
- **D3.** Posting writes upsert on `vacancyId` and `channel`. Withdrawal reads the vacancy's
  `geplaatst` postings with `fanOut`, calls `DELETE /vacancies/{{ externalId }}` on the board's
  source and updates the posting to `ingetrokken` with `withdrawnAt`; a refused withdrawal keeps
  `geplaatst` and records the reason in `lastError`.
- Register 0.44.0 (Vacancy 0.5.0 with `channels` and `postedToChannels`; VacancyPosting 0.1.0).
  `VacancyDetail` shows `channels` in the data widget and a Job boards `object-list`.
- Task 2.3 and 4.2 (a live run against a stub integriq source) are left open for the live check.

## Open Questions

- Some boards pull an XML feed instead of accepting a push. Should the careers collection
  from `hiring-portal-audiences` double as that feed, or should integriq publish one?
