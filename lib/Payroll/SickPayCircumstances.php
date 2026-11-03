<?php

/**
 * Sick Pay Circumstances
 *
 * The circumstances of one sick-pay calculation besides the wage: where the
 * run period sits in the case, and the hours the WML floor is scaled with.
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
 * The case timing and hours input of a {@see SickPayInput}.
 *
 * @spec openspec/specs/sick-pay-calc/spec.md#REQ-SICK-001
 */
final class SickPayCircumstances {

	/**
	 * @param SickPayTiming $timing Year one, the wachtdag and whether the first sick day is in the period.
	 * @param SickPayHours $hours Contracted and full-time hours per week.
	 */
	public function __construct(
		public readonly SickPayTiming $timing,
		public readonly SickPayHours $hours,
	) {

	}//end __construct()
}//end class
