# humaniq-integrations

## ADDED Requirements

### Requirement: humaniq SHALL declare its outside connections with an honest status (REQ-HIN-001)

humaniq SHALL declare in `lib/Settings/connections.json` every outside system it exchanges data
with or hands work to, including those whose transport is not built yet, which SHALL be
declared not available with the reason. The status of each SHALL come from integriq's
connection registry.

Rows: `plt-integration-accounts` (humaniq matrix).

#### Scenario: HR sees which connections work
- **GIVEN** integriq installed and the RDW source linked
- **WHEN** an HR administrator opens `Integrations` in humaniq
- **THEN** the RDW vehicle register shows as configured, the pension fund UPA delivery as not
  available with the reason, and the BRP lookup as not configured

### Requirement: An administrator SHALL add an integration from humaniq (REQ-HIN-002)

The `Integrations` page SHALL offer "Add integration", opening integriq's link dialog filtered
to humaniq's declared connections, and SHALL link to integriq's catalogue of adapters and
source templates. Without integriq the page SHALL NOT be shown.

Rows: `plt-marketplace` (humaniq matrix).

#### Scenario: Linking a source from humaniq
- **GIVEN** a declared connection with no source
- **WHEN** the administrator chooses "Add integration" and picks a source template
- **THEN** integriq links the source, probes it, and humaniq's page shows the new status

#### Scenario: No integriq, no page
- **GIVEN** an instance without integriq
- **WHEN** a user opens the Configuratie menu
- **THEN** there is no Integrations entry, and "Access grants" is still there
