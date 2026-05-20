# Design: payroll-cao-mvp

## Context

HRMQ stores HR objects in OpenRegister. The payroll-core-basic change (blocking dependency) implements bruto-netto calculation but needs CAO rules to enforce sector-specific salary minimums. This change introduces a `Cao` schema in OpenRegister with pre-built rulesets loaded at install time. No custom DB tables — all CAO objects live in OpenRegister via `ObjectService`.

## Goals / Non-Goals

**Goals:**
- Ship 10 CAO JSON rulesets covering salary scales, working hours, leave, and overtime
- Admin can view and activate CAOs within their Nextcloud organisation
- Employee contract can reference a CAO object (UUID relation) for payroll-core-basic to consume
- All CAO data retrievable via REST API for use by payroll calculation engine

**Non-Goals:**
- Auto-updating CAO rulesets from external sources (manual update per CAO year)
- Supporting non-NL CAOs or collective agreements from BE/DE
- Generating payslips — that is payroll-core-basic scope
- CAO negotiation workflow or approval process

## Decisions

### Decision 1: JSON ruleset stored as single OpenRegister object

Each CAO is one OpenRegister object under the `hrmq` register and `cao` schema. The `rules` field holds the full JSON ruleset (salary scales, working hours, leave, allowances). This avoids a separate relational structure and allows the payroll engine to fetch a single object per calculation run. The ruleset is read-only at runtime; updates happen via a new repair step on each CAO version bump.

### Decision 2: Pre-built rulesets loaded via IRepairStep

The 10 CAO JSON files live in `resources/cao-rulesets/`. An `InstallCaoRulesets` repair step (run on install and upgrade) saves them into OpenRegister using `ObjectService::saveObject()`. This keeps seed data in version control and ensures clean installs always have all 10 CAOs. Existing CAO objects are matched by `identifier` (sector slug) + `version`; if both match, the ruleset is skipped (idempotent).

### Decision 3: Activation is per-organisation flag, not deletion

A CAO can be activated or deactivated for an organisation without deleting the underlying object. The `isActive` boolean on the CAO object is scoped to the organisation via the Nextcloud `userId`/group context passed through `IUserSession`. Multiple organisations sharing the same Nextcloud instance each manage their own activation state independently.

### Decision 4: Employee-contract CAO link via OpenRegister relation

The employee's `Contract` object (from contract-management change) holds a `cao` relation field (register: `hrmq`, schema: `cao`, objectId: `<cao-uuid>`). No foreign keys. The payroll engine resolves the relation at calculation time using `ObjectService::findObject()`.

## Data Model

### Schema: `cao`

Register: `hrmq`

| Property | Type | Required | Description |
|---|---|---|---|
| `name` | `string` | yes | Full CAO name (e.g., "CAO Schoonmaak- en Glazenwassersbedrijf") |
| `identifier` | `string` | yes | Sector slug (e.g., `schoonmaak`). Unique per version. |
| `version` | `string` | yes | CAO year/edition (e.g., `2026`) |
| `schema:startDate` | `date` | yes | Effective from (e.g., `2026-01-01`) |
| `schema:endDate` | `date` | no | Effective until; null = open-ended |
| `minimumHourlyRate` | `number` | yes | Lowest salary scale step in €/hour |
| `standardWeeklyHours` | `number` | yes | Standard work week in hours |
| `isActive` | `boolean` | yes | Whether this CAO is activated for the requesting organisation |
| `rules` | `object` | yes | Full JSON ruleset (see ruleset structure below) |

#### Ruleset structure (`rules` field)

```json
{
  "salaryScales": [
    {
      "scaleId": "A",
      "description": "Schaal omschrijving",
      "steps": [
        { "step": 1, "hourlyRate": 13.68 },
        { "step": 2, "hourlyRate": 14.20 }
      ]
    }
  ],
  "workingHours": {
    "weeklyHours": 38,
    "maxOvertimeHoursPerWeek": 10,
    "overtimePremiumPercent": 25
  },
  "leaveEntitlements": {
    "vacationDaysPerYear": 25,
    "vacationAllowancePercent": 8,
    "sickLeaveWaitingDays": 0
  },
  "allowances": {
    "travelAllowancePerKm": 0.23,
    "unsocialHoursPremiumPercent": 15
  }
}
```

## Seed Data

Five representative CAO objects (Dutch values; all 10 are shipped but these illustrate the structure):

**1 — CAO Schoonmaak- en Glazenwassersbedrijf 2026**
```json
{
  "name": "CAO Schoonmaak- en Glazenwassersbedrijf",
  "identifier": "schoonmaak",
  "version": "2026",
  "schema:startDate": "2026-01-01",
  "schema:endDate": null,
  "minimumHourlyRate": 13.68,
  "standardWeeklyHours": 38,
  "isActive": true,
  "rules": {
    "salaryScales": [
      {"scaleId":"A","description":"Algemeen medewerker","steps":[{"step":1,"hourlyRate":13.68},{"step":2,"hourlyRate":14.12},{"step":3,"hourlyRate":14.58}]},
      {"scaleId":"B","description":"Medewerker gespecialiseerd","steps":[{"step":1,"hourlyRate":14.80},{"step":2,"hourlyRate":15.30}]}
    ],
    "workingHours":{"weeklyHours":38,"maxOvertimeHoursPerWeek":10,"overtimePremiumPercent":25},
    "leaveEntitlements":{"vacationDaysPerYear":25,"vacationAllowancePercent":8,"sickLeaveWaitingDays":0},
    "allowances":{"travelAllowancePerKm":0.23,"unsocialHoursPremiumPercent":15}
  }
}
```

**2 — CAO Horeca 2026**
```json
{
  "name": "CAO Horeca",
  "identifier": "horeca",
  "version": "2026",
  "schema:startDate": "2026-01-01",
  "schema:endDate": null,
  "minimumHourlyRate": 13.27,
  "standardWeeklyHours": 38,
  "isActive": true,
  "rules": {
    "salaryScales": [
      {"scaleId":"I","description":"Hulpkracht","steps":[{"step":1,"hourlyRate":13.27}]},
      {"scaleId":"II","description":"Medewerker","steps":[{"step":1,"hourlyRate":14.00},{"step":2,"hourlyRate":14.55}]},
      {"scaleId":"III","description":"Leidinggevende","steps":[{"step":1,"hourlyRate":16.20},{"step":2,"hourlyRate":17.40}]}
    ],
    "workingHours":{"weeklyHours":38,"maxOvertimeHoursPerWeek":12,"overtimePremiumPercent":30},
    "leaveEntitlements":{"vacationDaysPerYear":25,"vacationAllowancePercent":8,"sickLeaveWaitingDays":0},
    "allowances":{"travelAllowancePerKm":0.23,"unsocialHoursPremiumPercent":20}
  }
}
```

**3 — CAO Kappersbedrijf 2026**
```json
{
  "name": "CAO Kappersbedrijf",
  "identifier": "kappers",
  "version": "2026",
  "schema:startDate": "2026-01-01",
  "schema:endDate": null,
  "minimumHourlyRate": 13.50,
  "standardWeeklyHours": 40,
  "isActive": false,
  "rules": {
    "salaryScales": [
      {"scaleId":"1","description":"Junior kapper","steps":[{"step":1,"hourlyRate":13.50},{"step":2,"hourlyRate":13.90}]},
      {"scaleId":"2","description":"Kapper","steps":[{"step":1,"hourlyRate":14.40},{"step":2,"hourlyRate":15.10}]},
      {"scaleId":"3","description":"Senior kapper","steps":[{"step":1,"hourlyRate":15.80},{"step":2,"hourlyRate":16.60}]}
    ],
    "workingHours":{"weeklyHours":40,"maxOvertimeHoursPerWeek":8,"overtimePremiumPercent":25},
    "leaveEntitlements":{"vacationDaysPerYear":25,"vacationAllowancePercent":8,"sickLeaveWaitingDays":0},
    "allowances":{"travelAllowancePerKm":0.21,"unsocialHoursPremiumPercent":0}
  }
}
```

**4 — CAO Bouwnijverheid (UTA) 2026**
```json
{
  "name": "CAO Bouwnijverheid (UTA)",
  "identifier": "bouw",
  "version": "2026",
  "schema:startDate": "2026-01-01",
  "schema:endDate": null,
  "minimumHourlyRate": 15.21,
  "standardWeeklyHours": 40,
  "isActive": false,
  "rules": {
    "salaryScales": [
      {"scaleId":"A","description":"Vakarbeider","steps":[{"step":1,"hourlyRate":15.21},{"step":2,"hourlyRate":16.10},{"step":3,"hourlyRate":17.00}]},
      {"scaleId":"B","description":"Gespecialiseerd vakarbeider","steps":[{"step":1,"hourlyRate":17.90},{"step":2,"hourlyRate":18.80}]}
    ],
    "workingHours":{"weeklyHours":40,"maxOvertimeHoursPerWeek":10,"overtimePremiumPercent":50},
    "leaveEntitlements":{"vacationDaysPerYear":25,"vacationAllowancePercent":8,"sickLeaveWaitingDays":0},
    "allowances":{"travelAllowancePerKm":0.23,"unsocialHoursPremiumPercent":0}
  }
}
```

**5 — CAO ICT 2026**
```json
{
  "name": "CAO ICT",
  "identifier": "ict",
  "version": "2026",
  "schema:startDate": "2026-01-01",
  "schema:endDate": null,
  "minimumHourlyRate": 15.00,
  "standardWeeklyHours": 40,
  "isActive": false,
  "rules": {
    "salaryScales": [
      {"scaleId":"1","description":"Junior medewerker","steps":[{"step":1,"hourlyRate":15.00},{"step":2,"hourlyRate":16.20}]},
      {"scaleId":"2","description":"Medior medewerker","steps":[{"step":1,"hourlyRate":18.50},{"step":2,"hourlyRate":20.00}]},
      {"scaleId":"3","description":"Senior medewerker","steps":[{"step":1,"hourlyRate":23.00},{"step":2,"hourlyRate":26.00}]}
    ],
    "workingHours":{"weeklyHours":40,"maxOvertimeHoursPerWeek":8,"overtimePremiumPercent":25},
    "leaveEntitlements":{"vacationDaysPerYear":28,"vacationAllowancePercent":8,"sickLeaveWaitingDays":0},
    "allowances":{"travelAllowancePerKm":0.23,"unsocialHoursPremiumPercent":0}
  }
}
```

## Reuse Analysis (ADR-012)

- **ObjectService**: CAO CRUD uses `findObjects($register, $schema, $params)` and `saveObject($register, $schema, $object)` — no custom DB layer.
- **SchemaService**: CAO schema registered and resolved via OpenRegister `SchemaService`.
- **ConfigurationService**: Not applicable; CAO activation state stored on the object itself (per-org `isActive` flag managed via `IUserSession` context).
- **@conduction/nextcloud-vue**: `CnIndexPage` for CAO list, `CnDetailPage` for CAO detail, `CnObjectDataWidget` for rule display — no custom layout needed.
- No overlap with existing shared services. CAO-specific salary-scale logic stays in `CaoService` and is not a candidate for OpenRegister core (domain-specific to NL payroll).
