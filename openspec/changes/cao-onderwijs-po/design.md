---
status: design
created: 2026-05-23
---

# CAO Primair Onderwijs — Design

## Data Model

### Core Entity: CaoPoEmployment

Extends `Employment` with education-sector-specific attributes.

```
CaoPoEmployment
├─ id: UUID
├─ employmentId: UUID (foreign key to Employment)
├─ salarisschaal: Enum (LA | LB | LC | L10 | L11 | L12 | L13 | L14 | OOP | DIR)
├─ salarisnummer: Integer (1-20, seniority trede)
├─ functiecategorie: Enum (
│   groepsleerkracht 
│   | IB-er-interne-begeleider 
│   | leraar-specialist 
│   | vakleerkracht 
│   | onderwijsassistent 
│   | leraarondersteuner 
│   | directeur 
│   | adjunct-directeur 
│   | bestuurder 
│   | stafmedewerker 
│   | facilitair
│ )
├─ werktijdfactor: Decimal (0.0–1.0, represents fraction of 1659-hour normjaartaak)
├─ schoolsoort: Enum (BaO | SbO | SO | VSO)
├─ bevoegdheidsstatus: Enum (
│   bevoegd 
│   | in-opleiding-LIO 
│   | zij-instromer-ZIO 
│   | niet-bevoegd-met-toestemming 
│   | niet-bevoegd-zonder-toestemming
│ )
├─ lerarenregisterId: String (nullable; required if bevoegdheidsstatus = bevoegd)
├─ vervangingsregime: Enum (eigen-risico-bestuur | vervangingsfonds-aangesloten)
├─ opleidingstrajectId: UUID (nullable, foreign key to LIOZIOTraject)
├─ effectiveDates: Period (startDate, endDate)
└─ auditTrail: AuditLog[]
```

### Supporting Entities

#### LesgebondenUrenAllocatie
Breakdown of lesgebonden vs. not-lesgebonden hours within normjaartaak per school year.

```
LesgebondenUrenAllocatie
├─ id: UUID
├─ caoPoEmploymentId: UUID
├─ schoolYear: SchoolYear (e.g., 2026-2027)
├─ lesgebondenUren: Integer (default 940 for wtf=1.0)
├─ nietLesgebondenSchoolgebondenUren: Integer (default 559 for wtf=1.0)
├─ nietLesgebondenIndividueelKeuzeUren: Integer (default 160 for wtf=1.0)
├─ totalUren: Integer (default 1659 for wtf=1.0)
├─ effectiveDate: Date
└─ createdAt: DateTime
```

#### DuspBudget
Annual DUSP accrual and spending tracker for employees 57+.

```
DuspBudget
├─ id: UUID
├─ caoPoEmploymentId: UUID
├─ schoolYear: SchoolYear
├─ accruedHours: Integer (170 for age 57-66 at wtf=1.0, 255 for 67+)
├─ remainingHours: Integer
├─ spendingCategories: SpendingCategory[]
│  ├─ structuurWerktijdverkorting: Integer (hours allocated to permanent work-time reduction)
│  ├─ studieverof: Integer (hours allocated to study leave)
│  ├─ coachingBudget: Integer (hours or EUR)
│  └─ ikbUitruil: Integer (EUR equivalent payouts taken)
├─ duspStatus: Enum (active | paused | exhausted)
├─ effectiveDate: Date
└─ auditLog: AuditLog[]
```

#### LIOZIOTraject
Training agreement record for teacher-in-training and side-entry programs.

```
LIOZIOTraject
├─ id: UUID
├─ caoPoEmploymentId: UUID
├─ trajectType: Enum (LIO | ZIO)
├─ trajectStartDate: Date
├─ trajectEndDate: Date (geplande einddate, 2 years typical for ZIO)
├─ salarisStelsel: Enum (LIO-jaar-1 | LIO-jaar-2 | LIO-jaar-3 | ZIO-jaar-1 | ZIO-jaar-2)
├─ garanteerdeOpleidingsuren: Integer (440 for ZIO, varies by LIO-year)
├─ onderwijsuren: Integer (1219 for ZIO at wtf=1.0)
├─ opleIdingsinstelling: String (name of PABO or approved training provider)
├─ einddatumBeoordeling: Date (final exam date)
├─ conversieStatus: Enum (in-progress | completed-succesful | completed-unsuccessful | paused)
└─ auditTrail: AuditLog[]
```

#### VervangingsClaim
Replacement-fund claim lifecycle for sick-leave substitutions.

```
VervangingsClaim
├─ id: UUID
├─ ziekmeldingId: UUID (reference to sick-leave event)
├─ vervangerId: UUID (reference to substitute employment)
├─ schoolBestuurId: UUID
├─ vervangingsperiode: Period (startDate, endDate)
├─ vervangerId: UUID (employee who filled in)
├─ brutoSalariskosten: Money (EUR)
├─ wtfVervanger: Decimal
├─ claimStatus: Enum (gegenereerd | ingediend | goedgekeurd | afgewezen | reconciled)
├─ vfpfReferentie: String (nullable, VfPf claim reference)
├─ afwijzingReden: String (nullable, reason if rejected)
├─ dateSubmitted: Date (nullable)
├─ dateApproved: Date (nullable)
└─ auditTrail: AuditLog[]
```

#### ConvenantsVerlofTegoed
Annual collective-agreement leave balance.

```
ConvenantsVerlofTegoed
├─ id: UUID
├─ caoPoEmploymentId: UUID
├─ schoolYear: SchoolYear
├─ tegoedUren: Integer (428 for wtf=1.0, pro-rata scaled)
├─ benutteUren: Integer (accumulated usage)
├─ restantUren: Integer (remaining)
├─ gekoppeldAanSchoolVakanties: Boolean (default true)
├─ statusPerDatum: Status[] (changes per school-holiday period)
└─ lastUpdate: DateTime
```

#### SeniorenVerlofTegoed
Legacy pre-DUSP overgangsrecht for employees hired before 2014.

```
SeniorenVerlofTegoed
├─ id: UUID
├─ caoPoEmploymentId: UUID
├─ schoolYear: SchoolYear
├─ tegoedUren: Integer (age/seniority based; 170 for age 60-67)
├─ benutteUren: Integer
├─ restantUren: Integer
├─ bapoAfstemmingsPercentage: Decimal (typically 35%, legacy)
├─ applicableOnlyIfNotUnderDusp: Boolean (true)
└─ auditTrail: AuditLog[]
```

#### OnderwijsCaoSalarisTabel
Reference data: salary scales per CAO agreement.

```
OnderwijsCaoSalarisTabel
├─ id: UUID
├─ caoAkkoordDatum: Date (e.g., 2024-01-15 for CAO PO 2024-2025)
├─ schaal: Enum (LA | LB | LC | L10-L14 | OOP | DIR)
├─ functiecategorie: Enum (groepsleerkracht, etc.)
├─ schoolsoort: Enum (BaO | SbO | SO | VSO)
├─ bevoegdheidsstatus: Enum
├─ tredes: TredeLoon[]
│  ├─ trede: Integer (1-20)
│  ├─ maandBrutoBedrag: Money (EUR)
│  ├─ jaarBrutoBedrag: Money (EUR)
│  └─ effectiveDate: Date
├─ effectivePeriod: Period
└─ publicationDate: Date
```

#### NormjaartaakConfiguratie
Normjaartaak definition (default 1,659 hours fulltime).

```
NormjaartaakConfiguratie
├─ id: UUID
├─ effectiveDate: Date
├─ fullTimeHours: Integer (1659 default)
├─ lesgebondenUrenDefault: Integer (940)
├─ nietLesgebondenSchoolgebondenDefault: Integer (559)
├─ nietLesgebondenIndividueelDefault: Integer (160)
├─ schoolYear: SchoolYear
└─ remark: String (nullable)
```

#### DuspBudgetStaffel
Age-cohort DUSP accrual schedule.

```
DuspBudgetStaffel
├─ id: UUID
├─ caoAkkoordDatum: Date
├─ leeftijdCohort: Range (e.g., 57-66, 67+)
├─ urenbedragPerJaarWtf1: Integer (170 for 57-66, 255 for 67+)
├─ euroBedragIkbEquivalent: Money (EUR per hour, for cash-out option)
├─ schoolYear: SchoolYear
└─ effectivePeriod: Period
```

#### AbpPremietabel
Shared kernel: ABP pension premium schedule.

```
AbpPremietabel
├─ id: UUID
├─ caoAkkoordDatum: Date
├─ opPremiePercentageWerkgever: Decimal (e.g., 8.5%)
├─ opPremiePercentageWerknemer: Decimal (e.g., 8.5%)
├─ aaopPremieWerkgever: Decimal
├─ aaopPremieWerknemer: Decimal
├─ anwHiaatBedrag: Money (EUR)
├─ franchise: Money (EUR, pensioen-grondslag minimumdrempel)
├─ effectiveDate: Date
└─ abpOWOvergangsrechtDetails: OvergangsRechtDefinition (for pre-2006 hires)
```

#### VervangingsfondsPremieTabel
VfPf premium schedule for enrolled employers.

```
VervangingsfondsPremieTabel
├─ id: UUID
├─ caoAkkoordDatum: Date
├─ premiePercentageWerkgever: Decimal (e.g., 0.5% of payroll)
├─ deductiblePercentage: Decimal (amount before VfPf kicks in)
├─ maximumClaimPerGebeurtenis: Money (EUR, annual cap per sick-leave event)
├─ effectiveDate: Date
├─ schoolYear: SchoolYear
└─ remark: String (nullable)
```

## Seed Data

### OnderwijsCaoSalarisTabel — L11 Scale (2024-2025)

| Trede | SchoolSoort | Functiecategorie | MaandBrutoBedrag | EffectiveDate |
|-------|-------------|-----------------|-----------------|---------------|
| 1 | BaO | groepsleerkracht | €2.650 | 2024-09-01 |
| 2 | BaO | groepsleerkracht | €2.725 | 2024-09-01 |
| 3 | BaO | groepsleerkracht | €2.800 | 2024-09-01 |
| 4 | BaO | groepsleerkracht | €2.875 | 2024-09-01 |
| 5 | BaO | groepsleerkracht | €2.950 | 2024-09-01 |

### OnderwijsCaoSalarisTabel — LB Scale (2024-2025)

| Trede | SchoolSoort | Functiecategorie | MaandBrutoBedrag | EffectiveDate |
|-------|-------------|-----------------|-----------------|---------------|
| 1 | BaO | groepsleerkracht | €2.550 | 2024-09-01 |
| 2 | BaO | groepsleerkracht | €2.620 | 2024-09-01 |
| 3 | BaO | groepsleerkracht | €2.690 | 2024-09-01 |
| 4 | SbO | groepsleerkracht | €2.760 | 2024-09-01 |
| 5 | SO | leerkracht | €2.830 | 2024-09-01 |

### OnderwijsCaoSalarisTabel — OOP Scale (Onderwijsondersteunend Personeel)

| Trede | Functiecategorie | MaandBrutoBedrag | EffectiveDate |
|-------|-----------------|-----------------|---------------|
| 4 | onderwijsassistent | €1.900 | 2024-09-01 |
| 5 | onderwijsassistent | €1.975 | 2024-09-01 |
| 6 | leraarondersteuner | €2.050 | 2024-09-01 |
| 7 | leraarondersteuner | €2.125 | 2024-09-01 |

### CaoPoEmployment — Example Records

#### Employee 1: Full-time qualified teacher

```json
{
  "id": "emp-001",
  "employmentId": "empl-2024-0815-001",
  "salarisschaal": "L11",
  "salarisnummer": 3,
  "functiecategorie": "groepsleerkracht",
  "werktijdfactor": 1.0,
  "schoolsoort": "BaO",
  "bevoegdheidsstatus": "bevoegd",
  "lerarenregisterId": "DUO-PO-45823-2024",
  "vervangingsregime": "vervangingsfonds-aangesloten",
  "effectiveDates": {
    "startDate": "2024-08-15",
    "endDate": null
  }
}
```

#### Employee 2: Part-time teacher with DUSP

```json
{
  "id": "emp-002",
  "employmentId": "empl-2020-0901-045",
  "salarisschaal": "LB",
  "salarisnummer": 5,
  "functiecategorie": "groepsleerkracht",
  "werktijdfactor": 0.8,
  "schoolsoort": "SbO",
  "bevoegdheidsstatus": "bevoegd",
  "lerarenregisterId": "DUO-PO-31456-2019",
  "vervangingsregime": "eigen-risico-bestuur",
  "effectiveDates": {
    "startDate": "2020-09-01",
    "endDate": null
  }
}
```

#### Employee 3: ZIO (side-entry) trainee

```json
{
  "id": "emp-003",
  "employmentId": "empl-2025-0801-089",
  "salarisschaal": "LB",
  "salarisnummer": 2,
  "functiecategorie": "groepsleerkracht",
  "werktijdfactor": 0.6,
  "schoolsoort": "BaO",
  "bevoegdheidsstatus": "zij-instromer-ZIO",
  "lerarenregisterId": null,
  "vervangingsregime": "vervangingsfonds-aangesloten",
  "opleidingstrajectId": "lio-zio-2025-089",
  "effectiveDates": {
    "startDate": "2025-08-01",
    "endDate": null
  }
}
```

#### Employee 4: Educational support (OOP)

```json
{
  "id": "emp-004",
  "employmentId": "empl-2023-1001-102",
  "salarisschaal": "OOP",
  "salarisnummer": 6,
  "functiecategorie": "leraarondersteuner",
  "werktijdfactor": 0.5,
  "schoolsoort": "BaO",
  "bevoegdheidsstatus": "niet-bevoegd-zonder-toestemming",
  "lerarenregisterId": null,
  "vervangingsregime": "eigen-risico-bestuur",
  "effectiveDates": {
    "startDate": "2023-10-01",
    "endDate": null
  }
}
```

### LesgebondenUrenAllocatie — Example Records

```json
{
  "id": "les-001",
  "caoPoEmploymentId": "emp-001",
  "schoolYear": "2024-2025",
  "lesgebondenUren": 752,
  "nietLesgebondenSchoolgebondenUren": 447,
  "nietLesgebondenIndividueelKeuzeUren": 128,
  "totalUren": 1327,
  "effectiveDate": "2024-08-15"
}
```

### DuspBudget — Example Records

```json
{
  "id": "dusp-001",
  "caoPoEmploymentId": "emp-002",
  "schoolYear": "2026-2027",
  "accruedHours": 136,
  "remainingHours": 136,
  "spendingCategories": {
    "structuurWerktijdverkorting": 0,
    "studieverof": 0,
    "coachingBudget": 0,
    "ikbUitruil": 0
  },
  "duspStatus": "active",
  "effectiveDate": "2026-09-01"
}
```

### ConvenantsVerlofTegoed — Example Records

```json
{
  "id": "conv-001",
  "caoPoEmploymentId": "emp-001",
  "schoolYear": "2024-2025",
  "tegoedUren": 428,
  "benutteUren": 384,
  "restantUren": 44,
  "gekoppeldAanSchoolVakanties": true,
  "lastUpdate": "2025-05-15T10:30:00Z"
}
```

### LIOZIOTraject — Example Records

```json
{
  "id": "lio-zio-2025-089",
  "caoPoEmploymentId": "emp-003",
  "trajectType": "ZIO",
  "trajectStartDate": "2025-08-01",
  "trajectEndDate": "2027-07-31",
  "salarisStelsel": "ZIO-jaar-1",
  "garanteerdeOpleidingsuren": 440,
  "onderwijsuren": 733,
  "opleIdingsinstelling": "PABO Arnhem",
  "einddatumBeoordeling": "2027-06-30",
  "conversieStatus": "in-progress"
}
```

### VervangingsClaim — Example Records

```json
{
  "id": "vfpf-001",
  "ziekmeldingId": "ziek-2025-0514-001",
  "schoolBestuurId": "bestuur-88",
  "vervangingsperiode": {
    "startDate": "2025-05-15",
    "endDate": "2025-06-10"
  },
  "vervangerId": "emp-005",
  "brutoSalariskosten": 3200,
  "wtfVervanger": 0.6,
  "claimStatus": "ingediend",
  "vfpfReferentie": "VFP/2025/00048",
  "dateSubmitted": "2025-06-20",
  "dateApproved": null
}
```

## Entity Relationships

```
CaoPoEmployment ┌─→ LesgebondenUrenAllocatie (1:many, per school-year)
                ├─→ DuspBudget (1:many, per school-year, for age 57+)
                ├─→ ConvenantsVerlofTegoed (1:many, per school-year)
                ├─→ SeniorenVerlofTegoed (1:many, per school-year, legacy)
                └─→ LIOZIOTraject (1:1 optional, for trainees)

VervangingsClaim ┌─→ CaoPoEmployment (vervangerId, many:1)
                 └─→ SchoolBoard (vervangingsregime reference)

OnderwijsCaoSalarisTabel ─ referenced by salary-calculation engine
AbpPremietabel ─ shared kernel with other CAO modules
VervangingsfondsPremieTabel ─ referenced by VfPf claim generation
```

## Constraints & Validations

1. **L11 only in BaO + groepsleerkracht/specialist** — autres scolartypes must use LB/LC
2. **lerarenregisterId mandatory if bevoegdheidsstatus = "bevoegd"** — no exceptions
3. **werktijdfactor ≤ 1.0** — meerwerk goes to separate MeerWerkClaim entity
4. **School-year boundaries** — all date fields respect SchoolYear (01-08 to 31-07)
5. **DUSP eligibility** — age 57+ only, with specific accrual per cohort
6. **ABP required** — all contracts require active ABP registration from day 1
7. **LIO/ZIO trajectories** — must have valid end-date and conversion status tracking
8. **Vervangingsfonds regime per school board** — cannot mix regimes within same board
