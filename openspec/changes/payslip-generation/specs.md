# Specifications: Payslip / Loonstrook Generation

Requirements use GIVEN/WHEN/THEN format. REQ-LSG = Loonstrook Generatie, REQ-WPR = Werknemer Portaal, REQ-JAG = Jaaropgaaf Generatie.

---

## REQ-LSG-001: PDF loonstrook generation in NL standard format

**Description**  
The system must generate a PDF loonstrook in the standard Dutch NL format containing bruto loon, netto loon, inhoudingen (loonheffing, ZVW, pensioenpremie), toeslagen, and cumulatieven per salarisperiode.

**Priority**: Must

**Scenario 1 — Successful PDF generation**

```
GIVEN a Loonstrook object exists in status "gegenereerd"
  AND the object contains valid brutoLoon, nettoLoon, loonheffing, zvwBijdrage, pensioenpremie fields
  AND the associated werknemer record exists
WHEN the salarisadministrateur triggers PDF generation via POST /api/loonstroken/{id}/pdf
THEN the system renders a PDF using the NL-standard loonstrook template (Twig + Dompdf)
  AND the PDF contains sections: Bruto Loon, Inhoudingen, Netto Loon, Cumulatieven
  AND the PDF is stored via FileService and linked to the Loonstrook object
  AND the Loonstrook status remains "gegenereerd" until explicitly published
  AND the response returns HTTP 200 with the FileService reference URL
```

**Scenario 2 — Loonstrook with toeslagen and inhoudingen**

```
GIVEN a Loonstrook object has non-empty toeslagen array (e.g. "Onregelmatigheidstoeslag € 320,00")
  AND a non-empty inhoudingen array (e.g. "Loonbeslag € 150,00")
WHEN PDF generation is triggered
THEN each toeslag appears as a line item under "Bruto Loon" with omschrijving and bedrag
  AND each inhouding appears under "Inhoudingen" with omschrijving and bedrag
  AND nettoLoon = brutoLoon + sum(toeslagen) - loonheffing - zvwBijdrage - pensioenpremie - sum(inhoudingen)
```

**Scenario 3 — BSN masking in PDF**

```
GIVEN the associated werknemer record contains a BSN
WHEN the PDF is rendered
THEN the BSN field displays as "***" (fully masked) in the PDF output
  AND the raw BSN value is never included in any HTTP response or file content
```

**Scenario 4 — Missing required data**

```
GIVEN a Loonstrook object is missing a required field (e.g. brutoLoon is null)
WHEN PDF generation is triggered
THEN the system returns HTTP 422 with message "Onvolledig loonstrook: vereiste velden ontbreken"
  AND no PDF is generated or stored
  AND the Loonstrook status remains unchanged
```

---

## REQ-LSG-002: Loonstrook lifecycle management

**Description**  
Loonstroken follow a defined lifecycle: `concept → gegenereerd → gepubliceerd → gedownload`. Transitions are managed via the `x-openregister-lifecycle` declarative extension in the schema register.

**Priority**: Must

**Scenario 1 — Lifecycle transition: concept to gegenereerd**

```
GIVEN a Loonstrook object exists in status "concept"
  AND all required financial fields are populated (from LoonstrookGeneratieJob)
WHEN the salarisadministrateur triggers PDF generation via POST /api/loonstroken/{id}/pdf
THEN the Loonstrook transitions to status "gegenereerd"
  AND the generatieDatum is set to the current timestamp
  AND the lifecycle transition is recorded in the OpenRegister audit trail
```

**Scenario 2 — Lifecycle transition: gegenereerd to gepubliceerd**

```
GIVEN a Loonstrook object is in status "gegenereerd"
  AND a PDF has been generated and stored
WHEN the salarisadministrateur publishes the loonstrook via POST /api/loonstroken/{id}/publish
THEN the Loonstrook transitions to status "gepubliceerd"
  AND publicatieDatum is set to the current timestamp
  AND the declarative notification triggers: werknemer receives a Nextcloud notification "Uw loonstrook over [periodeOmschrijving] is beschikbaar"
```

**Scenario 3 — Lifecycle transition: gepubliceerd to gedownload**

```
GIVEN a Loonstrook object is in status "gepubliceerd"
WHEN a werknemer or admin downloads the PDF via GET /api/loonstroken/{id}/download
THEN the Loonstrook transitions to status "gedownload" on first download by the werknemer
  AND the audit trail records the downloading user's UID and timestamp
  AND subsequent downloads by the same user do not change the status again
```

**Scenario 4 — Invalid transition attempt**

```
GIVEN a Loonstrook object is in status "concept"
WHEN a request is made to publish it (skipping "gegenereerd")
THEN the system returns HTTP 409 with message "Ongeldige statusovergang: loonstrook is nog niet gegenereerd"
  AND the status remains "concept"
```

---

## REQ-LSG-003: Batch loonstrook generation after SalarisRun

**Description**  
When a SalarisRun (from payroll-core-basic) completes, the system automatically creates Loonstrook objects in `concept` status for all employees included in the run.

**Priority**: Must

**Scenario 1 — Successful batch creation**

```
GIVEN a SalarisRun object transitions to status "voltooid" in payroll-core-basic
WHEN the LoonstrookGeneratieJob processes the completion event
THEN one Loonstrook object is created per employee included in the SalarisRun
  AND each Loonstrook is populated with: brutoLoon, nettoLoon, loonheffing, zvwBijdrage, pensioenpremie, cumulatieven from the SalarisRun calculation data
  AND each Loonstrook has status "concept"
  AND each Loonstrook includes a relation to the SalarisRun object
  AND the job runs asynchronously (does not block the SalarisRun completion response)
```

**Scenario 2 — Employee without payroll data**

```
GIVEN a SalarisRun includes an employee with incomplete contract data
WHEN LoonstrookGeneratieJob processes the run
THEN a Loonstrook object is NOT created for that employee
  AND a warning is logged server-side with the employee ID and reason
  AND all other employees' Loonstroken are created successfully
  AND the salarisadministrateur receives a notification listing the skipped employees
```

**Scenario 3 — Duplicate prevention**

```
GIVEN a Loonstrook already exists for werknemer X and periode "2026-01"
WHEN LoonstrookGeneratieJob processes a SalarisRun for the same periode
THEN no duplicate Loonstrook is created for werknemer X
  AND the job logs "Loonstrook al aanwezig voor [werknemerId] periode [periode] — overgeslagen"
```

---

## REQ-LSG-004: Admin loonstrook management

**Description**  
Salarisadministrateurs can view, filter, batch-publish, and re-generate loonstroken across all employees.

**Priority**: Must

**Scenario 1 — Batch publish**

```
GIVEN multiple Loonstrook objects in status "gegenereerd"
  AND a salarisadministrateur is authenticated
WHEN the admin selects multiple loonstroken and triggers bulk publish via CnMassActionBar
THEN all selected loonstroken transition to status "gepubliceerd"
  AND each affected werknemer receives a Nextcloud notification
  AND the admin sees a confirmation summary: "X loonstroken gepubliceerd"
```

**Scenario 2 — Filter by status and period**

```
GIVEN loonstroken exist across multiple statuses and periods
WHEN the admin filters by status "gegenereerd" and periode "2026-02"
THEN only loonstroken matching both filters are displayed
  AND the total count reflects the filtered set
```

---

## REQ-WPR-001: Employee portal — payslip list

**Description**  
Authenticated employees can access a digital portal showing their own loonstroken, filtered to their user account.

**Priority**: Must

**Scenario 1 — Employee sees only own payslips**

```
GIVEN a werknemer is authenticated with Nextcloud credentials
  AND loonstroken exist for multiple werknemers
WHEN the werknemer navigates to the Loonstroken portal page
THEN only loonstroken with werknemerId matching the authenticated user's UID are displayed
  AND loonstroken belonging to other werknemers are never returned — enforced at the API layer
  AND the list is sorted by periode descending (most recent first)
```

**Scenario 2 — No payslips available**

```
GIVEN a werknemer has no loonstroken in status "gepubliceerd" or "gedownload"
WHEN the werknemer accesses the portal
THEN the page displays an empty state: "Nog geen loonstroken beschikbaar. Uw salarisadministrateur publiceert uw loonstroken na de salarisrun."
```

**Scenario 3 — Pagination**

```
GIVEN a werknemer has more than 10 loonstroken
WHEN the portal page loads
THEN the list shows 10 loonstroken per page with CnPagination controls
  AND the werknemer can navigate to older loonstroken via pagination
```

---

## REQ-WPR-002: Employee portal — payslip download

**Description**  
Employees can download their loonstrook as PDF directly from the portal.

**Priority**: Must

**Scenario 1 — Successful download**

```
GIVEN a Loonstrook in status "gepubliceerd" or "gedownload" exists for the authenticated werknemer
WHEN the werknemer clicks "Download PDF" on the loonstrook detail page
THEN the system returns the stored PDF via FileService
  AND the browser triggers a file download with filename "Loonstrook_[periode]_[werknemerNaam].pdf"
  AND the Loonstrook status transitions to "gedownload" on first download
  AND the download event is recorded in the audit trail
```

**Scenario 2 — Unauthorized download attempt**

```
GIVEN werknemer A is authenticated
WHEN werknemer A requests GET /api/loonstroken/{id}/download where the loonstrook belongs to werknemer B
THEN the system returns HTTP 403 with message "Niet geautoriseerd"
  AND no file data is returned
  AND the attempt is logged server-side with both user IDs
```

---

## REQ-WPR-003: Notification on new payslip

**Description**  
Employees receive a Nextcloud notification when a new loonstrook is published to their portal.

**Priority**: Must

**Scenario 1 — Notification on publish**

```
GIVEN a Loonstrook transitions to status "gepubliceerd" via the lifecycle engine
WHEN the x-openregister-notifications declaration triggers
THEN the system sends a Nextcloud notification to the werknemer:
     Title: "Nieuwe loonstrook beschikbaar"
     Body:  "Uw loonstrook over [periodeOmschrijving] is gepubliceerd door uw werkgever."
  AND the notification contains a deep link to the loonstrook detail page
  AND the notification is visible in the Nextcloud notification bell
```

**Scenario 2 — No notification for concept/gegenereerd status**

```
GIVEN a Loonstrook transitions from concept to gegenereerd (PDF generated but not yet published)
WHEN the lifecycle transition occurs
THEN no notification is sent to the werknemer
  AND only internal audit trail records the transition
```

---

## REQ-JAG-001: Jaaropgaaf generation

**Description**  
At year end (or on demand), the salarisadministrateur can generate a jaaropgaaf PDF for one or all employees, aggregating the full year's loonstrook data.

**Priority**: Must

**Scenario 1 — Batch jaaropgaaf generation**

```
GIVEN loonstroken exist for all werknemers for the requested year (e.g. 2025)
  AND the salarisadministrateur is authenticated as admin
WHEN the admin submits POST /api/jaaropgaven/batch with body {"jaar": 2025}
THEN the system creates one Jaaropgaaf object per werknemer
  AND totaalBrutoLoon = sum of all Loonstrook.brutoLoon for that werknemer in jaar 2025
  AND totaalLoonheffing = sum of all Loonstrook.loonheffing for that werknemer in jaar 2025
  AND totaalZvwWerknemersaandeel = sum of all Loonstrook.zvwBijdrage for that werknemer in 2025
  AND aantalLoonperioden = count of Loonstrook objects for that werknemer in 2025
  AND each Jaaropgaaf starts in status "concept"
  AND the response returns HTTP 202 with a job ID for async tracking
```

**Scenario 2 — Missing periods**

```
GIVEN a werknemer has loonstroken for only 10 of 12 months in a year (e.g. joined mid-year)
WHEN a Jaaropgaaf is generated for that year
THEN totaalBrutoLoon reflects only the months with loonstroken (not annualized)
  AND aantalLoonperioden = 10
  AND a note field on the Jaaropgaaf reads "Dienstverband gestart [startDatum]; [N] van 12 perioden aanwezig"
```

**Scenario 3 — Jaaropgaaf PDF**

```
GIVEN a Jaaropgaaf object in status "concept"
WHEN the admin triggers PDF generation via POST /api/jaaropgaven/{id}/pdf
THEN the PDF is rendered using the NL jaaropgaaf template (kolommen 1, 2, 14 per Belastingdienst format)
  AND the PDF contains: werkgever info, werknemer info (BSN masked), fiscal year, all totaal fields
  AND the Jaaropgaaf status transitions to "gegenereerd"
```

---

## REQ-JAG-002: Jaaropgaaf employee access

**Description**  
Employees can view and download their jaaropgaaf from the portal.

**Priority**: Must

**Scenario 1 — Employee views jaaropgaaf**

```
GIVEN a Jaaropgaaf in status "gepubliceerd" exists for the authenticated werknemer
WHEN the werknemer navigates to the Jaaropgaven section of the portal
THEN the jaaropgaaf for their own user account is displayed
  AND jaaropgaven from other werknemers are never returned (server-side filter by UID)
  AND the werknemer can download the PDF
```

**Scenario 2 — Historical jaaropgaven**

```
GIVEN jaaropgaven exist for a werknemer for years 2023, 2024, and 2025
WHEN the werknemer accesses the jaaropgaven list
THEN all three years are shown, sorted most recent first
  AND each row shows: jaar, werkgeverNaam, totaalBrutoLoon, status, download button
```
