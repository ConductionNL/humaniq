# Design: the wage tax return message, rendered from the payroll run

## Context

Read at `development` af702f78.

- `LoonaangifteFiling` (`lib/Settings/register.d/hr-objects.json`): `period`, `jurisdiction`,
  `filingType`, `tijdvak`, `tijdvakcode`, `deadline`, `submittedDate`, `aangiftenummer`,
  `betalingskenmerk`, `responseStatus`, `responseMessage`, `verzondenDoor`, `retainedUntil`,
  `status`, `administrationId`. Lifecycle `klaarzetten`, `bevestigen`, `verzenden`,
  `heropenen`, `corrigeren`. Page `LoonaangifteFilingDetail` (`src/manifest.d/hr-objects.json:1054`).
- Rules: `lib/Standards/Checks/NlWageTaxFilingChecks.php` (tijdvakcode, deadline).
- Run data: `PayrollRun` (`totalGross`, `totalLoonheffing`, `totalEmployerCharges`, `status`);
  `Payslip` (`grossPay`, `loonheffing`, `volksverzekeringen`, `werknemersverzekeringen`, `zvw`,
  `zvwMode`, `appliedTaxRate`, `anoniementariefApplied`, `vakantiegeldReserved`,
  `engineInputSnapshot`, `payrollRunId`). The pack `lib/Standards/packs/nl-2026.pack.json` and
  tables `lib/Standards/tables/nl-2026.json` hold the year's parameters.
- The WW premium (Awf) high or low indicator is already applied per contract:
  `EmploymentContract.awfTariff` (`hr-objects.json:83`) resolved by
  `PayrollRunService::awfTariffFor()` (`lib/Service/PayrollRunService.php:1611`), checked by
  `nl-awf-laag-hoog-tarief`, and carried in each payslip's `engineInputSnapshot`.
- Guards live in `lib/Lifecycle` and register in `lib/AppInfo/Application.php`.
- Non-goals on record: Digipoort transport (`fil-digipoort`, decided no), correction messages
  (`fil-correction`).

## Goals / Non-Goals

**Goals**

- A valid loonaangifte message per filing, made from what humaniq calculated.
- A clear refusal, per employee and field, when the data cannot make a valid message.

**Non-Goals**

- Sending. Uploading or a gateway is the employer's.

## Decisions

### D1. Build from the approved run, never from a draft

`LoonaangifteMessageService::render(filing)` finds the administration's approved (or posted
or paid) `PayrollRun` for the filing's period, reads its payslips with their
`engineInputSnapshot`, and hands both to `LoonaangifteMessageBuilder`, a pure class producing
the XML. A draft run refuses. Alternative considered: rendering from employee records at
render time. Rejected: the snapshot is what was calculated and taxed.

### D2. Versioned by year

The message version and XSD path sit beside the tax tables, per year, so a new year is a data
change like the tables. The builder keys its element map on the version.

### D3. Guarded readiness

`LoonaangifteMessageGuard` on `klaarzetten` renders and validates; on success the file id,
version and collective totals are stored and the transition proceeds; on failure the
transition is refused and `messageFindings` lists `{employeeId, element, problem}`.

### D4. Rules compare totals

A new rule in `NlWageTaxFilingChecks` compares `collectiveTotals` with the run's totals, so a
file that drifted from the run (a re-calculated run after rendering) is flagged.

## Declarative-vs-imperative decision (ADR-031)

| behaviour | path | why |
|---|---|---|
| rendering the message | imperative builder and service | document generation, the ADR-031 exception |
| refusing readiness | lifecycle guard | a cross-object precondition |
| totals consistency | corpus rule | the existing filing rule family |

## Seed data

- Amended 2026-10-02: the seeded payslips carry no `engineInputSnapshot` and no run reference,
  so the seed cannot render a message (every line would be `payslip-not-reproducible`). The seed
  gives `ADM-001` its contact for the return; its tax number `000000000L01` stays a placeholder,
  so a render on the seed shows the `LhNr` finding. The live check (task 3.2) uses a run the
  engine calculated on the instance.

## Risks / Trade-offs

- [The Gegevensspecificaties change every year] → version per year, XSD shipped with the tables,
  a golden file test per version.
- [Fields the engine does not compute yet (for example anoniementarief cases)] → the builder
  refuses with a finding rather than filling a default.

## Build notes (2026-10-02, lane 26): where the build follows the code and the specification

Sources, read for this build and cited below as GS (page) and XSD:
- **XSD**: `Loonaangifte2026v2.0.xsd` (namespace `http://xml.belastingdienst.nl/schemas/Loonaangifte/2026/01`,
  `version="2.0"`), from the Belastingdienst ODB product "Loonheffingen Aangifte 2026 v09"
  (https://odb.belastingdienst.nl/documentatie/loonheffingen-aangifte-2026v09/, zip
  `LH2026v09.zip`, downloaded 2026-10-02). Shipped alone in `lib/Standards/loonaangifte/` with a
  NOTICE naming the source and the licence basis.
- **GS**: "Gegevensspecificaties aangifte loonheffingen 2026" v3.0, from the same zip.

### D1 amended: rendering is an action, the guard checks the result

An OpenRegister lifecycle guard returns allow or deny and cannot write the object, so the guard
cannot store the file. The build follows the `filings-ib47` pattern instead: "Make the message"
(`POST /api/loonaangifte/filings/{filingId}/message`, HR or payroll, the filing read under the
caller's RBAC first) renders, validates and stores the result on the filing;
`LoonaangifteMessageGuard` on `klaarzetten` refuses a Dutch loonaangifte filing that has no
message, or whose last render left blocking findings. Filings of other jurisdictions pass the guard
unchanged. The file is stored inline as `messageXml` with `messageFileName` (the `ThirdPartyReport`
precedent), not as a Nextcloud file id.

### D2 amended: the year entry is a PHP class, not a tables file

`TaxTables` loads every `*.json` in `lib/Standards/tables/`, so a sibling JSON there would be read as
a tax table. `LoonaangifteYear` holds, per year, the message version, the XSD path, the namespace and
the four-weekly period dates (GS p37). The XSD directory holds the XSD and its NOTICE only, so it can
be replaced by a fetch step in one commit.

### D5. Where each element comes from

The payslip's `engineInputSnapshot` is recalculated with the same engine (the `AwfReviewService`
precedent) to obtain the premium components the payslip stores only as a roll-up; a payslip whose
recalculated wage tax differs from the stored `loonheffing` is a blocking finding (the message must
report what was paid). `CalculationResult` gains `taxableWageCents` (the pack's `belastbaarLoon`) and
`premiumWageCents` (`belastbaarLoon` capped at `premieloonCap`), both additive.

| element | value | rule |
|---|---|---|
| `Bericht/IdBer` | `LA` + period + creation time, at most 32 characters | GS p31, 0302 |
| `ContPers`, `TelNr` | new `hrAdministration.aangifteContactName` / `aangifteContactPhone`; missing is blocking | GS p32, 0304, 0305 |
| `RelNr` | the software relation number, app config `ubd_relnr` (one OSWO number per software developer); missing is blocking | GS p33, 1015; XSD length 8 |
| `GebrSwPakket` | `humaniq` + version | GS p33, 1016 |
| `LhNr` | `hrAdministration.loonheffingennummer`, 9 digits + L + 2 digits passing the elfproef | GS p34, 0310-0313, 0014.1 |
| `NmIP` | administration name | GS p35, 0309 |
| `DatAanvTv`/`DatEindTv` | the filing period: a month, or the four-weekly table | GS p37 |
| `VolledigeAangifte` | always a full return; corrections are `filings-correction-message` | GS p21 (2.3) |
| `NumIV` | `Employee.incomeRelationshipNumber`, default 1 (GS advice: ascending per BSN from 1, never renumbered, a rehire gets a new number) | GS p58-59, 0353 |
| `DatAanv`/`DatEind` | `Employee.startDate`; `endDate` only when on or before the period end | GS p60, p62, 0040 |
| `CdRdnEindArbov` | `Employee.endReason` (new, the GS code list); an ended 11/13/15 relationship without one is blocking | GS p64-65, 2501 |
| `PersNr` | `employeeNumber` when filled | GS p65 |
| `SofiNr` | `bsn`, elfproef, first digit not 8 or 9; missing is blocking unless the anonymous rate (940) applied | GS p66, 0045, 2101, 2287 |
| `Voorl`, `SignNm`, `Gebdat` | initials of `firstName` (max 6), `lastName`, `dateOfBirth`; name and birth date are required with a BSN | GS p67-69, 0046, 0047 |
| address | `AdresBinnenland` when street, number, postcode and place are complete and the country is NL; otherwise omitted (optional) | XSD |
| `Inkomstenperiode/DatAanv` | the later of employment start and period start | GS p77, 2209 |
| `SrtIV` | 17 for a DGA not insured for the employee insurances; 11 when `publicSectorRegime` is set; else 15 | GS p78 |
| `CdAard` | 83 for a `bbl` contract, 11 for `agency`, 18 for `ambtenarenwet`, else 1; not sent with 17 | GS p79-80, 1606, 2218, 2203 |
| `IndArbovOnbepTd`, `IndSchriftArbov`, `IndOprov` | contract without end date, `writtenContract`, type `oproep`; sent for 11/13/15 except with CdAard 18 | GS p84-86, 2213-2215, 2103 |
| `IndLhKort` | the snapshot's `loonheffingskortingToegepast` | GS p92, 0216 |
| `LbTab` | 940 when the anonymous rate applied; else `0` + colour (wit 1, groen 2) + period (month 2, four weeks 4) | GS p93-94 |
| `IndWAO`, `IndWW`, `IndZW` | the snapshot's `verzekeringsplichtig` | GS p95-97 |
| `CdZvw` | K for the employer levy, M when withheld | GS p99 |
| `LnLbPh`, `LnSV` | `taxableWageCents`; Loon SV is 0 when not insured | GS p103-104, 1818 |
| Aof, Whk, AWf bases | `premiumWageCents` on the low or high column (`aofTariff`; the payslip's `awfTariff`), 0 when not insured; AWf herzien, uitkering and Ufo are 0 (the review is settled through corrections) | GS p104-118, 2252, 2254, 2050, 2057 |
| premiums | the recalculated `aof`, `wko`, `whk`, `awf` in the matching column | GS p124-131, 2257, 2267, 2329, 2071, 2073 |
| `BijdrZvw` / `WghZvw` | `zvw` on the side `zvwMode` names, 0 on the other | GS p131-132, 1309, 1311, 1312 |
| `IngLbPh`, `VerrArbKrt` | `loonheffing`, `arbeidskorting` | GS p124, p134 |
| `OpgRchtVakBsl` / `VakBsl` | `vakantiegeldReserved` / 0 (no payout is built yet) | GS p120 |
| `LnInGld` | the gross pay | GS p122 |
| car | a payslip with `bijtelling` is blocking: the value before the employee contribution is not stored | GS p133 |
| `AantVerlU` | `hoursPaid`, else the contracted hours, half an hour or more rounds up | GS p135-136 |
| `Ctrctln`, `AantCtrcturenPWk` | `grossMonthlySalary` (else hourly wage times hours), `hoursPerWeek`; required for 11/13/15/17 | GS p136-137, 1614, 1615 |
| other amounts | 0 | XSD required |

### D6. Collective totals

Each collective amount is the sum of the unrounded employee amounts, cut to whole euros in the
employer's favour (GS p38, 0318 and the rounding explanation). `TotTeBet` is the sum condition 2315
names (GS p55) and `TotGen` equals it, as no previous period is corrected (GS p55, 0011). The
premium totals that the XSD marks optional are sent, because 2315 sums them.

### D4 amended

The filing stores `messageRunCalculatedAt` and `messageRunTotalLoonheffing` from the run it was made
from. Rule `nl-loonaangifte-message-drift` flags a filing whose run was recalculated after the message
was made, or whose total wage tax changed.

## Open Questions

- Which year's specification to start with: the design assumes 2026, matching the shipped
  tables.
