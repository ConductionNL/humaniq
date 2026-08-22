# mijn-hr-self-service — delta for humaniq-personal-dashboard

> Written against the post-state of `humaniq-hours-process-redesign`'s delta to this spec (which
> lands directly before this change): REQ-MHS-002 already reads
> "Timesheet, TimeEntry, Expense, LeaveRequest and Payslip".

## MODIFIED Requirements

### REQ-MHS-002: Timesheet, TimeEntry, Expense, LeaveRequest, Payslip **and LeaveBalance** SHALL carry an optional denormalized `userId`

`LeaveBalance` (`hr-leave.json`, 0.2.0 → 0.3.0) joins the denormalized-`userId` set under the
established convention: string, nullable, never a `$ref` (mirrors `approvedBy` — rationale in
`x-notes`), never a form field on any surface, no `required` change — every existing object
stays valid. Population custody: `LeaveAccrualJob` — the schema's sole systematic writer —
stamps `userId` from the resolved Employee's `nextcloudUserId` on both its create and its
monthly update path (see the `leave-accrual-job` delta), so pre-existing rows self-heal on the
next accrual run; no dedicated repair step. A balance whose employee has no linked account
keeps `userId: null` and never appears on a personal surface (fail-closed). All other members
of the set are unchanged by this change.

#### Scenario: Additive LeaveBalance property is backward compatible

@e2e exclude Repair-step/import behaviour with no UI surface; verified by re-importing the bumped fragment and revalidating existing seed balances (tasks.md 2.1, 3.2)
- **GIVEN** an existing LeaveBalance object seeded before this change (no `userId`)
- **WHEN** the register re-imports the bumped fragment via the Repair step
- **THEN** the object still validates and is unchanged

#### Scenario: Balances without userId never leak onto the personal dashboard

- **GIVEN** the De Vries LeaveBalance with no `userId`
- **WHEN** the `admin` user opens `/mijn`
- **THEN** that balance is not listed in "Mijn verlofsaldo" (fail-closed)

### REQ-MHS-003: The manifest SHALL add the two frozen ADR-001 top menu slots without restructuring the rest

**Amended (this change): the `Mijn HR` group entry routes to the personal dashboard.** The
menu contract of the original requirement stands — `Dashboard` (order 10) first, group
`MijnHrGroup` (label `Mijn HR`, icon `Account`, order 20) second, all pre-existing groups
unchanged — with one addition: `MijnHrGroup` carries `route: "MijnHr"` (supplied by the
`personal-dashboard.json` fragment through `mergeMenuItems`' fill-undefined-key semantics), so
the group title is the entry point to the caller's personal dashboard while its children
remain the caller's own collections. This implements hydra ADR-097 Decision 3's exemption
conditions for the personal surface (see `humaniq-personal-dashboard` REQ-PDB-003).

#### Scenario: Menu order matches ADR-001

- **WHEN** the app shell renders the manifest menu
- **THEN** Dashboard is the first entry and Mijn HR the second, before all pre-existing groups

#### Scenario: Mijn HR is a routed group, not a bare parent

- **WHEN** the app shell renders the manifest menu
- **THEN** the `Mijn HR` entry navigates to `/mijn` on title click and still exposes its
  children under the collapse chevron

## ADDED Requirements

### REQ-MHS-007: Every Mijn surface SHALL live under the `/mijn/...` route prefix, with a redirect from the legacy path

`MijnGebruikelijkLoon` — the only Mijn page outside the prefix — moves from
`/mijn-hr/gebruikelijk-loon` to `/mijn/gebruikelijk-loon` (route value edited in the base
`src/manifest.json`, where the fragment pipeline's Decision 2 homes this app-shell page).
Compatibility: the menu entry references the page by route **name** and is untouched; no
`deepLinks[]` entry references `/mijn-hr` (all 37 are schema `urlTemplate`s — verified);
`src/main.js` gains a hand-written redirect route
(`{ path: '/mijn-hr/gebruikelijk-loon', redirect: '/mijn/gebruikelijk-loon' }`) before the
catch-all, per the `/vehicles/:id` precedent (`main.js:150-151`), so stale bookmarks resolve
instead of falling through to the catch-all default. The page's `visibleIf`
(`dga_single_person`) gating and content are unchanged.

#### Scenario: The legacy path redirects

- **WHEN** a user opens `/mijn-hr/gebruikelijk-loon` directly by URL
- **THEN** the router lands on `/mijn/gebruikelijk-loon` and the gebruikelijk-loon dashboard
  renders

#### Scenario: The DGA menu entry still resolves

@e2e exclude the entry is `visibleIf`-gated on `administrationMode: dga_single_person`, which the CI seed's default active administration does not select; the route-name reference is asserted structurally via the manifest and the redirect scenario covers the path change
- **GIVEN** a caller whose active administration mode is `dga_single_person`
- **WHEN** they activate the "Mijn gebruikelijk loon" menu entry
- **THEN** the router resolves the `MijnGebruikelijkLoon` route name to
  `/mijn/gebruikelijk-loon`
