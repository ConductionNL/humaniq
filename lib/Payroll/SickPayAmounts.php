<?php

/**
 * Sick Pay Amounts
 *
 * The money of one sick-pay calculation, in integer cents: the doorbetaald
 * loon, the wachtdag deduction, the payable gross and the WML floor.
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
 * The amounts of a {@see SickPayResult}.
 *
 * @spec openspec/specs/sick-pay-calc/spec.md#REQ-SICK-001
 */
final class SickPayAmounts {

	/**
	 * @param int $doorbetaaldLoonCents `L` — the floored doorbetaald loon (before the wachtdag deduction).
	 * @param int $wachtdagDeductionCents `wd` — the wachtdag deduction (`0` when no wachtdag applies).
	 * @param int $payableGrossCents `L - wd` — fed to `PayrollCalculator` as `grossMonthlySalaryCents`.
	 * @param int $minimumWageFloorCents `M` — the WML monthly floor (part-time scaled).
	 */
	public function __construct(
		public readonly int $doorbetaaldLoonCents,
		public readonly int $wachtdagDeductionCents,
		public readonly int $payableGrossCents,
		public readonly int $minimumWageFloorCents,
	) {

	}//end __construct()
}//end class
