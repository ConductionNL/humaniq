<?php

/**
 * Seeds the memoised rule catalogue with a variation of the real corpus.
 *
 * Several predicates read their parameters from a corpus rule and degrade to
 * a vacuous pass (or a documented fallback) when that rule, or its
 * `parameters`, cannot be read. The corpus is read from `rules/*.json`, so
 * those branches are only reachable with a catalogue that differs from the
 * shipped one: this helper loads the real catalogue, drops or rewrites one
 * rule and stores the result in `RuleCatalogue`'s memo (the
 * CaoOvertimeGoldenTest precedent for `CaoRegistry`). Callers restore the
 * real corpus with `RuleCatalogue::reset()` in tearDown.
 *
 * @category Test
 * @package  OCA\Humaniq\Tests\Unit\Standards\Checks
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
 * @spec openspec/specs/hrm-rule-engine/spec.md
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Standards\Checks;

use OCA\Humaniq\Standards\RuleCatalogue;
use ReflectionProperty;

/**
 * Replaces one rule of the memoised catalogue.
 */
final class CatalogueOverride {

	/**
	 * Seed the catalogue with the real corpus minus `$ruleId`.
	 *
	 * @param string $ruleId The rule to drop.
	 *
	 * @return void
	 */
	public static function without(string $ruleId): void {
		self::seed($ruleId, null);

	}//end without()

	/**
	 * Seed the catalogue with the real corpus, `$ruleId` rewritten by `$rewrite`.
	 *
	 * @param string $ruleId The rule to rewrite.
	 * @param callable(array<string, mixed>): array<string, mixed> $rewrite Returns the rewritten rule.
	 *
	 * @return void
	 */
	public static function rewrite(string $ruleId, callable $rewrite): void {
		self::seed($ruleId, $rewrite);

	}//end rewrite()

	/**
	 * Load the real corpus, drop or rewrite one rule, and store the result in the memo.
	 *
	 * @param string $ruleId The rule.
	 * @param callable|null $rewrite The rewrite, or null to drop the rule.
	 *
	 * @return void
	 */
	private static function seed(string $ruleId, ?callable $rewrite): void {
		RuleCatalogue::reset();

		$rules = [];
		$found = false;
		foreach (RuleCatalogue::all() as $rule) {
			if ((string)($rule['id'] ?? '') !== $ruleId) {
				$rules[] = $rule;
				continue;
			}

			$found = true;
			if ($rewrite !== null) {
				$rules[] = $rewrite($rule);
			}
		}

		if ($found === false) {
			throw new \LogicException('CatalogueOverride: rule "' . $ruleId . '" is not in the corpus.');
		}

		$cache = new ReflectionProperty(RuleCatalogue::class, 'cache');
		$cache->setValue(null, $rules);

	}//end seed()

}//end class
