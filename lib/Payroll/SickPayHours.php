<?php

/**
 * Sick Pay Hours
 *
 * The contracted and full-time hours per week the WML floor's part-time
 * factor is scaled with.
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
 * The hours input of a {@see SickPayInput}.
 *
 * @spec openspec/specs/sick-pay-calc/spec.md#REQ-SICK-001
 */
final class SickPayHours {

	/**
	 * @param float $contractHoursPerWeek The employee's contracted hours per week, for the WML floor's part-time factor.
	 * @param float $fulltimeHoursPerWeek The full-time hours-per-week basis the WML floor's part-time factor is scaled against.
	 */
	public function __construct(
		public readonly float $contractHoursPerWeek,
		public readonly float $fulltimeHoursPerWeek,
	) {

	}//end __construct()
}//end class
