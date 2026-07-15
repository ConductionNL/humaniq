<?php

/**
 * Rule Engine
 *
 * Evaluates an HR/labour object against the machine-checkable rules that apply to
 * it, returning structured Violations. This is the executable layer over the
 * static RuleCatalogue: each registered check is a pure predicate over an object
 * (plus context), keyed by a real catalogue rule id, so a violation carries the
 * rule's severity and source straight from the corpus. Applicability is scoped by
 * jurisdiction (a rule applies to its own country, plus EU-wide rules for EU
 * members and `global` rules everywhere) so the engine only enforces what the
 * organisation is actually subject to.
 *
 * The engine carries no built-in checks of its own: every executable check is
 * contributed by an auto-discovered per-domain CheckProvider under
 * lib/Standards/Checks/. Only rules with a registered predicate are enforced
 * today; the rest of the corpus is catalogued and grows an executable check per
 * wave. The predicates are side-effect free and unit-tested; the lifecycle wiring
 * + object loading live in OCA\Hrmq\Lifecycle\RuleComplianceGuard.
 *
 * @category Standards
 * @package  OCA\Hrmq\Standards
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/hrm-rule-engine/specs/hrm-rule-engine/spec.md
 */

declare(strict_types=1);

namespace OCA\Hrmq\Standards;

/**
 * Evaluates objects against applicable machine-checkable rules.
 */
final class RuleEngine
{

    /**
     * EU member states (ISO 3166-1 alpha-2) — used to decide whether an EU-wide
     * obligation applies to a given jurisdiction.
     *
     * @var string[]
     */
    public const EU_MEMBER_STATES = [
        'AT', 'BE', 'BG', 'HR', 'CY', 'CZ', 'DK', 'EE', 'FI', 'FR',
        'DE', 'GR', 'HU', 'IE', 'IT', 'LV', 'LT', 'LU', 'MT', 'NL',
        'PL', 'PT', 'RO', 'SK', 'SI', 'ES', 'SE',
    ];

    /**
     * Memoised rule index (id => rule), built from RuleCatalogue.
     *
     * @var array<string, array<string, mixed>>|null
     */
    private static ?array $index = null;

    /**
     * Memoised list of discovered per-domain CheckProvider class-strings.
     *
     * @var array<int, class-string>|null
     */
    private static ?array $providers = null;

    /**
     * The registered predicates, keyed by object type then rule id. Each
     * predicate is `fn(array $object, array $context): bool` — true = the rule is
     * satisfied. Every key is a real RuleCatalogue id.
     *
     * The engine ships no built-in checks: the entire registry is assembled from
     * the auto-discovered per-domain CheckProviders (lib/Standards/Checks/*.php).
     * Each provider contributes [objectType => [ruleId => predicate]] and later
     * providers add to (never silently overwrite) the merged registry, so the
     * corpus can grow an executable check per domain without editing this file.
     *
     * @return array<string, array<string, callable>>
     */
    private static function checks(): array
    {
        $merged = [];
        foreach (self::providers() as $provider) {
            foreach ($provider::checks() as $objectType => $ruleChecks) {
                $merged[$objectType] = array_merge(($merged[$objectType] ?? []), $ruleChecks);
            }
        }

        return $merged;

    }//end checks()

    /**
     * The merged test-data field defaults declared by all providers, keyed by
     * object type then field name. Consumed by RuleTestDataSeeder.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function providerSeedSpecs(): array
    {
        $specs = [];
        foreach (self::providers() as $provider) {
            foreach ($provider::seedSpec() as $objectType => $fields) {
                $specs[$objectType] = array_merge(($specs[$objectType] ?? []), $fields);
            }
        }

        return $specs;

    }//end providerSeedSpecs()

    /**
     * The sample objects to create for empty object types, declared by providers
     * that implement the SeedsObjects capability. Keyed by object type.
     *
     * @return array<string, array<int, array<string, mixed>>>
     */
    public static function providerSeedObjects(): array
    {
        $objects = [];
        foreach (self::providers() as $provider) {
            if (in_array(\OCA\Hrmq\Standards\Checks\SeedsObjects::class, class_implements($provider), true) === false) {
                continue;
            }

            foreach ($provider::seedObjects() as $objectType => $samples) {
                $objects[$objectType] = array_merge(($objects[$objectType] ?? []), $samples);
            }
        }

        return $objects;

    }//end providerSeedObjects()

    /**
     * The natural-key field name to upsert seeded samples on, keyed by object
     * type, declared by providers that implement `UpsertsObjects` (cao-library
     * design.md D7). Only object types a provider declares here get
     * upsert-by-key seeding in `RuleTestDataSeeder`; every other `SeedsObjects`
     * sample keeps the default create-once-when-empty behaviour.
     *
     * @return array<string, string>
     *
     * @spec openspec/changes/cao-library/specs/cao-library/spec.md#REQ-CAO-006
     */
    public static function providerUpsertKeys(): array
    {
        $keys = [];
        foreach (self::providers() as $provider) {
            if (in_array(\OCA\Hrmq\Standards\Checks\UpsertsObjects::class, class_implements($provider), true) === false) {
                continue;
            }

            foreach ($provider::upsertKeys() as $objectType => $field) {
                $keys[$objectType] = $field;
            }
        }

        return $keys;

    }//end providerUpsertKeys()

    /**
     * Discover the registered per-domain CheckProvider classes (memoised).
     *
     * @return array<int, class-string<\OCA\Hrmq\Standards\Checks\CheckProvider>>
     */
    private static function providers(): array
    {
        if (self::$providers !== null) {
            return self::$providers;
        }

        $found = [];
        foreach ((glob(__DIR__.'/Checks/*.php') ?: []) as $file) {
            $class = '\\OCA\\Hrmq\\Standards\\Checks\\'.basename($file, '.php');
            if (class_exists($class) === true
                && in_array(\OCA\Hrmq\Standards\Checks\CheckProvider::class, class_implements($class), true) === true
            ) {
                $found[] = $class;
            }
        }

        self::$providers = $found;
        return $found;

    }//end providers()

    /**
     * Evaluate an object of $objectType against its applicable machine-checkable
     * rules, returning the Violations (empty when compliant).
     *
     * @param string               $objectType OpenRegister schema name (e.g. `EmploymentContract`).
     * @param array<string, mixed> $object     The object being evaluated.
     * @param array<string, mixed> $context    `{ jurisdiction?: string }` — defaults to NL.
     *
     * @return array<int, Violation>
     */
    public static function evaluate(string $objectType, array $object, array $context=[]): array
    {
        $rules      = self::index();
        $violations = [];

        foreach ((self::checks()[$objectType] ?? []) as $ruleId => $predicate) {
            $rule = ($rules[$ruleId] ?? null);
            if ($rule === null || self::applies($rule, $context) === false) {
                continue;
            }

            $satisfied = false;
            try {
                $satisfied = (bool) $predicate($object, $context);
            } catch (\Throwable $e) {
                $satisfied = false;
            }

            if ($satisfied === false) {
                $violations[] = self::violationFor($ruleId);
            }
        }

        return $violations;

    }//end evaluate()

    /**
     * True when any violation is `mandatory` (i.e. a lifecycle guard must block).
     *
     * @param array<int, Violation> $violations Violations to inspect.
     *
     * @return bool
     */
    public static function hasMandatory(array $violations): bool
    {
        foreach ($violations as $violation) {
            if ($violation->severity === 'mandatory') {
                return true;
            }
        }

        return false;

    }//end hasMandatory()

    /**
     * Build a Violation for a rule id from the catalogue (severity/source/text).
     *
     * @param string $ruleId The catalogue rule id.
     *
     * @return Violation
     */
    public static function violationFor(string $ruleId): Violation
    {
        $rule = (self::index()[$ruleId] ?? null);
        return new Violation(
            $ruleId,
            (string) ($rule['severity'] ?? 'mandatory'),
            (string) ($rule['source'] ?? $ruleId),
            (string) ($rule['statement'] ?? '')
        );

    }//end violationFor()

    /**
     * Object types that have at least one registered executable check.
     *
     * @return array<int, string>
     */
    public static function supportedTypes(): array
    {
        return array_keys(self::checks());

    }//end supportedTypes()

    /**
     * All catalogue rule ids that have a registered executable check (across all
     * object types) — i.e. the rules the engine can actually enforce today.
     *
     * @return array<int, string>
     */
    public static function checkedRuleIds(): array
    {
        $ids = [];
        foreach (self::checks() as $byRule) {
            foreach (array_keys($byRule) as $ruleId) {
                $ids[$ruleId] = true;
            }
        }

        return array_keys($ids);

    }//end checkedRuleIds()

    /**
     * Reset the memoised index (test hook).
     *
     * @return void
     */
    public static function reset(): void
    {
        self::$index     = null;
        self::$providers = null;

    }//end reset()

    /**
     * Whether a rule applies to the context jurisdiction: its own country, plus
     * EU-wide rules for EU members and `global` rules everywhere.
     *
     * @param array<string, mixed> $rule    The catalogue rule.
     * @param array<string, mixed> $context Evaluation context.
     *
     * @return bool
     */
    private static function applies(array $rule, array $context): bool
    {
        $ruleJ = strtoupper((string) ($rule['jurisdiction'] ?? ''));
        $code  = strtoupper((string) ($context['jurisdiction'] ?? 'NL'));

        if ($ruleJ === $code || $ruleJ === 'GLOBAL') {
            return true;
        }

        return $ruleJ === 'EU' && in_array($code, self::EU_MEMBER_STATES, true);

    }//end applies()

    /**
     * Build the id => rule index from RuleCatalogue (memoised).
     *
     * @return array<string, array<string, mixed>>
     */
    private static function index(): array
    {
        if (self::$index !== null) {
            return self::$index;
        }

        $index = [];
        foreach (RuleCatalogue::all() as $rule) {
            $index[(string) $rule['id']] = $rule;
        }

        self::$index = $index;
        return $index;

    }//end index()
}//end class
