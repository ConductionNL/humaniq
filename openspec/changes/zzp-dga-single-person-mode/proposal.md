# Proposal: zzp-dga-single-person-mode

## Why

The Dutch DGA (directeur-grootaandeelhouder) and ZZP (zzelfstandige zonder personeel) populations represent ~400,000 entrepreneurs running single-person BVs. Today these users either:

1. **Pay accountants EUR 1,500–3,000/year** to run payroll in closed-source packages (expensive for a one-person setup)
2. **Use spreadsheets** and hope the Belastingdienst doesn't question the gebruikelijk-loon (error-prone)

Both paths are suboptimal: option 1 is costly; option 2 risks penalties (naheffing loonheffing, correctieboete, rente). The crux is the *gebruikelijk-loon* discussion — proving the annual minimum draw-down that the Belastingdienst expects from a DGA is a recurring source of disputes.

HRMQ's existing payroll-engine (used by multi-employee organisations) handles loonheffingen, ZVW, and jaaropgaaf correctly. What DGAs lack is a **focused UI shell** that:
- Hides multi-employee chrome (team rosters, leave queues, org charts)
- Surfaces DGA-specific artefacts (gebruikelijk-loon status, ZVW running total, FOR-saldo, lijfrente-jaarruimte)
- Generates a **clean IB-pakket handover** for accountants (jaaropgaaf + FOR + lijfrente + box-2, bundled as ZIP with manifest)

## What Changes

Extends HRMQ with a **single-person mode** toggle at the organisation level. The mode:

- **Data model:** Adds `hrmq_organisation.mode` (enum: `standard`, `dga_single_person`), locks the organisation to exactly one employee (`dga_employee_id`), and introduces `hrmq_dga_profile` (1:1 with the DGA employee) for fiscal metadata (gebruikelijk-loon norm, FOR-saldo, lijfrente-polissen, aanmerkelijk-belang).
- **UI shell:** Hides all multi-employee navigation (teams, leave approvals, org chart); surfaces a DGA-specific dashboard as default landing page.
- **Payroll artefacts:** Monthly loonstrook now includes DGA-specific YTD totals against the gebruikelijk-loon norm; annual jaaropgaaf is generated on demand.
- **IB-handover:** Generates a ZIP bundle (jaaropgaaf + FOR + lijfrente + aanmerkelijk-belang + manifest) uploaded to the accountant's configured deliverypath (SFTP, Nextcloud share, or email).
- **Reversibility:** Flipping back to standard mode on first hire preserves all DGA data as a read-only tab on the DGA's own employee record.
- **Accountant delegation:** External accountants can be invited with role `accountant_of_record`, gaining read-write payroll access but no access to personal data outside payroll scope.

## Capabilities

### New Capabilities

- **DGA-single-person mode toggle**: Organisation-level enum switch that locks workforce to exactly one employee and hides multi-employee menus.
- **Gebruikelijk-loon tracking**: Dashboard widget showing annual norm (default EUR 56,000, indexed yearly), YTD paid loon, projected year-end, and status indicator (green/amber/red) with audit trail on norm basis changes.
- **DGA-flavoured loonstrook**: Monthly payslip adds DGA-specific section showing YTD bruto, loonheffing, ZVW, and progress against gebruikelijk-loon norm.
- **ZVW running total**: Dashboard widget tracking year-to-date werkgeversheffing (6.57% of bijdrage-inkomen, max EUR 75,864 ceiling) and overpayment flag.
- **FOR-afbouw tracking**: Dashboard widget showing legacy Fiscale Oudedagsreserve saldo (opening balance + onttrekkingen timeline), read-only for jaren ≥ 2023.
- **Lijfrente jaarruimte**: Dashboard widget computing available aftrekruimte (formula: 30% × premiegrondslag − factor-A × 6.27, max EUR 36,077) with reserveringsruimte (10-year lookback).
- **Jaaropgaaf generation**: Annual report in Belastingdienst format (PDF + machine-readable JSON) locked once finalised; corrections require formal correctieboeking.
- **IB-pakket export**: One-click ZIP bundle (jaaropgaaf + FOR + lijfrente + aanmerkelijk-belang overzicht + manifest.json) uploaded to accountant's deliverypath with acknowledgement tracking.
- **Accountant-of-record delegation**: Role allowing external accountant to read/write payroll and jaaropgaaf, export IB-pakket, but no access to personal/non-payroll data; revocable by DGA.

### Modified Capabilities

- **Organisation settings**: Adds `mode` enum field and conditional `dga_employee_id` display.
- **Employee detail page**: Adds `hrmq_dga_profile` tab (used, aanmerkelijk-belang-percentage, gebruikelijk-loon-norm, FOR-saldo, lijfrente-polissen, box-2-dividenduitkeringen) when in DGA mode.
- **Monthly payroll dashboard**: Hides multi-employee chrome (teams, leave calendars for others, manager approvals); surfaces DGA dashboard with gebruikelijk-loon, ZVW, FOR, lijfrente widgets.
- **Jaaropgaaf flow**: Extended to include DGA-specific FOR/lijfrente/box-2 sections in JSON export.

## Impact

- **Apps affected:**
  - `hrmq` — primary app (organisation mode, DGA dashboard, UI shell)
  - `payroll-engine-nl` — used as-is; no changes to loonheffing calculation logic
  - `docudesk` — extends PDF templating for DGA-flavoured loonstrook and jaaropgaaf
  - `bank-payment-batch-sepa` — used for monthly salaris-overboeking (single-payment batch)
  - `document-template-engine` — new: IB-pakket manifest and accountant cover letter
  - Optional: `openconnector` — post-MVP Belastingdienst loonaangifte-API adapter

- **Breaking changes:** None. The mode is optional; existing multi-employee organisations remain unaffected.

- **Migration needed:** No. Single-person organisations can opt-in to DGA mode at any time. Data model extensions are additive (new columns + new tables). Existing payroll data remains unchanged.

- **Information architecture placement:** SETTING under `Configuratie › Administraties` (per ADR-001 Rule 4). No new top-level menu.

## Rationale

DGAs represent a material market opportunity (~400k in NL) currently underserved by HRMQ. The mode-switch approach (vs. a fork) keeps the data model and payroll engine unified, reduces code duplication, and creates a clear upgrade path for growing BVs: a DGA who hires their first employee simply flips back to standard mode, preserving all DGA-specific data. This ensures the migration path is **always forward** — no data loss, no mode lock-in.

For accountants managing 20–200 DGA clients, consistency is critical: one IB-pakket format across all clients, one login to switch tenants, and a delegated payroll-admin role that gives them what they need (payroll, jaaropgaaf, export) without access to personal/non-payroll data.
