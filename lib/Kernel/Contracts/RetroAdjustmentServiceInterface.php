<?php

/**
 * Contract of RetroAdjustmentService
 *
 * The public surface of RetroAdjustmentService that other components call, so they depend
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

use OCA\Humaniq\Service\RetroCorrection;

/**
 * Contract of RetroAdjustmentService.
 *
 * @category Kernel
 * @package  OCA\Humaniq\Kernel\Contracts
 * @author   Conduction Development Team <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://conduction.nl
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface RetroAdjustmentServiceInterface {
	/**
	 * Compute (and, with `$apply`, settle) a TWK correction -- the occ
	 * `humaniq:payroll:adjust` entry point (design.md D3/D4/D5).
	 *
	 * @param RetroCorrection $correction The period, employee and reference, and the corrected gross (reused from an existing adjustment when null).
	 * @param string|null $correctionType Free-text correction classification.
	 * @param string|null $settlementPeriod The period to settle into, or null to auto-resolve the open draft run's period.
	 * @param bool $apply Whether to flip `status: draft -> applied` and stamp `settlementPayrollRunId`.
	 *
	 * @return array<string, mixed> Outcome: {adjustmentId, originalPeriod, employeeId, correctionRef, status, message, idempotent, delta, engineVersion, settlementPeriod, settlementLine}.
	 *
	 * @spec openspec/specs/retro-adjustments/spec.md#REQ-RETRO-001
	 * @spec openspec/specs/retro-adjustments/spec.md#REQ-RETRO-002
	 * @spec openspec/specs/retro-adjustments/spec.md#REQ-RETRO-003
	 * @spec openspec/specs/retro-adjustments/spec.md#REQ-RETRO-005
	 */
	public function adjustFor(
		RetroCorrection $correction,
		?string $correctionType = null,
		?string $settlementPeriod = null,
		bool $apply = false,
	): array;
}//end interface
