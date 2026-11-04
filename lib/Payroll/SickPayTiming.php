<?php

/**
 * Sick Pay Timing
 *
 * Where the run period sits in the sickness case: within the first year,
 * whether the case has a wachtdag, and whether the first sick day falls in
 * the period.
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
 * The case timing input of a {@see SickPayInput}.
 *
 * @spec openspec/specs/sick-pay-calc/spec.md#REQ-SICK-001
 */
final class SickPayTiming {

	/**
	 * @param bool $yearOne Whether the run period falls within the first 52 weeks of `firstSickDay`.
	 * @param bool $wachtdag Whether the case configures a wachtdag (waiting day).
	 * @param bool $firstSickDayInPeriod Whether the case's `firstSickDay` falls within the run period (the wachtdag is deducted only once, at case start).
	 */
	public function __construct(
		public readonly bool $yearOne,
		public readonly bool $wachtdag,
		public readonly bool $firstSickDayInPeriod,
	) {

	}//end __construct()
}//end class
