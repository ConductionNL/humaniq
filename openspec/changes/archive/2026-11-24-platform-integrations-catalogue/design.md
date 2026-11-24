# Design: real connections and an integration catalogue for humaniq

## Context

Read at `development` af702f78, and hydra `openspec/changes/connection-registry/design.md`.

- `IntegrationAccount` (`lib/Settings/register.d/hr-integrations.json`): `name`, `purpose`,
  `nextcloudUserId`, `grantedSchemas`, `status`, `reviewedBy`, `reviewedAt`, `createdAt`; its
  description says it is not an authorization mechanism. Pages `IntegrationAccounts`
  (`/configuratie/integraties`) and `IntegrationAccountDetail` in
  `src/manifest.d/hr-integrations.json`, from `2026-09-07-hris-api-public`.
- humaniq has no `lib/Settings/connections.json` today; `people-register-prefill` adds the
  first one with `rdw-voertuigen` and `brp-personen`.
- Connection registry (hydra, D2): the file carries `app` and `connections[]` with `key`,
  `title`, `description`, `order`, `settingsUrl`, `requiredConfig`, `adapter`, `available`,
  `unavailableMessage`, `switch`, `sourceTemplate`. integriq's `ConnectionRegistryService`
  turns each into an `app_connection` row with a status. D8 gives the app page shape (index
  over `integriq/app_connection`, menu query `app: humaniq`, `visibleIf.appInstalled:
  integriq`); D9 the "Add integration" handler to `/apps/integriq/connections?app=<id>&link=1`.
- integriq's `CatalogRegistryService` assembles the catalogue of adapters and source
  templates.
- `lib/Support/FleetAppId.php` resolves integriq duck-typed; the page is hidden when integriq
  is absent.

## Goals / Non-Goals

**Goals**

- Every outside system humaniq depends on is declared, with an honest status.
- One page in humaniq to see and add them.

**Non-Goals**

- Owning connection state in humaniq. integriq owns the rows; humaniq only declares and reports.

## Decisions

### D1. The declared connections

| key | title | available | note |
|---|---|---|---|
| `rdw-voertuigen` | RDW vehicle register | yes | from `people-register-prefill` |
| `brp-personen` | BRP person lookup | yes | behind the recorded legal basis |
| `shillinq-ledger` | Accounting ledger (shillinq) | yes, `reportedOnly` | humaniq reports whether shillinq answered its last handoff |
| `upa-pensioen` | Pension fund UPA delivery | no | "The UPA message is specified in filings-pension-upa-message and not built yet." |
| `arbodienst` | Occupational health service | no | "Sick reports reach the arbodienst by hand today." |
| `lms` | Learning platform | no | "The learning sync is specified in talent-training-and-lms." |
| `payroll-bureau` | Outside payroll bureau | no | "The handoff is specified in payroll-external-bureau-handoff." |

Alternative considered: declaring only what works today. Rejected: an HR department needs to
see what is missing, and the registry has `available: false` for exactly that.

### D2. Reporting the ledger status

For `shillinq-ledger` humaniq dispatches integriq's `ConnectionStatusReportedEvent` after each
glpost or netpay handoff (configured, or error with the message), because only humaniq knows
whether its last handoff reached shillinq.

### D3. Pages

`Integrations` per D8 under Configuratie; `IntegrationAccounts` retitled "Access grants" with
its route unchanged, so links keep working. The "Add integration" header action is a small
handler, as D9 prescribes, since a manifest `navigate` cannot leave the app.

## Declarative-vs-imperative decision (ADR-031)

| behaviour | path | why |
|---|---|---|
| declaring connections | static JSON read by integriq | the registry contract |
| the page | declarative manifest index page over `integriq/app_connection` | D8 |
| the ledger status | one event dispatch after a handoff | only humaniq can observe it |

## Seed data

None; the rows come from the declaration.

## Risks / Trade-offs

- [Declared-but-unavailable rows look like failures] → each carries the reason and names the
  change that will make it available.

## Open Questions

- None.
