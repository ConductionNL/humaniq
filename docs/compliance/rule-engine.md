---
sidebar_position: 1
description: The versioned, machine-checkable HR/labour rule corpus and the RuleEngine that audits HRMQ's data against it.
---

# The compliance rule engine

Alongside the register data layer, HRMQ ships a **versioned, static
corpus** of operative HR/labour and payroll rules — derived from EU
labour directives, ILO core conventions, GDPR for employee data,
occupational health & safety, national labour law (NL first), and
payroll / wage-tax & social-security compliance. An executable
`RuleEngine` audits the register's HR/labour objects against that corpus
and reports compliance coverage.

## Rules are facts, not config

Rules are facts about directives and laws — identical for every tenant,
changing only with regulation — so they ship as **versioned static data**,
one JSON file per sub-domain under `lib/Standards/rules/`, not as
OpenRegister per-tenant configuration:

- `payroll.json` — tax and reporting rules (loonaangifte, UPA pension
  filing, GL posting, engine traceability)
- `labour.json` — labour law (leave, verzuim/Poortwachter,
  Arbeidstijdenwet, onboarding/offboarding, org-chart and asset
  integrity, performance dossiervorming)
- `privacy.json` — GDPR/AVG rules (recruiting-data retention)

`RuleCatalogue` (a read-only, versioned accessor,
`RuleCatalogue::VERSION`) loads and merges these files and exposes query
helpers. Bump the version on any change to a rule file.

## The rule shape

Every rule declares:

| Field | Meaning |
| --- | --- |
| `id` | stable slug, unique across the whole corpus |
| `domain` | sub-domain key (`labour`, `payroll`, `reporting`, `gdpr-recruitment`, ...) |
| `jurisdiction` | ISO 3166-1 alpha-2, `EU`, or `global` |
| `framework` | the law/framework slug (e.g. `bw7-10`, `nl-poortwachter`, `nl-arbeidstijdenwet`, `gdpr`) |
| `source` | human citation |
| `statement` | the operative rule statement |
| `severity` | `mandatory`, `conditional`, or `recommended` |
| `machineCheckable` | `true` only when a deterministic program can decide compliance from structured fields |

`machineCheckable` is set `true` **only** when compliance is decidable
from structured fields — presence, format, arithmetic, enumeration,
cardinality, date-window, or referential checks. Narrative or judgemental
rules (disclosures, "reasonable", policy text) are `false`. The audit
coverage metric is `enforced ÷ machineCheckable`, so over-flagging
silently deflates coverage — the corpus is expected to flag honestly.

## Enforcement: CheckProviders

A rule becomes **enforced** when a `CheckProvider` under
`lib/Standards/Checks/` registers a predicate keyed by that rule's `id`.
Every `CheckProvider` implementation is auto-discovered by
`RuleEngine::providers()` — adding a new compliance domain means adding a
new provider file, never editing `RuleEngine` itself. Each predicate is a
pure, side-effect-free function `fn(array $object, array $context): bool`
that returns `true` when the rule is satisfied for that object.

The engine only enforces rules that have **both** a corpus entry and a
registered predicate — the rest of the corpus is catalogued but not yet
executable, and grows an executable check per development wave. Every
provider referenced throughout this documentation —
`NlPayrollChecks`, `NlEngineChecks`, `NlWageTaxFilingChecks`,
`NlPensionFilingChecks`, `NlGlPostChecks`, `NlNetPayChecks`,
`NlLeaveChecks`, `NlVerzuimChecks`, `NlAttendanceChecks`,
`NlOnboardingChecks`, `NlOffboardingChecks`, `NlAtsChecks`,
`NlPerformanceChecks`, `NlOrgChecks`, `NlAssetChecks`,
`NlDocumentChecks`, `NlSignalChecks` — is one of these providers.

Applicability is jurisdiction-scoped: a rule applies to its own country,
plus EU-wide rules for EU members and `global` rules everywhere, so the
engine only enforces what an organisation is actually subject to.

## Running the audit

```bash
occ hrmq:rules:audit --jurisdiction=NL
```

The report includes:

```
Hrmq rule-compliance audit
  catalogue version : 2026-07.13
  corpus rules      : <N> (machine-checkable: <N>)
  enforceable today : <N> (<NN.N>% of machine-checkable)

  objects checked   : <N>
  compliant         : <N>
  with violations   : <N>
  violations        : <N> mandatory / <N> conditional / <N> recommended
    <ObjectType>     checked=<N> compliant=<N> withViolations=<N>
    ...

  top violated rules:
    <rule-id>                          <N>
```

Exit code is non-zero for `occ hrmq:payroll:verify` (the run-scoped
payroll audit) on any mandatory violation; the general `hrmq:rules:audit`
command is a read-only reporting tool.

## Seeding test data

```bash
occ hrmq:rules:seed-testdata
```

Idempotently backfills local test data so the enforced rules have
something to check — provider sample objects and provider field
defaults, applied by `RuleTestDataSeeder` from each provider's
`seedSpec()`.

## Where this shows up elsewhere

Nearly every capability documented elsewhere in this site is backed by
one or more corpus rules — see
[Loonaangifte filing](/docs/payroll/loonaangifte),
[Pension filing](/docs/payroll/pension-upa),
[Leave & verzuim](/docs/hr/leave-and-verzuim),
[Time & attendance](/docs/hr/time-attendance),
[Onboarding & offboarding](/docs/people/onboarding-offboarding),
[Recruiting](/docs/people/recruiting),
[Performance reviews](/docs/people/performance),
[Org chart](/docs/people/org-chart),
[Documents](/docs/people/documents), and
[Assets](/docs/people/assets) — for the specific rules each one adds.
