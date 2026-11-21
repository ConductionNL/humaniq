<?php

/**
 * Comp Cycle Budget
 *
 * What a compensation cycle's raises cost against the cycle's budget
 * (REQ-COMP-009). A raise costs the difference between an adjustment's
 * proposed and current monthly salary, in cents. Proposed, approved and
 * effective adjustments count: an approved raise that has taken effect still
 * spends the budget. Drafts and refused proposals do not. Going over the
 * budget is reported, never refused.
 *
 * @category Service
 * @package  OCA\Humaniq\Service
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
 * @spec openspec/specs/comp-cycles/spec.md
 */

declare(strict_types=1);

namespace OCA\Humaniq\Service;

/**
 * Sums a cycle's raises against its budget.
 *
 * @spec openspec/specs/comp-cycles/spec.md
 */
class CompCycleBudget {

	/**
	 * The adjustment states whose raise spends the budget.
	 *
	 * @var array<int, string>
	 */
	public const SPENDING_STATES = ['proposed', 'approved', 'effective'];

	/**
	 * @param RecordStoreInterface $gateway Reads the cycle's adjustments.
	 */
	public function __construct(
		private readonly RecordStoreInterface $gateway,
	) {

	}//end __construct()

	/**
	 * The budget, what the cycle's raises use of it, what is left and how far
	 * over it the cycle is, all in integer cents.
	 *
	 * @param array<string, mixed> $cycle The CompReviewCycle as read under the caller's RBAC.
	 *
	 * @return array<string, mixed> `{cycleId, budgetAmount, used, left, overBudget, isOverBudget, adjustments}`
	 *     in cents, and `euro` holding budget, used, left and overBudget in euros for display.
	 *
	 * @spec openspec/specs/comp-cycles/spec.md
	 */
	public function forCycle(array $cycle): array {
		$cycleId = (string)($cycle['id'] ?? ($cycle['@self']['id'] ?? ''));
		$budget = self::cents(value: $cycle['budgetAmount'] ?? null);

		$used = 0;
		$counted = 0;
		foreach ($this->gateway->findFiltered('CompAdjustment', ['cycleId' => $cycleId]) as $adjustment) {
			if (in_array((string)($adjustment['status'] ?? ''), self::SPENDING_STATES, true) === false) {
				continue;
			}

			$used += self::raise(adjustment: $adjustment);
			$counted++;
		}

		$left = null;
		$over = 0;
		if ($budget !== null) {
			$left = max(0, $budget - $used);
			$over = max(0, $used - $budget);
		}

		return [
			'cycleId' => $cycleId,
			'budgetAmount' => $budget,
			'used' => $used,
			'left' => $left,
			'overBudget' => $over,
			'isOverBudget' => $over > 0,
			'adjustments' => $counted,
			'euro' => [
				'budget' => self::euro(cents: $budget),
				'used' => self::euro(cents: $used),
				'left' => self::euro(cents: $left),
				'overBudget' => self::euro(cents: $over),
			],
		];
	}//end forCycle()

	/**
	 * One adjustment's raise: proposed minus current monthly salary, never
	 * negative. A proposal without a current salary snapshot costs nothing
	 * that can be counted, so it adds zero.
	 *
	 * @param array<string, mixed> $adjustment The CompAdjustment.
	 *
	 * @return int The raise in cents.
	 */
	private static function raise(array $adjustment): int {
		$current = self::cents(value: $adjustment['currentSalary'] ?? null);
		$proposed = self::cents(value: $adjustment['proposedSalary'] ?? null);
		if ($current === null || $proposed === null) {
			return 0;
		}

		return max(0, $proposed - $current);
	}//end raise()

	/**
	 * Cents as euros, null staying null.
	 *
	 * @param int|null $cents The amount in cents.
	 *
	 * @return float|null
	 */
	private static function euro(?int $cents): ?float {
		if ($cents === null) {
			return null;
		}

		return round($cents / 100, 2);
	}//end euro()

	/**
	 * An amount in cents, or null when it is absent or not a number.
	 *
	 * @param mixed $value The stored value.
	 *
	 * @return int|null
	 */
	private static function cents(mixed $value): ?int {
		if (is_int($value) === true) {
			return $value;
		}

		if (is_string($value) === true && is_numeric($value) === true) {
			return (int)$value;
		}

		if (is_float($value) === true) {
			return (int)round($value);
		}

		return null;
	}//end cents()
}//end class
