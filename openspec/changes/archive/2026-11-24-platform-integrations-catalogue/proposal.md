---
kind: code
---

# Real connections and an integration catalogue for humaniq

## Why

humaniq's `IntegrationAccounts` page (`/configuratie/integraties`) lists which outside systems
are said to have access to HR data, but its own schema says it is "deliberately NOT an
authorization mechanism" and configures nothing: no connection, no credential, no status.
An HR department cannot see from humaniq whether its pension fund delivery, its occupational
health service or its accounting link is set up and working, and cannot add one.

The fleet already has the machinery. integriq keeps a connection registry fed by each app's
`lib/Settings/connections.json`, shows every declared connection with a live status, links a
source to it from a catalogue of adapters and source templates, and each adopting app shows
its own rows on an Integrations page (hydra `connection-registry`, D2 and D8). humaniq has not
adopted it. Doing so gives humaniq both halves the buyer asks for: connections it configures,
and a catalogue to extend it from.

### Matrix rows (humaniq `openspec/parity/capabilities.json`)

| row | capability | humaniq today |
|---|---|---|
| `plt-integration-accounts` | Configure connections to outside systems such as a pension fund or health service. | `partial`: a catalogue of intent, no working connection |
| `plt-marketplace` | Extend the system with third-party integrations from a marketplace. | `no`, none |

### Competitors rated yes

- `plt-integration-accounts`, AFAS Profit: "communication profiles are set up for outside
  exchanges, used for Belastingdienst, APG, arbo services"
  (https://help.afas.nl/help/NL/SE/Sys_ComSer_Config.htm).
- `plt-integration-accounts`, Visma Raet Youforce: "verified integrations with arbodiensten,
  IAM, learning, finance, rostering and recruitment systems"
  (https://youforce.nl/product/integraties).
- `plt-integration-accounts`, HR2day: "UPA links with pension funds such as PNO Media and
  Loyalis" (https://data.maglr.com/1697/issues/66612/785296/index.html).
- `plt-integration-accounts`, Loket.nl: "external party identifications per provider and
  administration for pension parties"
  (https://developer.loket.nl/ApiDocs#tag/Upa-pension-declaration).
- `plt-integration-accounts`, Personio: "connect Personio to a Loket administration with a
  dedicated API user and authorizations"
  (https://support.personio.de/hc/en-us/articles/24665026109853-Set-up-the-Loket-integration).
- `plt-marketplace`, AFAS Profit: "the AFAS partner portal lists certified integrations tested
  on security and function" (https://partner.afas.nl/).
- `plt-marketplace`, Loket.nl: "list all applications available to the employer and their
  authorizations" (https://developer.loket.nl/ApiDocs#tag/Marketplace).
- `plt-marketplace`, Personio: "Choose from our 200+ integrations in the Personio
  Marketplace" (https://support.personio.de/hc/en-us/articles/360018689897-Personio-Marketplace).

## What Changes

- **humaniq declares its connections.** `lib/Settings/connections.json` (started by
  `people-register-prefill` with the RDW and the BRP) lists every outside system humaniq talks
  to or hands work to: the pension funds' UPA delivery, the occupational health service
  (arbodienst), the learning platform, the accounting ledger (shillinq), and an outside payroll
  bureau. A connection whose sending half is not built yet is declared `available: false` with
  the reason, so the page is honest about it.
- **An Integrations page.** humaniq gains the standard page over integriq's `app_connection`
  schema, scoped to humaniq, showing each connection's status (configured, not configured,
  simulated, switched off, not available, error) and its settings link, with an "Add
  integration" action that opens integriq's dialog filtered to humaniq.
- **The catalogue.** The same page links to integriq's catalogue of adapters and source
  templates, and the humaniq Store (already in the footer) stays the place for configuration
  sets; together they are humaniq's integration marketplace.
- **Access grants keep their place.** `IntegrationAccounts` stays as the record of which
  systems were granted access and when that was reviewed, retitled "Access grants" so the
  two pages are not confused.

## Capabilities

### New Capabilities

- `humaniq-integrations`: humaniq's declared outside connections with live status, an
  Integrations page, and the catalogue to add one.

## Impact

- `lib/Settings/connections.json`: the connections above (hydra gate 116).
- `src/manifest.d/hr-integrations.json`: the `Integrations` page per the connection-registry
  D8 shape, menu entry under Configuratie with `visibleIf.appInstalled: integriq`, the
  "Add integration" handler, and the retitled `IntegrationAccounts`.
- `src/registry.js`: the two formatters `connectionStatus` and `connectionSettingsLabel` until
  nextcloud-vue ships them.

## Cross-app dependencies

- integriq: the connection registry, the catalogue and the link dialog (all shipped per the
  hydra connection-registry change). No integriq change is needed for humaniq to adopt it.

## Out of scope

- Building the individual transports (UPA file delivery, arbodienst messages). Their changes
  (`filings-pension-upa-message`, `absence-deadlines-and-signals`) flip their connection to
  available when they land.
- A curated marketplace of third-party vendors. The catalogue is integriq's adapters and
  templates.
