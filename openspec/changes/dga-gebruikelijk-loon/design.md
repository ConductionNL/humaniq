# Design: DGA Gebruikelijk-loon Flow

**Change ID**: dga-gebruikelijk-loon  
**Authored**: 2026-05-24  
**Schema Version**: 1.0

## Architecture Overview

The DGA gebruikelijk-loon flow is a **periodic compliance engine** that runs on-demand or scheduled, examining DGA-marked medewerkers' YTD salary against statutory guideline thresholds. It is **not a real-time system** and does not modify payroll data; it reports and flags discrepancies for manual review.

### Design Principles

1. **Data-driven**: All DGA rules (threshold, eligible salary components, audit frequency) stored in OpenRegister DGA-configuration schema.
2. **Non-destructive**: Reports only; no auto-correction or payslip modification.
3. **Audit-trail required**: Every toets run logged with parameters, results, and changes flagged.
4. **Related-company aware**: Links DGA salary to vennootschap distributions for pseudo-salary detection.
5. **NL Design System compliance**: All UI uses nldesign tokens (ADR-010).

## Data Model

### Entities (OpenRegister Schemas)

#### 1. DGA-medewerker (extends Medewerker)

Marks an employee as a director-shareholder. Stored as an object in OpenRegister with reference to the Medewerker schema.

**Schema: DgaMedewerker**

```
{
  "type": "object",
  "title": "DGA Medewerker",
  "properties": {
    "medewerker_id": { "type": "string", "description": "Reference to Medewerker object ID" },
    "dga_type": { 
      "enum": ["eigenaar", "aandeelhouder", "vennoot"], 
      "description": "Type of director-shareholder role"
    },
    "ownership_percentage": { "type": "number", "minimum": 0, "maximum": 100 },
    "vennootschap_id": { "type": "string", "description": "Reference to Vennootschap object ID (optional)" },
    "designation_date": { "type": "string", "format": "date", "description": "Date DGA status began" },
    "guideline_salary_threshold": { "type": "number", "description": "Annual EUR threshold for this DGA" },
    "eligible_salary_components": { "type": "array", "items": "string", "description": "List of salary component slugs counted toward threshold" },
    "notes": { "type": "string", "description": "Admin notes (e.g., special sector, tenure)" }
  },
  "required": ["medewerker_id", "dga_type", "designation_date"]
}
```

#### 2. DGA-configuration (Global Settings)

Admin-configurable DGA ruleset.

**Schema: DgaConfiguration**

```
{
  "type": "object",
  "title": "DGA Configuration",
  "properties": {
    "threshold_year": { "type": "integer", "minimum": 2020, "description": "Applicable year (e.g., 2026)" },
    "threshold_amount_eur": { "type": "number", "minimum": 40000, "description": "Guideline salary in EUR" },
    "eligible_salary_components": { "type": "array", "items": "string", "description": "Salary component slugs that count toward threshold" },
    "audit_frequency": { "enum": ["monthly", "quarterly", "biannual", "annual"], "description": "How often to run toets" },
    "alert_thresholds": {
      "type": "object",
      "properties": {
        "warning_margin_percent": { "type": "number", "description": "Yellow flag if shortfall > X% (default: 10%)" },
        "critical_margin_percent": { "type": "number", "description": "Red flag if shortfall > X% (default: 25%)" }
      }
    },
    "detect_pseudo_salary": { "type": "boolean", "description": "Enable related-company distribution tracking" },
    "enabled": { "type": "boolean", "default": true }
  },
  "required": ["threshold_year", "threshold_amount_eur", "eligible_salary_components"]
}
```

#### 3. DGA-toets-run (Audit Run Record)

Records a single run of the gebruikelijk-loon-toets, with results and timestamp.

**Schema: DgaToetsRun**

```
{
  "type": "object",
  "title": "DGA Toets Run",
  "properties": {
    "run_date": { "type": "string", "format": "date", "description": "Date toets was run" },
    "period_end": { "type": "string", "format": "date", "description": "As-of date for YTD calculation" },
    "triggered_by": { "type": "string", "description": "User ID / system trigger" },
    "configuration_snapshot": { "type": "object", "description": "DGA-configuration as of run date" },
    "medewerker_results": { "type": "array", "description": "Array of individual medewerker results (see schema below)" },
    "flagged_count": { "type": "integer", "description": "Count of flagged (non-compliant) DGA medewerkers" },
    "report_status": { "enum": ["draft", "reviewed", "exported"], "description": "Audit state" },
    "audit_trail": { "type": "array", "description": "Log of changes to flagged items (auto-managed by audit service)" }
  },
  "required": ["run_date", "period_end", "triggered_by"]
}
```

**Sub-object: DgaToetsResult (array item)**

```
{
  "type": "object",
  "properties": {
    "medewerker_id": { "type": "string" },
    "medewerker_name": { "type": "string" },
    "threshold_eur": { "type": "number" },
    "ytd_bruto_eur": { "type": "number" },
    "remaining_margin_eur": { "type": "number", "description": "Positive = compliant; negative = shortfall" },
    "remaining_margin_percent": { "type": "number" },
    "flag_status": { "enum": ["green", "yellow", "red"], "description": "Compliance status" },
    "last_salary_month": { "type": "string", "format": "date", "description": "Month of most recent payslip" },
    "vennootschap_distribution_yod_eur": { "type": "number", "description": "Sum of dividends + fees this year" },
    "pseudo_salary_risk": { "enum": ["none", "low", "medium", "high"], "description": "Related-company distribution risk flag" },
    "notes": { "type": "string" }
  },
  "required": ["medewerker_id", "threshold_eur", "ytd_bruto_eur"]
}
```

#### 4. Vennootschap (Related Company)

Existing schema extended with DGA-related fields. Already managed by OpenRegister.

**Extensions to existing Vennootschap schema:**
- Add field: `dga_medewerkers` (array of DGA-medewerker object IDs for display)
- Add field: `dividend_policy` (enum: "conservative" | "standard" | "aggressive") for risk scoring

### Data Relationships (OpenRegister Relations)

- **Medewerker ↔ DGA-medewerker**: 1:1 (optional; only if DGA-marked)
- **DGA-medewerker ↔ Vennootschap**: 1:many (one DGA can have multiple holding structures)
- **DGA-toets-run → Medewerker (multiple)**: References via `medewerker_results` array
- **DGA-configuration → DGA-toets-run**: Referenced in run snapshot (immutable copy at run time)

### Seed Data

**DgaConfiguration (3 variants)**

```
{
  "@self": {
    "register": "hrmq_settings",
    "schema": "DgaConfiguration",
    "slug": "dga-nl-2026-standard"
  },
  "threshold_year": 2026,
  "threshold_amount_eur": 56000,
  "eligible_salary_components": ["base_salary", "bonus", "allowance_housing"],
  "audit_frequency": "monthly",
  "alert_thresholds": {
    "warning_margin_percent": 10,
    "critical_margin_percent": 25
  },
  "detect_pseudo_salary": true,
  "enabled": true
}
```

```
{
  "@self": {
    "register": "hrmq_settings",
    "schema": "DgaConfiguration",
    "slug": "dga-nl-2026-conservative"
  },
  "threshold_year": 2026,
  "threshold_amount_eur": 60000,
  "eligible_salary_components": ["base_salary"],
  "audit_frequency": "quarterly",
  "alert_thresholds": {
    "warning_margin_percent": 15,
    "critical_margin_percent": 30
  },
  "detect_pseudo_salary": true,
  "enabled": false
}
```

**DGA-medewerker (3 examples)**

```
{
  "@self": {
    "register": "hrmq_medewerkers",
    "schema": "DgaMedewerker",
    "slug": "dga-anne-bakker"
  },
  "medewerker_id": "uuid:employee:001",
  "dga_type": "eigenaar",
  "ownership_percentage": 100,
  "vennootschap_id": "uuid:vennootschap:bakkerij-innovatie",
  "designation_date": "2024-01-01",
  "guideline_salary_threshold": 56000,
  "eligible_salary_components": ["base_salary", "bonus", "allowance_housing"],
  "notes": "Bakery sector; high seasonal variance"
}
```

```
{
  "@self": {
    "register": "hrmq_medewerkers",
    "schema": "DgaMedewerker",
    "slug": "dga-jan-visser"
  },
  "medewerker_id": "uuid:employee:002",
  "dga_type": "aandeelhouder",
  "ownership_percentage": 75,
  "vennootschap_id": "uuid:vennootschap:consultancy-nl",
  "designation_date": "2020-06-15",
  "guideline_salary_threshold": 56000,
  "eligible_salary_components": ["base_salary", "bonus"],
  "notes": "Co-founder; receives management fee from holding"
}
```

```
{
  "@self": {
    "register": "hrmq_medewerkers",
    "schema": "DgaMedewerker",
    "slug": "dga-petra-jong"
  },
  "medewerker_id": "uuid:employee:003",
  "dga_type": "vennoot",
  "ownership_percentage": 50,
  "vennootschap_id": "uuid:vennootschap:architecten-bv",
  "designation_date": "2023-03-01",
  "guideline_salary_threshold": 56000,
  "eligible_salary_components": ["base_salary"],
  "notes": "Equal partner in design firm"
}
```

**DGA-toets-run (1 example)**

```
{
  "@self": {
    "register": "hrmq_audit",
    "schema": "DgaToetsRun",
    "slug": "dga-toets-may-2026"
  },
  "run_date": "2026-05-24",
  "period_end": "2026-05-31",
  "triggered_by": "user:hr-admin:789",
  "configuration_snapshot": {
    "threshold_year": 2026,
    "threshold_amount_eur": 56000,
    "eligible_salary_components": ["base_salary", "bonus", "allowance_housing"],
    "audit_frequency": "monthly"
  },
  "medewerker_results": [
    {
      "medewerker_id": "uuid:employee:001",
      "medewerker_name": "Anne Bakker",
      "threshold_eur": 56000,
      "ytd_bruto_eur": 23500,
      "remaining_margin_eur": 32500,
      "remaining_margin_percent": 58,
      "flag_status": "green",
      "last_salary_month": "2026-05-31",
      "vennootschap_distribution_yod_eur": 0,
      "pseudo_salary_risk": "none",
      "notes": ""
    },
    {
      "medewerker_id": "uuid:employee:002",
      "medewerker_name": "Jan Visser",
      "threshold_eur": 56000,
      "ytd_bruto_eur": 18000,
      "remaining_margin_eur": 38000,
      "remaining_margin_percent": 68,
      "flag_status": "green",
      "last_salary_month": "2026-05-31",
      "vennootschap_distribution_yod_eur": 12000,
      "pseudo_salary_risk": "low",
      "notes": "Receiving management fee from holding; monitor"
    },
    {
      "medewerker_id": "uuid:employee:003",
      "medewerker_name": "Petra Jong",
      "threshold_eur": 56000,
      "ytd_bruto_eur": 15500,
      "remaining_margin_eur": 40500,
      "remaining_margin_percent": 72,
      "flag_status": "green",
      "last_salary_month": "2026-05-31",
      "vennootschap_distribution_yod_eur": 0,
      "pseudo_salary_risk": "none",
      "notes": ""
    }
  ],
  "flagged_count": 0,
  "report_status": "draft",
  "audit_trail": []
}
```

## Backend Architecture

### Services

**DgaComplianceService**  
- `calculateYtdSalary(medewerker_id, period_end): number`
- `runToets(configuration, period_end): DgaToetsRun`
- `flagNonCompliant(result, configuration): flag_status`
- `detectPseudoSalary(dga_id, vennootschap_id, period): risk_level`

**DgaConfigurationService**  
- `getActiveConfiguration(year): DgaConfiguration`
- `updateConfiguration(payload): DgaConfiguration`
- `importDefaultThresholds(year): void`

**DgaMedewerkerService**  
- `markAsDga(medewerker_id, dga_type, vennootschap_id): DgaMedewerker`
- `unmarkDga(medewerker_id): void`
- `listDgaMedewerkers(filter): DgaMedewerker[]`

**DgaReportService**  
- `generatePdfReport(toets_run_id): bytes`
- `generateExcelReport(toets_run_id): bytes`
- `exportToAuditor(toets_run_id): bytes`

### Integration Points

- **SalarisrunService** (payroll-core-basic): Fetch completed salary runs, calculate YTD
- **MedewerkerService**: Query DGA markers, contract history
- **ObjectService** (OpenRegister): CRUD for DGA-medewerker, DGA-toets-run objects
- **AuditTrailService**: Log toets runs and configuration changes
- **FileService**: Store generated PDFs/Excel exports

## Frontend Architecture

### Pages & Components

**Configuratie / DGA-regels** (SETTING page)  
- Route: `/apps/hrmq/settings/dga-configuration`
- Component: `CnDetailPage` with form for DGA configuration
- Fields: threshold year, amount (EUR), eligible components, audit frequency, alert thresholds
- Actions: Save, Import defaults, Test run

**Medewerker Detail — DGA Tab** (DETAIL_TAB)  
- Route: `/apps/hrmq/medewerker/{id}` → "DGA" tab
- Component: Form to mark/unmark DGA, set ownership type, link vennootschap
- Display: Current threshold, YTD status, pseudo-salary risk badge
- Actions: Run toets for this medewerker, export compliance history

**Salarissen / DGA-toets Report** (SUB_PAGE)  
- Route: `/apps/hrmq/payroll/dga-reports`
- Component: `CnDetailPage` listing recent toets runs
- Display: Date, period-end, flagged count, status
- Actions: View report, download PDF/Excel, edit notes, mark reviewed
- Drill-down: Click run → detail view with per-medewerker results

**DGA-medewerker List** (Widget or filtered list)  
- Accessible from Medewerkers > search/filter by "DGA"
- Display: Name, ownership type, threshold, YTD, flag status
- Quick actions: Run toets, view compliance history

## UI/UX Decisions

1. **Status badges** (green/yellow/red) use NL Design System color tokens (`--nldesign-status-success`, `--nldesign-status-warning`, `--nldesign-status-error`)
2. **Report download**: Export PDF with company letterhead; Excel with per-medewerker rows + audit note column
3. **Pseudo-salary risk**: Displayed as an icon + tooltip ("Distribution + salary change in same month")
4. **Configuration defaults**: Pre-load 2026 EUR 56,000 threshold; allow override per tenant

## Reuse Analysis

- **ObjectService** (OpenRegister): Used for DGA-medewerker, DGA-configuration, DGA-toets-run CRUD
- **CnDetailPage**: Used for configuration page and report details
- **CnDataTable**: Used for medewerker results grid
- **AuditTrailService**: Automatic change tracking on all objects
- **FileService**: Store/retrieve PDF/Excel exports
- **ImportService/ExportService**: CSV import for bulk DGA marking (future enhancement)

No custom CRUD, search, or state management required — all provided by OpenRegister platform.

## Non-Functional Requirements

- **Performance**: Toets run for 100 DGA medewerkers completes in < 5s (YTD calculation cached)
- **Availability**: Toets is async; does not block payroll processing
- **Audit**: Every run, parameter change, and flag edit logged with user/timestamp
- **Security**: Only payroll admins can run toets or export reports; read-only access for HR managers
- **Accessibility**: WCAG AA; all status indicators have text labels; keyboard-navigable tables
