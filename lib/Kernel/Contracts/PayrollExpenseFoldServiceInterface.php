<?php

/**
 * Contract of PayrollExpenseFoldService
 *
 * The public surface of PayrollExpenseFoldService that other components call, so they depend
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
 * Contract of PayrollExpenseFoldService.
 *
 * @category Kernel
 * @package  OCA\Humaniq\Kernel\Contracts
 * @author   Conduction Development Team <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://conduction.nl
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface PayrollExpenseFoldServiceInterface {
	/**
	 * Mark every approved claim a run paid as reimbursed; returns the count.
	 *
	 * @param string $runId The approved run.
	 *
	 * @return int
	 *
	 * @spec openspec/specs/payroll-expenses-and-allowances/spec.md#REQ-PEA-001
	 */
	public function markReimbursed(string $runId): int;
}//end interface
