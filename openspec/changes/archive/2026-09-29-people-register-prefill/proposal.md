---
kind: code
---

# Fill records from the population and vehicle registers

## Why

HR types an employee's address and personal details by hand, and fleet administration
types a company car's make, first registration date and catalogue value by hand, although
both are held in a Dutch base register: the BRP for people and the RDW vehicle register for
cars. Every typed value is a chance for a wrong address on a payslip or a wrong catalogue
value under the bijtelling.

For cars the RDW data is open; Visma Raet and Loket both fill a company car from its licence
plate. For people the BRP is closed to most employers, but a government employer with a
legal basis can use it, and humaniq's core buyer includes municipalities.

### Matrix rows (humaniq `openspec/parity/capabilities.json`)

| row | capability | humaniq today |
|---|---|---|
| `ppl-brp-lookup` | Fill in an employee's address and personal details from the population register. | `no`, none: no BRP reference anywhere in the repository |
| `dm-plate-lookup` | Fill in a company car's make, first registration date and catalogue value from the vehicle register by entering its licence plate. | `no`, none: `Asset.licencePlate` is typed next to catalogue value and fuel type, no lookup |

`ppl-brp-lookup` is in humaniq's core area (people); no competitor was rated yes, the
readers could not settle it from public documents. `dm-plate-lookup` has two competitors
rated yes.

### Demand

- `dm-plate-lookup`, changelog:
  https://community.visma.com/t5/Releases-YouServe/tkb-p/nl_ys_vismayouserve_releases/page/2
  (AFAS lists the same on its Profit 9 roadmap, https://www.afas.nl/roadmap).

### Competitors rated yes

- `dm-plate-lookup`, Visma Raet Youforce: "Core en Payroll release notes 2026-02 add a link
  with the RDW that fills brand, model, first registration dates and catalogue value from the
  licence plate" (https://community.visma.com/t5/Releases-YouServe/tkb-p/nl_ys_vismayouserve_releases/page/2).
- `dm-plate-lookup`, Loket.nl: "dateOfFirstAdmission, valueForTaxPurposes and the tax
  liability group can be acquired based on the RDW-registration by calling that
  supplementary endpoint" (https://developer.loket.nl/ApiDocs, Fiscal-company-car).

## What Changes

- **Two declared connections.** humaniq declares its outside connections in a new
  `lib/Settings/connections.json` for integriq's connection registry: the RDW open data
  vehicle register and the BRP (Haal Centraal BRP Personen bevragen).
- **Fill a car from its plate.** On `AssetDetail` for a vehicle, "Fill from RDW" reads the
  licence plate, asks the RDW for make, model, first admission date, catalogue value and
  fuel type, writes the values into empty fields and lists every field where the register
  differs from what is stored, without overwriting it.
- **Fill an employee from the BRP.** On `EmployeeDetail`, "Fill from BRP" asks the BRP for
  the person with the employee's BSN and fills name, date of birth and address the same way.
  It is offered only when the administration records a legal basis for BRP use.
- **Vehicle fields.** `Asset` gains `make`, `model` and `firstAdmissionDate`.

## Capabilities

### New Capabilities

- `register-prefill`: filling employee and vehicle records from the BRP and the RDW through
  integriq, never overwriting a stored value silently.

## Impact

- `lib/Settings/connections.json` (new), validated by hydra gate 116.
- `lib/Service/RegisterPrefillService.php` (new), `lib/Controller/RegisterPrefillController.php`
  (new), `appinfo/routes.php`: `POST /api/prefill/vehicle/{assetId}`,
  `POST /api/prefill/employee/{employeeId}`.
- `lib/Settings/register.d/hr-assets.json`: three `Asset` properties.
  `lib/Settings/register.d/hr-administratie.json`: `hrAdministration.brpGrondslag`.
- `src/manifest.d/hr-assets.json`, `src/manifest.d/hr-objects.json`: one header action each.
- Depends on the address fields `people-record-change-approval` adds to `Employee`.

## Cross-app dependencies

- integriq: a Source for each connection and the call path through `CallService` with the
  credential held by OpenRegister's credential broker; humaniq never holds the BRP
  certificate or key.

## Out of scope

- Keeping records in sync with the BRP (mutation subscriptions). This is a lookup on demand.
- Computing the bijtelling category from the RDW data. `fleet-bijtelling` keeps that input.
