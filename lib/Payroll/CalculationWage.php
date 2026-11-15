<?php

/**
 * Calculation Wage
 *
 * Whose wage one gross-to-net calculation computes: the gross monthly salary,
 * the date of birth, the wage period and the jurisdiction.
 *
 * @category Payroll
 * @package  OCA\Humaniq\Payroll
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
 * @spec openspec/specs/payroll-core-engine/spec.md#REQ-PCE-001
 */

declare(strict_types=1);

namespace OCA\Humaniq\Payroll;

/**
 * Part of a {@see CalculationInput}.
 *
 * @spec openspec/specs/payroll-core-engine/spec.md#REQ-PCE-001
 */
final class CalculationWage {

	/**
	 * @param int $grossMonthlySalaryCents The fixed monthly gross salary, in integer cents (`tvl`).
	 * @param ?string $dateOfBirth ISO-8601 date of birth, or null when unknown (treated as below-AOW).
	 * @param string $period Wage period, `YYYY-MM`.
	 * @param string $jurisdiction The ISO 3166-1 alpha-2 jurisdiction whose pack computes this wage (jurisdiction-packs). Additive and defaults to `NL`, so every pre-existing named-argument call site is unaffected.
	 */
	public function __construct(
		public readonly int $grossMonthlySalaryCents,
		public readonly ?string $dateOfBirth,
		public readonly string $period,
		public readonly string $jurisdiction = 'NL',
	) {

	}//end __construct()
}//end class
