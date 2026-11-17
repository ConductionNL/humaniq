<?php

/**
 * Contract of SalaryBureauExchangeService
 *
 * The public surface of SalaryBureauExchangeService that other components call, so they depend
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
 * Contract of SalaryBureauExchangeService.
 *
 * @category Kernel
 * @package  OCA\Humaniq\Kernel\Contracts
 * @author   Conduction Development Team <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://conduction.nl
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface SalaryBureauExchangeServiceInterface {
	/**
	 * Compile (or recompile, while in concept) the handoff of one
	 * administration and period.
	 *
	 * @param string $administrationId The administration.
	 * @param string $period           The wage period, YYYY-MM.
	 * @param string $userId           The account compiling it.
	 *
	 * @return array{status: string, handoffId: string, mutationCount: int, message?: string}
	 *
	 * @spec openspec/specs/payroll-external-bureau-handoff/spec.md#REQ-PXB-002
	 */
	public function compile(string $administrationId, string $period, string $userId): array;

	/**
	 * Check the bureau's returned payslips: every employee of the period
	 * has exactly one, and none names someone outside the administration.
	 * Returned payslips are stamped with the employee's account.
	 *
	 * @param string $handoffId The handoff.
	 *
	 * @return array{blocking: int, findings: list<array<string, mixed>>}
	 *
	 * @spec openspec/specs/payroll-external-bureau-handoff/spec.md#REQ-PXB-004
	 */
	public function checkIntake(string $handoffId): array;
}//end interface
