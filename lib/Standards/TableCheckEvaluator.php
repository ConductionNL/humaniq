<?php

/**
 * Table Check Evaluator
 *
 * The one door from humaniq's compliance engine to OpenRegister's shared
 * decision-table evaluator (`OCA\OpenRegister\Service\Dmn\DecisionTableEvaluator`,
 * the engine behind the `openregister.decision-table` flow node). humaniq
 * implements no cell grammar, no hit policies and no input coercion of its
 * own — a table-declared check hands the table plus its derived inputs to the
 * shared engine and reads back the boolean `satisfied` output.
 *
 * The evaluator is resolved by direct construction behind a `class_exists()`
 * availability guard (ADR-083): OpenRegister documents the class as pure and
 * directly constructible, so no container round-trip is needed and the
 * delegate behaves identically under occ, background jobs and tests. There is
 * deliberately NO fallback matcher — a fallback would be the second engine
 * the rules-onto-or-decision-tables change exists to remove. When OpenRegister
 * is absent or the evaluation is refused, the exception propagates and
 * `RuleEngine::evaluate()`'s existing throwing-predicate path converts it to a
 * violation: fail closed, never a silent pass.
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

use RuntimeException;

/**
 * Delegates decision-table matching to OpenRegister's shared evaluator.
 */
final class TableCheckEvaluator {

	/**
	 * OpenRegister's shared evaluator, referenced by name because the class
	 * only exists when the OpenRegister app is installed alongside humaniq.
	 *
	 * @var string
	 */
	private const EVALUATOR_CLASS = 'OCA\\OpenRegister\\Service\\Dmn\\DecisionTableEvaluator';

	/**
	 * Memoised evaluator instance (it is pure and stateless, one is enough).
	 *
	 * @var object|null
	 */
	private static ?object $evaluator = null;

	/**
	 * Test-only override of the evaluator class name, so the unit suite can
	 * exercise the missing-OpenRegister refusal (the vendored test copies make
	 * the real name resolvable there) and edge shapes no real evaluator
	 * returns. Never set in production code.
	 *
	 * @var string|null
	 */
	private static ?string $classOverride = null;

	/**
	 * Evaluate a decision table against derived inputs and read the boolean
	 * `satisfied` output.
	 *
	 * Anything other than a strict `true` — including a table that forgot to
	 * declare the output — reads as unsatisfied, so a malformed table can
	 * never silently pass.
	 *
	 * @param array<string, mixed> $table The decision table, in OpenRegister's inline grammar.
	 * @param array<string, mixed> $inputs Derived input values, keyed by declared input name.
	 *
	 * @return bool True when the table decides `satisfied`.
	 *
	 * @throws \RuntimeException When OpenRegister's evaluator is not resolvable (fail closed).
	 * @throws \Throwable OpenRegister's DecisionEvaluationException propagates unchanged.
	 *
	 * @spec openspec/changes/rules-onto-or-decision-tables/specs/hrm-rule-engine/spec.md#REQ-RULE-008
	 */
	public static function satisfied(array $table, array $inputs): bool {
		$result = self::evaluator()->evaluate($table, $inputs);
		if (is_array($result) === false || is_array($result['outputs'] ?? null) === false) {
			return false;
		}

		return ($result['outputs']['satisfied'] ?? null) === true;
	}//end satisfied()

	/**
	 * Reset the memoised evaluator (test hook, mirroring RuleEngine::reset()).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/rules-onto-or-decision-tables/specs/hrm-rule-engine/spec.md#REQ-RULE-008
	 */
	public static function reset(): void {
		self::$evaluator = null;
		self::$classOverride = null;
	}//end reset()

	/**
	 * Point the delegate at a different evaluator class (test hook — see
	 * `$classOverride`). Clears the memoised instance so the next call
	 * resolves fresh.
	 *
	 * @param string|null $class Fully qualified class name, or null to restore the default.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/rules-onto-or-decision-tables/specs/hrm-rule-engine/spec.md#REQ-RULE-008
	 */
	public static function overrideEvaluatorClass(?string $class): void {
		self::$classOverride = $class;
		self::$evaluator = null;
	}//end overrideEvaluatorClass()

	/**
	 * Resolve the shared evaluator (memoised), or refuse loudly.
	 *
	 * @return object The shared DecisionTableEvaluator.
	 *
	 * @throws \RuntimeException When the OpenRegister class is absent.
	 */
	private static function evaluator(): object {
		if (self::$evaluator !== null) {
			return self::$evaluator;
		}

		$class = (self::$classOverride ?? self::EVALUATOR_CLASS);
		if (class_exists($class) === false) {
			throw new RuntimeException(
				'OpenRegister decision-table evaluator unavailable — table-declared compliance checks fail closed without the openregister app'
			);
		}

		self::$evaluator = new $class();

		return self::$evaluator;
	}//end evaluator()
}//end class
