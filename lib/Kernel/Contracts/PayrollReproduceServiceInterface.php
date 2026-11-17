<?php

/**
 * Contract of PayrollReproduceService
 *
 * The public surface of PayrollReproduceService that other components call, so they depend
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
 * Contract of PayrollReproduceService.
 *
 * @category Kernel
 * @package  OCA\Humaniq\Kernel\Contracts
 * @author   Conduction Development Team <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://conduction.nl
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface PayrollReproduceServiceInterface {
	/**
	 * Reproduce one Payslip from its stored `engineInputSnapshot` and
	 * compare cents-exact against its stored figures (REQ-AUDP-002).
	 *
	 * @param string $payslipId The Payslip id (uuid).
	 *
	 * @return array<string, mixed> `{payslipId, status: reproduced|mismatch|refused, message, mismatches}`.
	 *
	 * @spec openspec/specs/audit-trail-payroll/spec.md#REQ-AUDP-002
	 */
	public function reproduce(string $payslipId): array;
}//end interface
