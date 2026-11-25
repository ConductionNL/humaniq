## 1. The seam (code)

- [x] 1.1 Add `lib/Standards/Checks/ProvidesTables.php` — capability interface
      (`tables(): array<objectType, array<ruleId, {derive: callable, table: array}>>`), mirroring
      the `SeedsObjects`/`UpsertsObjects` capability pattern.
- [x] 1.2 Add `lib/Standards/TableCheckEvaluator.php` — memoised `class_exists()` +
      direct-construction resolution of `OCA\OpenRegister\Service\Dmn\DecisionTableEvaluator`,
      `satisfied(table, inputs): bool` mapping `outputs['satisfied'] === true`, `RuntimeException`
      when OpenRegister is absent (fail closed), `reset()` test hook. No fallback matcher.
- [x] 1.3 In `RuleEngine::checks()`, merge table-declared checks from `ProvidesTables` providers
      into the registry, wrapped as predicates (`derive` null => vacuous pass; otherwise delegate).

## 2. The proof provider (code)

- [x] 2.1 Convert `NlLeaveChecks` to `ProvidesTables`: the four LeaveBalance rules as
      derivation + table per design.md's mapping table; `checks()` returns empty.
- [x] 2.2 Keep the legacy predicates as `legacyChecks()` — the parity oracle, documented as
      unregistered and scheduled for staged retirement (REQ-RULE-009).

## 3. Test substrate

- [x] 3.1 Vendor OR's pure Dmn classes verbatim (openregister@d1594ccd) into
      `tests/stubs/OpenRegisterDmn/` (`DecisionEvaluationException`, `UnaryTestEvaluator`,
      `DecisionTableEvaluator`), rewriting only the OR-repo `@spec` anchors to provenance notes.
- [x] 3.2 Load them from `tests/bootstrap.php` behind `class_exists()` so the real classes always
      win on a live instance.

## 4. Tests

- [x] 4.1 `tests/Unit/Standards/TableCheckEvaluatorTest.php` — satisfied mapping, non-`true`
      output is a violation, evaluator errors propagate, memoisation/reset.
- [x] 4.2 `tests/Unit/Standards/NlLeaveTableParityTest.php` — the pinned fixture matrix through
      BOTH the table path (real evaluator semantics via the vendored classes) and
      `legacyChecks()`; verdicts identical per rule per fixture (REQ-RULE-009).
- [x] 4.3 Update `tests/Unit/Standards/Checks/NlLeaveChecksTest.php` to drive all four rules
      through `RuleEngine::evaluate('LeaveBalance', ...)` (registry path end-to-end) instead of
      raw closures.
- [x] 4.4 Full unit suite green; analyzers (phpcs, psalm, phpstan, phpmd per subdir) individually
      green on the diff.

## 5. Re-scope humaniq-rule-compliance-enforcement (docs only)

- [x] 5.1 Amend its `design.md` Option C: the OR-side substrate now partially exists
      (`openregister.decision-table` + shared Dmn evaluator, openregister#3329); name the two
      build-on paths (flow with inline table; a future guard calling the already-delegating
      `RuleEngine`).
- [x] 5.2 Add the standing constraint to its design and tasks: enforcement work builds on the
      shared evaluator through `ProvidesTables`; no new in-app matching/hit-policy/cell-grammar
      machinery; new tabular rules are authored as decision tables.

## 6. Staged retirement (deliberately unchecked — proof before deletion)

- [x] 6.1 On an OpenRegister-backed environment, run `occ humaniq:rules:audit` before and after
      this change over seeded data covering the four converted rules; verdicts must match.
      Done 2026-11-25 on a sandbox Nextcloud 34.0.6 (MariaDB 10.11, OpenRegister main 7997381d,
      humaniq development 89e5e1bf): 14 LeaveBalance fixtures seeded through the OpenRegister API
      beside the 5 shipped seed balances (19 checked), covering each rule satisfied, violated and
      vacuous. Audited once with the tables registered (as shipped) and once with
      `legacyChecks()` registered as `checks()` and `tables()` empty (local, not committed). The
      per-object violations (13 rows: 5 minimum, 3 saldo, 3 vervaltermijn, 2 bovenwettelijk) are
      identical, and so are the audit's counts (152 objects, 103/22/41 violations, LeaveBalance
      checked=19 compliant=9 withViolations=10); only the position of the LeaveBalance line in the
      per-type list moves, because table-declared types register after closure types.
- [x] 6.2 After 6.1 is proven, retire `NlLeaveChecks::legacyChecks()` and rewrite the parity test
      into a table-behaviour pin (fixtures + expected verdicts, no oracle).
      Done: `legacyChecks()` and its four predicates are gone; `NlLeaveTableParityTest` became
      `NlLeaveTableBehaviourTest` (16 fixtures with the expected verdict per rule, plus the
      live audit's fractional-shortfall and all-violated rows; a second test asserts the four
      rules, no registered closure, and both verdicts pinned per rule). The other providers keep
      their oracles until their own audit proves them.
- [x] 6.3 Next conversion wave: sweep the remaining 36 providers for tabular shapes (thresholds,
      enumerations, boolean gates over derived values) and convert them provider-by-provider with
      the same parity discipline; leave genuinely non-tabular predicates as closures.
      Progress: `NlPerformanceChecks` (`nl-performance-dossiervorming`: status set + two presence gates) is table-declared, legacy predicate kept as oracle, `NlPerformanceTableParityTest` over every status x present/missing/blank/whitespace rating and afspraken; `NlUitzendChecks` (`nl-uitzendbeding-alleen-fase-a`, `nl-inlenersbeloning-onderbouwing-vereist`: contract type, flag and presence gates) likewise, `NlUitzendTableParityTest`; `NlStagiairChecks` (`nl-bpv-overeenkomst-vereist` on Stagiair and bbl EmploymentContract) likewise, `NlStagiairTableParityTest`; `NlAorChecks` (`nl-ambtenaar-eed-vereist`, `nl-ambtenaar-nevenwerkzaamheden-melding`) likewise, `NlAorTableParityTest`; `NlThirdPartyChecks` (`nl-ubd-deadline`: deadline-passed x report-sent gate, unreadable payment date derives null; `nl-ubd-payee-identification`: paid x BSN x date-of-birth presence gates) likewise, `NlThirdPartyTableParityTest`; `NlNetPayChecks` (`nl-netpay-iban-present`: payable period x IBAN on file) likewise, `NlNetPayTableParityTest`; `NlDocumentChecks` (`nl-contract-schriftelijk`: permanent+written x generated arbeidsovereenkomst, `nl-loonstrook-verplicht`: generated loonstrook; no id derives null) likewise, `NlDocumentTableParityTest`; `NlAbpChecks` (`nl-abp-fund-required`: NL x approved-or-later status set x ABP-plichtig x filed) likewise, `NlAbpTableParityTest`; `NlSinglePersonChecks` (`nl-single-person-mode-employee-count`: mode x active-employee count `!= 1` x sole employee is DGA) likewise, `NlSinglePersonTableParityTest`; `NlWntChecks` (`nl-wnt-norm-overschrijding`: topfunctionaris x exemption x over the loaded norm; dangling employee or unreadable norm derives null) likewise, `NlWntTableParityTest`; `NlGlPostChecks` (`nl-glpost-idempotent-per-run`: active status set x active posts `> 1`, posted x journal entry / posted-at / balanced lines) likewise, `NlGlPostTableParityTest`; `NlPensionFilingChecks` in part (`nl-upa-payrollrun-approved`: referenced run status set; `nl-upa-monthly-completeness`: NL x status set x period filed; `nl-upa-deadline-alert` stays a closure, a date window over the shared deadline arithmetic) likewise, `NlPensionFilingTableParityTest`; `NlOffboardingChecks` (all four: transitievergoeding reason x status sets x bedrag, verlofsaldo closed x paid-out x open hours `> 0`, getuigschrift and einddatum gates) likewise, `NlOffboardingTableParityTest`; `NlOnboardingChecks` (WID check: cancelled x done x start passed x ready-or-later set; proeftijd: within contract cap x overdue unclosed; loonheffingenverklaring: ready-or-later set x on file) likewise, `NlOnboardingTableParityTest`; `NlAssetChecks` (assignment consistency: returned x dates in order x issued asset x employee; inname: returned x planned completion passed) likewise, `NlAssetTableParityTest`; `NlTravelExpenseChecks` (`nl-reiskosten-onbelast-tarief`: mileage claim x tax-free per km within the corpus rate; no usable distance/amount or rate derives null) likewise, `NlTravelExpenseTableParityTest`; `NlFleetChecks` in part (`nl-asset-voertuig-fiscale-velden-compleet`: vehicle x three presence gates; `nl-bijtelling-auto-privegebruik` stays a closure, it recomputes the statutory formula) likewise, `NlFleetTableParityTest`; `NlWageGarnishmentChecks` (floor: net pay minus voet in cents `< 0`; single active: can conflict x overlapping active beslagen `> 0`) likewise, `NlWageGarnishmentTableParityTest`; `NlDgaChecks` (`nl-gebruikelijkloon-norm`: DGA x justified x shortfall to the loaded norm `> 0`; `meetsGebruikelijkloonNorm()` stays public for `PayrollController::dgaStatus()`) likewise, `NlDgaTableParityTest`; `NlAtsChecks` (retention derivation: rejected x dates readable x clock matches; expiry: expired, unreadable expiry derives null) likewise, `NlAtsTableParityTest`; `NlDossierRetentionChecks` (`nl-bewaartermijn-verstreken` on all its schemas: destroyed x ceiling passed, Employee's two held-past-ceiling documents, closed relations case x retention passed) likewise, `NlDossierRetentionTableParityTest`; `NlHr21Checks` (scale: verified non-empty mapping x scale matches, no or unresolved normfunctie derives null; retired function: retired x contract live today) likewise, `NlHr21TableParityTest`; shared builder `BuildsSatisfiedTables`. `NlOpeningBalanceChecks` (overlap: period starts after the balance's last previous period, no readable period or balance derives null; ontbreekt: first payslip of the year x year's first period x balance on file) likewise, `NlOpeningBalanceTableParityTest`. `NlFlexContractChecks` (ketenregeling: live today x chain position `>= 3` or `>= 1` with the chain turning permanent inside the 60-day window; oproep-vaste-uren: on-call x ended x offer due x offer recorded, an unreadable start derives null) likewise, `NlFlexContractTableParityTest`. `NlAdministratieChecks` (`nl-administratie-scope-consistency` on Payslip and the 11 Employee-anchored types: own administratie equals the parent's, any missing side derives null) likewise, `NlAdministratieTableParityTest`. `NlCaoChecks` (minimumloon-schaal: shortfall to the verified scale minimum in cents `> 0`; component-onbekend: undeclared named components `> 0`; verlof-minimum: annual leave shortfall to the verified CAO minimum in hours `> 0`; unverified, unknown or unresolvable derives null) likewise, `NlCaoTableParityTest`. `NlOrgChecks` in part (`nl-org-assignment-consistency`: ends before start, or active today outside an active unit; `nl-org-unit-cycle` and `nl-mss-manager-consistency` stay closures, they walk the unit graph and resolve the manager chain) likewise, `NlOrgTableParityTest`. `NlSignalChecks` (contract-verloopt: temporary x ends inside 60 days x successor on file; aanzegtermijn: temporary x ended x past the notice deadline x aanzegging in time, an unreadable or too-short term derives null; proeftijd-eindigt and bhv-certificaat-verloopt: date inside its 14/90-day window, an unreadable date derives null) likewise, `NlSignalTableParityTest`. `NlAbsenceChecks` in part (milestone-overdue: open x undone milestones due within 14 days or past `> 0`; loondoorbetaling-minimum: open x percentage recorded x `< 70`; loondoorbetaling-floor: figures numeric x shortfall to the 70%/year-one WML floor `> 0`, the floor arithmetic in a shared `SickPayFloor`; `nl-wvp-milestone-derivation` stays a closure, it recomputes every due date and compares) likewise, `NlAbsenceTableParityTest`. `NlEngineChecks` in part (table-version: stamped x calculated x known table or pack; provenance-complete: snapshot readable x jurisdiction matches the pack, outside an engine run or of an unbundled artefact derives null, the snapshot decoding in a shared `EngineInputSnapshot`; `nl-engine-output-consistency` stays a closure, it recomputes net pay and compares cents) likewise, `NlEngineTableParityTest`. `NlWageTaxFilingChecks` in part (tijdvak: loonaangifte x electronic x tijdvak in maand/vierweken/kwartaal/jaar; tijdvakcode: NL loonaangifte x code recorded x concept x code as LH 210 expects; termijn and bewaarplicht delegate to the shared `FilingDates` arithmetic, the deadline derivation recomputes the deadline, the alert counts days to it and message drift compares message with run, so those five stay closures; the fingerprint test now pins the table path) likewise, `NlWageTaxFilingTableParityTest`. `NlPayrollChecks` Employee rules, moved into the sibling providers `NlEmployeeDocumentChecks` (ID copy, loonheffingenverklaring, A1) and `NlThirtyPercentRulingChecks` (the four 30%-ruling rules) to keep every class inside the size limits, with the shared `present`/`numeric` readers as the `ReadsRecordedFields` trait (id- and loonheffingenverklaring-bewaarplicht: applies x retained 5 years after the year employment ends; 30-percent-regeling: granted x numeric rate x `> 30`; aftoppingsgrens: granted x capped; looptijd: end readable x beyond 60 months x ended, no granted ruling or readable start derives null; salarisnorm: shortfall to the (reduced) norm `> 0`; eu-a1: posted x numbered, dated and within 24 months) likewise, `NlPayrollTableParityTest`; the payroll fingerprint test now pins the table path. `NlPayrollChecks` EmploymentContract rules (awf-laag-hoog-tarief: tariff recorded x as `AwfTariffResolver` expects; minimumloon-2026: numeric x `< 14.71`; minimumuurloon-wet: hours numeric x `<= 0` x wage numeric) and `xc-withholding-liability-clearing` (posted x cleared x balance numeric x cents `!= 0`) likewise; `xc-payroll-gl-reconciliation` stays a closure, it recomputes the run totals and compares cents. `NlPayrollChecks`' nine tabular Payslip rules, moved into the sibling providers `NlPayslipWithholdingChecks` (loonheffing and werknemersverzekeringen numeric x `< 0`; volksverzekeringen numeric x `< 0` x headroom to the loonheffing `< 0`; both Zvw modes x statutory rate x amount) and `NlPayslipStatementChecks` (anoniementarief: rate numeric x flag x 52% x `>= 52`; wkr-vrije-ruimte: remaining numeric x `< 0` x excess recorded; wkr-eindheffing-80: excess `> 0` x 80% rate; loonstrook-inhoud: four shown-flags), likewise; the Awf hours review, the vakantiebijslag reservation and the 30%-exemption amount recompute a figure and compare, so they stay closures. Swept and left as closures (non-tabular): `NlAttendanceChecks` and `NlRosterChecks`, whose three ATW rules only delegate to the shared `WorkingTimeRules` clock arithmetic, `NlRetroChecks`, whose rule recomputes the payslip with the calculator and compares cents, and `NlWkrChecks`, whose exposure rule recomputes the vrije-ruimte tranches and eindheffing and compares cents. Every provider is swept.
