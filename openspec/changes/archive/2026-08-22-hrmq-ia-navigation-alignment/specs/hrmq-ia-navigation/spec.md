## ADDED Requirements

### Requirement: humaniq's menu structure conforms to its frozen ADR-001 top-level navigation

The system SHALL build its effective menu via the shared `@conduction/nextcloud-vue`
`buildManifest(base, fragments, menuLayout)` pipeline (ADR-037/ADR-044), and SHALL NOT introduce a
top-level menu entry that is not one of the nine entries frozen by
`openspec/architecture/adr-001-information-architecture.md` (Dashboard, Mijn HR, Medewerkers,
Salarissen, Verlof & verzuim, Onboarding & ATS, Declaraties & assets, Aangiftes & compliance,
Configuratie) without an ADR-001 amendment.

**Feature tier**: MVP

#### Scenario: Time-registration pages nest under Verlof & verzuim, not a standalone top-level group

- GIVEN the humaniq app menu is built from `src/manifest.d/*.json` and `src/menu-layout.json`
- WHEN the effective menu is rendered
- THEN the `Timesheets` and `TimesheetApproval` pages MUST appear under the "Verlof & verzuim"
  top-level group
- AND no standalone "Uren" top-level menu entry SHALL exist

#### Scenario: Expense pages nest under Declaraties & assets, not a standalone top-level group

- GIVEN the humaniq app menu is built from `src/manifest.d/*.json` and `src/menu-layout.json`
- WHEN the effective menu is rendered
- THEN the `Expenses` and `ExpenseApproval` pages MUST appear under the "Declaraties & assets"
  top-level group
- AND no standalone "Onkosten" top-level menu entry SHALL exist

#### Scenario: Relocating a menu entry never drops its route

- GIVEN a leaf menu entry is relocated to a different parent group via `menu-layout.json`
- WHEN the relocation is applied
- THEN the entry's page route MUST remain reachable and unchanged (ADR-044 no-functionality-loss
  invariant / ADR-029 route reachability)
