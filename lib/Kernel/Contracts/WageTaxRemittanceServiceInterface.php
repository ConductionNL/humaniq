<?php

/**
 * Contract of WageTaxRemittanceService
 *
 * The public surface of WageTaxRemittanceService that other components call, so they depend
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
 * Contract of WageTaxRemittanceService.
 *
 * @category Kernel
 * @package  OCA\Humaniq\Kernel\Contracts
 * @author   Conduction Development Team <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://conduction.nl
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface WageTaxRemittanceServiceInterface {
	/**
	 * Hand off the confirmed return of every payable run, optionally for one
	 * wage period.
	 *
	 * @param string|null $period Only runs of this wage period (YYYY-MM), or null for all.
	 *
	 * @return list<array<string, mixed>> One outcome per run.
	 *
	 * @spec openspec/specs/payroll-wage-tax-remittance-shillinq/spec.md#REQ-PWR-001
	 */
	public function processPayableRuns(?string $period=null): array;
}//end interface
