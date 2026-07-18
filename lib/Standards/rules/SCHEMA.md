# Rule corpus schema

The HR/labour rule corpus is versioned static data — one JSON file per sub-domain
in this directory, loaded and merged by `RuleCatalogue`. Compliance rules are
universal facts about directives/conventions/laws (identical for every tenant,
changing only with regulation), so they live here in code, **not** in OpenRegister
(which is for per-tenant config only). Bump `RuleCatalogue::VERSION` on any change.

Each file is `{ "domain": "<key>", "rules": [ <rule>, ... ] }`.

## Rule shape

| key              | required | meaning                                                                                  |
| ---------------- | -------- | ---------------------------------------------------------------------------------------- |
| `id`             | yes      | stable slug, unique across the whole corpus (e.g. `wtd-art6-max-weekly-hours`)            |
| `domain`         | yes      | sub-domain key (e.g. `working-time`, `leave`, `pay`, `payroll`, `gdpr-employee`)         |
| `jurisdiction`   | yes      | ISO 3166-1 alpha-2, or `EU` (EU-wide), or `global`                                       |
| `framework`      | yes      | framework/law slug (e.g. `wtd-2003-88`, `eu-2019-1152`, `gdpr`, `bw7-10`, `wml`, `nl-pensioenaangifte`, `nl-poortwachter`, `nl-wid`, `hr-org-core`, `hr-assets-core`, `nl-arbeidstijdenwet`, `hr-signals`, `hr-administratie-core`, `hr21`) |
| `source`         | yes      | human citation (e.g. `WTD 2003/88/EC art. 6(b)`)                                          |
| `statement`      | yes      | the operative rule statement                                                             |
| `severity`       | yes      | `mandatory` \| `conditional` \| `recommended`                                            |
| `machineCheckable` | no     | `true` only if a deterministic program can decide compliance from structured fields      |
| `effectiveDate`  | no       | ISO date the obligation became/becomes mandatory                                         |
| `sourceUrl`      | no       | authoritative URL                                                                        |

## `machineCheckable` discipline

Set `true` **only** when compliance is decidable from structured fields by a
deterministic predicate: presence, format, arithmetic, enumeration, cardinality,
date-window, or referential. Narrative / judgemental rules (disclosures,
"reasonable", "appropriate", policy text) are `false`. The audit coverage metric is
`enforced ÷ machine-checkable`, so over-flagging silently deflates coverage — flag
honestly.

A rule becomes *enforced* when a `CheckProvider` under `../Checks/` registers a
predicate keyed by that rule `id`. The engine only enforces rules that have both a
corpus entry and a registered predicate.
