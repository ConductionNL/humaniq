<?php

/**
 * Sick Pay Wage
 *
 * The wage side of one sick-pay calculation: the reference wage, the wage
 * still earned from partial work, and the continuation percentage.
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
 * @spec openspec/specs/sick-pay-calc/spec.md#REQ-SICK-001
 */

declare(strict_types=1);

namespace OCA\Humaniq\Payroll;

/**
 * The wage input of a {@see SickPayInput}.
 *
 * @spec openspec/specs/sick-pay-calc/spec.md#REQ-SICK-001
 */
final class SickPayWage {

	/**
	 * @param int $referenceWageCents `W` — the reference wage (the employee's full `grossMonthlySalary`), in integer cents.
	 * @param int $aangepastLoonCents `A` — the wage still earned from partial work (samengesteld/aangepast loon), in integer cents. `0` when fully sick.
	 * @param float $loondoorbetalingPercentage `p` — the case's `loondoorbetalingPercentage` (statutory minimum 70, CAOs may set 90/100).
	 */
	public function __construct(
		public readonly int $referenceWageCents,
		public readonly int $aangepastLoonCents,
		public readonly float $loondoorbetalingPercentage,
	) {

	}//end __construct()
}//end class
