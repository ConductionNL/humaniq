# Design: fill records from the population and vehicle registers

## Context

Read at `development` af702f78.

- `Asset` (`lib/Settings/register.d/hr-assets.json`): `name`, `category`, `serialNumber`,
  `licencePlate`, `purchaseDate`, `purchaseValue`, `status`, `listPrice`, `fuelType`,
  `companyCarTaxCategory`, `administrationId`. No make, model or first admission date; the
  `fleet-bijtelling` non-goals name 60-month DET re-rating as untracked.
- `Employee` has `bsn`, `firstName`, `lastName`, `dateOfBirth`; the address fields arrive
  with `people-record-change-approval`.
- humaniq calls no outside system today; `lib/Support/FleetAppId.php:71` resolves
  `integriq` (still installed as `openconnector` in places) duck-typed.
- integriq reads `lib/Settings/connections.json` from each app for its connection registry
  (hydra gate 116, `hydra-gate-connections-declaration`), and its `CallService` and
  `BrokeredCallService` make the outbound call with credentials held by OpenRegister's
  credential broker, so the consuming app never holds a secret.
- `hrAdministration` (`lib/Settings/register.d/hr-administratie.json`): `name`,
  `kvkNumber`, `loonheffingennummer`, `active`, `abpAansluitingsplichtig`, `mode`.
- The manifest `api-call` header action type calls a humaniq route from a detail page (as
  `dsr-rectify` does on `DsrRequestDetail`).

## Goals / Non-Goals

**Goals**

- One click fills empty fields from the register and shows every difference.
- No secret or certificate in humaniq; no call without a declared connection.

**Non-Goals**

- Overwriting stored data. A difference is shown, and an address difference becomes a
  change request under `people-record-change-approval` if HR accepts it.

## Decisions

### D1. Connections, not HTTP clients

`connections.json` declares `rdw-voertuigen` (base URL of the RDW open data API, no
credential) and `brp-personen` (Haal Centraal BRP Personen bevragen, credential by
reference). `RegisterPrefillService` resolves integriq through `FleetAppId` and asks it to
call the source; when integriq is absent the action answers `skipped-no-integriq`, the
duck-typed degradation humaniq uses for shillinq and filinq.

### D2. Fill empty, report different

The service maps the response to schema fields (RDW `merk`, `handelsbenaming`,
`datum_eerste_toelating`, `catalogusprijs`, `brandstof_omschrijving`; BRP `naam`,
`geboorte.datum`, `verblijfplaats`), writes only fields that are empty, and returns
`{filled: [...], differs: [{field, stored, register}]}`. An existing value is never
replaced by the lookup.

### D3. BRP only on a recorded legal basis

`hrAdministration.brpGrondslag` (text, nullable) records the legal basis for BRP use. With
no basis recorded, the BRP action is hidden (`visibleIf`) and the endpoint refuses. The
BSN is sent only to the declared BRP connection and is never logged by humaniq.

### D4. Access

Both endpoints are admin or HR only, the `PayrollController` precedent for sensitive
calls, and write through the caller's own OpenRegister rights.

## Declarative-vs-imperative decision (ADR-031)

| behaviour | path | why |
|---|---|---|
| outside call and mapping | imperative `RegisterPrefillService` through integriq | external integration, the ADR-031 exception |
| new vehicle fields and the legal-basis field | declarative schema properties | data |
| the two actions | declarative manifest `api-call` header actions | the existing action type |

## Seed data

- A vehicle `Asset` with licence plate `XX-000-X` (placeholder) and empty make and model.
- The seed administration keeps `brpGrondslag` empty, so the BRP action is hidden by default.

## Risks / Trade-offs

- [BRP access is legally narrow] → the action exists only behind a recorded basis, and the
  proposal says plainly that most private employers will never switch it on.
- [RDW field names change] → the mapping lives in one method with a test against a recorded
  response.

## Open Questions

- Should an address difference from the BRP open a change request automatically? This
  design reports it and leaves the choice to HR.
