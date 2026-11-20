<?php

/**
 * Contract of PersonnelBudgetService
 *
 * The public surface of PersonnelBudgetService that other components call, so they depend
 * on this contract instead of the implementation.
 *
 * @category Kernel
 * @package  OCA\Humaniq\Kernel\Contracts
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
 * @spec exclude Structural contract, no functional requirement.
 */

declare(strict_types=1);

namespace OCA\Humaniq\Kernel\Contracts;

/**
 * Contract of PersonnelBudgetService.
 *
 * @category Kernel
 * @package  OCA\Humaniq\Kernel\Contracts
 * @author   Conduction Development Team <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://conduction.nl
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface PersonnelBudgetServiceInterface {
	/**
	 * The budget of one scenario for one year.
	 *
	 * @param array<string, mixed> $scenario The FormationScenario.
	 * @param array<string, mixed> $rows places, contracts, employees, normfuncties, orgUnits, compAdjustments, mutations, runs.
	 * @param int $year The budget year.
	 *
	 * @return array<string, mixed> basis, lines, byUnit, byFunction, byCostCenter, total.
	 *
	 * @spec openspec/specs/personnel-budget/spec.md#REQ-PBS-001
	 */
	public function budget(array $scenario, array $rows, int $year): array;

	/**
	 * Two scenarios and the baseline (the first scenario without its changes), per unit.
	 *
	 * @param array<string, mixed> $a The first scenario.
	 * @param array<string, mixed> $b The second scenario.
	 * @param array<string, mixed> $rows The rows, as budget() takes them.
	 * @param int $year The budget year.
	 *
	 * @return array<string, mixed> year and units, each with baseline, a, b and the differences.
	 *
	 * @spec openspec/specs/personnel-budget/spec.md#REQ-PBS-002
	 */
	public function compare(array $a, array $b, array $rows, int $year): array;
}//end interface
