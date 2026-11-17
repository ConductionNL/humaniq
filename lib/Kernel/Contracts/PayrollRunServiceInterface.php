<?php

/**
 * Contract of PayrollRunService
 *
 * The public surface of PayrollRunService that other components call, so they depend
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
 * Contract of PayrollRunService.
 *
 * @category Kernel
 * @package  OCA\Humaniq\Kernel\Contracts
 * @author   Conduction Development Team <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://conduction.nl
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface PayrollRunServiceInterface {
	/**
	 * Create or recalculate the draft PayrollRun for (period,
	 * administrationId) — the occ `humaniq:payroll:run` entry point
	 * (design.md D4).
	 *
	 * @param string $period Wage period, `YYYY-MM`.
	 * @param string|null $administrationId The administration, or null for the seed-convention default.
	 * @param bool $recalculate Whether an existing draft run may be regenerated.
	 *
	 * @return array<string, mixed> Outcome: {runId, period, administrationId, status, message, computed, skipped, totals}.
	 *
	 * @spec openspec/specs/payroll-core-engine/spec.md#REQ-PCE-003
	 * @spec openspec/specs/payroll-core-engine/spec.md#REQ-PCE-004
	 */
	public function runFor(string $period, ?string $administrationId = null, bool $recalculate = false): array;
}//end interface
