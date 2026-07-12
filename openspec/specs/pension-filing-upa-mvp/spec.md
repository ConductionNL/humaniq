---
capability: pension-filing-upa-mvp
status: in-progress
built_by: openspec/changes/pension-filing-upa-mvp
---

# pension-filing-upa-mvp Specification

**Status**: in-progress
**Scope**: hrmq
**OpenSpec changes**:
- [pension-filing-upa-mvp](../../changes/pension-filing-upa-mvp/) _(active)_ — new `PensionFiling` schema in a new `hr-pension` fragment with a declarative concept→gecontroleerd→bevestigd→verzonden lifecycle gated by `PayrollRunApprovedGuard`, 3 new machine-checkable NL UPA rules (framework `nl-pensioenaangifte`), and pension-filing index/detail pages (kind: config)

## Purpose

Give hrmq its first sector-pension filing surface (UPA — Uniforme
Pensioenaangifte): a `PensionFiling` per period×fund for the APG-administered
funds (ABP, SPW, bpfBOUW, Schoonmaak, PFAB, PWRI), with a declarative
create→review→confirm→send lifecycle whose review step is server-side gated on
the referenced PayrollRun being approved/posted/paid — the verified Loket.nl
gating rule — plus reference-integrity, monthly-completeness and deadline-alert
rules in the versioned corpus, and filing pages that drive the lifecycle.
#3-ranked missing feature from the 2026-07-12 market deep-research (Spectr
insight `hrmq-insight-upa-table-stakes`, source `hrmq-src-loket-apg`).
UPA XML generation, APG wire delivery and scheduled auto-dispatch are
explicitly out of scope.

## Requirements

See the active change's delta spec:
[changes/pension-filing-upa-mvp/specs/pension-filing-upa-mvp/spec.md](../../changes/pension-filing-upa-mvp/specs/pension-filing-upa-mvp/spec.md)
(REQ-PFU-001 … REQ-PFU-007). Canonical requirements land here on archive.
