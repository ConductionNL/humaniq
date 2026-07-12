---
capability: verzuim-wvp
status: in-progress
built_by: openspec/changes/leave-verzuim-mvp
---

# verzuim-wvp Specification

**Status**: in-progress
**Scope**: hrmq
**OpenSpec changes**:
- [leave-verzuim-mvp](../../changes/leave-verzuim-mvp/) _(active)_ — new `SickLeaveCase` schema (fragment hr-verzuim.json) with gemeld⇄hersteld lifecycle, stored-but-rule-checked WVP milestone dates (weeks 6/8/42/52), 70% loondoorbetaling tracking, 3 new machine-checkable NL verzuim rules, verzuim pages, and the no-medical-data privacy requirement (kind: config)

## Purpose

Dutch employers must run Wet-verbetering-poortwachter case management from
day one of sickness: probleemanalyse in week 6 and plan van aanpak in week 8
(Regeling procesgang eerste en tweede ziektejaar), the 42-weken ziekmelding
at UWV (ZW art. 38), the eerstejaarsevaluatie in week 52, and at least 70%
wage continuation for up to 104 weeks (BW art. 7:629; relapse within 4 weeks
continues the same case — samengesteld ziektegeval, lid 10). This capability
models the administrative sickness case — never medical data, per the AVG
and the Autoriteit Persoonsgegevens beleidsregels "De zieke werknemer" —
with a declarative lifecycle, derived-but-stored milestone dates whose
correctness and timeliness are enforced by versioned machine-checkable rules
(`NlVerzuimChecks`), and case pages under `Verlof & verzuim`. No Nextcloud
app has this depth (2026-07-12 deep-research, Spectr
`hrmq-insight-nc-ecosystem-gap`). UWV wire transport and WIA/tweede-spoor
flows are explicitly out of scope.

## Requirements

See the active change's delta spec:
[changes/leave-verzuim-mvp/specs/verzuim-wvp/spec.md](../../changes/leave-verzuim-mvp/specs/verzuim-wvp/spec.md)
(REQ-VWP-001 … REQ-VWP-006). Canonical requirements land here on archive.
