<?php

/**
 * Pension Premium
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The pension part of a calculation (payroll-engine-port-from-shillinq D1):
 * the period's pension base and the scheme's employee and employer
 * percentages. The run computes the base with `PensionBase` from the
 * participation and the fund's `PensionScheme`; the pack only applies the
 * percentages, so the payslip and the pension filing share one base.
 *
 * @category Payroll
 * @package  OCA\Humaniq\Payroll
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/payroll-pension-premium/spec.md#REQ-PPP-001
 */

declare(strict_types=1);

namespace OCA\Humaniq\Payroll;

use InvalidArgumentException;

/**
 * Part of a {@see CalculationInput}: the pension base and the two premium percentages.
 *
 * @spec openspec/specs/payroll-pension-premium/spec.md#REQ-PPP-001
 */
final class PensionPremium {

	/**
	 * @param int   $pensionBaseCents   The period's pension base, in cents.
	 * @param float $employeePercentage The scheme's employee premium, percent of the base.
	 * @param float $employerPercentage The scheme's employer premium, percent of the base.
	 *
	 * @throws InvalidArgumentException When the base or a percentage is negative.
	 *
	 * @spec openspec/specs/payroll-pension-premium/spec.md#REQ-PPP-001
	 */
	public function __construct(
		public readonly int $pensionBaseCents = 0,
		public readonly float $employeePercentage = 0.0,
		public readonly float $employerPercentage = 0.0,
	) {
		if ($pensionBaseCents < 0 || $employeePercentage < 0 || $employerPercentage < 0) {
			throw new InvalidArgumentException('PensionPremium: the base and the percentages cannot be negative.');
		}
	}//end __construct()

	/**
	 * Whether this carries nothing: no base and no percentages.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/payroll-pension-premium/spec.md#REQ-PPP-001
	 */
	public function isEmpty(): bool {
		return $this->pensionBaseCents === 0 && $this->employeePercentage === 0.0 && $this->employerPercentage === 0.0;
	}//end isEmpty()
}//end class
