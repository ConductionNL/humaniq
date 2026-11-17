<?php

/**
 * Contract of WpmReportService
 *
 * The public surface of WpmReportService that other components call, so they depend
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
 * Contract of WpmReportService.
 *
 * @category Kernel
 * @package  OCA\Humaniq\Kernel\Contracts
 * @author   Conduction Development Team <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://conduction.nl
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface WpmReportServiceInterface {
	/**
	 * Compile and keep the report for an administration and a year.
	 *
	 * @param string $administrationId The administration.
	 * @param int    $year             The calendar year.
	 * @param string $userId           Who compiles it.
	 *
	 * @return array<string, mixed> The report as saved, with its id.
	 *
	 * @spec openspec/specs/expenses-travel-calculation/spec.md#REQ-TRV-004
	 */
	public function compile(string $administrationId, int $year, string $userId): array;
}//end interface
