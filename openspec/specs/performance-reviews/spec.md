---
capability: performance-reviews
status: in-progress
built_by: openspec/changes/performance-reviews-mvp
---

# performance-reviews Specification

**Status**: in-progress
**Scope**: hrmq
**OpenSpec changes**:
- [performance-reviews-mvp](../../changes/performance-reviews-mvp/) _(active)_ — `PerformanceReview` schema (goals inside the review, no Goal entity) with declarative concept→ingediend→besproken→vastgesteld/heropenen lifecycle, `NoSelfApprovalGuard` reuse on vaststellen, `nl-performance-dossiervorming` corpus rule + `NlPerformanceChecks` provider, EmployeeDetail Beoordelingen row + PerformanceReviewDetail + MijnBeoordelingen, seeded complete + intentionally incomplete reviews (kind: config)

## Purpose

The dossier half of the performance-review MVP: the `PerformanceReview`
object per employee per cycle, carrying rating, sterktes,
ontwikkelpunten, afspraken and a goals array **inside** the review (one
dossier document, one lifecycle, one retention context — no separate Goal
entity; the OKR follow-up owns cross-cycle goals), with separation of
duties on `vaststellen` via the existing `NoSelfApprovalGuard` and the
machine-checkable `nl-performance-dossiervorming` rule (BW 7:669 lid 3
sub d — a vastgestelde beoordeling without rating + afspraken is no
ontslagdossier; severity recommended, audit-only). Surfaces per ADR-001
Rule 6: the Beoordelingen row on the personnel dossier (`EmployeeDetail`),
`PerformanceReviewDetail`, and the `MijnBeoordelingen` `userId=@me`
self-service view (round-2 denormalized-uid pattern). Grounded in Spectr
canonicalFeatures `hrmq-canon-performance-reviews` (6/9 coverage) and
`hrmq-canon-goals-okr`. OKR/9-box/kalibratie, comp-cycles and career
frameworks are explicitly out of scope (separate drafts).

## Requirements

Detailed requirements (REQ-PRV-001 … REQ-PRV-006) are defined in the active
change's delta spec —
[`openspec/changes/performance-reviews-mvp/specs/performance-reviews/spec.md`](../../changes/performance-reviews-mvp/specs/performance-reviews/spec.md)
— and are merged here when the change is archived. The umbrella requirement
below anchors the capability until then.

### Requirement: Reviews are dossier-anchored register objects with goals inside and separation of duties on vaststellen (REQ-PRV-000)

The review capability MUST consist solely of the `PerformanceReview` schema
in `lib/Settings/register.d/hr-performance.json` (goals array inside the
review — no `Goal` schema anywhere; declarative lifecycle with
`vaststellen.requires` the existing `OCA\Hrmq\Lifecycle\NoSelfApprovalGuard`,
reused unchanged), one `recommended`-severity corpus rule
(`nl-performance-dossiervorming`) with its check provider, manifest surfaces
anchored on the personnel dossier per ADR-001 Rule 6 (EmployeeDetail row,
`PerformanceReviewDetail`, `MijnBeoordelingen` via the denormalized
`userId`), and seed data. It MUST NOT introduce a new menu group, a new
guard class, or any OKR/9-box/kalibratie/comp-cycle object.

#### Scenario: Dossier-anchored surface, reused guard

- GIVEN the hrmq codebase with this capability applied
- WHEN `src/manifest.json`, `lib/Settings/register.d/` and `lib/Lifecycle/` are inspected
- THEN the review surface hangs off `EmployeeDetail`/`MijnHrGroup` with no new menu group, `hr-performance.json` declares no `Goal` schema, and `vaststellen` references the pre-existing `NoSelfApprovalGuard` FQCN with no new guard class added
- @e2e exclude structural manifest/register assertion with no user-observable flow of its own; covered by check:manifest and the delta spec's scenarios
