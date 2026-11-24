<?php
/**
 * Pension Premium
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The pension part of a calculation (payroll-engine-port-from-shillinq D1):
 * the pension base the run computed with PensionBase from the participation
 * and the fund's scheme, and the scheme's employee and employer percentages.
 * The pack takes the premiums of the base; it never looks up a scheme.
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
 * @spec openspec/changes/payroll-engine-port-from-shillinq/specs/payroll-pension-premium/spec.md#REQ-PPP-001
 */

declare(strict_types=1);

namespace OCA\Humaniq\Payroll;

use InvalidArgumentException;

/**
 * Part of a {@see CalculationInput}: the pension base and the two percentages.
 *
 * @spec openspec/changes/payroll-engine-port-from-shillinq/specs/payroll-pension-premium/spec.md#REQ-PPP-001
 */
final class PensionPremium {

	/**
	 * @param int   $pensionBaseCents The period's pension base, in cents.
	 * @param float $employeePct      The scheme's employee percentage of the base.
	 * @param float $employerPct      The scheme's employer percentage of the base.
	 *
	 * @throws InvalidArgumentException When the base or a percentage is negative.
	 *
	 * @spec openspec/changes/payroll-engine-port-from-shillinq/specs/payroll-pension-premium/spec.md#REQ-PPP-001
	 */
	public function __construct(
		public readonly int $pensionBaseCents = 0,
		public readonly float $employeePct = 0.0,
		public readonly float $employerPct = 0.0,
	) {
		if ($pensionBaseCents < 0 || $employeePct < 0 || $employerPct < 0) {
			throw new InvalidArgumentException('PensionPremium: the base and the percentages cannot be negative.');
		}
	}//end __construct()

	/**
	 * Whether this carries nothing: no participation, or one without a premium.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/payroll-engine-port-from-shillinq/specs/payroll-pension-premium/spec.md#REQ-PPP-001
	 */
	public function isEmpty(): bool {
		return $this->pensionBaseCents === 0 && $this->employeePct === 0.0 && $this->employerPct === 0.0;
	}//end isEmpty()
}//end class
