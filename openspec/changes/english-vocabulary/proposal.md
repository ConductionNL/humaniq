# English vocabulary for hrmq — employment-law terms

> Implements `hydra/openspec/changes/fleet-english-vocabulary`.

## Why

Scan found **0 Dutch-named schemas and 12 Dutch property names**. Small, but
every one names a **Dutch employment-law or tax-authority concept**, so this is
a §2 (internationalise) change rather than a translation.

## What changes

### Internationalised (§2)

| Dutch | English | note |
|---|---|---|
| `uwv42WeekMeldingDue` / `…Done` | `sicknessReportDueDate` / `sicknessReportFiled` | UWV is the NL social-security agency; the *concept* is a statutory long-term-sickness notification |
| `loonheffingenVerklaringOnFile` | `payrollTaxDeclarationOnFile` | |
| `transitievergoedingBedrag` | `severancePayAmount` | transitievergoeding = statutory severance |
| `wntUitzonderingReden` | `executivePayCapExemptionReason` | WNT = public-sector pay cap |
| `betalingskenmerk` | `paymentReference` | |
| `certificaatGeldigTot` | `certificateValidUntil` | |
| `innameDatum` / `uitgifteDatum` | `returnedDate` / `issuedDate` | |
| `bpvSchoolNaam` | `apprenticeshipSchoolName` | BPV = vocational work placement |
| `dossierRef` | `caseReference` | |
| `naam` | `name` | |

### Statutory marker (§4)

`wnt*`, `transitievergoeding` and the UWV notification are Dutch statute with no
1:1 counterpart. English identifier + `x-statutory-basis`
(`jurisdiction: NL`, `instrument: WNT / BW 7:673 / Ziektewet`).

⚠️ These fields drive **money and legal obligations** (severance amounts, pay-cap
exemptions, statutory sickness reporting). A silently-absent value after a rename
is a compliance failure, not a display bug — see risks.

## Tasks

- [ ] Inventory per schema and per lib/+src/ file — real counts.
- [ ] Rename the 12 properties.
- [ ] `x-statutory-basis` on the WNT / severance / sickness-notification fields.
- [ ] Rename classes/methods/files.
- [ ] **Diff every read of these fields**: the hourly-cost chain
      (`EmployeeCostRateService`, `EmployerCostRateController`) and shillinq's
      `HrmqCostRateAdapter` consume hrmq fields **cross-app** — a rename here can
      break shillinq without failing in hrmq.
- [ ] `l10n/nl.json` + `check-l10n`.
- [ ] Full suite + hydra gates.

## Risks

- ⚠️ **Cross-app consumer.** shillinq resolves hrmq's cost-rate service
  in-process; renamed hrmq properties surface there as *missing wage data*,
  which by design makes shillinq withhold a cost total rather than error. The
  failure is invisible in hrmq's own tests.
- Statutory amounts read with `??` default to absent, not to a loud failure.
