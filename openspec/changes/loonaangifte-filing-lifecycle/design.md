# Design — loonaangifte-filing-lifecycle

## Context

`LoonaangifteFiling` (in `lib/Settings/register.d/hr-objects.json`) is a passive record: `period`, `jurisdiction`, `filingType`, `tijdvak`, `deadline`, `submittedDate`, `paidDate`, `electronicallyFiled` + DE/FR/US fields. The existing rule corpus already has `nl-loonaangifte-tijdvak` and `nl-loonaangifte-termijn` (statement-level); `NlWageTaxFilingChecks.php` audits submitted-before-deadline. What is missing is the *workflow*: no status, no state machine, no tijdvakcode, no derivation/alerting of the statutory deadline, and no lifecycle actions on the pages.

Market reference (verified 2026-07-12, Spectr `hrmq-src-loket-apg`, `hrmq-src-tijdvakcodes`):
- Loket.nl models filings as create → review → confirm → send, and surfaces official response messages next to the filing.
- Belastingdienst LH 210 (2026): monthly tijdvakcodes `6010`(Jan)…`6120`(Dec), four-weekly `6710`…`6840`, year `6400`; deadline is exactly one calendar month after period end, **no weekend/holiday extension** (28-02-2026, a Saturday, stands).

## Goals / Non-Goals

**Goals:** declarative lifecycle; tijdvakcode as first-class data; deadline correctness + alerting as versioned machine-checkable rules; lifecycle actions + deadline KPI on the existing pages; response capture fields.

**Non-Goals:** Digipoort transport, Gegevensspecificaties XML rendering, payroll calculation, DE/FR/US lifecycle parity (fields untouched, checks jurisdiction-guarded to NL).

## Decisions

### D1 — Lifecycle states are Dutch domain terms

`status`: `concept → klaargezet → bevestigd → verzonden`, plus terminal-ish response outcomes recorded in `responseStatus` (not extra states). Transitions:

| action | from | to | notes |
|---|---|---|---|
| `klaarzetten` | concept | klaargezet | review-ready; requires `period`, `tijdvak`, `tijdvakcode`, `deadline` present (schema-required + check) |
| `bevestigen` | klaargezet | bevestigd | confirm for dispatch |
| `verzenden` | bevestigd | verzonden | stamps `submittedDate` on the carrying write; `electronicallyFiled` set by the writer |
| `heropenen` | klaargezet, bevestigd | concept | corrections before send |
| `corrigeren` | verzonden | concept | a sent filing can be superseded by a correction (NL allows correcties binnen het tijdvak); description documents that a NEW filing object for the same period is the usual route |

Rationale: mirrors Timesheet/Expense lifecycles already in the register (same renderer + `lifecycleActions` widget just work); Dutch labels match the app's NL-first UI. No guard classes in this change (separation-of-duties on filings is not a statutory requirement the corpus asserts; the active `hrmq-rule-compliance-enforcement` change owns guard wiring).

### D2 — `tijdvakcode` is stored, not computed at runtime

New string property `tijdvakcode` (pattern `^6[0-9]{3}$`) with the full 2026 mapping documented in the property description (maand: `60`+`MM` → 6010..6120; vierweken: 6710,6750,6790,6830,6870,6910,6950,6990,7030,7070,7110,7150,7190 per LH 210; jaar: 6400). Consistency with `period`/`tijdvak` is enforced by a rule check, not a calculation — the code is authoritative input on real aangiftes and must survive spec-year changes (the mapping table is re-issued annually with the Gegevensspecificaties; the check reads the corpus rule's `effectiveDate`-scoped table).

Note for the implementer: the four-weekly code table above MUST be taken from the seeded rule data (`nl-loonaangifte-tijdvakcode` rule `parameters.vierwekenCodes`), not hard-coded in PHP, so an annual re-issue is a data change.

### D3 — Deadline derivation + alerting are corpus rules (Declarative-vs-imperative decision)

| behaviour | path | rationale |
|---|---|---|
| Filing state machine | **declarative** `x-openregister-lifecycle` on the schema | ADR-031 default; renderer ships `lifecycleActions` widget |
| `submittedDate` stamping on `verzenden` | declarative (carrying write, as Timesheet stamps `approvedAt`) | existing pattern |
| Tijdvakcode↔period consistency | imperative **CheckProvider** method (`NlWageTaxFilingChecks`) | domain rule evaluation over the corpus — the established ADR-031 exception this app already uses for all 89 rules |
| Deadline correctness (period end + 1 calendar month, no extension) | imperative CheckProvider method | same |
| Deadline approaching (≤14 days) / overdue for unfiled or un-sent filings | imperative CheckProvider method producing violations surfaced by `occ hrmq:rules:audit` | same; **no** new notification channel in this change — x-openregister-notifications for deadline alerts is deliberately deferred until the notification dialect (ADR-031/gate-18) is adopted app-wide |
| Deadline KPI on pages | declarative stats-block widgets with `@today` filter tokens | manifest supports `@today±Nd` |

### D4 — Response capture is data, not workflow

`responseStatus` enum (`geen`, `ontvangen-ok`, `afgekeurd`) + free-text `responseMessage`. A rejected (`afgekeurd`) filing is corrected via `corrigeren`. No polling/ingestion in this change.

## Schema delta (LoonaangifteFiling)

New properties: `status` (enum concept/klaargezet/bevestigd/verzonden, default concept), `tijdvakcode` (string, pattern `^[67][0-9]{3}$`, nullable), `aangiftenummer` (string, nullable — loonheffingennummer + tijdvakcode composite the Belastingdienst uses), `betalingskenmerk` (string, nullable), `responseStatus` (enum, default `geen`), `responseMessage` (string, nullable), `verzondenDoor` (string, nullable — display name of the sender, stamped on the `verzenden` carrying write like Timesheet.approvedBy). Existing `required: [period, deadline]` stays. Schema `version` bumps 0.1.0 → 0.2.0.

## New corpus rules (payroll.json)

| id | source | statement (short) | machineCheckable |
|---|---|---|---|
| `nl-loonaangifte-tijdvakcode` | Belastingdienst LH 210 (2026) | Each filing period carries the official tijdvakcode matching its period + tijdvak; carries `parameters.maandCodePrefix`/`parameters.vierwekenCodes`/`parameters.jaarCode` data | true |
| `nl-loonaangifte-deadline-derivation` | AWR art. 19 / LH 210 | The statutory deadline equals the last day of the calendar month following the period end, without weekend/holiday extension | true |
| `nl-loonaangifte-deadline-alert` | AWR art. 19 | An unfiled (status ≠ verzonden) filing whose deadline is within 14 days or past is a violation (severity: mandatory when overdue, advisory when approaching) | true |

All three: `domain: reporting`, `jurisdiction: NL`, `framework: nl-loonheffingen`, `sourceUrl: https://www.belastingdienst.nl/wps/wcm/connect/bldcontentnl/themaoverstijgend/brochures_en_publicaties/aangifte-loonheffingen-tijdvakcodes-aangifte-en-betaaldatums`.

## Manifest delta

- `LoonaangifteFilings` (index): columns `period`, `tijdvakcode`, `status`, `deadline`, `submittedDate`, `responseStatus`; default sort deadline asc.
- `LoonaangifteFilingDetail`: add a stats-block row (Deadline, Dagen resterend via `@today` delta presentation if supported — otherwise plain deadline stat + status stat), and `lifecycleActions` widget bound to the new lifecycle (labels: Klaarzetten, Bevestigen, Verzenden, Heropenen, Corrigeren) following the exact structure of `TimesheetDetail`'s lifecycleActions.
- No new menu entries (pages already exist under Loonadministratie; IA realignment is owned by the active `hrmq-ia-navigation-alignment` change).

## Seed Data (ADR-001)

Extend `lib/Settings/register.d/hr-seed.json` with 4 LoonaangifteFiling objects for a fictional consultancy (matching existing seed employees):

1. `2026-04`, maand, tijdvakcode `6040`, deadline `2026-05-31`, status `verzonden`, submittedDate `2026-05-21`, responseStatus `ontvangen-ok`.
2. `2026-05`, maand, tijdvakcode `6050`, deadline `2026-06-30`, status `verzonden`, submittedDate `2026-06-25`, responseStatus `geen`.
3. `2026-06`, maand, tijdvakcode `6060`, deadline `2026-07-31`, status `bevestigd` (deadline approaching → audit shows advisory violation).
4. `2026-03`, maand, tijdvakcode `6030`, deadline `2026-04-30`, status `concept` (overdue → mandatory violation; exercises the alert check).

All monetary/identifying values are obvious placeholders (loonheffingennummer `000000000L01`, nil-UUID refs where needed).

## Risks / Trade-offs

- **Lifecycle on an existing schema**: existing filing objects (if any) have no `status`; default `concept` applies on read/validation. Seed re-import is idempotent via the Repair step.
- **Annual re-issue**: tijdvakcode tables and deadlines are 2026-edition; the corpus rule carries the data so 2027 is a JSON update (this is exactly the app's versioned-corpus philosophy).
- **`corrigeren` semantics** are simplified (reset to concept) — full correctie-berichten (VL/IB-correcties) are a future spec; the description flags this.

## Open Questions

- None blocking. Digipoort transport decision tracked in Spectr (`hrmq-insight-digipoort`).
