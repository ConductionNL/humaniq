<?php

/**
 * Contract of CompAdjustmentService
 *
 * The public surface of CompAdjustmentService that other components call, so they depend
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
 * Contract of CompAdjustmentService.
 *
 * @category Kernel
 * @package  OCA\Humaniq\Kernel\Contracts
 * @author   Conduction Development Team <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://conduction.nl
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface CompAdjustmentServiceInterface {
	/**
	 * Effectuate one CompAdjustment by id — the guarded endpoint's entry
	 * point (design.md D6). The controller has already RBAC-resolved the
	 * adjustment; this re-fetches it unscoped and applies the same
	 * approved+due+within-band predicate before writing.
	 *
	 * @param string $adjustmentId The CompAdjustment id.
	 * @param string|null $asOf ISO date to evaluate "due" against, or null for today.
	 *
	 * @return array<string, mixed> Outcome: {adjustmentId, status, message, employeeId, newGrossMonthlySalary}.
	 *
	 * @spec openspec/specs/comp-cycles/spec.md#REQ-COMP-006
	 */
	public function effectuateOne(string $adjustmentId, ?string $asOf = null): array;

	/**
	 * Batch-effectuate every due, approved CompAdjustment in a
	 * CompReviewCycle — the `occ humaniq:comp:effectuate --cycle` entry point
	 * (design.md D5).
	 *
	 * @param string $cycleId The CompReviewCycle id.
	 * @param string|null $asOf ISO date to evaluate "due" against, or null for today.
	 * @param bool $dryRun When true, evaluates and reports without writing anything.
	 *
	 * @return array<int, array<string, mixed>> One outcome per CompAdjustment in the cycle.
	 *
	 * @spec openspec/specs/comp-cycles/spec.md#REQ-COMP-006
	 */
	public function effectuateCycle(string $cycleId, ?string $asOf = null, bool $dryRun = false): array;
}//end interface
