# Annual Compensation Planning Cycle — Design

**Spec:** comp-planning-cycle  
**App:** hrmq  
**Version:** 0.1.0  
**Date:** 2026-05-23

## Information Architecture

**Placement:** `DETAIL_TAB` on Medewerkers › Functie & comp  
**Rationale (ADR-001, Rule 6):** Performance and compensation are personnel-dossier anchors, not standalone modules. Detail-tab placement avoids a 10th top-level menu and keeps manager/compensation context native to the employee record.

## Data Model

All entities are **OpenRegister objects** (no custom Entity/Mapper per ADR-001).

### CompCycle
Orchestrates a single annual compensation round. Top-level aggregator for budget, proposals, workflow state, pay-equity-audits, letter-generation, and payroll-effectuation.

```json
{
  "@self": {
    "register": "hrmq",
    "schema": "CompCycle",
    "slug": "comp-2025-nlm-jan01"
  },
  "cyclus_id": "comp-2025-nlm",
  "jaar": 2025,
  "effectief_per": "2026-01-01",
  "status": "planning",
  "totaal_loonsverhoging_budget_pct": 3.5,
  "totaal_bonus_budget_pct": 2.0,
  "pay_equity_check_status": "scheduled",
  "transparency_disclosure_brief_url": "s3://docs/comp-2025-disclosure.pdf"
}
```

### BudgetAllocatie
Allocates a share of the top-down budget to a manager or cost-center. Tracks spend vs. allocation; flags overages.

```json
{
  "@self": {
    "register": "hrmq",
    "schema": "BudgetAllocatie",
    "slug": "budget-2025-nlm-engineering"
  },
  "cyclus_ref": "comp-2025-nlm",
  "kostenplaats_of_unit_ref": "cost-unit-eng-nl",
  "verantwoordelijke_manager_ref": "emp-mjvdbergh",
  "loonsverhoging_budget_eur": 125000,
  "bonus_budget_eur": 45000,
  "besteed_loonsverhoging_eur": 120500,
  "besteed_bonus_eur": 44200,
  "restant_eur": 5300,
  "over_budget_flag": false
}
```

### CompVoorstelEmployee
Manager's proposal for a single employee: salary-increase %, bonus €, promotion intent, compa-ratio post-increase, and workflow state.

```json
{
  "@self": {
    "register": "hrmq",
    "schema": "CompVoorstelEmployee",
    "slug": "comp-prop-2025-emp-adevries"
  },
  "cyclus_ref": "comp-2025-nlm",
  "employee_ref": "emp-adevries",
  "huidige_salaris": 65000,
  "huidige_band_ref": "band-sa-2",
  "huidige_compa_ratio": 0.98,
  "performance_input_ref": "perf-2025-emp-adevries",
  "voorgestelde_loonsverhoging_pct": 4.0,
  "voorgestelde_loonsverhoging_eur": 2600,
  "nieuw_salaris": 67600,
  "nieuwe_compa_ratio": 1.02,
  "voorgestelde_bonus_eur": 3200,
  "promotie_voorstel_bool": false,
  "equity_flag": false,
  "manager_onderbouwing": "Consistent strong performer; market-rate adjustment for senior experience.",
  "status": "hrbp-review"
}
```

### SalarisBand
Reference data defining salary ranges per functional family and level. Mid-point represents target compa-ratio 1.0.

```json
{
  "@self": {
    "register": "hrmq",
    "schema": "SalarisBand",
    "slug": "band-sa-2-hay"
  },
  "band_code": "SA-2",
  "functie_familie": "Software Architecture",
  "niveau": 2,
  "min_eur": 58000,
  "mid_eur": 65000,
  "max_eur": 78000,
  "valuta": "EUR",
  "geldig_per_datum": "2025-01-01",
  "bron": "Hay"
}
```

### PayEquityCheck
Audit result for a salary band across a demographic dimension (gender, age, nationality). Auto-generated pre-HR-BP sign-off.

```json
{
  "@self": {
    "register": "hrmq",
    "schema": "PayEquityCheck",
    "slug": "equity-2025-band-sa-2-gender"
  },
  "cyclus_ref": "comp-2025-nlm",
  "dimensie": "gender",
  "band_ref": "band-sa-2-hay",
  "groep_a_label": "Female",
  "groep_a_gemiddelde_eur": 62000,
  "groep_b_label": "Male",
  "groep_b_gemiddelde_eur": 67500,
  "gap_pct": 8.1,
  "gap_signaal": "rood",
  "actie_aanbeveling": "Review proposals in SA-2 to ensure no gender-correlated raise-reductions; consider targeted increase for underrepresented group or document justified differential."
}
```

### CompensationLetter
Generated employee document with old/new salary, raise %, bonus, promotion (optional), effective-date. Tracks verstuurd-date and employee-acknowledgment.

```json
{
  "@self": {
    "register": "hrmq",
    "schema": "CompensationLetter",
    "slug": "letter-2025-emp-adevries-nl"
  },
  "cyclus_ref": "comp-2025-nlm",
  "employee_ref": "emp-adevries",
  "letter_versie": 1,
  "oud_salaris": 65000,
  "nieuw_salaris": 67600,
  "loonsverhoging_pct": 4.0,
  "bonus_eur": 3200,
  "promotie_text_optional": null,
  "effectief_per": "2026-01-01",
  "gegenereerd_datum": "2025-12-15",
  "verstuurd_datum": "2025-12-16",
  "acknowledged_door_employee_datum": "2025-12-18",
  "pdf_url": "s3://docs/letter-2025-adevries-v1.pdf"
}
```

## Seed Data (6 entities, realistic Dutch values)

### Employees (from employee-master reference)
```
- emp-jgronewagen: Manager, Engineering, €75k, band-eng-3
- emp-mjvdbergh: Manager, Finance, €68k, band-fin-2
- emp-adevries: IC, Engineering, €65k, band-sa-2
- emp-sjongen: IC, HR, €58k, band-hr-1
- emp-pvandenbosch: Director, €92k, band-dir-1
```

### SalarisBand (3 levels)
```
band-sa-2 (Software Architecture, Level 2):
  min: €58k, mid: €65k, max: €78k (Hay)

band-fin-2 (Finance, Level 2):
  min: €54k, mid: €62k, max: €74k (Hay)

band-eng-3 (Engineering Management, Level 3):
  min: €70k, mid: €82k, max: €98k (Hay)
```

### CompCycle (2025)
```
comp-2025-nlm:
  jaar: 2025
  effectief_per: 2026-01-01
  status: planning
  totaal_loonsverhoging_budget_pct: 3.5
  totaal_bonus_budget_pct: 2.0
```

### BudgetAllocatie (3 units)
```
Engineering unit (mgr: jgronewagen):
  loonsverhoging_budget: €250k (55 FTE × 3.5%)
  bonus_budget: €90k

Finance unit (mgr: mjvdbergh):
  loonsverhoging_budget: €180k (40 FTE × 3.5%)
  bonus_budget: €65k

HR unit (mgr: sjongen):
  loonsverhoging_budget: €65k (15 FTE × 3.5%)
  bonus_budget: €22k
```

### CompVoorstelEmployee (4 proposals)
```
emp-adevries (Engineering):
  huidige_salaris: €65k, compa_ratio: 0.98
  voorstel: +4% = €2,600 (market-rate, strong performer)
  bonus: €3,200
  status: hrbp-review

emp-sjongen (HR):
  huidige_salaris: €58k, compa_ratio: 0.95
  voorstel: +3.5% = €2,030 (meets expectations)
  bonus: €1,500
  status: manager-submit

emp-pvandenbosch (Director):
  huidige_salaris: €92k, compa_ratio: 1.08
  voorstel: +2.5% = €2,300 (stable senior, on-band outlier but justified by seniority)
  promotie: no
  bonus: €12,000
  status: committee-review

emp-jgronewagen (Manager):
  huidige_salaris: €75k, compa_ratio: 0.92
  voorstel: +4.2% = €3,150 (internal growth, promotion-track)
  promotie: yes (eng-3 promotion to lead-eng-1)
  bonus: €5,400
  status: cfo-approved
```

### PayEquityCheck (Example from cycle-start)
```
band-sa-2, gender:
  female avg: €62k (7 employees)
  male avg: €67.5k (12 employees)
  gap: 8.1% (rood flag)
  recommendation: Review proposals; targeted raise for underrep group

band-fin-2, age:
  30-40yo avg: €61k (8 employees)
  40+ yo avg: €64k (6 employees)
  gap: 4.8% (geel flag)
  recommendation: Document if justified by seniority/tenure
```

## Reuse Analysis (ADR-022, ADR-031)

| Capability | Provider | Reuse |
|-----------|----------|-------|
| CRUD on CompCycle, BudgetAllocatie, etc. | ObjectService | ✓ No custom mapper; all entities in OpenRegister |
| List + filter (proposals, cycles) | IndexService + CnFilterBar | ✓ Schema-driven indexing |
| Workflow state transitions | `x-openregister-lifecycle` | ✓ Lifecycle declaration in register (see tasks.md) |
| Pay-equity aggregations | `x-openregister-aggregations` | ✓ PayEquityCheck computation as aggregation |
| Compa-ratio calculation | `x-openregister-calculations` | ✓ Derived field on CompVoorstelEmployee |
| Workflow task creation | TasksController | ✓ Task-on-transition (status changes) |
| Email notifications | `x-openregister-notifications` | ✓ Template-driven, recipient resolution |
| PDF letter generation | FileService + document-template-svc | Custom: domain-specific letter template + vars |
| Payroll mutation batching | payroll-engine-nl adapter | Custom: mutation marshalling, two-stage approval |
| Audit trail | AuditTrailService | ✓ Automatic on object-save |
| Employee transparency | RBAC + audit logging | ✓ PropertyRbacHandler + AuditTrailService |

## Declarative-vs-Imperative Decision (ADR-031)

### Declarative (x-openregister-* extensions)
- **Workflow state machine** (manager-submit → hrbp-review → committee → cfo → letters → payroll): `x-openregister-lifecycle` in register with transition-guards
- **Pay-equity aggregations** (gender/age/nationality avg per band): `x-openregister-aggregations`
- **Compa-ratio calculation** (post-increase, new_compa_ratio): `x-openregister-calculations` + `@self.nouvelle_salaris` / band-mid
- **Budget tracking** (restant_eur, over_budget_flag): calculated field per BudgetAllocatie
- **Status-transition notifications** (manager-submitted, hrbp-returned, etc.): `x-openregister-notifications` with template + recipient-resolver
- **Workflow tasks** (task-per-status for managers, HR-BP, CFO): TasksController via lifecycle-transition event

### Imperative (PHP service)
- **Compensation letter generation** — domain-specific PDF template with variable substitution (oud-salaris, %, nieuw-salaris, bonus, effective-date, organisatie-naam, CFO-ondertekening). No OR extension for rendered output.
- **Payroll mutation batch marshalling** — maps CompVoorstelEmployee (approved) → payroll-engine-nl mutation schema (salary-code, old-amt, new-amt, effective-date, cost-center). Two-stage approval (HR submit, Finance verify) before dispatch.
- **Equity remediation logic** (optional, Phase 2) — when gap >5%, route to exception-handler with suggested corrective raises per band. Domain rule-engine consulting the cycle's proposal-set.

## Integration Points

### Inbound
- **employee-master:** getCurrentSalary(), getManager(), getRole(), getWerkplekband()
- **payroll-engine-nl:** getEmployeeWorkingAgreement(), getPaycodeMapping()
- **performance-management-advanced:** getPerformanceOutcome(employee, fiscal-year)
- **finance-export:** getTopDownBudget(org-unit, fiscal-year)

### Outbound
- **payroll-engine-nl:** submitMutationBatch(mutations[], effective-date) → mutation-ID
- **document-storage:** archiveCompensationLetter(pdf-stream, metadata)
- **task-management:** createWorkflowTask(employee, action, deadline)
- **audit-log:** logStatusTransition(object, old-status, new-status, actor, timestamp, notes)
- **notifications:** dispatchCompensationNotification(employee, letter-url, ack-deadline)

## Limitations & Exceptions

- **Seed data:** OpenRegister's `ImportHandler` supports flat objects only. Related-items linking (files, notes, contacts) is pending `seed-related-items` change. Seed data in `design.md` is object-property only.
- **Custom letter service:** OR provides no declarative document-generation extension; PDF rendering is domain-specific and requires a PHP service (`CompensationLetterGenerationService`).
- **Payroll mutation marshalling:** payroll-engine-nl has its own schema; mapping is app-local (`PayrollMutationAdapter`). No shared OR abstraction yet.

---

**Next:** specs.md (detailed requirements with GIVEN/WHEN/THEN scenarios)
