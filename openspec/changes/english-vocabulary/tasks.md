# Tasks — english-vocabulary (hrmq)

Scan: **16 schemas / 25 Dutch properties**, **2 files / 2 classes / 9 methods**.
Nearly all of it is Dutch employment, payroll and social-security law. hrmq's `title`
fields are **also Dutch**, so unlike most apps there is no recorded English intent to
copy — every name here is a real translation decision.

## 1. Enumerate the cross-app dependency first

- [ ] 1.1 Enumerate every shillinq read of an hrmq property. Nothing else in this change
      starts until this list exists — shillinq consumes these, reads with a
      null-coalescing default, and would go silently null rather than fail.

## 2. Abstract the ordinary concepts (category a)

- [ ] 2.1 Rename the twelve properties that are merely Dutch-worded, not legal:
      `aanschafdatum`/`aanschafwaarde` → `purchaseDate`/`purchaseValue`,
      `uitgifteDatum`/`innameDatum` → `issuedDate`/`returnedDate`,
      `cataloguswaarde` → `listPrice`, `eigenBijdrage` → `employeeContribution`,
      `certificaatGeldigTot` → `certificateValidUntil`, `naam` → `name`,
      `goals[].titel`/`goals[].toelichting` → `title`/`notes`, `niveau` → `level`,
      `onderwijsinstelling` → `educationalInstitution`, `dossierRef` → `caseRef`,
      `toegangIngetrokken` → `accessRevoked`, schema `Stagiair` → `Intern`.
      No statute markers — these are not legal concepts.

## 3. Abstract the internationally-shared concepts (category b)

- [ ] 3.1 `Loonbeslag` → `WageGarnishment`, `betalingskenmerk` → `paymentReference`,
      `aanleverkenmerk` → `submissionReference`, `bpvSchoolNaam` → `vocationalSchoolName`,
      `stagevergoedingPerMaand` → `monthlyInternshipAllowance`,
      `loondoorbetalingPercentage` → `sickPayContinuationPercentage`,
      `BhvCertificering` → `EmergencyResponseCertification`.

## 4. Rename the NL-specific statutory constructs with markers (category c)

- [ ] 4.1 `transitievergoedingBedrag` → `statutorySeveranceAmount` (NL, WWZ);
      `wkrEindheffingRate`/`eindheffingRate`/`eindheffingDue` → `finalLevyRate`/
      `finalLevyDue` (NL, WKR); `uwv42WeekMeldingDue`/`…Done` → `sicknessReportDue`/
      `…Done` (NL, Wet verbetering poortwachter); schema `Normfunctie` → `JobProfile`
      (NL, HR21).
- [ ] 4.2 Attach the statute marker to each, recording jurisdiction and instrument, so
      the legal specificity survives as machine-readable data.
- [ ] 4.3 Check `Normfunctie` → `JobProfile` does not collide with an existing hrmq
      `JobProfile`.

## 5. Preserve the wire at the filing boundary

- [ ] 5.1 Map the renamed `LoonaangifteFiling` and `PensionFiling` properties back to
      their published field names at the adapter boundary. The schema name is ours; the
      submitted payload's field names are not.

## 6. Rename code identifiers

- [ ] 6.1 Rename `NlDossierRetentionChecks`, `NlReiskostenChecks` and their files, plus
      the nine Dutch methods including `dertigProcentRegeling` → `expatTaxRuling`,
      `aanzegtermijnSatisfied` → `contractEndNoticePeriodSatisfied`,
      `vervaltermijnSatisfied` → `leaveForfeitPeriodSatisfied`,
      `loondoorbetaling*` → `sickPayContinuation*`, `minMaandloonCents` →
      `minMonthlyWageCents`, `einddatumSatisfied` → `endDateSatisfied`,
      `dossiervormingSatisfied` → `recordKeepingSatisfied`.

## 7. Translations and verification

- [ ] 7.1 Move the Dutch titles into `l10n/nl.json` so Dutch HR users see no change;
      run `check-l10n`.
- [ ] 7.2 Re-run the token-aware scan; require 0 Dutch schemas and 0 Dutch properties.
- [ ] 7.3 Process identical payroll inputs before and after; require byte-identical
      output. This change renames and touches no rate, threshold or formula.
- [ ] 7.4 Exercise one filing end to end and confirm the submitted payload still carries
      the published field names.
- [ ] 7.5 Full test suite plus hydra gates 46 / 53 / 54 / 55 / 57 / 61, then land
      together with the shillinq side from task 1.1.

## Acceptance criteria

- Token-aware scan reports hrmq at 0/0.
- Every category (c) property carries a statute marker naming jurisdiction and instrument.
- No property retains Dutch on the grounds of being the standardised legal term.
- Payroll output is byte-identical across the rename.
- A filing payload still uses its published wire field names.
- shillinq's reads are enumerated and land in the same window.
