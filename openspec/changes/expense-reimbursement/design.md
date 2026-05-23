---
status: design
created: 2026-05-23
---

# Design: Onkostendeclaratie Declaratie Entity & Approval Workflow

## Context

hrmq treats declaratie-management as a core HR workflow: employees submit receipts, management approves them, and the system routes them to either shillinq (AP module) or payroll-engine-nl (as bijtelling). The workflow must satisfy three overlapping concerns:

1. **Fiscal compliance**: WKR-classificatie is not optional—every approved declaratie must be correctly classified or the employer faces Belastingdienst penalties.
2. **Workflow efficiency**: The mobile scan-to-submit path must complete in ≤30 seconds or employees revert to the bonnetjes-in-schoenendoos pattern.
3. **Data integrity**: Duplicate declaraties, missing receipts, and mismatched amounts must be detected at submission time, not discovered during audit.

The system models a `Declaratie` as an immutable lifecycle with three phases:

- **DRAFTING** (`ingediend_op` null, `status: bewerken`) — user filling form, can discard
- **PENDING_APPROVAL** (`ingediend_op` set, `status: wacht_op_approval`) — waiting for 1-3 approval steps
- **COMPLETED** (`goedgekeurd_op` or `afgewezen_op` set, `status: goedgekeurd|afgewezen`) — routed to shillinq/payroll or rejected

## Goals

**Primary goals:**
- Provide a fiscally compliant declaratie-capture pipeline that reduces approval time from days to hours
- Enforce WKR-classificatie at submission time to catch mis-classifications before they reach accounting
- Maintain an auditable trail of every declaratie state-change (submission, OCR, corrections, approvals, routing) for Belastingdienst review
- Support the high-volume, low-latency mobile scan flow without sacrificing data quality

**Secondary goals:**
- Automatically detect duplicate receipts (SHA-256 hash + date proximity)
- Provide a WKR-budget dashboard so finance can forecast year-end overspend (requiring 80% eindheffing or WKR-reclassification)
- Support kilometer-vergoeding in three input modes (manual, GPS-tracked with consent, bulk CSV)
- Accept declaraties in foreign currency with automatic ECB rate lookup

## Non-Goals

- **Zakelijke creditcard administratie** — out of scope; credit-card statement reconciliation is a shillinq concern
- **Zakelijke reizen-boekingsproces** — out of scope; travel booking happens separately; only the per-diem/hotel receipt declaratie is in scope
- **Facturen doorbelasten aan klanten** — AR routing is out of scope; only employee-reimbursement
- **BTW-aangifte zelf** — not in scope; only the BTW-metadata is captured so shillinq can generate the aangifte

## Key Decisions

### Decision 1: Immutable Audit Trail

**Choice:** Every `Declaratie` state-change (submission, correction, approval step, escalation, routing, payment, payroll-run link) is recorded in an immutable `audit_trail_id` sub-document. No updates retroactively hide the history.

**Rationale:** Belastingdienst audit trails must be complete and unambiguous. Immutability forces correctness over apparent convenience.

**Tradeoff:** Requires careful cascade-delete handling during tests; mitigated by marking `Declaratie` as permanently archived after export (90-day archival window before delete).

### Decision 2: OCR Confidence + Manual Override

**Choice:** docudesk OCR produces a `ocr_confidence` score (0.0–1.0). If confidence < 0.80, the form flags the field and requires manual review. On override, set `gecorrigeerd_door_gebruiker: true` in the field metadata for audit.

**Rationale:** OCR accuracy on worn receipts (faded date, blurred amount) is unreliable. A low threshold (0.80) catches obvious garbage while accepting near-legible scans; manual correction is the correction mechanism, not a system failure.

**Tradeoff:** Adds a second form-fill burden for blurry scans; mitigated by making the "approve as-is" one-click for high-confidence OCR.

### Decision 3: WKR-Classificatie Suggestion, Not Default

**Choice:** The form suggests a default WKR-class based on the category + Belastingdienst handreiking version + prior declaraties from the same leverancier. The suggestion is always editable and the approver MUST explicitly choose the final class. No silent defaults.

**Rationale:** Belastingdienst guidance changes year-to-year (telewerkvergoeding caps, new gerichte vrijstellingen). Baking a default into code leads to stale logic. A suggestion + explicit choice keeps fiscal responsibility transparent.

**Tradeoff:** One extra field per declaratie; mitigated by pre-populating from prior declaraties from the same vendor (MRU logic).

### Decision 4: Configurable Multi-Step Approval per BU

**Choice:** Each business-unit defines approval-workflow rules at the BU level (settings), with rules triggering on:
- Amount threshold (e.g., <€50 → 1 step, €50–€500 → 2 steps, >€500 → 3 steps)
- Category (e.g., "opleidingen" → always 3-step; "representatie" → 2-step if >€100)
- WKR-classificatie (e.g., "vrije-ruimte" + >€200 → auto-finance-review)
- Employee role (e.g., director → 1-step, medewerker → 2-step)

Each approval-step is held in an `ApprovalStap` record with status (wacht | goedgekeurd | afgewezen). On age > 5 workdays, auto-escalate to the next level or finance.

**Rationale:** MKB approval-rules vary wildly by industry and company culture. Allowing configuration (without requiring code changes) supports this diversity.

**Tradeoff:** Requires a UI for rule-definition (not in this spec; belongs to configuratie-module). Initial implementation hardcodes one default workflow; configuration UI is a follow-on.

### Decision 5: Kilometer Tariff as Indexated Config

**Choice:** The system stores a `KilometerTarief` config record per calendar-year with:
- `tarief_belastingvrij_per_km` (€0.23 for 2026, €0.21 for 2027 per Belastingplan)
- `tarief_belast_per_km` (normally €0.00; only for rates > tarief_belastingvrij)

On submit of a kilometer-rit, fetch the tarief for that rit's year and split the amount: belastingvrij = rit_km × tarief_vrij, belast = (rit_km × rate_claimed) − belastingvrij.

**Rationale:** Tariffs are set by law, not operational choice. Storing them as config (not hardcoded) allows rapid index-updates in January without code deploy.

**Tradeoff:** Requires pre-population of tarieven for future years (e.g., 2027 must be configured before 2027-01-01 to avoid Jan 1 failures). Mitigated by a calendar pre-fill job in December.

### Decision 6: Foreign Currency: ECB Lookup, Not Manual Entry

**Choice:** When a declaratie has `valuta ≠ EUR`, the system auto-calls openconnector to fetch the ECB reference-rate for that declaratie's `datum_uitgave`. If the rate is available, it's used; if not (exotisch currency), the user is prompted for a manual rate + source. Both original + EUR amounts are stored and shown.

**Rationale:** ECB rates are authoritative for Dutch tax purposes. Manual rates introduce auditor friction; only use them for edge cases.

**Tradeoff:** Requires openconnector availability; if openconnector is down, declaratie-submission is blocked (not ideal). Mitigated by a fallback: if ECB rate is unavailable, prompt user for rate and allow submission with a warning that the rate must be confirmed before approval.

### Decision 7: GPS-Tracking Requires Per-Rit Consent

**Choice:** GPS-based kilometer-tracking is available only if the employee explicitly enables it for each trip. The system logs GPS coordinates only for approved trips; unapproved trips are immediately deleted and the coordinates never leave the phone. "Mark as private" immediately deletes the GPS trace.

**Rationale:** GDPR/AVG compliance requires explicit consent for location tracking. Passive tracking without per-rit approval violates GDPR art. 6 (legitimate interest) in the context of an employee relationship.

**Tradeoff:** Per-rit consent is a friction point (1 tap per trip, vs continuous passive tracking). Justified by legal compliance.

### Decision 8: WKR-Budget Tracking with Prognosis

**Choice:** The system maintains a `WKRBudget` record per calendar-year with:
- `vrije_ruimte_beschikbaar` — calculated from `loonsom_grondslag × percentage` (1.92% of first €400k + 1.18% above)
- `vrije_ruimte_verbruikt_ytd` — running sum of approved declaraties classified as "vrije_ruimte"
- `vrije_ruimte_verbruikt_pct` — 100 × (verbruikt / beschikbaar)
- Warnings sent at 75% and 100%; year-end calculation of 80% eindheffing on overage

Additionally, a **prognosis feature** extracts approve declaraties in the last month, extrapolates forward to Dec 31, and calculates "likely to exceed Y/N" — available to finance in a dashboard (not in this spec; belongs to WKR-overzicht module).

**Rationale:** December is a high-spending month (year-end bonuses, jubilees, Christmas parties). Without proactive prognosis (Sept–Oct), finance discovers overspend on Dec 28 with no time to respond. Prognosis enables Sept forecasting and conscious WKR-reclassification decisions.

**Tradeoff:** Prognosis requires a predictive model (simple monthly extrapolation vs seasonal ML). Initial implementation uses naive extrapolation; improvement is future work.

## Seed Data

Example entity instances (Dutch values, 2026-05-12):

### Declaratie (bonnetje example)

```json
{
  "id": "dec_01KCDE567HIJ",
  "employee_id": "emp_01HXYW987DEF",
  "soort": "bonnetje",
  "categorie": "verblijf",
  "subcategorie": "diner_zakelijke_relatie",
  "wkr_classificatie": "gerichte_vrijstelling",
  "wkr_grondslag": "art_31a_lid_2_letter_b_wet_lb",
  "datum_uitgave": "2026-05-12",
  "bedrag_incl_btw": 87.50,
  "btw_bedrag": 7.21,
  "btw_tarief": 9.0,
  "valuta": "EUR",
  "valuta_koers_eur": 1.0,
  "leverancier": "Restaurant De Kas",
  "leverancier_btw_nummer": "NL001234567B01",
  "omschrijving": "Diner met klant X over project Y",
  "deelnemers": ["Werknemer", "Contactpersoon Klant X"],
  "zakelijk_doel": "Voortgangsbespreking project Y",
  "bonnetje_document_id": "doc_bon_01KCDE",
  "ocr_confidence": 0.94,
  "status": "wacht_op_approval",
  "huidige_approval_stap": 1,
  "totaal_approval_stappen": 1,
  "ingediend_op": "2026-05-13T08:22:00+02:00",
  "goedgekeurd_op": null,
  "afgewezen_op": null,
  "uitbetaald_op": null,
  "verwerkt_in_run_id": null,
  "audit_trail_id": "aud_dec_01KCDE"
}
```

### KilometerRit (manual entry)

```json
{
  "id": "km_01KCDG12",
  "declaratie_id": "dec_01KCDE567IJK",
  "datum": "2026-05-10",
  "vertrek_adres": "Lauriergracht 14h, Amsterdam",
  "aankomst_adres": "Hoofdstraat 22, Utrecht",
  "afstand_km": 47.3,
  "afstand_bron": "openconnector_geokeyed_v2",
  "zakelijk_doel": "Klantbezoek X",
  "passagiers": 0,
  "tarief_belastingvrij_per_km": 0.23,
  "tarief_belast_per_km": 0.00,
  "bedrag_belastingvrij": 10.88,
  "bedrag_belast": 0.00,
  "tracking_methode": "handmatig",
  "gps_log_id": null
}
```

### VasteVergoeding (telewerkvergoeding)

```json
{
  "id": "vv_01KCDH34",
  "employee_id": "emp_01HXYW987DEF",
  "soort": "telewerkvergoeding",
  "bedrag_per_maand": 41.00,
  "wkr_classificatie": "gerichte_vrijstelling",
  "wkr_grondslag": "art_31a_telewerkvergoeding_2026",
  "ingangsdatum": "2026-01-01",
  "einddatum": null,
  "steekproef_laatste": "2025-09-15",
  "steekproef_volgende_vereist_voor": "2026-09-15",
  "onderbouwing_document_id": "doc_steek_01KCDH"
}
```

### WKRBudget (2026)

```json
{
  "id": "wkr_2026",
  "kalenderjaar": 2026,
  "loonsom_grondslag": 845000.00,
  "vrije_ruimte_percentage_eerste_400k": 1.92,
  "vrije_ruimte_percentage_boven_400k": 1.18,
  "vrije_ruimte_beschikbaar": 13931.00,
  "vrije_ruimte_verbruikt_ytd": 4218.55,
  "vrije_ruimte_verbruikt_pct": 30.28,
  "waarschuwing_75pct_verzonden": false,
  "waarschuwing_100pct_verzonden": false,
  "eindheffing_verschuldigd": 0.00
}
```

