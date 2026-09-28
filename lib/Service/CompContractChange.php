<?php

/**
 * Comp Contract Change
 *
 * The contract write a pay change carries (comp-collective-raise-and-step-increase
 * design.md D4): `EmploymentContract.hourlyWage` from `proposedHourlyWage`, and
 * `salaryStep` from `toStep` with `stepDate` moved one year on. Pure: it
 * reads the adjustment and the contract it is given and returns the changed
 * contract; `CompAdjustmentService` loads and saves.
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
 * @spec openspec/specs/comp-collective-raise-and-step-increase/spec.md#REQ-CRS-004
 */

declare(strict_types=1);

namespace OCA\Humaniq\Service;

/**
 * Works out the hourly wage and step a pay change writes onto its contract.
 *
 * @spec openspec/specs/comp-collective-raise-and-step-increase/spec.md#REQ-CRS-004
 */
class CompContractChange {

	/**
	 * The new hourly wage the adjustment carries, in euros, or null.
	 *
	 * @param array<string, mixed> $adjustment The CompAdjustment.
	 *
	 * @return float|null
	 *
	 * @spec openspec/specs/comp-collective-raise-and-step-increase/spec.md#REQ-CRS-003
	 */
	public function hourlyWage(array $adjustment): ?float {
		$value = ($adjustment['proposedHourlyWage'] ?? null);
		return is_numeric($value) === true ? round((float)$value, 2) : null;
	}//end hourlyWage()

	/**
	 * The step the adjustment moves the contract to, or null.
	 *
	 * @param array<string, mixed> $adjustment The CompAdjustment.
	 *
	 * @return int|null
	 *
	 * @spec openspec/specs/comp-collective-raise-and-step-increase/spec.md#REQ-CRS-004
	 */
	public function toStep(array $adjustment): ?int {
		$value = ($adjustment['toStep'] ?? null);
		return is_numeric($value) === true ? (int)$value : null;
	}//end toStep()

	/**
	 * Whether the adjustment writes anything onto its contract.
	 *
	 * @param array<string, mixed> $adjustment The CompAdjustment.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/comp-collective-raise-and-step-increase/spec.md#REQ-CRS-003
	 */
	public function touchesContract(array $adjustment): bool {
		return $this->hourlyWage($adjustment) !== null || $this->toStep($adjustment) !== null;
	}//end touchesContract()

	/**
	 * The contract as it is after the change.
	 *
	 * @param array<string, mixed> $adjustment The CompAdjustment.
	 * @param array<string, mixed> $contract The EmploymentContract as loaded.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/specs/comp-collective-raise-and-step-increase/spec.md#REQ-CRS-004
	 */
	public function apply(array $adjustment, array $contract): array {
		unset($contract['@self']);
		$hourlyWage = $this->hourlyWage($adjustment);
		if ($hourlyWage !== null) {
			$contract['hourlyWage'] = $hourlyWage;
		}

		$toStep = $this->toStep($adjustment);
		if ($toStep === null) {
			return $contract;
		}

		$contract['salaryStep'] = $toStep;
		$stepDate = trim((string)($contract['stepDate'] ?? ''));
		$next = ($stepDate === '' ? false : strtotime($stepDate . ' +1 year'));
		if ($next !== false) {
			$contract['stepDate'] = gmdate('Y-m-d', $next);
		}

		return $contract;
	}//end apply()

	/**
	 * Name the contract change in an effectuation outcome, so the preview
	 * shows it too.
	 *
	 * @param array<string, mixed> $outcome The outcome.
	 * @param array<string, mixed>|null $change The change, or null when there is none.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/specs/comp-collective-raise-and-step-increase/spec.md#REQ-CRS-003
	 */
	public function describe(array $outcome, ?array $change): array {
		if ($change === null) {
			return $outcome;
		}

		$outcome['contractId'] = $change['contractId'];
		$outcome['newHourlyWage'] = $change['hourlyWage'];
		$outcome['newSalaryStep'] = $change['salaryStep'];

		return $outcome;
	}//end describe()

}//end class
