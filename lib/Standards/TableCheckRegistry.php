<?php

/**
 * Table Check Registry
 *
 * Collects the table-declared checks from providers implementing the
 * ProvidesTables capability and wraps each as a predicate over
 * (object, context), so RuleEngine's registry — and with it every consumer of
 * evaluate() — is unaffected by a check's representation. Matching is
 * delegated to OpenRegister's shared decision-table evaluator through
 * TableCheckEvaluator; a `derive` that returns null is the vacuous pass (not
 * decidable from this object alone). Split out of RuleEngine so the engine
 * keeps its complexity budget and its single job: applicability, severity and
 * Violation assembly.
 *
 * @category Standards
 * @package  OCA\Humaniq\Standards
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
 * @spec openspec/changes/rules-onto-or-decision-tables/specs/hrm-rule-engine/spec.md#REQ-RULE-008
 */

declare(strict_types=1);

namespace OCA\Humaniq\Standards;

use OCA\Humaniq\Standards\Checks\ProvidesTables;

/**
 * Wraps table-declared checks as registry predicates.
 */
final class TableCheckRegistry {

	/**
	 * The table-declared checks of the given providers, wrapped as predicates
	 * and keyed by object type then rule id — the same shape provider
	 * closures use, so RuleEngine merges both without distinction.
	 *
	 * @param array<int, class-string> $providers The discovered CheckProvider classes.
	 *
	 * @return array<string, array<string, callable>>
	 *
	 * @spec openspec/changes/rules-onto-or-decision-tables/specs/hrm-rule-engine/spec.md#REQ-RULE-008
	 */
	public static function checks(array $providers): array {
		$merged = [];
		foreach ($providers as $provider) {
			if (is_a($provider, ProvidesTables::class, true) === false) {
				continue;
			}

			foreach ($provider::tables() as $objectType => $tableSpecs) {
				foreach ($tableSpecs as $ruleId => $spec) {
					$merged[$objectType][$ruleId] = self::wrap($spec);
				}
			}
		}

		return $merged;
	}//end checks()

	/**
	 * Wrap one table-declared check spec as a predicate over (object, context).
	 *
	 * @param array{derive: callable, table: array<string, mixed>} $spec The declared check.
	 *
	 * @return callable The predicate the registry serves.
	 *
	 * @spec openspec/changes/rules-onto-or-decision-tables/specs/hrm-rule-engine/spec.md#REQ-RULE-008
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) TableCheckEvaluator is the engine's
	 * one static door to OpenRegister's shared evaluator, mirroring the fully
	 * static RuleEngine registry these wrappers are served from — injecting it
	 * would force DI onto a static discovery chain for no seam gain.
	 */
	private static function wrap(array $spec): callable {
		return static function (array $object, array $context) use ($spec): bool {
			$inputs = ($spec['derive'])($object, $context);
			if ($inputs === null) {
				return true;
			}

			return TableCheckEvaluator::satisfied($spec['table'], (array)$inputs);
		};
	}//end wrap()
}//end class
