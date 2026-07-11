## ADDED Requirements

### Requirement: Cross-surface feature vocabulary agreement
HRMQ's code metadata (`appinfo/info.xml`), the public product page
(`conduction-website/src/pages/apps/hrmq.mdx` + NL i18n), and any docs site SHALL describe the same
canonical feature list, worded consistently, and SHALL NOT claim a capability that has no
corresponding shipped code path.

#### Scenario: Product page feature bullets match info.xml description
- **WHEN** a reviewer compares the `<description>` in `hrmq/appinfo/info.xml` against the
  `FeatureList` items in `conduction-website/src/pages/apps/hrmq.mdx`
- **THEN** both list the same three shipped feature areas — Timesheets with an approval workflow,
  Expense claims with an approval workflow, and the HR/payroll compliance rule engine — using
  matching terminology.

#### Scenario: Register-only schemas are not claimed as managed UI
- **WHEN** the product page or info.xml describes the Employee / EmploymentContract / PayrollRun /
  Payslip / WageTaxFiling objects
- **THEN** the copy describes them as OpenRegister data audited by the compliance rule engine, and
  does **not** imply a dedicated management UI exists for them, because `src/manifest.json` ships
  no page/route for any of those schemas (only `Timesheets`/`Expenses`).

#### Scenario: Version is consistent across surfaces
- **WHEN** a reviewer compares the version shown on the product page against
  `hrmq/appinfo/info.xml`'s `<version>`
- **THEN** the product page's `version` prop is derived from the same `0.1.x` line as
  `info.xml`'s `<version>0.1.0</version>`, labelled "Beta".

### Requirement: Declared dependencies match actual code
Any external app dependency the product page or docs assert SHALL be declared in
`appinfo/info.xml`, and any dependency declared in `appinfo/info.xml` SHALL be verifiable in code.

#### Scenario: OpenRegister dependency is declared
- **WHEN** `src/manifest.json` declares `"dependencies": ["openregister"]` and HRMQ's Vue pages
  read/write the OpenRegister object store directly
- **THEN** `hrmq/appinfo/info.xml`'s `<dependencies>` block documents the OpenRegister dependency
  (via an explanatory comment, since NC's `app-info.xsd` has no native cross-app dependency
  element).

### Requirement: Marketing/compliance claims trace to shipped code
Every standard, law, or framework named on the product page or in info.xml SHALL be traceable to
an implemented check in `lib/Standards/`.

#### Scenario: Named legal frameworks are implemented
- **WHEN** the product page or info.xml names a legal framework (e.g. Wet minimumloon, Wet LB
  1964, GDPR, EU labour directives, ILO conventions)
- **THEN** at least one rule in `lib/Standards/rules/payroll.json` or a `CheckProvider` in
  `lib/Standards/Checks/` references that framework.

#### Scenario: Unimplemented compliance marks are not claimed
- **WHEN** a compliance mark is not found anywhere in `lib/Standards/`, `lib/`, or `src/` (e.g.
  Peppol, SEPA, BBV, DigiD — vocabulary that belongs to other Conduction apps, not HRMQ)
- **THEN** the product page and info.xml SHALL NOT claim it for HRMQ.
