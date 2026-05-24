# ADR-033: Root Configuration Consolidation

`phpcs.xml`, `phpmd.xml`, `psalm.xml`, `phpstan.neon`, `phpstan-bootstrap.php`, and the `phpcs-custom-sniffs/CustomSniffs/Sniffs/` directory are **fleet-canonical**. They live in [`nextcloud-app-template`](https://github.com/ConductionNL/nextcloud-app-template) and every Conduction PHP app mirrors them byte-for-byte (modulo a small, allowed set of cosmetic / tracked-debt deviations described below).

This consolidation was performed fleet-wide in May 2026 across 17 PHP apps via a single template + per-app PR rollout.

## The directive

> **No per-app validation rules.** If we need exceptions, they are for all apps.
>
> — Conduction, 2026-05-21

The rule applies to root-level lint configurations only. Application source code and tests are still per-app. Per-app **lint debt** is allowed but only via the tracked-debt mechanism below.

## What is canonical (no per-app overrides allowed)

| File | Source of truth | What it carries |
|---|---|---|
| `phpcs.xml` | template root | Full PEAR / Squiz / Generic / PSR ruleset, forbidden-functions list (`die`, `error_log`, …), `Generic.Files.LineLength` (limit 150), `Squiz.PHP.DisallowInlineIf`, `ignore_warnings_on_exit=1`, custom sniffs (SpecTag, NoLegacyServerAccessors, NamedParameters), excludes for `vendor/`, `vendor-bin/`, `node_modules/`, `lib/Resources/template/`. |
| `phpmd.xml` | template root | Clean-code + codesize + design + naming + unused-code rulesets with the Conduction adjustments (`UnusedFormalParameter` excludes `*Migration*`, `ShortVariable` allowlist for `id`, `db`, `qb`, …, `CamelCaseParameterName`/`CamelCaseVariableName` removed). |
| `psalm.xml` | template root | `errorLevel=4` + the canonical `<UndefinedClass>` whitelist (Nextcloud OCP types, OpenRegister cross-app types, server-internal `OC` + `GuzzleHttp`), `errorBaseline="psalm-baseline.xml"`, ignores `lib/Resources/template/`. |
| `phpstan.neon` | template root | `level: 5`, paths `lib`, bootstrap `phpstan-bootstrap.php`, full `ignoreErrors` family list (Nextcloud server-internals + OCA\DAV + OC\Security\CSRF + OCA\OpenRegister + GuzzleHttp + Doctrine\DBAL + OCP stub gaps), `includes: phpstan-baseline.neon`. |
| `phpstan-bootstrap.php` | template root | The autoloader entry used by phpstan. |
| `phpcs-custom-sniffs/CustomSniffs/Sniffs/Commenting/SpecTagSniff.php` | template (originally decidesk) | Enforces hydra ADR-003 `@spec` tag fleetwide as a warning. |
| `phpcs-custom-sniffs/CustomSniffs/Sniffs/Functions/NamedParametersSniff.php` | template | Enforces named-arguments style for `OC*` framework calls. |
| `phpcs-custom-sniffs/CustomSniffs/Sniffs/Nextcloud/NoLegacyServerAccessorsSniff.php` | template (originally openregister) | Blocks `\OC::$server->getX()` / `query()` removed in Nextcloud 34. |

## What apps own (allowed per-app)

| File | Why per-app | Rules |
|---|---|---|
| `phpstan-baseline.neon` | Tracked phpstan debt | Empty in template; populated per-app via `./vendor/bin/phpstan analyse --generate-baseline=phpstan-baseline.neon`. Every non-empty entry MUST have a matching open GitHub issue with a removal plan. |
| `psalm-baseline.xml` | Tracked psalm debt | Same pattern as phpstan-baseline.neon. Populated via psalm's set-baseline CLI flag. Open issue required. |
| `composer.json` `name` field cosmetic | App identity | `conductionnl/<app-slug>` is the canonical vendor; no other deviation. |
| `phpcs.xml <description>` and `phpmd.xml <ruleset name>` | App identity | The only allowed text edit on the synced files: change `AppTemplate` → `<App>`. Everything else stays byte-identical to the canonical. |

## What is forbidden

- App-specific `phpcs.xml <rule>` overrides that change a rule's severity or properties.
- App-specific `phpmd.xml` threshold changes (Cyclomatic, NPath, ExcessiveMethodLength, ClassLength).
- App-specific `phpstan.neon` `ignoreErrors` patterns. Use the baseline.
- App-specific `psalm.xml <referencedClass>` additions. Promote to canonical instead.
- Removing or reordering canonical rules.
- Stale `.php-cs-fixer.dist.php` (the legacy file). Every app dropped it; do not re-introduce.

## How a fleetwide rule change works

1. Submit a PR against [`nextcloud-app-template`](https://github.com/ConductionNL/nextcloud-app-template) on the `development` branch. The change applies to every app.
2. After merge, the [`sync-canonical-to-fleet.yml`](https://github.com/ConductionNL/nextcloud-app-template/blob/development/.github/workflows/sync-canonical-to-fleet.yml) workflow opens a PR against every app in the fleet, syncing the changed canonical files.
3. Per-app PRs are reviewed and merged on each app's normal cadence (pre-production: admin-merge OK; production: human review).

## How tracked debt works (the zaakafhandelapp#201 pattern)

When the canonical surfaces real lint findings in an app and they can't be fixed in one sitting:

1. Open a GitHub issue titled `Lint debt cleanup post-canonical-sync` on the affected app's repo. List every phpstan / psalm / phpmd / phpcs finding as a checklist with file + line + rule.
2. Generate `phpstan-baseline.neon` (and `psalm-baseline.xml` if needed) capturing the findings. Both baseline files MUST include a comment header linking to the tracking issue.
3. Mention the issue number in the sync PR. Quality CI for the affected gates stays red — except phpstan/psalm which baseline absorbs — until the issue closes.
4. phpmd has no native baseline support. Apps with phpmd debt either fix in source or accept red phpmd CI until cleanup completes. Do not relax thresholds.

The single allowed exception is the ticket-with-demotion pattern (`<rule ref="…"><severity>0</severity></rule>` blocks in `phpcs.xml` with a header comment linking to the GitHub issue). This is reserved for apps with extensive historical comment debt; the demotion is removed as the issue closes per-rule. zaakafhandelapp#201 is the canonical example. Apply this pattern sparingly.

## Mechanical phpmd violations — fix in source

When a canonical sync surfaces these categories, fix them in source as part of the sync PR — they're cheap and safe:

- `MissingImport` — add `use Foo;`, replace `\Foo` with `Foo`.
- `ElseExpression` — refactor `if/else` to two-if pattern (NOT ternary — Squiz.PHP.DisallowInlineIf forbids inline if).
- `UnusedLocalVariable` — drop the assignment, preserve side-effect calls.
- `UnusedFormalParameter` — annotate `@SuppressWarnings(PHPMD.UnusedFormalParameter)` when the parameter is part of a required interface signature; otherwise remove.
- `BooleanArgumentFlag` on private methods — refactor. On public methods, defer to a follow-up architectural PR.

Architectural categories (Cyclomatic, NPath, ExcessiveMethodLength, ExcessiveClassComplexity, CouplingBetweenObjects) belong in follow-up PRs that close the tracking issue checklist.

## See also

- [ADR-008: Testing](adr-008-testing.md) — `composer check:strict` runs the canonical lint suite.
- [ADR-013: Container pool](adr-013-container-pool-and-model-selection.md) — Hydra builder + reviewer containers consume the canonical via image-build copies.
- [ADR-022: Apps consume OpenRegister abstractions](adr-022-apps-consume-or-abstractions.md) — the OR `referencedClass` whitelist in canonical `psalm.xml` reflects this contract.

## Rollout history

May 2026 — full fleet rollout across 17 PHP apps (decidesk pilot, then 16 fleet apps via parallel subagents). Five canonical PRs landed in `nextcloud-app-template` (#47–#51). One per-app PR per fleet app, each with its own tracking issue capturing residual debt. ~400 mechanical phpmd violations fixed in source during sync; thousands of phpstan / psalm / phpmd architectural findings baselined as tracked debt for incremental cleanup.
