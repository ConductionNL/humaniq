<?php

/**
 * Sick Pay Basis
 *
 * What one sick-pay calculation was based on: whether the floor applied, the
 * applied percentage, whether the year-one floor was in scope, and the
 * reference wage it ran against.
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
 * The basis of a {@see SickPayResult}.
 *
 * @spec openspec/specs/sick-pay-calc/spec.md#REQ-SICK-001
 */
final class SickPayBasis {

	/**
	 * @param bool $floorApplied Whether the statutory/WML floor raised `doorbetaaldLoon` above the pre-floor `L0`.
	 * @param float $appliedPercentage `p` — the applied loondoorbetaling percentage.
	 * @param bool $yearOne Whether the year-1 WML floor was in scope for this computation.
	 * @param int $referenceWageCents `W` — the reference wage this computation was run against, echoed for stamping.
	 */
	public function __construct(
		public readonly bool $floorApplied,
		public readonly float $appliedPercentage,
		public readonly bool $yearOne,
		public readonly int $referenceWageCents,
	) {

	}//end __construct()
}//end class
