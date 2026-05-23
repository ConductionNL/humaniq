---
status: proposed
---

# Design: CAO Ziekenhuizen

## Entity Schemas

### CaoZiekenhuisEmployment

Extends `Employment` with hospital-specific attributes.

```
CaoZiekenhuisEmployment {
  employmentId: UUID (inherited)
  organisationId: UUID (inherited)
  personId: UUID (inherited)
  contractStartDate: ZonedDateTime(Europe/Amsterdam) (inherited)
  contractEndDate: ZonedDateTime(Europe/Amsterdam)? (inherited)
  
  fwgFunctiegroep: Integer (5-80, required)
  salarisschaal: String (FWG-5 through FWG-80, required)
  salarisnummer: Integer (0-12, ancienniteit trede, required)
  functiebenaming: String (free text, max 255, required)
  dienstverband: Enum(
    verpleegafdeling,
    ok,
    ic,
    seh,
    polikliniek,
    ok-anesthesie,
    laboratorium,
    radiologie,
    apotheek,
    facilitair,
    administratie
  )
  inroosterbaarVoorPiket: Boolean (default false)
  bereidheidsuurtarief: Money(EUR)? (only for surgical-anaesthetic roles)
  parttimePercentage: Decimal(0.0-1.0, step 0.01, default 1.0)
  aanvangsdatumDienstverband: LocalDate (for jubilee calculation)
}
```

### FwgScoreReport

FWG 3.0 scoring with 9 sub-components.

```
FwgScoreReport {
  scoreReportId: UUID
  caoZiekenhuisEmploymentId: UUID (FK)
  reportDate: LocalDate
  
  kennisScore: Integer (0-16)
  zelfstandigheidScore: Integer (0-16)
  socialVaardigheidScore: Integer (0-16)
  risicoVerantwoordelijkheidInfluedScore: Integer (0-20)
  expressieVaardigheidScore: Integer (0-8)
  bewegingsVaardigheidScore: Integer (0-8)
  oplettendheidsScore: Integer (0-4)
  overigeFunctieEisenScore: Integer (0-4)
  inconvenientenScore: Integer (0-4)
  
  totalScore: Integer (sum of above, 0-96)
  derivedFunctiegroep: Integer (5-80)
  referentieFunctieMatch: String? (reference function name if matched)
  validationStatus: Enum(valid, incomplete, mismatch, pending_review)
}
```

### OrtClaim

Shift allowance claim per worked hour.

```
OrtClaim {
  ortClaimId: UUID
  caoZiekenhuisEmploymentId: UUID (FK)
  inroosteringId: UUID (FK, linking to shift)
  
  workDate: LocalDate
  startTime: ZonedDateTime(Europe/Amsterdam)
  endTime: ZonedDateTime(Europe/Amsterdam)
  durationMinutes: Integer
  
  dayOfWeek: Enum(monday, tuesday, wednesday, thursday, friday, saturday, sunday)
  ortPercentage: Decimal (0.00, 0.22, 0.38, 0.47, 0.49, 0.60)
  baseHourlyRate: Money(EUR)
  ortAmount: Money(EUR) = baseHourlyRate * (durationMinutes / 60) * ortPercentage
  
  claimStatus: Enum(provisional, confirmed, disputed, settled)
}
```

### BereikbaarheidsDienst

Availability service (passive on-call duty).

```
BereikbaarheidsDienst {
  bereikbaarheidsDienstId: UUID
  caoZiekenhuisEmploymentId: UUID (FK)
  inroosteringId: UUID? (FK, if linked to roster)
  
  dienstStartDate: ZonedDateTime(Europe/Amsterdam)
  dienstEndDate: ZonedDateTime(Europe/Amsterdam)
  durationHours: Decimal
  
  functieCategorie: String (e.g., "chirurg", "anesthesioloog")
  passiveHourlyRate: Money(EUR)
  passiveAmount: Money(EUR) = durationHours * passiveHourlyRate
  
  callOutSessions: Array<CallOutSession> [
    {
      callOutStartTime: ZonedDateTime,
      callOutEndTime: ZonedDateTime,
      callOutDurationMinutes: Integer,
      actualWorkType: Enum(operatie, onderzoek, consult, overig),
      callOutAmount: Money(EUR) (base + ORT)
    }
  ]
  
  totalAmount: Money(EUR) = passiveAmount + sum(callOutSessions.callOutAmount)
  dienstStatus: Enum(planned, completed, cancelled, disputed)
}
```

### AanwezigheidsDienst

On-premises attendance service (non-active).

```
AanwezigheidsDienst {
  aanwezigheidsDienstId: UUID
  caoZiekenhuisEmploymentId: UUID (FK)
  inroosteringId: UUID (FK)
  
  dienstStartTime: ZonedDateTime(Europe/Amsterdam)
  dienstEndTime: ZonedDateTime(Europe/Amsterdam)
  dienstDurationMinutes: Integer
  
  conversionFactor: Decimal (per FWG table, typically 0.4–0.6)
  convertedWageHours: Decimal = (dienstDurationMinutes / 60) * conversionFactor
  baseHourlyRate: Money(EUR)
  convertedAmount: Money(EUR) = convertedWageHours * baseHourlyRate
  
  dienstStatus: Enum(planned, completed, cancelled)
}
```

### SlaapDienst

Sleep-duty service (on-premises with sleep facility, subject to call-out).

```
SlaapDienst {
  slaapDienstId: UUID
  caoZiekenhuisEmploymentId: UUID (FK)
  inroosteringId: UUID (FK)
  
  dienstStartTime: ZonedDateTime(Europe/Amsterdam)
  dienstEndTime: ZonedDateTime(Europe/Amsterdam)
  totalDurationMinutes: Integer
  
  sleepBlocks: Array<SleepBlock> [
    {
      sleepBlockStartTime: ZonedDateTime,
      sleepBlockEndTime: ZonedDateTime,
      sleepBlockDurationMinutes: Integer,
      conversionFactor: Decimal (0.4–0.6 per FWG table),
      convertedWageHours: Decimal
    }
  ]
  
  callOutInterruptions: Array<CallOutInterruption> [
    {
      interruptionStartTime: ZonedDateTime,
      interruptionEndTime: ZonedDateTime,
      interruptionDurationMinutes: Integer,
      countAsFullWageHours: Integer
    }
  ]
  
  totalConvertedWageHours: Decimal = sum(sleepBlocks.convertedWageHours) + sum(callOutInterruptions.countAsFullWageHours)
  baseHourlyRate: Money(EUR)
  totalAmount: Money(EUR) = totalConvertedWageHours * baseHourlyRate
  
  dienstStatus: Enum(planned, completed, cancelled)
}
```

### OveruurClaim

Overtime claim for work above contracted daily shift.

```
OveruurClaim {
  overuurClaimId: UUID
  caoZiekenhuisEmploymentId: UUID (FK)
  inroosteringId: UUID (FK)
  
  workDate: LocalDate
  contractedShiftEndTime: ZonedDateTime(Europe/Amsterdam)
  actualWorkEndTime: ZonedDateTime(Europe/Amsterdam)
  overuurDurationMinutes: Integer
  
  dienstverband: Enum (to check if surgical/anaesthetic)
  overuurPercentage: Decimal (1.0 for surgical, 0.5 for others after 8h, 1.0 for weekend)
  baseHourlyRate: Money(EUR)
  overuurAmount: Money(EUR) = baseHourlyRate * (overuurDurationMinutes / 60) * overuurPercentage
  
  requiresManagerApproval: Boolean (true if overuurDurationMinutes > 60)
  claimStatus: Enum(pending_approval, approved, rejected, settled)
}
```

### AdvVerlofTegoed

Supra-statutory working-time reduction entitlement.

```
AdvVerlofTegoed {
  advTegoedId: UUID
  caoZiekenhuisEmploymentId: UUID (FK)
  tegoedYear: Integer
  
  accrualStartDate: LocalDate
  accrualEndDate: LocalDate
  contractomvangFactor: Decimal (0.0–1.0, for part-time pro-rata)
  
  annualEntitlementHours: Decimal (typically 96 * contractomvangFactor)
  accruedHours: Decimal (year-to-date)
  balanceHours: Decimal (available for election)
  
  elections: Array<AdvElection> [
    {
      electionDate: LocalDate,
      electionQuarter: Enum(Q1, Q2, Q3, Q4),
      electionType: Enum(cash_payout, extra_vacation, structured_reduction),
      electedHours: Decimal,
      payoutAmount: Money(EUR)? (for cash elections),
      vacationHoursGenerated: Decimal? (for vacation elections),
      executionStartDate: LocalDate? (for reduction elections)
    }
  ]
  
  tegoedStatus: Enum(active, settled, expired)
}
```

### TijdVoorTijdSaldo

Time-off compensation bank (alternative to cash payout for ORT/overtime).

```
TijdVoorTijdSaldo {
  tijdSaldoId: UUID
  caoZiekenhuisEmploymentId: UUID (FK)
  
  totalBalanceHours: Decimal (max 80)
  maximumBalanceHours: Decimal (default 80)
  balanceExpiryDate: LocalDate (12 months from last entry)
  
  entries: Array<TijdSaldoEntry> [
    {
      entryId: UUID,
      sourceType: Enum(ort_claim, overuur_claim),
      sourceId: UUID,
      creditedHours: Decimal (work hours + ORT fraction),
      creditDate: LocalDate,
      
      expiryDate: LocalDate (entry date + 12 months)
    }
  ]
  
  usageHistory: Array<TijdSaldoUsage> [
    {
      usageId: UUID,
      usageStartDate: LocalDate,
      usageEndDate: LocalDate,
      usedHours: Decimal,
      usageType: Enum(vacation_substitution, sick_leave, personal)
    }
  ]
  
  saldoStatus: Enum(active, at_maximum, expired_pending_payout, closed)
}
```

### Reference Data Tables

#### FwgSchaalTabel

Maps FWG scores to salary scales with effective-from dates.

```
FwgSchaalTabel {
  schaalTabelId: UUID
  caoAkkoordDatum: LocalDate (CAO agreement date)
  effectiveFromDate: LocalDate
  effectiveToDate: LocalDate?
  
  entries: Array<FwgSchaalEntry> [
    {
      functiegroep: Integer (5-80),
      salarisschaal: String (FWG-5 through FWG-80),
      scoreRangeMin: Integer,
      scoreRangeMax: Integer,
      
      trede0_baseSalary: Money(EUR),
      trede1_salary: Money(EUR),
      trede2_salary: Money(EUR),
      ...
      trede12_salary: Money(EUR)
    }
  ]
}
```

#### OrtTariefMatrix

Day-time combination matrix for ORT percentages.

```
OrtTariefMatrix {
  matrixId: UUID
  effectiveFromDate: LocalDate
  effectiveToDate: LocalDate?
  
  entries: Array<OrtMatrixEntry> [
    {
      dayOfWeek: Enum,
      timeRange: String (e.g., "00:00-06:00", "06:00-22:00", "22:00-24:00"),
      ortPercentage: Decimal,
      isHolidayOverride: Boolean? (for feestdag overrides)
    }
  ]
}
```

#### BereikbaarheidsTariefTabel

Hourly rates for availability services by role.

```
BereikbaarheidsTariefTabel {
  tariefTabelId: UUID
  effectiveFromDate: LocalDate
  effectiveToDate: LocalDate?
  
  entries: Array<BereikbaarheidsTariefEntry> [
    {
      functieCategorie: String (e.g., "chirurg", "anesthesioloog"),
      passiveHourlyRate: Money(EUR),
      callOutMultiplier: Decimal? (if applicable)
    }
  ]
}
```

#### AanwezigheidsdienstConversieTabel

Conversion factors for on-premises attendance services.

```
AanwezigheidsdienstConversieTabel {
  tabelId: UUID
  effectiveFromDate: LocalDate
  effectiveToDate: LocalDate?
  
  entries: Array<ConversionEntry> [
    {
      dienstType: Enum(aanwezigheid, slaap),
      timeOfDay: String (day/night/mixed),
      conversionFactor: Decimal (0.4-0.6)
    }
  ]
}
```

#### PfzwPremieTabel

PFZW pension premium rates (mirrored from PFZW).

```
PfzwPremieTabel {
  premieTabelId: UUID
  effectiveFromDate: LocalDate
  effectiveToDate: LocalDate?
  
  premiePercentage: Decimal (currently 25.8%)
  employerSharePercentage: Decimal (50%)
  employeeSharePercentage: Decimal (50%)
  annualFranchise: Money(EUR) (currently 17,545)
}
```

## Seed Data

### CaoZiekenhuisEmployment Examples

```json
[
  {
    "employmentId": "emp-001",
    "fwgFunctiegroep": 50,
    "salarisschaal": "FWG-50",
    "salarisnummer": 5,
    "functiebenaming": "Verpleegkundige IC",
    "dienstverband": "ic",
    "inroosterbaarVoorPiket": true,
    "bereidheidsuurtarief": null,
    "parttimePercentage": 1.0,
    "aanvangsdatumDienstverband": "2022-01-15"
  },
  {
    "employmentId": "emp-002",
    "fwgFunctiegroep": 60,
    "salarisschaal": "FWG-60",
    "salarisnummer": 8,
    "functiebenaming": "Operatieassistent",
    "dienstverband": "ok",
    "inroosterbaarVoorPiket": true,
    "bereidheidsuurtarief": {"amount": 4.85, "currency": "EUR"},
    "parttimePercentage": 1.0,
    "aanvangsdatumDienstverband": "2019-06-01"
  },
  {
    "employmentId": "emp-003",
    "fwgFunctiegroep": 40,
    "salarisschaal": "FWG-40",
    "salarisnummer": 3,
    "functiebenaming": "Administratief Medewerker",
    "dienstverband": "administratie",
    "inroosterbaarVoorPiket": false,
    "bereidheidsuurtarief": null,
    "parttimePercentage": 0.6,
    "aanvangsdatumDienstverband": "2021-03-10"
  },
  {
    "employmentId": "emp-004",
    "fwgFunctiegroep": 55,
    "salarisschaal": "FWG-55",
    "salarisnummer": 6,
    "functiebenaming": "Anesthesiemedewerker",
    "dienstverband": "ok-anesthesie",
    "inroosterbaarVoorPiket": true,
    "bereidheidsuurtarief": {"amount": 5.20, "currency": "EUR"},
    "parttimePercentage": 0.8,
    "aanvangsdatumDienstverband": "2020-09-01"
  },
  {
    "employmentId": "emp-005",
    "fwgFunctiegroep": 45,
    "salarisschaal": "FWG-45",
    "salarisnummer": 4,
    "functiebenaming": "SEH-Verpleegkundige",
    "dienstverband": "seh",
    "inroosterbaarVoorPiket": true,
    "bereidheidsuurtarief": null,
    "parttimePercentage": 1.0,
    "aanvangsdatumDienstverband": "2023-02-15"
  }
]
```

### FwgScoreReport Examples

```json
[
  {
    "scoreReportId": "fwg-001",
    "caoZiekenhuisEmploymentId": "emp-001",
    "reportDate": "2025-06-15",
    "kennisScore": 12,
    "zelfstandigheidScore": 14,
    "socialVaardigheidScore": 13,
    "risicoVerantwoordelijkheidInfluedScore": 16,
    "expressieVaardigheidScore": 6,
    "bewegingsVaardigheidScore": 7,
    "oplettendheidsScore": 3,
    "overigeFunctieEisenScore": 2,
    "inconvenientenScore": 3,
    "totalScore": 76,
    "derivedFunctiegroep": 75,
    "referentieFunctieMatch": "Verpleegkundige IC",
    "validationStatus": "valid"
  }
]
```

### OrtClaim Examples

```json
[
  {
    "ortClaimId": "ort-001",
    "caoZiekenhuisEmploymentId": "emp-001",
    "inroosteringId": "roster-001",
    "workDate": "2025-05-17",
    "startTime": "2025-05-17T22:00:00+02:00",
    "endTime": "2025-05-18T00:00:00+02:00",
    "durationMinutes": 120,
    "dayOfWeek": "saturday",
    "ortPercentage": 0.49,
    "baseHourlyRate": {"amount": 22.50, "currency": "EUR"},
    "ortAmount": {"amount": 22.05, "currency": "EUR"},
    "claimStatus": "confirmed"
  },
  {
    "ortClaimId": "ort-002",
    "caoZiekenhuisEmploymentId": "emp-001",
    "inroosteringId": "roster-002",
    "workDate": "2025-05-18",
    "startTime": "2025-05-18T06:00:00+02:00",
    "endTime": "2025-05-18T22:00:00+02:00",
    "durationMinutes": 960,
    "dayOfWeek": "sunday",
    "ortPercentage": 0.60,
    "baseHourlyRate": {"amount": 22.50, "currency": "EUR"},
    "ortAmount": {"amount": 135.00, "currency": "EUR"},
    "claimStatus": "confirmed"
  }
]
```

### BereikbaarheidsDienst Examples

```json
[
  {
    "bereikbaarheidsDienstId": "berk-001",
    "caoZiekenhuisEmploymentId": "emp-002",
    "inroosteringId": "roster-003",
    "dienstStartDate": "2025-05-16T18:00:00+02:00",
    "dienstEndDate": "2025-05-19T08:00:00+02:00",
    "durationHours": 62,
    "functieCategorie": "operatieassistent",
    "passiveHourlyRate": {"amount": 4.85, "currency": "EUR"},
    "passiveAmount": {"amount": 300.70, "currency": "EUR"},
    "callOutSessions": [
      {
        "callOutStartTime": "2025-05-17T03:00:00+02:00",
        "callOutEndTime": "2025-05-17T06:00:00+02:00",
        "callOutDurationMinutes": 180,
        "actualWorkType": "operatie",
        "callOutAmount": {"amount": 82.50, "currency": "EUR"}
      }
    ],
    "totalAmount": {"amount": 383.20, "currency": "EUR"},
    "dienstStatus": "completed"
  }
]
```

### AdvVerlofTegoed Examples

```json
[
  {
    "advTegoedId": "adv-001",
    "caoZiekenhuisEmploymentId": "emp-001",
    "tegoedYear": 2025,
    "accrualStartDate": "2025-01-01",
    "accrualEndDate": "2025-12-31",
    "contractomvangFactor": 1.0,
    "annualEntitlementHours": 96.0,
    "accruedHours": 48.0,
    "balanceHours": 48.0,
    "elections": [],
    "tegoedStatus": "active"
  },
  {
    "advTegoedId": "adv-002",
    "caoZiekenhuisEmploymentId": "emp-003",
    "tegoedYear": 2025,
    "accrualStartDate": "2025-01-01",
    "accrualEndDate": "2025-12-31",
    "contractomvangFactor": 0.6,
    "annualEntitlementHours": 57.6,
    "accruedHours": 28.8,
    "balanceHours": 8.8,
    "elections": [
      {
        "electionDate": "2025-03-31",
        "electionQuarter": "Q1",
        "electionType": "cash_payout",
        "electedHours": 20.0,
        "payoutAmount": {"amount": 480.00, "currency": "EUR"},
        "vacationHoursGenerated": null,
        "executionStartDate": null
      }
    ],
    "tegoedStatus": "active"
  }
]
```

### TijdVoorTijdSaldo Examples

```json
[
  {
    "tijdSaldoId": "tvt-001",
    "caoZiekenhuisEmploymentId": "emp-001",
    "totalBalanceHours": 28.5,
    "maximumBalanceHours": 80.0,
    "balanceExpiryDate": "2026-05-23",
    "entries": [
      {
        "entryId": "tvt-entry-001",
        "sourceType": "ort_claim",
        "sourceId": "ort-001",
        "creditedHours": 2.58,
        "creditDate": "2025-05-17",
        "expiryDate": "2026-05-17"
      },
      {
        "entryId": "tvt-entry-002",
        "sourceType": "ort_claim",
        "sourceId": "ort-002",
        "creditedHours": 15.36,
        "creditDate": "2025-05-18",
        "expiryDate": "2026-05-18"
      }
    ],
    "usageHistory": [],
    "saldoStatus": "active"
  }
]
```

### Reference Tables

#### FwgSchaalTabel Excerpt (2025-2027)

```json
{
  "schaalTabelId": "fwg-tabel-2025",
  "caoAkkoordDatum": "2024-10-15",
  "effectiveFromDate": "2025-01-01",
  "effectiveToDate": "2027-12-31",
  "entries": [
    {
      "functiegroep": 40,
      "salarisschaal": "FWG-40",
      "scoreRangeMin": 36,
      "scoreRangeMax": 40,
      "trede0_baseSalary": {"amount": 2680.00, "currency": "EUR"},
      "trede1_salary": {"amount": 2750.00, "currency": "EUR"},
      "trede2_salary": {"amount": 2820.00, "currency": "EUR"},
      "trede3_salary": {"amount": 2890.00, "currency": "EUR"},
      "trede4_salary": {"amount": 2960.00, "currency": "EUR"},
      "trede5_salary": {"amount": 3030.00, "currency": "EUR"},
      "trede6_salary": {"amount": 3100.00, "currency": "EUR"},
      "trede7_salary": {"amount": 3170.00, "currency": "EUR"},
      "trede8_salary": {"amount": 3240.00, "currency": "EUR"},
      "trede9_salary": {"amount": 3310.00, "currency": "EUR"},
      "trede10_salary": {"amount": 3380.00, "currency": "EUR"},
      "trede11_salary": {"amount": 3450.00, "currency": "EUR"},
      "trede12_salary": {"amount": 3520.00, "currency": "EUR"}
    },
    {
      "functiegroep": 50,
      "salarisschaal": "FWG-50",
      "scoreRangeMin": 46,
      "scoreRangeMax": 50,
      "trede0_baseSalary": {"amount": 3200.00, "currency": "EUR"},
      "trede1_salary": {"amount": 3280.00, "currency": "EUR"},
      "trede2_salary": {"amount": 3360.00, "currency": "EUR"},
      "trede3_salary": {"amount": 3440.00, "currency": "EUR"},
      "trede4_salary": {"amount": 3520.00, "currency": "EUR"},
      "trede5_salary": {"amount": 3600.00, "currency": "EUR"},
      "trede6_salary": {"amount": 3680.00, "currency": "EUR"},
      "trede7_salary": {"amount": 3760.00, "currency": "EUR"},
      "trede8_salary": {"amount": 3840.00, "currency": "EUR"},
      "trede9_salary": {"amount": 3920.00, "currency": "EUR"},
      "trede10_salary": {"amount": 4000.00, "currency": "EUR"},
      "trede11_salary": {"amount": 4080.00, "currency": "EUR"},
      "trede12_salary": {"amount": 4160.00, "currency": "EUR"}
    }
  ]
}
```

#### OrtTariefMatrix (Current NVZ)

```json
{
  "matrixId": "ort-matrix-nvz-2025",
  "effectiveFromDate": "2025-01-01",
  "effectiveToDate": null,
  "entries": [
    {
      "dayOfWeek": "monday",
      "timeRange": "06:00-22:00",
      "ortPercentage": 0.0,
      "isHolidayOverride": false
    },
    {
      "dayOfWeek": "monday",
      "timeRange": "00:00-06:00",
      "ortPercentage": 0.47,
      "isHolidayOverride": false
    },
    {
      "dayOfWeek": "monday",
      "timeRange": "22:00-24:00",
      "ortPercentage": 0.47,
      "isHolidayOverride": false
    },
    {
      "dayOfWeek": "saturday",
      "timeRange": "06:00-22:00",
      "ortPercentage": 0.38,
      "isHolidayOverride": false
    },
    {
      "dayOfWeek": "saturday",
      "timeRange": "00:00-06:00",
      "ortPercentage": 0.49,
      "isHolidayOverride": false
    },
    {
      "dayOfWeek": "saturday",
      "timeRange": "22:00-24:00",
      "ortPercentage": 0.49,
      "isHolidayOverride": false
    },
    {
      "dayOfWeek": "sunday",
      "timeRange": "00:00-24:00",
      "ortPercentage": 0.60,
      "isHolidayOverride": false
    },
    {
      "dayOfWeek": "tuesday",
      "timeRange": "00:00-24:00",
      "ortPercentage": 0.60,
      "isHolidayOverride": true,
      "holidayName": "Eerste Kerstdag / Tweede Kerstdag / etc."
    }
  ]
}
```
