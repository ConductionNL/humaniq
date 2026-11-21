<?php

/**
 * Special Payment
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The one-off part of a calculation (payroll-reservation-payouts D1, D3, D7):
 * a payment taxed at the special rate instead of as the period's wage, the
 * annual wage that selects that rate, and the end-of-year reserve rate. The
 * run resolves all three; the pack only reads them, so its arithmetic stays
 * per-period pure.
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
 * @spec openspec/changes/payroll-reservation-payouts/specs/payroll-reservation-payouts/spec.md#requirement-the-engine-shall-tax-a-special-payment-at-the-special-rate-req-rsp-001
 */

declare(strict_types=1);

namespace OCA\Humaniq\Payroll;

use InvalidArgumentException;

/**
 * Part of a {@see CalculationInput}: the special payment and the end-of-year rate.
 *
 * @spec openspec/changes/payroll-reservation-payouts/specs/payroll-reservation-payouts/spec.md#requirement-the-engine-shall-tax-a-special-payment-at-the-special-rate-req-rsp-001
 */
final class SpecialPayment {

	/**
	 * @param int   $specialPaymentCents      The one-off payment, in cents.
	 * @param int   $annualWageReferenceCents The annual wage that selects the special rate, in cents.
	 * @param float $endOfYearRate            The end-of-year reserve rate, percent of the taxable wage.
	 *
	 * @throws InvalidArgumentException When an amount or the rate is negative.
	 *
	 * @spec openspec/changes/payroll-reservation-payouts/specs/payroll-reservation-payouts/spec.md#requirement-the-engine-shall-tax-a-special-payment-at-the-special-rate-req-rsp-001
	 */
	public function __construct(
		public readonly int $specialPaymentCents = 0,
		public readonly int $annualWageReferenceCents = 0,
		public readonly float $endOfYearRate = 0.0,
	) {
		if ($specialPaymentCents < 0 || $annualWageReferenceCents < 0 || $endOfYearRate < 0) {
			throw new InvalidArgumentException('SpecialPayment: amounts and the end-of-year rate cannot be negative.');
		}
	}//end __construct()

	/**
	 * Whether this carries nothing: no payment and no end-of-year rate.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/payroll-reservation-payouts/specs/payroll-reservation-payouts/spec.md#requirement-the-engine-shall-tax-a-special-payment-at-the-special-rate-req-rsp-001
	 */
	public function isEmpty(): bool {
		return $this->specialPaymentCents === 0 && $this->annualWageReferenceCents === 0 && $this->endOfYearRate === 0.0;
	}//end isEmpty()
}//end class
