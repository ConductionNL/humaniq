> [!IMPORTANT]
> ## 🚚 This repository has moved to Codeberg
>
> Active development now happens at **https://codeberg.org/Conduction/hrmq**.
> This GitHub mirror is read-only — issues, pull requests, and new commits should go to Codeberg.
> Update your remote with: `git remote set-url origin https://codeberg.org/Conduction/hrmq`# hrmq

ConductionNL — Human Resources & Payroll administration for Dutch SMBs.

Status: specs pending. See [openspec/](openspec/) for the change log.

Part of the Conduction ecosystem alongside [shillinq](https://codeberg.org/Conduction/shillinq) (bookkeeping), [pipelinq](https://codeberg.org/Conduction/pipelinq) (CRM), and [openregister](https://codeberg.org/Conduction/openregister) (data layer).

## Payroll engine — NOT certified (read this before production use)

hrmq ships an open-source Dutch payroll calculation engine implementing the
Belastingdienst *Rekenvoorschriften voor de geautomatiseerde loonadministratie
2026* formula chain (witte/groene maandtabel, schijventarief, AHK/ARK/OUK
heffingskortingen, Zvw werkgeversheffing, Awf/Aof/Wko/Whk employer charges) over
the versioned tax-year parameter file `lib/Standards/tables/nl-2026.json`.

Since **jurisdiction packs** (ADR-101), that chain is no longer PHP: it is
declarative configuration in `lib/Standards/packs/nl-2026.pack.json`, executed by
a small pure interpreter (`lib/Payroll/Dsl/`).
`PayrollCalculator` is now a thin façade over it. A new tax year stays a
data-only change — and so does **a new country**.

**The engine is NOT certified.** Be aware of the following before relying on
its output:

- **Traceability**: every computed `PayrollRun` carries `engineVersion` (the
  exact jurisdiction pack that produced it, `{packId}@{packVersion}`, e.g.
  `nl-2026@1.0.0` — which names the *chain* as well as the parameter set; runs
  computed before jurisdiction packs carry the older bare `nl-2026` form and are
  never rewritten) and `calculatedAt`,
  and every computed `Payslip` reconciles cents-exact to the declared net
  equation — both enforced by the machine-checkable corpus rules
  `nl-engine-table-version` and `nl-engine-output-consistency`
  (`occ hrmq:payroll:verify --period YYYY-MM` audits a run against the same
  corpus that audits hand-entered data).
- **Certification gap**: the golden tests
  (`tests/fixtures/payroll-2026/*.json`) are *self-consistent* with the
  parameter tables — the anchor case is hand-computed from the primary PDFs,
  but the official Belastingdienst test sets (loonheffingstabellen
  proefberekeningen) have **not** been run against this engine yet. The
  marked slot for them is `tests/fixtures/payroll-2026/official/README.md`.
- **Known MVP limitations**: fixed monthly salary only (hourly wage x
  approved Timesheet hours is a named fast-follow); no VCR (voortschrijdend
  cumulatief rekenen — premium bases are period-capped, not cumulative, which
  drifts for wages fluctuating around the maximum premieloon); no
  anoniementarief computation (employees failing the BSN/ID preconditions are
  skipped with a reason, never computed wrong); no CAO logic, no bijzonder
  tarief (vakantiegeld payout), no 30%-ruling netto-operation, no pension
  premie calculation, no Zvw-inhouding mode, no loonaangifte message
  generation.
- **Production use requires verification** of the engine's output against the
  official Belastingdienst test sets by a qualified loonadministrateur.

Honesty is a feature: this disclaimer is a requirement of the
`payroll-core-engine` spec, not a footnote.

## Jurisdiction packs — onboarding a country by uploading configuration

A **jurisdiction pack** is one country's gross-to-net chain as a single
self-contained JSON artefact: you can author it, validate it, version it,
download it and hand it to someone else — without shipping PHP. The authoring
reference is `lib/Standards/packs/SCHEMA.md`; the machine-readable schema is
`lib/Standards/packs/pack.schema.json`.

- **Net is not a rule, it is a fold.** Every step declares its *incidence* —
  `reduces-net`, `employer-cost`, `informative` or `reserve` — and the
  interpreter derives `net = gross - sum(reduces-net)`. The Dutch property that
  employer charges never reduce take-home pay is **not** built into the engine;
  it falls out of the NL pack declaring its Zvw/Awf/Aof/Wko/Whk steps
  `employer-cost`. A country whose pension contribution reduces net says
  `reduces-net`, and nothing in the interpreter changes.
- **A pack is config, not code.** The `expr` grammar is a closed, total
  calculator (`+ - * /`, `min max abs round floor ceil`) — no loops, no
  recursion, no IO, no clock. Every pack is a finite acyclic graph evaluated
  once, with step-count and expression-depth caps.
- **The escape hatch names a handler; it can never define one.** A pack supplies
  a *name*, resolved at **validation time** against a compile-time allow-list. An
  unknown handler **rejects the upload with the name in the error** — it never
  reaches a run to be silently skipped, because a skipped step quietly
  under-taxes someone. hrmq ships **zero** handlers and NL needs **zero**.
- **A pack must prove itself before it can pay anybody.** Every pack carries
  golden vectors, run in-process at upload; any mismatch rejects it. **NL's 9
  golden fixtures are the NL pack's own self-test block**, so the machinery that
  gates a third-party pack is the machinery that proved the NL migration was
  behaviour-identical.
- **Bundled NL is not shadowable by accident.** Uploading a pack for a
  `(jurisdiction, taxYear)` that a bundled pack owns is rejected unless an admin
  explicitly activates it as a recorded override.

Upload: `POST /api/payroll/packs` (admin only).

**What packs cannot do — named up front, not discovered later:**

- **No VCR** (voortschrijdend cumulatief rekenen). The DSL is per-period pure by
  construction and *cannot* express cross-period state. The honest expectation is
  that **NL itself will be the escape hatch's first customer**, at VCR. Widening
  `expr` to compensate is forbidden (ADR-101): it would void the entire "config,
  not code" trust model.
- **No inverse solves** — the 30%-ruling netto-operation is not a forward chain.
- **`piecewiseAccrue` is on probation.** It was designed by staring at NL's
  `arkChain()`, and its round-each-term-then-cap ordering is Rekenvoorschriften
  arcana. Phase-in/phase-out credit schedules are a plausibly general shape, but
  **that generality is unproven until a second country lands on it.** Country two
  either validates this primitive or exposes it as NL-shaped. That is this
  design's central unproven claim, and it is recorded rather than hidden.
- **Some NL law still lives outside the pack.** Bijtelling privégebruik auto and
  the loonbeslag *beslagvrije voet* are computed in `PayrollRunService`, because
  they read stored objects while the interpreter is pure and object-blind. That
  is a *scope* cut, not a principled boundary — bijtelling's arithmetic is a pure
  function of parameters already in the tables and belongs in the pack. It is a
  named follow-up, and country two will feel it.
- **A second country is unproven.** This mechanism is proved against NL and ships
  the upload surface. Any claim that country two "just works" is unproven until
  country two lands.

## Retroactive corrections (TWK) — terugwerkende kracht herrekening

Real payroll inputs change *after* a period is sealed (a backdated raise, a
late-corrected sick day, a retroactive contract fix). hrmq settles these the
Dutch way — **terugwerkende kracht herrekening (TWK)** — via a
`PayrollAdjustment` that models a **delta**, never a rewrite of the sealed
original (`lib/Service/RetroAdjustmentService.php`,
`occ hrmq:payroll:adjust`). Be aware of the following:

- **The sealed original is never mutated.** A correction reads the stored,
  already-approved/posted/paid `Payslip` only to diff against it; it writes a
  new `PayrollAdjustment` carrying the cents-exact `delta*` fields. The
  historical payslip (filed in a loonaangifte, posted to the GL) stays
  byte-untouched — an adjustment can exist against a run the engine itself
  refuses to recompute.
- **The recompute uses the ORIGINAL period's tax year.** A 2025 correction
  must use the 2025 schijven/kortingen/premiepercentages, not 2026 —
  `RetroAdjustmentService` derives `nl-{year of the original period}` and
  recomputes against that table. **Same-tax-year MVP boundary**: only
  `nl-2026.json` ships today, so a correction whose original period falls in a
  year for which no table exists is **refused** with a clear
  `historical-tables-missing` message rather than recomputed against the wrong
  year. Seeding historical `nl-YYYY.json` corpora is a named follow-up
  (`retro-multi-year-tables`); the recompute code is already year-generic, so
  that follow-up is data, not logic.
- **The delta surfaces in the CURRENT run, never in history.** An `applied`
  adjustment folds its net delta into the current period's `Payslip`
  (`retroAdjustment` component + `nettoPay`) as a nabetaling (positive) or
  terugvordering (negative). A `draft` adjustment is computed-but-unsettled and
  affects no run. Adjustments are idempotent by
  `(originalPeriod, employeeId, correctionRef)` — re-running the same
  correction updates one object and never double-counts.
- **The tax year is period-derived and immutable once stamped.** There is
  deliberately no mutable "active tax year" global: each run derives its table
  from its own period, and a generated run's `engineVersion`/`calculatedAt`
  stamp plus the non-`draft` recompute refusal make that stamp immutable. The
  annual roll is therefore **data-only** — ship `lib/Standards/tables/nl-YYYY.json`
  and runs for `YYYY-MM` periods pick it up automatically;
  `occ hrmq:payroll:year-transition --year YYYY` is the preflight that asserts
  the new table exists and confirms the immutable-stamp guard, changing no
  engine state.
- **Known MVP limitations**: no bijzonder tarief on the nabetaling (a
  nabetaling is often taxed at bijzonder tarief; the MVP settles the delta at
  the tabel result and names bijzonder tarief as a follow-up — it inherits the
  engine's own bijzonder-tarief non-goal); no cumulative/VCR reconciliation
  (the delta is a period-vs-period recompute); no automated loonaangifte
  correctie-berichten (the filing lifecycle's `corrigeren` transition is the
  manual route). The `nl-retro-adjustment-consistency` corpus rule recomputes
  every adjustment's delta during `occ hrmq:rules:audit`, so a tampered delta
  is a mandatory audit violation.

## Rostering (MVP)

hrmq ships a forward-looking shift-planning MVP that plans *and* pre-checks a
roster against the same Arbeidstijdenwet (working-time law) rules the app
already enforces on realised clock data:

- **Define shifts** — reusable `Shift` definitions (name, start/end time,
  break, optional org-unit scope). A shift whose end time is not after its
  start time denotes a night shift crossing midnight.
- **Assign employees per period** — a `RosterAssignment` places one employee
  on one shift on one date within a `Roster`, projecting the shift's times
  onto the date (`plannedStart`/`plannedEnd`/`plannedBreakMinutes`).
- **Publish a roster** — the `Roster` header carries a real
  `concept → gepubliceerd` lifecycle (`publiceren`/`intrekken`); publishing
  freezes the plan and makes it the team's roster.
- **Check against the Arbeidstijdenwet** — the roster ATW cross-check
  **reuses the three existing corpus rules** (`nl-atw-dagelijkse-rust`,
  `nl-atw-max-werkdag`, `nl-atw-pauze`) over the *planned* assignments — no
  new working-time rule is invented. Run it on demand with
  `occ hrmq:roster:check --roster ID | --period YYYY-Www [--administration ADM]`
  (exits non-zero on any mandatory violation) or from the `RosterDetail`
  "ATW-controle" action; published assignments also join the standing
  `occ hrmq:rules:audit`.

**Non-goals (deeper workforce management is a future integration, not this
change):** auto-optimisation, demand forecasting and rule-based
auto-scheduling are deferred to a dedicated workforce-management tool
integrated via **openconnector** — hrmq owns the plan of record and the ATW
compliance view, not the WFM optimiser. A drag-and-drop planbord,
availability/preferences, skills-matching, open-shift bidding/shift-swap and
coverage alerts are named fast-follows. There is no automation between a
published roster and realised `AttendanceRecord`/`Timesheet` hours.

## BHV (bedrijfshulpverlening) — certificate signalling, not a coverage formula

hrmq tracks BHV-related certifications (`BhvCertificering`: employee, role
`bhv_basis`/`hoofd_bhv`/`ehbo`/`ontruimingsleider`, obtained/valid-until
dates, training provider, optional `OrgUnit` scope) and signals an expiring
certificate through the **existing `hr-signals` mechanism** — the same
provider class, the same framework, the same "Aflopende ..." dashboard
widget shape the expiring-contract signal already uses
(`nl-bhv-certificaat-verloopt`, advisory, 90-day window). No second alerting
mechanism was built for this.

**No numeric BHV coverage ratio is asserted anywhere** (not in the rule
corpus, not in the manifest, not as a computed adequacy verdict on any
page). Arbeidsomstandighedenwet art. 15 requires the werkgever to appoint one
or more bedrijfshulpverleners accounting for de grootte van het bedrijf en de
aard van de aanwezige risico's — a qualitative, RI&E-driven standard. No
article sets a fixed number (no "1 per 50" or similar), so this feature does
not invent one: the `BhvCertificeringen` index gives HR **visibility**
(who is certified, in which role, in which `OrgUnit`, expiring when) so
coverage adequacy can be judged against the organisation's own RI&E, not
against a formula hrmq computes.

hrmq has no physical-`Location` concept (`OrgUnit` is an organisational
grouping — afdeling/team/kostenplaats — not a site register), so BHV
coverage visibility is scoped by `OrgUnit`, an honest downgrade from "per
building" named as such, not disguised as the real thing.

**Named fast-follow:** `Asset` (laptop/telefoon/voertuig/gereedschap/
toegangspas/kleding/`overig`) has no inspection-expiry field today, so
AED/EHBO-equipment inspection tracking is not covered by this feature. A
future small change adding that field would reuse this same `hr-signals`
expiry-alert mechanism rather than build a third one.

## Multi-administratie (accountant multi-client)

hrmq supports multiple administraties (companies/clients) in one instance — the
NL accountant channel where one office runs payroll for many SMBs:

- **Tenant model** — an `Administration` (name, KvK, loonheffingennummer) plus
  an `AdministrationAccess` membership (userId → administratie, role
  accountant/hr/employee). Every core HR/payroll object carries an optional
  plain-string `administrationId` (the `PayrollRun` convention, never a
  `$ref` — there is deliberately no `Administration` `$ref` graph).
- **Active administratie per user** — `GET/POST /api/administration/*` set and
  read a per-user active-administratie pointer, guard-first: the setter refuses
  any administratie the caller has no `AdministrationAccess` row for (unknown or
  inaccessible → 404). The `Configuratie › Administraties` switcher drives it.
- **Consistency** — `nl-administratie-scope-consistency` (recommended severity)
  flags a child object whose `administrationId` disagrees with its parent;
  vacuous when the field is absent, so single-administratie installs are
  unaffected.

> **Scoping is NOT a security boundary.** The active-administratie pointer and
> the per-page filtering it will drive are a *convenience* scoping layer, not an
> isolation guarantee: OpenRegister still serves objects by the app's own RBAC,
> and a user with register access can read across administraties via the API.
> Hard tenant isolation (per-administratie OpenRegister organisation ownership)
> is a named security fast-follow, tracked separately. Do not rely on
> administratie scoping to keep one client's data from another's.

> **Upstream dependency (MVP boundary):** automatic per-page filtering by the
> active administratie needs an `@administration` filter token in the shared
> nextcloud-vue manifest vocabulary (a closed, schema-validated set — it cannot
> be invented app-side without failing `check:manifest`). This build ships the
> full hrmq side (schemas, service, guarded endpoints, switcher UI, consistency
> rule, seeds); wiring `@administration` into `sentinelTokens.js` /
> `resolveFilterTokens.js` + stamping `runtime.user.activeAdministrationId` into
> the served manifest + adding `filter: { administrationId: "@administration?" }`
> to each page is a named nextcloud-vue follow-up. Until it lands, all
> administraties a user can access are shown together (the safe `?`-optional
> default).

## Loonbeslag (wage garnishment)

hrmq models a court/deurwaarder-ordered wage garnishment (loonbeslag) as a
`Loonbeslag` record: a fourth current-run, post-tax component folded into
`Payslip.nettoPay` by `PayrollRunService::generate()` (the exact
sick-pay/retro-adjustment/leave-buy-sell shape — the deduction is computed
entirely against the already-decided net figure, `PayrollCalculator` is never
re-invoked). Be aware of the following:

- **The beslagvrije voet is a stored INPUT, not a computed figure.**
  `Loonbeslag.beslagvrijeVoet` is a required, HR-entered field trusted as the
  authoritative figure the deurwaarder is legally required to state on the
  garnishment order. This build does **not** compute the protected minimum
  from income and household composition per the *Wet vereenvoudiging
  beslagvrije voet* (partner income, co-residents, housing costs,
  health-insurance premium) — that computation is a named fast-follow.
  `beslagvrijeVoet` would simply gain a second, computed source feeding the
  same field the floor formula already reads; the fold mechanics do not
  change.
- **The floor itself IS a hard, machine-checked rule.** The deduction is
  computed as `min(orderedAmount, max(0, nettoPay − beslagvrijeVoet))` — by
  construction it can never push `nettoPay` below the voet — and
  `nl-loonbeslag-beslagvrije-voet-floor` (auto-discovered `CheckProvider`,
  reachable via `occ hrmq:rules:audit`) flags any Payslip where a tampered or
  otherwise inconsistent `nettoPay` falls below its referenced Loonbeslag's
  `beslagvrijeVoet`.
- **Single-active-beslag is the MVP scope**, not a corner case glossed over:
  hrmq selects at most ONE `actief` Loonbeslag per employee per period.
  Priority/preferente-vordering ordering across multiple simultaneous
  garnishments for the same employee (e.g. alimony arriving mid-order on top
  of an existing tax-debt beslag — BW art. 475d governs the ordering) is a
  named fast-follow, not implemented here. The assumption is enforced, not a
  silent doc note: `nl-loonbeslag-single-active` flags any employee with more
  than one `actief` Loonbeslag whose effective ranges overlap. Should the
  selection ever encounter more than one active match despite the check, the
  earliest `effectiveFrom` wins deterministically — never a silent drop, never
  a double deduction.
- **Admin/HR-only, guarded transitions, never a bare lifecycle button.**
  `Loonbeslag.status` carries no `x-openregister-lifecycle` map — activating,
  settling, and withdrawing a garnishment are sensitive, caller-role-gated
  writes (`LoonbeslagController`, the `PayrollController::mutations()`/
  `wkrAssess()` two-gate shape: admin/HR 403 BEFORE any RBAC resolve, then
  RBAC-resolve-first 404). A dedicated Nextcloud "HR" group (vs. reusing the
  admin group) is a shared fast-follow across every admin/HR-gated endpoint in
  this app, not specific to loonbeslag.

## AVG data-subject rights (inzage/portabiliteit, vergetelheid, rectificatie)

hrmq orchestrates the four AVG (GDPR) data-subject rights — Art 15 inzage,
Art 20 portabiliteit, Art 17 vergetelheid, and Art 16 rectificatie — as a thin
layer (`AvgDsrService`) over OpenRegister's `DsarService`. hrmq owns no
entity-matching, soft-delete, or anonymisation logic of its own (ADR-022);
`DsarService` has zero new call sites beyond its three existing public
methods. Be aware of the following:

- **The erase is structurally two-path, not a single call.**
  `DsarService::eraseObjectsForSubject()` is a wholesale, subject-wide sweep
  with **no per-object exclusion parameter** — it cannot skip a retained
  object while erasing the rest in one call. `AvgDsrService::classifyForErasure()`
  therefore splits every matched object into `retained` (a populated
  `retainedUntil`/`identityDocumentRetainedUntil` dated on or after today, or
  — when unpopulated — the AWR art. 52 lid 4 7-year fallback derivation for
  the payroll/loonadministratie schema family) and `eligible`. When nothing
  is retained, ONE wholesale `eraseObjectsForSubject()` call runs (efficient,
  fast path). The moment **anything** is retained, `eraseObjectsForSubject()`
  is **never called** for that subject — instead a per-object
  `rectifyObjectForSubject()` anonymisation loop runs over `eligible` ONLY.
  A retention-locked object is never referenced in either DsarService write
  call; it is always reported in the outcome's `retained` list, labelled
  `"retained (wettelijke bewaarplicht)"`, with its retention date — excluded
  AND visibly reported, never silently skipped. This two-path design is a
  named workaround for a primitive `DsarService` does not (yet) provide, not
  a permanent architecture choice — a future selective per-object erase
  capability in OpenRegister could collapse this to one call, without
  changing the erase's requirements.
- **The retention predicate only covers the payroll/loonadministratie
  family** (`Payslip`, `PayrollRun`, `LoonaangifteFiling`,
  `PayrollMutationReport`, `WkrDeclaration`, `WkrAssessment`) plus any schema
  carrying a populated `retainedUntil`/`identityDocumentRetainedUntil` field.
  A schema outside that family with a real (but currently unmodelled) legal
  retention duty would not be protected — a named scope boundary (design.md
  Non-Goals), not a silent gap. `retainedUntil`/`identityDocumentRetainedUntil`
  are today unpopulated on real objects until `PayrollRunService`/onboarding
  flows start setting them at write time (out of this feature's scope).
- **Dry-run always precedes execute, structurally.**
  `AvgDsrService::previewErasure()` performs zero writes to any subject's
  data object; `eraseSubject()` refuses (a controlled, non-write refusal) any
  `DsrRequest` whose preview was not first recorded onto it. `occ
  hrmq:avg:erase` defaults to preview-only; `--confirm` requires
  `--dsr-request-id` naming a request whose preview already ran.
- **`AvgDsrController`'s admin gate is admin-ONLY, deliberately never
  `isAdminOrHr()`, and must stay that way.** Unlike `LoonbeslagController`/
  `PayrollController::mutations()`/`wkrAssess()`, which may correctly widen
  to admit a future dedicated Nextcloud "HR" group,
  `DsarService::assertPrivileged()` hard-requires actual
  `IGroupManager::isAdmin()` — widening `AvgDsrController`'s gate to
  `isAdminOrHr()` after that fast-follow ships would let a non-admin HR
  caller pass hrmq's gate and then hit the `RuntimeException`-to-403
  translation instead of succeeding (a behaviour regression, not a security
  hole, but a named trap for that future change to avoid — design.md D3).
- **The special-category `bsn` value is never persisted by this feature.**
  `DsrRequest.employeeId` (a `$ref` to `Employee`) is the only persisted
  subject-identifying field; `AvgDsrService::resolveSubject()` reads
  `Employee.bsn` transiently, in memory, at call time, and never writes it
  back onto `DsrRequest`, `retainedObjectRefs`, `outcomeSummary`, or any log
  line (Wet BSN).
- **The Rectificeer manifest action's `changes` payload is not yet
  prompt-collected**, the same `LoonbeslagDetail` withdraw-`reason` gap: the
  manifest v2 `api-call` action type has no free-text field-map prompt. A
  prompt-collecting modal is a named fast-follow; `occ hrmq:avg:rectify
  --changes '{"field":"value"}'` and the `POST /api/dsr/rectify` endpoint
  are both fully functional today.

## Stagiairs & BBL-leerlingen (stageovereenkomst vs. arbeidsovereenkomst)

hrmq distinguishes the two legally different things the word "stage" covers,
and models each where it structurally belongs:

- **A stagiair (HBO/WO/MBO-BOL, zonder dienstverband)** is a first-class
  `Stagiair` schema, kept **structurally outside** `Employee` and the payroll
  engine. A stagiair has no arbeidsovereenkomst and, in the ordinary case, no
  loonheffing on the stagevergoeding, so no payroll schema (`PayrollRun`,
  `Payslip`, `PayrollMutationReport`) references `Stagiair` and
  `PayrollCalculator` never reads it — a stagiair can never reach the loon
  path by accident. It lives under the **Personeel/Medewerkers** menu with a
  plain `aangemeld → lopend → afgerond`/`gestopt` lifecycle.
- **A BBL-leerling (MBO-BBL)** has a real *leerarbeidsovereenkomst* and is,
  fiscally, an ordinary employee (loon, loonheffing, premies, CAO-toepassing
  all apply — Belastingdienst, Handboek Loonheffingen, hoofdstuk 17
  "Stagiairs"). It is therefore **not** a second entity: it is an
  `EmploymentContract` with `type: bbl`, visible on the existing contract
  pages, flowing through `PayrollCalculator` and the NL jurisdiction pack
  **exactly like** a `permanent`/`temporary` contract — no `type`-specific
  branch exists or was added.

Be aware of the following boundaries:

- **BPV-overeenkomst signing is a plain HR-entered boolean, not an
  e-signature flow.** `Stagiair.bpvOvereenkomstOndertekend` and
  `EmploymentContract.bpvOvereenkomstOndertekend` mirror
  `EmploymentContract.writtenContract` exactly — a fact HR marks true once
  the three-party praktijkleerovereenkomst (leerbedrijf/onderwijsinstelling/
  deelnemer) is signed by whatever external means the parties use. This is a
  **deliberate, documented boundary**: the shipped `offer-esign` leaf already
  proved digital multi-party signing through docudesk cannot complete for a
  non-NC-user signer (`SigningService::sign()` requires
  `signer.userId === $user->getUID()`, offer-esign design.md point 4), and two
  of POK's three signers are not ordinarily Nextcloud users of this instance.
  Building a second signing mechanism for exactly the case the first one ruled
  out would not fix it. The corpus rule `nl-bpv-overeenkomst-vereist` flags a
  placement (a `Stagiair`, or a `type: bbl` `EmploymentContract` — never any
  other contract type) that has **started** with the BPV still unsigned.
- **No stagevergoeding fiscal ceiling is asserted.**
  `Stagiair.stagevergoedingPerMaand` is stored as a plain informational figure
  and no machine-checkable rule enforces an untaxed euro limit, because no
  single Belastingdienst threshold can be asserted in the abstract for every
  organisation. A future change sourcing the exact Handboek Loonheffingen
  onkostenvergoeding figure (with URL + effective date) can add the rule using
  the `{value, source, verified}` leaf discipline (`verified: false` +
  `checkAgainst` until confirmed).
- **Out of scope (named fast-follows, not silently dropped):** SBB-erkenning/
  CREBO validation, RVO Subsidieregeling Praktijkleren submission/polling
  (no `openconnector`-mediated integration surface exists in hrmq today);
  automated 25%/50%/75% evaluation scheduling and reminders (hrmq has no
  task-scheduling capability to create them against); BBL-staffel payscale
  data (a data-only follow-up on a sourced sector-CAO via the existing
  `caoSchaal` mechanism). The minimum-wage rules (`nl-minimumloon-2026`/
  `nl-minimumuurloon-wet`) remain age-unaware — a pre-existing corpus gap that
  affects every contract type, not just `bbl`.
