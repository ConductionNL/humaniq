# Goal: HRM compliance rule-engine for humaniq

> Kickoff brief for a **separate session**. Replicates the compliance-rule-engine
> built in **shillinq** (bookkeeping) for the **HRM/labour** domain in humaniq.
> Reference implementation to copy: `apps-extra/shillinq/lib/Standards/`,
> `lib/Service/RuleAuditService.php`, `lib/Service/RuleTestDataSeeder.php`,
> `lib/Command/Rules*Command.php`, `lib/Lifecycle/RuleComplianceGuard.php`,
> `lib/Reporting/`. Result there: 442 machine-checkable rules enforced, 258/258
> test objects compliant, via 11 auto-discovered CheckProviders + 3 multi-agent
> workflow runs.

## What to build (the methodology — proven in shillinq)

1. **Static, versioned rule corpus** of international HR/labour rules and guidelines.
   Compliance rules are universal facts → **versioned static code/JSON, NOT
   OpenRegister** (OR is for per-tenant config only). One JSON file per sub-domain
   under `lib/Standards/rules/*.json`, loaded/merged by a `RuleCatalogue.php`
   (a `VERSION` constant, bump on any change). Each rule:
   `{id, domain, jurisdiction, framework, source, statement, severity,
   machineCheckable, effectiveDate, sourceUrl}`. Aim for hundreds of rules — go
   deep per framework, cite the article/section in `source`.

2. **Corpus correctness pass.** `machineCheckable: true` ONLY if a deterministic
   program can decide compliance from structured fields (presence / format /
   arithmetic / enumeration / cardinality / date-window / referential). Narrative
   /judgemental rules (disclosures, "reasonable", "appropriate", policy text) →
   `false`. Use a fan-out of sub-agents to audit and correct the flags honestly
   (in shillinq only ~6% were genuinely narrative — don't over-flag).

3. **`RuleEngine`** (`lib/Standards/RuleEngine.php`): executable layer over the
   corpus. Auto-discovers per-domain **`CheckProvider`** classes in
   `lib/Standards/Checks/*.php` (interface `checks(): [objectType => [ruleId =>
   fn($obj,$ctx):bool]]` + `seedSpec()`; optional `SeedsObjects::seedObjects()`
   for new object types). **Jurisdiction-gated** (a rule fires only for its country
   + EU-wide for EU members + global everywhere). Returns `Violation`s sourced from
   the corpus. Copy this whole mechanism from shillinq.

4. **Lifecycle guards** (`RuleComplianceGuard`): block OR lifecycle transitions
   (e.g. contract `activate`, payroll `approve`) when a mandatory rule is violated.

5. **Seeder + audit**: `RuleTestDataSeeder` + `occ humaniq:rules:seed-testdata`
   (idempotent compliant test data; all local data is test data) and
   `RuleAuditService` + `occ humaniq:rules:audit` (reports enforced ÷ machine-checkable
   = coverage, violations, per-object-type compliance). Drive test data to 100%
   compliant.

6. **Scope discipline.** Define humaniq's **in-scope** domains; the coverage target is
   **100% of in-scope**, not of the whole corpus. Route out-of-scope rules to the
   right app and skip them honestly (shillinq routed *payroll/loonheffing* rules
   **to humaniq** — so payroll IS in scope here). Never fabricate vacuous `return true`
   checks or invent data just to inflate the number.

7. **Scale with multi-agent workflows.** Once the engine + a few providers exist,
   fan out one author agent per domain (writes a `CheckProvider` + schema fragment +
   `seedObjects`), each followed by an **adversarial verify** agent that fails on:
   vacuous always-true predicates, invalid/duplicate rule ids, lowercased-slug
   collisions, and un-seeded new object types. In shillinq this added ~120 rules per
   run. Integrate centrally (re-enable app to import schema fragments, re-seed,
   audit, drop anything flagged, commit per domain).

8. **Reporting & Compliance UI** (optional, later): surface the audit + generate
   compliance report files. Reuse the PHPOffice libs bundled in OpenRegister
   (PhpWord/PhpSpreadsheet + dompdf) — no new office dependency.

## HRM domains & frameworks to catalogue (in-scope for humaniq)

- **EU labour directives**: Working Time Directive 2003/88/EC (max weekly hours,
  rest periods, paid annual leave, night work); Transparent & Predictable Working
  Conditions Directive (EU) 2019/1152; Work-Life Balance Directive (EU) 2019/1158
  (parental/carers' leave); EU Pay Transparency Directive (EU) 2023/970; Posted
  Workers Directive 96/71/EC + 2018/957; Fixed-term (1999/70/EC), Part-time
  (97/81/EC), Temporary Agency Work (2008/104/EC) directives; Equal Treatment /
  non-discrimination (2000/43/EC, 2000/78/EC, 2006/54/EC); Information & Consultation
  (2002/14/EC); collective redundancies (98/59/EC); transfer of undertakings
  (2001/23/EC).
- **ILO** core conventions (freedom of association, forced/child labour,
  discrimination, equal remuneration C100, hours of work).
- **GDPR for employee data** (lawful basis, data minimisation, DPIA for monitoring,
  retention limits, special-category data).
- **Occupational health & safety** (EU Framework Directive 89/391/EEC; NL Arbowet;
  DE ArbSchG).
- **National labour law** — start NL then add DE/FR/BE:
  NL: BW Boek 7 titel 10 (arbeidsovereenkomst), WAB, Wet flexwerken, WW/WIA/ZW,
  Wet minimumloon (WML), Wet arbeid en zorg (WAZO), CAO/collective agreements,
  Wet DBA (zzp/schijnzelfstandigheid), Pensioenwet, AVG-uitvoeringswet, WNT.
- **Payroll / wage tax & social security** (the set shillinq routed here):
  loonheffing/withholding tables, social-security contributions, minimum-wage
  compliance, holiday allowance (vakantiegeld 8%), 30%-regeling, WKR vrije ruimte,
  pension contributions, gross↔net reconciliation.
- **Equal pay & reporting**: gender pay-gap thresholds, equal-pay-for-equal-work.

## Likely humaniq object types the checks map to
`Employee`, `EmploymentContract`, `Payslip`/`PayrollRun`, `WorkingTimeRecord`,
`LeaveRequest`/`LeaveBalance`, `Absence`/`SickLeave`, `PensionEnrolment`,
`PerformanceReview`, `Vacancy`/`Application` (recruitment), `CollectiveAgreement`.
Model only what the rules need; add fields via schema fragments + `seedSpec`.

## Carry-over gotchas (from shillinq)
- Adding a property to an OR schema needs the schema **`version` bumped** in the
  register.d fragment or OR won't re-import it (re-enable the app to import).
- OR resolves schemas by **lowercased slug** → avoid new object-type names that
  collide with an existing schema's slug.
- `seedObjects()` only fires when an object type is **empty**; backfill existing
  rows via `seedSpec()`.
- `phpcbf` needs `-u root` on bind-mounts; the fleet PHPCS has a pervasive
  named-parameters + no-ternary sniff already violated everywhere — match file style.
- Coverage `enforced ÷ machine-checkable`: literal 100% of the full corpus is
  unreachable (national rules are jurisdiction-dormant for one tenant; some rules
  need systems humaniq isn't). Target **100% of in-scope**, report honestly, don't fake.

## Definition of done
- `occ humaniq:rules:audit` reports a meaningful coverage % of the in-scope HR corpus
  and **0 violations** on seeded compliant test data across all modelled object types.
- Lifecycle guards block non-compliant transitions on the key HR objects.
- Corpus + engine + providers + seeder + audit committed; coverage trajectory and
  scope decisions recorded in this repo's memory.
