---
sidebar_position: 1
description: Case-managed onboarding and offboarding, gated by checklist fields and enforced by audit rules — not guard classes.
---

# Onboarding & offboarding

The `Onboarding & ATS` menu group gives every hire and every departure a
dedicated case with a deterministic, declarative lifecycle. Milestones are
gated by concrete checklist fields, and correctness is enforced by audit
rules rather than write-time guard classes — HRMQ's onboarding/offboarding
lifecycles declare **no `requires:` guards** at all; gate enforcement is
the compliance rule corpus's job.

## Onboarding

```
aangenomen → contract_getekend → gegevens_gevalideerd
           → gereed_eerste_werkdag → proeftijd_lopend → afgerond
```

`annuleren` is reachable from every state before `afgerond`. Each forward
transition corresponds to a checklist gate:

| Transition | Checklist fields |
| --- | --- |
| `contract_bevestigen` | `contractSigned` |
| `gegevens_valideren` | `bsnValidated`, `ibanVerified` |
| `gereed_melden` | `widCheckDone`, `itProvisioned`, `pensioenAangemeld` |

Three machine-checkable rules (domain `labour`/`payroll`, jurisdiction
`NL`) audit the checklist against the actual timeline:

1. **`nl-onboarding-wid-check`** (framework `nl-wid`, Wet op de
   identificatieplicht art. 15) — flags a case that has reached or passed
   its start date without `widCheckDone`. A cancelled case is never
   flagged.
2. **`nl-onboarding-proeftijd-bewaking`** (framework `bw7-10`, BW art.
   7:652) — flags a `proeftijdEndDate` that exceeds the statutory proeftijd
   cap for the contract type (1 month for short fixed-term, 2 months for
   permanent/2-year+ contracts), and flags a `proeftijd_lopend` case whose
   proeftijd end date has already passed without being closed.
3. **`nl-onboarding-loonheffingenverklaring`** (framework
   `nl-loonheffingen`, Wet LB 1964 art. 29 jo. 28) — flags a case at or
   past "gereed eerste werkdag" whose employee has no
   loonheffingenverklaring on file.

```bash
occ hrmq:rules:audit
```

## Offboarding

```
aangekondigd → afronding_gepland → eindafrekening_gereed → afgerond
```

Again `annuleren` is reachable pre-`afgerond`. The checklist and
eindafrekening fields — `exitGesprekDone`, `assetsIngeleverd`,
`toegangIngetrokken`, `verlofsaldoUitbetaald`, `vakantiegeldAfgerekend`,
`transitievergoedingBedrag`, `getuigschriftVerstrekt` — gate the same way,
audited by four rules:

1. **`nl-offboarding-transitievergoeding`** (BW art. 7:673) — a
   dismissal-initiated departure (`reason` is `opzegging-werkgever` or
   `einde-contract`) reaching `eindafrekening_gereed` or `afgerond`
   without a `transitievergoedingBedrag` is flagged. A resignation
   (`opzegging-werknemer`) is statutorily exempt and never flagged. The
   formula constants — the 1/3-monthly-wage-per-service-year fraction and
   the EUR cap — are versioned **as rule parameters**, not PHP constants.
2. **`nl-offboarding-verlofsaldo-uitbetaling`** (BW art. 7:641) — a
   completed case without a leave-balance payout is flagged when the
   employee actually has an open leave balance; when no `LeaveBalance` row
   resolves for the employee, the check is skipped rather than
   fail-closed (balance rows are optional MVP data).
3. **`nl-offboarding-getuigschrift`** (BW art. 7:656, severity
   `recommended`) — a completed case without a provided getuigschrift is
   flagged, but only advisorily.
4. **`nl-offboarding-einddatum-consistentie`** (BW art. 7:667) — a
   completed case's `lastWorkingDay` must match the employee's `endDate`.

```bash
occ hrmq:rules:audit
```

## Out of scope for both

The eindafrekening computation engine, UWV WW-melding, pensioenfonds and
ZVW submissions, AVG retention timers, and automatic
`Employee.endDate` updates are all explicitly out of scope for this MVP —
HRMQ tracks the case and its checklist, not the downstream government
filings or payroll math those checklist items imply. Per-item asset
tracking for `assetsIngeleverd` is loosely coupled to the separate
[asset register](/docs/people/assets), not owned by this capability.
