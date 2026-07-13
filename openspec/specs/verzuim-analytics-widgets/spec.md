---
capability: verzuim-analytics-widgets
status: in-progress
built_by: openspec/changes/verzuim-analytics-widgets
---

# verzuim-analytics-widgets Specification

**Status**: in-progress
**Scope**: hrmq
**OpenSpec changes**:
- [verzuim-analytics-widgets](../../changes/verzuim-analytics-widgets/) _(active)_ — four absence-analytics stat widgets on the Dashboard (open ziektegevallen, langdurig verzuim past the WVP 42-weken horizon via `@today-294d`, verlofaanvragen in behandeling, this-month approved verlofuren sum) plus the `VerzuimOverzicht` open-cases werkvoorraad sorted by the UWV 42-weken deadline; manifest + seeds only, with Bradford/trend charts named non-goals for verified technical reasons (kind: config)

## Purpose

Give HR the absence analytics the stored leave-verzuim-mvp data already
supports, using only demonstrably-supported declarative shapes (verified at
HEAD against the vendored app-manifest-v2 schema, `CnStatWidget`'s `/value`
aggregation fetch with operator-aware filters and fetch-time tokens, and the
OpenRegister checkout): four `stat` KPIs on the Dashboard and a pre-filtered,
deadline-sorted `VerzuimOverzicht` index page mirroring the round-1
`LoonaangifteFilings` werkvoorraad pattern. Bradford factor (per-employee
computed S²×D), frequency/duration trend charts (blocked on Dashboard
dateRange wiring resp. a nonexistent date-difference metric) and
verzuimpercentage (cross-schema denominator) are explicit non-goals recorded
for the `verzuim-trend-charts` follow-up. Spectr canon:
`hrmq-canon-verzuim-analytics` (3/9 competitive coverage).

## Requirements

Detailed requirements (REQ-VZA-001 … REQ-VZA-003) are defined in the active
change's delta spec —
[`openspec/changes/verzuim-analytics-widgets/specs/verzuim-analytics-widgets/spec.md`](../../changes/verzuim-analytics-widgets/specs/verzuim-analytics-widgets/spec.md)
— and are merged here when the change is archived. The umbrella requirement
below anchors the capability until then.

### Requirement: hrmq surfaces absence analytics as truthful declarative aggregations over the verzuim/verlof records (REQ-VZA-000)

The app MUST present absence analytics (open-case counts, the WVP 42-weken
long-term threshold, pending leave, windowed approved-hours sums) as
declarative widget aggregations computed at fetch time from the
`SickLeaveCase`/`LeaveRequest` records themselves — never from hand-maintained
counters — and MUST provide an open-cases werkvoorraad ordered by the UWV
42-wekenmelding deadline, so every displayed number is reproducible from the
register and every listed case is actionable by statutory urgency.

#### Scenario: Analytics reproduce from the register
- **GIVEN** an imported hrmq register with seeded open and recovered sickness cases and submitted/approved leave requests
- **WHEN** the Dashboard and `VerzuimOverzicht` render
- **THEN** each KPI equals the register-side aggregation of its filter (open gemeld cases; gemeld cases with `firstSickDay <= @today-294d`; submitted requests; this-month approved hours) and the werkvoorraad lists exactly the gemeld cases ordered by `uwv42WeekMeldingDue` ascending
