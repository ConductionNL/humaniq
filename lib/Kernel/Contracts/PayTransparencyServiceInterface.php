<?php

/**
 * Contract of PayTransparencyService
 *
 * The public surface of PayTransparencyService that other components call, so they depend
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
 * Contract of PayTransparencyService.
 *
 * @category Kernel
 * @package  OCA\Humaniq\Kernel\Contracts
 * @author   Conduction Development Team <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://conduction.nl
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface PayTransparencyServiceInterface {
	/**
	 * The report for one year.
	 *
	 * @param array{employees: array<int, array<string, mixed>>, contracts: array<int, array<string, mixed>>, normfuncties: array<int, array<string, mixed>>, payslips: array<int, array<string, mixed>>} $rows The administration's rows.
	 * @param int $year      The year.
	 * @param int $threshold The fewest women and the fewest men a group needs to be reported.
	 *
	 * @return array<string, mixed> {year, threshold, counted, women, men, otherOrUnknown, overall, categories[], quartiles[]}
	 *
	 * @spec openspec/specs/pay-transparency/spec.md#REQ-PTR-001
	 */
	public function report(array $rows, int $year, int $threshold): array;
}//end interface
