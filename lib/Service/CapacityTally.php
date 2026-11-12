<?php

/**
 * Capacity Tally
 *
 * One forward-capacity answer being built: one line per person (planned
 * against their own contracted hours, or said to have no contract), and the
 * totals over the people with a contract.
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
 * @spec openspec/specs/rostering/spec.md
 */

declare(strict_types=1);

namespace OCA\Humaniq\Service;

/**
 * Lines and totals of one ForwardCapacityService::capacity() answer.
 *
 * @spec openspec/specs/rostering/spec.md
 */
final class CapacityTally {

	/**
	 * Planned hours of the people with a contract.
	 *
	 * @var float
	 */
	private float $plannedTotal = 0.0;

	/**
	 * Contracted hours of the people with a contract.
	 *
	 * @var float
	 */
	private float $contractedTotal = 0.0;

	/**
	 * How many people have no contract in force.
	 *
	 * @var int
	 */
	private int $withoutContract = 0;

	/**
	 * The line of a person with a working pattern in force, counted in the totals.
	 *
	 * @param string                    $employeeId The person.
	 * @param array<string, mixed>|null $free       Their availability row, or null.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/specs/rostering/spec.md
	 */
	public function withContract(string $employeeId, ?array $free): array {
		$contracted = (float)($free['contractedHours'] ?? 0.0);
		$planned = (float)($free['committedHours'] ?? 0.0);
		$this->plannedTotal += $planned;
		$this->contractedTotal += $contracted;

		return [
			'employeeId' => $employeeId,
			'plannedHours' => round(num: $planned, precision: 2),
			'contractedHours' => round(num: $contracted, precision: 2),
			'utilisationPercentage' => self::percentage(planned: $planned, contracted: $contracted),
			'hasContractedHours' => true,
		];

	}//end withContract()

	/**
	 * The line of a person without a working pattern in force: said, not guessed.
	 *
	 * @param string                    $employeeId The person.
	 * @param array<string, mixed>|null $free       Their availability row, or null.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/specs/rostering/spec.md
	 */
	public function withoutContract(string $employeeId, ?array $free): array {
		++$this->withoutContract;

		return [
			'employeeId' => $employeeId,
			'plannedHours' => round(num: (float)($free['committedHours'] ?? 0.0), precision: 2),
			'contractedHours' => null,
			'utilisationPercentage' => null,
			'hasContractedHours' => false,
		];

	}//end withoutContract()

	/**
	 * The totals over the people with a contract.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/specs/rostering/spec.md
	 */
	public function totals(): array {
		return [
			'plannedHours' => round(num: $this->plannedTotal, precision: 2),
			'contractedHours' => round(num: $this->contractedTotal, precision: 2),
			'utilisationPercentage' => self::percentage(planned: $this->plannedTotal, contracted: $this->contractedTotal),
			'employeesWithoutContractedHours' => $this->withoutContract,
		];

	}//end totals()

	/**
	 * Planned as a percentage of contracted, one decimal; null without contracted hours.
	 *
	 * @param float $planned    Planned hours.
	 * @param float $contracted Contracted hours.
	 *
	 * @return float|null
	 */
	private static function percentage(float $planned, float $contracted): ?float {
		if ($contracted > 0.0) {
			return round(num: (($planned / $contracted) * 100.0), precision: 1);
		}

		return null;

	}//end percentage()
}//end class
