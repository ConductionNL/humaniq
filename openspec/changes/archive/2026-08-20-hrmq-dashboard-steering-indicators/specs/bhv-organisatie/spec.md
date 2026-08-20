## MODIFIED Requirements

### Requirement: BHV pages SHALL live under the existing Verlof & verzuim menu group, never a new top-level menu (REQ-BHV-005)

`src/manifest.json` SHALL expose `BhvCertificeringen` (index) and `BhvCertificeringDetail` (data +
related Employee/OrgUnit + audit sidebar) as `SUB_PAGE`/`DETAIL_TAB` placements under the existing
`Verlof & verzuim` menu group (`VerlofVerzuimGroup`). No new top-level menu SHALL be added. The
expiring-certificate signal SHALL surface as rows in the Obligations `object-table` widget on the
Dashboard (`hrmq-dashboard-steering-indicators` REQ-DSI-008) rather than as a dedicated
"Aflopende BHV-certificaten" widget — the 90-day `certificaatGeldigTot` window is unchanged.

#### Scenario: BHV pages are reachable under Verlof & verzuim
- **GIVEN** the manifest after this change
- **WHEN** a user navigates to `Verlof & verzuim`
- **THEN** a `BhvCertificeringen` entry is present, and the top-level menu count is unchanged from ADR-001's frozen 9 (8 menus + Configuratie)

#### Scenario: The dashboard widget mirrors the existing contracten-expiry widget shape
- **GIVEN** the Dashboard page after this change
- **WHEN** its widget list is inspected
- **THEN** no "Aflopende BHV-certificaten" `object-table` widget exists on the page, and a `BhvCertificering` record with `certificaatGeldigTot` within 90 days instead appears as a row in the same Obligations list a `contract-devries-tijdelijk`-shaped expiring-contract row appears in — one shared merged-list shape, not two mirrored dedicated widgets
