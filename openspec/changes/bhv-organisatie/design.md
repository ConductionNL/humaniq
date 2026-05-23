---
status: draft
---

# BHV Organisatie — Design

## Information Architecture

**Placement type**: `SUB_PAGE`  
**Navigation**: Verlof & verzuim › BHV  
**Register**: hrmq

Per ADR-001, BHV sits as a sub-page under the Verlof & verzuim top-level menu, co-locating roster, leave, and emergency response operations.

## Data Model

All domain entities stored in OpenRegister (register: `hrmq`). Cross-entity relationships via register-based references.

### Entities

#### Location
Central pand/facility record for BHV organisation.

```json
{
  "@self": {
    "register": "hrmq",
    "schema": "Location",
    "slug": "ams-hq"
  },
  "code": "AMH-001",
  "name": "Amsterdam HQ",
  "address": "Plantage Doklaan 25, 1018 CN Amsterdam",
  "postalCode": "1018CN",
  "municipality": "Amsterdam",
  "gpsCoordinates": {"lat": 52.3676, "lon": 4.9041},
  "maxOccupancy": 450,
  "floors": [
    {"floorNumber": 0, "name": "Ground", "area_m2": 800},
    {"floorNumber": 1, "name": "First", "area_m2": 650},
    {"floorNumber": 2, "name": "Second", "area_m2": 650}
  ],
  "safetyOfficerId": "emp-4521",
  "ontruimingsplanDocumentId": "doc-887",
  "riEDocumentId": "doc-888",
  "bezoekersRegistratiesourceId": "inte-visitors-api",
  "status": "actief",
  "createdAt": "2026-01-15T09:00:00Z"
}
```

#### BhvMember
Certified emergency responder assigned to one or more locations.

```json
{
  "@self": {
    "register": "hrmq",
    "schema": "BhvMember",
    "slug": "emp-4501-bhv"
  },
  "employeeId": "emp-4501",
  "name": "Jan de Vries",
  "primaryLocationId": "ams-hq",
  "secondaryLocationIds": ["ams-south-depot"],
  "roles": ["bhv", "ehbo"],
  "availabilityPattern": {
    "recurring": [
      {"dayOfWeek": 1, "startTime": "08:00", "endTime": "17:00"},
      {"dayOfWeek": 3, "startTime": "08:00", "endTime": "17:00"},
      {"dayOfWeek": 5, "startTime": "08:00", "endTime": "17:00"}
    ],
    "exceptions": [
      {"date": "2026-06-15", "startTime": "06:00", "endTime": "14:00", "reason": "extra_shift"}
    ]
  },
  "consentSharingMobile": true,
  "mobilePhoneNumber": "+31687654321",
  "status": "actief",
  "createdAt": "2025-11-01T10:30:00Z"
}
```

#### Certification
Individual qualification with issue and expiry dates.

```json
{
  "@self": {
    "register": "hrmq",
    "schema": "Certification",
    "slug": "cert-2401-001"
  },
  "bhvMemberId": "emp-4501-bhv",
  "certType": "bhv_basis",
  "issuerName": "NIBHV Trainingscentrum Amsterdam",
  "issueDate": "2023-06-01",
  "expiryDate": "2026-06-01",
  "certificateDocumentId": "doc-4501-cert-bhv",
  "creditedHours": 24,
  "status": "geldig",
  "warningsSent": [
    {"sentAt": "2026-03-01", "daysRemaining": 92, "type": "90day"}
  ],
  "createdAt": "2023-06-02T14:20:00Z"
}
```

#### BhvSchedule
Daily roster slot with required and assigned coverage.

```json
{
  "@self": {
    "register": "hrmq",
    "schema": "BhvSchedule",
    "slug": "sched-ams-hq-2026-06-15"
  },
  "locationId": "ams-hq",
  "date": "2026-06-15",
  "slotStart": "08:00",
  "slotEnd": "17:00",
  "requiredCount": 7,
  "riskMultiplier": 1.0,
  "assignedMemberIds": [
    "emp-4501-bhv",
    "emp-4502-bhv",
    "emp-4503-bhv",
    "emp-4504-bhv",
    "emp-4505-bhv",
    "emp-4506-bhv",
    "emp-4507-bhv"
  ],
  "expectedOccupancy": 350,
  "expectedOccupancySource": "visitor_register",
  "coverageStatus": "groen",
  "coverageCalculatedAt": "2026-06-15T06:00:00Z",
  "notes": "Regular shift, all members certified",
  "createdAt": "2026-06-01T08:30:00Z"
}
```

#### Drill
Evacuation exercise or incident with evaluation data.

```json
{
  "@self": {
    "register": "hrmq",
    "schema": "Drill",
    "slug": "drill-ams-hq-2026-05-20"
  },
  "locationId": "ams-hq",
  "type": "aangekondigd",
  "scheduledFor": "2026-05-20T10:00:00Z",
  "executedAt": "2026-05-20T10:02:00Z",
  "evacuationDurationSeconds": 342,
  "participantCount": 287,
  "assignedPloegleider": "emp-4501-bhv",
  "evaluationDocumentId": "doc-4501-eval-drill-2026-05",
  "lessonsLearned": [
    "Stairwell C congestion during peak evacuation",
    "Two BHV members not at assembly point at first roll-call"
  ],
  "actionItems": [
    "Increase stairwell C width signage",
    "Retrain members on assembly-point procedure"
  ],
  "createdAt": "2026-05-20T10:05:00Z"
}
```

#### InventoryItem
First-aid kit, AED, extinguisher, or other safety equipment.

```json
{
  "@self": {
    "register": "hrmq",
    "schema": "InventoryItem",
    "slug": "inv-ams-hq-aed-001"
  },
  "locationId": "ams-hq",
  "itemType": "aed",
  "position": "Ground floor hallway near main entrance",
  "serialNumber": "DEF7834329",
  "manufacturer": "Philips",
  "lastInspectedAt": "2026-04-15T10:00:00Z",
  "nextInspectionDue": "2026-10-15",
  "expiryDate": "2027-06-30",
  "condition": "operational",
  "replacementOrderId": null,
  "iotDeviceId": "iot-philips-def7834329",
  "createdAt": "2025-03-01T12:00:00Z"
}
```

#### AlarmEvent
Activation record with response timeline and outcome.

```json
{
  "@self": {
    "register": "hrmq",
    "schema": "AlarmEvent",
    "slug": "alarm-ams-hq-2026-05-18-1430"
  },
  "locationId": "ams-hq",
  "triggeredBy": "manual_activation",
  "triggeredAt": "2026-05-18T14:30:00Z",
  "alarmType": "ontruiming",
  "responseLog": [
    {
      "timestamp": "2026-05-18T14:30:05Z",
      "actor": "emp-4501-bhv",
      "action": "notification_received",
      "detail": "Push notification sent to 8 on-call members"
    },
    {
      "timestamp": "2026-05-18T14:30:22Z",
      "actor": "emp-4501-bhv",
      "action": "response_accepted",
      "detail": "Jan de Vries accepted responsibility"
    },
    {
      "timestamp": "2026-05-18T14:32:15Z",
      "actor": "emp-4501-bhv",
      "action": "evacuation_initiated",
      "detail": "Building evacuation announced"
    },
    {
      "timestamp": "2026-05-18T14:34:20Z",
      "actor": "emp-4501-bhv",
      "action": "assembly_point_verified",
      "detail": "All personnel accounted for"
    }
  ],
  "closedAt": "2026-05-18T14:35:00Z",
  "incidentReportDocumentId": "doc-incident-2026-05-18",
  "createdAt": "2026-05-18T14:30:02Z"
}
```

### Schema Definitions (OpenAPI 3.0 format)

Schemas are defined in `lib/Settings/bhv_organisatie_register.json` following the OpenRegister template pattern with `x-openregister` extensions. Each schema uses `schema.org` vocabulary where applicable.

Core properties per entity:
- **Location**: [address properties from schema.org/Place], gpsCoordinates (GeoJSON), floors (array), staffing capacity
- **BhvMember**: reference to Employee (employeeId), role array, availability pattern (recurring + exceptions), consent flags
- **Certification**: bhvMemberId reference, certType enum, issue/expiry dates, status enum, document reference
- **BhvSchedule**: locationId, date, time slot, requiredCount (calculated), assignedMemberIds array, occupancy source, coverageStatus enum
- **Drill**: locationId, type enum, execution timeline, evacuation metrics, evaluation document reference, lessons/actions arrays
- **InventoryItem**: locationId, itemType enum, serialNumber, inspection cycle, condition enum, IoT device reference
- **AlarmEvent**: locationId, trigger type, timestamp, response log array, closure, incident report reference

### Relationship Map

```
Location ←→ BhvMember (1:n)
         ←→ BhvSchedule (1:n)
         ←→ Drill (1:n)
         ←→ InventoryItem (1:n)
         ←→ AlarmEvent (1:n)

BhvMember ←→ Certification (1:n)
          ←→ BhvSchedule (n:m via assignedMemberIds)
          ←→ AlarmEvent (n:1 as responder)

Employee ←→ BhvMember (1:1 via employeeId ref to employee-master)
```

## Integration Points

### employee-master
- **Source**: BhvMember.employeeId links to Employee records
- **Triggers**: Offboarding event → BhvMember status update + compliance alert
- **Sync**: Name, location assignment, status changes

### training-opleidingen (new module)
- **Sink**: Certification expiry alerts → auto-propose training slot
- **Source**: Training completion → Certification record created
- **Enrollment**: BhvMember accepts training recommendation → creates provisional booking

### verlof-administratie
- **Constraint**: BhvSchedule roster respects approved verlof/ziek leaves
- **Sync**: Leave periods → reduce available BhvMembers in scheduler
- **Query**: When scheduling, check verlof status for candidates

### docudesk
- **Storage**: Ontruimingsplannen, RI&E docs, drill evaluations, incident reports
- **Versioning**: All documents versioned; updates trigger notification to BhvMembers
- **Retention**: Incident/drill reports retained per NEN/AVG rules (7+ years)

### openconnector
- **Inbound**: AED IoT heartbeat (condition/battery status)
- **Inbound**: Brandmeldcentrale alarm trigger
- **Inbound**: Building occupancy via BMS or visitor register
- **Outbound**: Alarm acknowledgement to BMS system
- **Webhook**: Device fault → immediate notification dispatch

### mydash
- **Dashboard widgets**: Coverage KPI (% assigned vs required), compliance status, incident count, avg response time
- **Reporting**: Link to full compliance export

### opencatalogi
- **Export**: Ontruimingsplan excerpts (public areas only) published for visitor access

### irma-digid-auth
- **Mobile app**: BHV members authenticate via Yivi/DigiD
- **Step-up**: Alarm activation, personal data edits require fresh authentication

## Reuse Analysis

- **ObjectService** (CRUD) — used for all entity create/read/update/delete
- **ImportService / ExportService** — bulk import of BhvMembers from HR, export compliance reports
- **CnDataTable, CnDetailPage** — standard list + detail UI with no custom components
- **CnFormDialog** — auto-generated create/edit forms from schema
- **NotificationService** — alert dispatch for expiries, coverage gaps, drill compliance
- **WebhookService** — IoT device events (AED, brandmeldcentrale)
- **FileService** — document upload (ontruimingsplannen, certificates, RI&E, drill reports)
- **AuditTrailService** — automatic tracking of all entity changes (who, what, when)
- **ArchivalService** — legal hold and destruction schedules for incident records
- **TasksController** — action-item tracking from drill evaluations and compliance alerts

No custom ORM, no custom webhook handlers, no custom notification logic — all provided by platform.

## Seed Data

Included in `lib/Settings/bhv_organisatie_register.json` under `components.objects[]`:

1. **3 Location records** (Amsterdam HQ, Rotterdam depot, Utrecht training facility) with realistic coordinates, capacities, safety officer assignments
2. **12 BhvMember records** (mix of single-role and multi-role; varying availability patterns)
3. **15 Certification records** (mix of valid, expiring-soon, and expired statuses with realistic dates)
4. **20 BhvSchedule records** (next 30 days with varying coverage status — some groen, some geel for testing)
5. **4 Drill records** (mix of announced, unannounced, with realistic metrics)
6. **8 InventoryItem records** (AED, EHBO kits, extinguishers at various locations)
7. **2 AlarmEvent records** (past test evacuations with full response logs)

All dates use realistic 2026 timelines; names are Dutch; postcodes are valid; employee IDs match fictional employee-master references.

## Data Validation & Constraints

- **BhvSchedule coverage check**: `assignedCount >= requiredCount` for groen status; requiredCount = ceil(occupancy/50) + floor(occupancy/100)
- **Certification status cascade**: On expiryDate, status auto-transitions to `verlopen`; member immediately removed from future schedule coverage calculations
- **Alarm response SLA**: First responder acceptance tracked; missing acceptance after 2 min → escalate to safety officer's supervisor
- **Drill frequency**: Each Location must record ≥1 Drill per calendar year; compliance check flags if missing
- **Multi-role overlap prevention**: A BhvMember with hoofd_bhv role cannot be assigned to >1 Location in same time slot

## Notes

- The `bezoekersRegistratiesourceId` on Location allows dynamic occupancy lookups; if visitor register unavailable, fall back to static `maxOccupancy`
- AED IoT integration assumes webhook delivery within 60s of device state change; slower transports documented in openconnector integration spec
- Ontruimingsplan versioning: document updates do NOT auto-approve; BhvMembers must explicitly confirm reading; only after all assigned confirm does status shift to `active`
