---
kind: code
---

# Secondments and side activities on the personnel file

## Why

Two facts about an employee that a municipality must keep have no place in humaniq.

An employee seconded (gedetacheerd) to another organisation works there for a period and a
number of hours while staying on the municipality's payroll. humaniq cannot record that: the
only trace is a free-text note an HR adviser might type into the cost-rate override reason,
whose own schema note says the derivation "cannot be told this employee is seconded". The
agenda and availability reads count the person as fully available at home.

Side activities (nevenwerkzaamheden) must be reported by every civil servant under the
Ambtenarenwet 2017 art. 9, and HR has to judge whether they conflict with the job. humaniq
keeps one yes or no box, `nevenwerkzaamhedenGemeld`, which says a report exists but not what
it says, and nothing a manager can approve.

A municipal tender asks for both registers.

### Matrix rows (humaniq `openspec/parity/capabilities.json`)

| row | capability | humaniq today |
|---|---|---|
| `td-secondment` | Register an employee who is seconded to another organisation. | `no`, none: no field or schema records an outward secondment |
| `td-side-activities` | Register an employee's side activities inside and outside the organisation. | `partial`: a yes or no attestation (`Employee.nevenwerkzaamhedenGemeld`) checked by `nl-ambtenaar-nevenwerkzaamheden-melding`; no register of the activities |

### Demand

- `td-secondment`, tender: https://www.tenderned.nl/aankondigingen/overzicht/415227
  (Sudwest-Fryslan E6.10, extern gedetacheerde medewerkers registreren).
- `td-side-activities`, tender: https://www.tenderned.nl/aankondigingen/overzicht/415227
  (Sudwest-Fryslan E6.8, nevenactiviteiten registreren bij instroom en gedurende loopbaan).

### Competitors rated yes

- `td-side-activities`, AFAS Profit: "with the workflow Nevenwerkzaamheid an employee reports
  side activities, which the manager approves or rejects"
  (https://help.afas.nl/help/NL/SE/142427.htm).
- `td-side-activities`, Visma Raet Youforce: "the Aanvragen of beeindigen tab offers a
  Nevenfuncties form for employees"
  (https://www.ssc-ons.nl/content/uploads/2024/07/Handleiding-Mijn-Youforce-1.pdf).
- `td-secondment`: no competitor was rated yes; the tender alone decides it.

## What Changes

- **A secondment record.** A new `Secondment` carries the employee, the receiving
  organisation (name and KvK number), the period, the hours per week spent there, the rate
  agreed with the receiving organisation, the signed agreement as a file, and a status.
- **Secondments count in planning.** The agenda shows a secondment as its own entry, and
  the availability and capacity reads treat those hours as committed, so a seconded person
  is not planned at home for hours they work elsewhere.
- **A side activity register.** A new `SideActivity` holds what the activity is, for whom,
  paid or unpaid, hours per week, from and to, and the employer's decision. The employee
  reports it from Mijn HR; the manager or HR approves it or records a conflict of interest
  with a reason. Activities of employees whose function the employer marks for publication
  can be listed for publication.
- **The attestation follows the register.** `nevenwerkzaamhedenGemeld` is set by humaniq when
  the employee has filed a report (including an explicit "none"), so the existing rule keeps
  working and can no longer be ticked without a report behind it.

## Capabilities

### New Capabilities

- `secondment-and-side-activities`: outward secondments with their effect on availability,
  and a side activity register with an approval decision.

## Impact

- `lib/Settings/register.d/hr-secondment.json` (new): `Secondment`, `SideActivity` with
  lifecycles.
- `lib/Service/AgendaComposer.php`: a `secondment` entry kind; `lib/Service/AvailabilityService.php`
  counts it as committed.
- `lib/Listener/SideActivityAttestationListener.php` (new): keeps
  `Employee.nevenwerkzaamhedenGemeld` in step with the register.
- `src/manifest.d/hr-secondment.json` (new): `Secondments`, `SecondmentDetail`,
  `SideActivities`, `SideActivityDetail`, `MijnNevenwerkzaamheden`; object lists on
  `EmployeeDetail`.

## Out of scope

- Invoicing the receiving organisation. The agreed rate is recorded; billing is shillinq's.
- Inward secondments (people from elsewhere working here). They are not employees in
  humaniq; the agency flow in `uitzend-flexpool` is the nearest model.
- Publishing side activities on a public site. The list is prepared; publication is the
  employer's own channel.
