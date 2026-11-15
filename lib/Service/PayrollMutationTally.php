<?php

/**
 * Payroll mutation tally
 *
 * Tallies two runs' per-employee payslip components (integer cents) into
 * the per-employee lines, the entered/left/changed/unchanged counts and the
 * run-level deltas of a `PayrollMutationReport` (payroll-mutation-reports
 * design.md D1-D3). All arithmetic is integer cents; euros only on output.
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
 * @spec openspec/specs/payroll-mutation-reports/spec.md#REQ-MUT-001
 */

declare(strict_types=1);

namespace OCA\Humaniq\Service;

/**
 * Per-employee lines, counts and run-level deltas of a mutation report.
 *
 * @spec openspec/specs/payroll-mutation-reports/spec.md#REQ-MUT-001
 */
final class PayrollMutationTally {

	/**
	 * The four headline components compared per employee.
	 *
	 * @var array<int, string>
	 */
	private const COMPONENTS = ['grossPay', 'nettoPay', 'loonheffing', 'employerCost'];

	/**
	 * The report lines, one per employee, in employeeId order.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $lines = [];

	/**
	 * The number of employees per classification.
	 *
	 * @var array<string, int>
	 */
	private array $counts = ['entered' => 0, 'left' => 0, 'changed' => 0, 'unchanged' => 0];

	/**
	 * The run-level delta per component, in cents.
	 *
	 * @var array<string, int>
	 */
	private array $rollup = ['grossPay' => 0, 'nettoPay' => 0, 'loonheffing' => 0, 'employerCost' => 0];

	/**
	 * @param array<string, array<string, int>> $fromByEmployee The baseline run's components by employeeId ([] on the first-run path).
	 * @param array<string, array<string, int>> $toByEmployee The reviewed run's components by employeeId.
	 */
	private function __construct(
		private readonly array $fromByEmployee,
		private readonly array $toByEmployee,
	) {
	}//end __construct()

	/**
	 * The counts, the run-level deltas and the lines of the two runs.
	 *
	 * @param array<string, array<string, int>> $fromByEmployee The baseline run's components by employeeId ([] on the first-run path).
	 * @param array<string, array<string, int>> $toByEmployee The reviewed run's components by employeeId.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/specs/payroll-mutation-reports/spec.md#REQ-MUT-002
	 * @spec openspec/specs/payroll-mutation-reports/spec.md#REQ-MUT-003
	 */
	public static function summarise(array $fromByEmployee, array $toByEmployee): array {
		$tally = new self($fromByEmployee, $toByEmployee);
		foreach ($tally->employeeIds() as $employeeId) {
			$tally->add($employeeId);
		}

		return $tally->countFields() + $tally->deltaFields() + ['lines' => $tally->lines];
	}//end summarise()

	/**
	 * Every employee on either side, sorted.
	 *
	 * @return array<int, string>
	 */
	private function employeeIds(): array {
		$employeeIds = array_unique(array_merge(array_keys($this->fromByEmployee), array_keys($this->toByEmployee)));
		sort($employeeIds);

		return $employeeIds;
	}//end employeeIds()

	/**
	 * Classify one employee, count it and add its line.
	 *
	 * @param int|string $employeeId The employee (an int when the id is numeric, as an array key).
	 *
	 * @return void
	 */
	private function add(int|string $employeeId): void {
		$classification = self::classify(($this->fromByEmployee[$employeeId] ?? null), ($this->toByEmployee[$employeeId] ?? null));
		$this->counts[$classification]++;

		$line = ['employeeId' => $employeeId, 'classification' => $classification];
		foreach (self::COMPONENTS as $component) {
			$line += $this->componentFields($employeeId, $component);
		}

		$this->lines[] = $line;
	}//end add()

	/**
	 * One component's before/after/delta for an employee, adding the delta
	 * to the run-level roll-up.
	 *
	 * @param int|string $employeeId The employee (an int when the id is numeric, as an array key).
	 * @param string $component The component.
	 *
	 * @return array<string, float>
	 */
	private function componentFields(int|string $employeeId, string $component): array {
		$beforeCents = (int)($this->fromByEmployee[$employeeId][$component] ?? 0);
		$afterCents = (int)($this->toByEmployee[$employeeId][$component] ?? 0);
		$deltaCents = ($afterCents - $beforeCents);

		$this->rollup[$component] += $deltaCents;

		return [
			$component . 'Before' => self::euros($beforeCents),
			$component . 'After' => self::euros($afterCents),
			$component . 'Delta' => self::euros($deltaCents),
		];
	}//end componentFields()

	/**
	 * The number of employees per classification.
	 *
	 * @return array<string, int>
	 */
	private function countFields(): array {
		return [
			'enteredCount' => $this->counts['entered'],
			'leftCount' => $this->counts['left'],
			'changedCount' => $this->counts['changed'],
			'unchangedCount' => $this->counts['unchanged'],
		];
	}//end countFields()

	/**
	 * The run-level deltas, and the total wage cost delta (gross plus
	 * employer cost).
	 *
	 * @return array<string, float>
	 */
	private function deltaFields(): array {
		return [
			'grossDelta' => self::euros($this->rollup['grossPay']),
			'netDelta' => self::euros($this->rollup['nettoPay']),
			'loonheffingDelta' => self::euros($this->rollup['loonheffing']),
			'employerCostDelta' => self::euros($this->rollup['employerCost']),
			'totalWageCostDelta' => self::euros($this->rollup['grossPay'] + $this->rollup['employerCost']),
		];
	}//end deltaFields()

	/**
	 * Classify one employee's membership across the two payslip sets
	 * (design.md D1): entered/left when present only in one side, changed
	 * when any of the four headline components differ, unchanged when all
	 * four are equal. A first-run `$from === null` always yields `entered`.
	 *
	 * @param array<string, int>|null $from The employee's `from`-side cents components, or null.
	 * @param array<string, int>|null $to The employee's `to`-side cents components, or null.
	 *
	 * @return string `entered`|`left`|`changed`|`unchanged`.
	 *
	 * @spec openspec/specs/payroll-mutation-reports/spec.md#REQ-MUT-001
	 */
	private static function classify(?array $from, ?array $to): string {
		if ($from === null) {
			return 'entered';
		}

		if ($to === null) {
			return 'left';
		}

		return (self::componentsDiffer($from, $to) === true) ? 'changed' : 'unchanged';
	}//end classify()

	/**
	 * Whether any of the four headline components differs between the sides.
	 *
	 * @param array<string, int> $from The employee's `from`-side cents components.
	 * @param array<string, int> $to The employee's `to`-side cents components.
	 *
	 * @return bool
	 */
	private static function componentsDiffer(array $from, array $to): bool {
		foreach (self::COMPONENTS as $component) {
			if ((int)($from[$component] ?? 0) !== (int)($to[$component] ?? 0)) {
				return true;
			}
		}

		return false;
	}//end componentsDiffer()

	/**
	 * Convert integer cents to a euro float rounded to 2 decimals — the
	 * write/output-time boundary, mirroring `PayrollRunService::euros()`.
	 *
	 * @param int $cents The cents amount.
	 *
	 * @return float
	 */
	private static function euros(int $cents): float {
		return round(($cents / 100), 2);
	}//end euros()
}//end class
