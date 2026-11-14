<?php

/**
 * Calculation Result
 *
 * The pure-value output of `PayrollCalculator::calculate()` (design.md D2):
 * the full component breakdown of one gross-to-net calculation, all money in
 * integer cents. Carries every figure `PayrollRunService` stamps onto a
 * Payslip plus the intermediate Awf/Aof/Wko/Whk lines the balancing-invariant
 * test cross-checks (design.md D8) even though only their sum
 * (`werknemersverzekeringenCents`) is a Payslip field.
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

use OCA\Humaniq\Payroll\Dsl\DslException;
use OCA\Humaniq\Payroll\Dsl\PackRunResult;

/**
 * Immutable output of one gross-to-net calculation, integer cents throughout.
 *
 * @SuppressWarnings(PHPMD.TooManyFields) One flat field per figure of the
 *  breakdown: PayrollRunService, the filings and the balancing check read each
 *  one by name, so they stay on this class rather than one level down.
 */
final class CalculationResult {

	/**
	 * The gross wage for the period (`tvl`, echoed).
	 *
	 * @var integer
	 */
	public readonly int $grossPayCents;

	/**
	 * Combined loonheffing withheld (`x`).
	 *
	 * @var integer
	 */
	public readonly int $loonheffingCents;

	/**
	 * Applied arbeidskorting tijdvakbedrag.
	 *
	 * @var integer
	 */
	public readonly int $arbeidskortingCents;

	/**
	 * Informative volksverzekeringen split.
	 *
	 * @var integer
	 */
	public readonly int $volksverzekeringenCents;

	/**
	 * Zvw contribution for the period.
	 *
	 * @var integer
	 */
	public readonly int $zvwCents;

	/**
	 * `werkgeversheffing` (MVP; `inhouding` never emitted).
	 *
	 * @var string
	 */
	public readonly string $zvwMode;

	/**
	 * Applied Zvw rate percentage.
	 *
	 * @var float
	 */
	public readonly float $zvwRate;

	/**
	 * Effective loonheffing rate percentage.
	 *
	 * @var float
	 */
	public readonly float $appliedTaxRate;

	/**
	 * Net wage paid to the employee.
	 *
	 * @var integer
	 */
	public readonly int $nettoPayCents;

	/**
	 * Vakantiebijslag reserved this period.
	 *
	 * @var integer
	 */
	public readonly int $vakantiegeldReservedCents;

	/**
	 * Applied vakantiebijslag rate percentage.
	 *
	 * @var float
	 */
	public readonly float $vakantiegeldRate;

	/**
	 * Awf employer charge (informative line).
	 *
	 * @var integer
	 */
	public readonly int $awfCents;

	/**
	 * Aof employer charge (informative line).
	 *
	 * @var integer
	 */
	public readonly int $aofCents;

	/**
	 * Wko opslag employer charge (informative line).
	 *
	 * @var integer
	 */
	public readonly int $wkoCents;

	/**
	 * Whk employer charge (informative line).
	 *
	 * @var integer
	 */
	public readonly int $whkCents;

	/**
	 * `awf+aof+wko+whk` (the Payslip field).
	 *
	 * @var integer
	 */
	public readonly int $werknemersverzekeringenCents;

	/**
	 * `werknemersverzekeringen + zvw`.
	 *
	 * @var integer
	 */
	public readonly int $employerChargesCents;

	/**
	 * Whether the tabelloon exceeded the tables' `Lmax` ceiling (documented edge, design.md D2 step 3).
	 *
	 * @var boolean
	 */
	public readonly bool $aboveLmax;

	/**
	 * The pack's `belastbaarLoon`: the wage the tax and premiums are calculated over (filings-wage-tax-message D5).
	 *
	 * @var integer
	 */
	public readonly int $taxableWageCents;

	/**
	 * `belastbaarLoon` capped at the period's maximum premium wage: the base of Awf, Aof, Wko and Whk (filings-wage-tax-message D5).
	 *
	 * @var integer
	 */
	public readonly int $premiumWageCents;

	/**
	 * The one-off payment taxed at the special rate, included in `grossPayCents` (payroll-reservation-payouts D1).
	 *
	 * @var integer
	 */
	public readonly int $specialPaymentCents;

	/**
	 * The special-rate percentage applied to it; 0 without a special payment.
	 *
	 * @var float
	 */
	public readonly float $specialTaxRate;

	/**
	 * The wage tax withheld on the special payment, apart from `loonheffingCents`.
	 *
	 * @var integer
	 */
	public readonly int $specialLoonheffingCents;

	/**
	 * The end-of-year (thirteenth month) reserve this period (D7).
	 *
	 * @var integer
	 */
	public readonly int $eindejaarsuitkeringReservedCents;

	/**
	 * Read every figure off one pack run. `nettoPayCents` and
	 * `employerChargesCents` are the interpreter's incidence folds: read, not
	 * computed here (REQ-JP-003). The fields stay flat and in this order.
	 *
	 * @param PackRunResult $out The pack run's output.
	 *
	 * @spec openspec/specs/payroll-core-engine/spec.md#REQ-PCE-001
	 * @spec openspec/changes/filings-wage-tax-message/specs/loonaangifte-message/spec.md#REQ-LAM-001
	 * @spec openspec/specs/jurisdiction-packs/spec.md#REQ-JP-003
	 */
	public function __construct(PackRunResult $out) {
		$this->grossPayCents = $out->gross();
		$this->loonheffingCents = $out->cents('loonheffing');
		$this->arbeidskortingCents = $out->cents('arbeidskorting');
		$this->volksverzekeringenCents = $out->cents('volksverzekeringen');
		$this->zvwCents = $out->cents('zvw');
		$this->zvwMode = 'werkgeversheffing';
		$this->zvwRate = (float)$out->binding('zvwRate');
		$this->appliedTaxRate = (float)$out->step('appliedTaxRate');
		$this->nettoPayCents = $out->net();
		$this->vakantiegeldReservedCents = $out->cents('vakantiegeld');
		$this->vakantiegeldRate = (float)$out->binding('vakantiegeldRate');
		$this->awfCents = $out->cents('awf');
		$this->aofCents = $out->cents('aof');
		$this->wkoCents = $out->cents('wko');
		$this->whkCents = $out->cents('whk');
		$this->werknemersverzekeringenCents = $out->cents('werknemersverzekeringen');
		$this->employerChargesCents = $out->employerCharges();
		$this->aboveLmax = (bool)$out->binding('aboveLmax');
		$this->specialPaymentCents = self::specialPayment(out: $out);
		$this->specialTaxRate = (float)self::optionalStep(out: $out, id: 'bijzonderPercentage');
		$this->specialLoonheffingCents = (int)self::optionalStep(out: $out, id: 'loonheffingBijzonder');
		$this->eindejaarsuitkeringReservedCents = (int)self::optionalStep(out: $out, id: 'eindejaarsuitkering');
		// A special payment is wage too: it counts in the taxable and the
		// premium wage the filings report (zero without one, as before).
		$this->taxableWageCents = (self::optionalCents(out: $out, id: 'belastbaarLoon') + $this->specialPaymentCents);
		$this->premiumWageCents = min((self::optionalCents(out: $out, id: 'belastbaarLoon') + $this->specialPaymentCents), self::optionalCents(out: $out, id: 'premieloonCap'));

	}//end __construct()

	/**
	 * A binding in cents, or 0 when the pack does not declare it (a third-party
	 * pack need not carry the Dutch wage bindings).
	 *
	 * @param PackRunResult $out The interpreter output.
	 * @param string        $id  The binding id.
	 *
	 * @return int
	 *
	 * @spec openspec/changes/filings-wage-tax-message/specs/loonaangifte-message/spec.md#REQ-LAM-001
	 */
	private static function optionalCents(PackRunResult $out, string $id): int {

		try {
			return (int)$out->binding($id);
		} catch (DslException $e) {
			return 0;
		}
	}//end optionalCents()

	/**
	 * The part of the gross that is not the regular wage `tvl`: the special
	 * payment. 0 for a pack without a `tvl` binding, whose gross is all regular.
	 *
	 * @param PackRunResult $out The interpreter output.
	 *
	 * @return int
	 *
	 * @spec openspec/changes/payroll-reservation-payouts/specs/payroll-reservation-payouts/spec.md#requirement-the-engine-shall-tax-a-special-payment-at-the-special-rate-req-rsp-001
	 */
	private static function specialPayment(PackRunResult $out): int {
		try {
			return max(0, ($out->gross() - (int)$out->binding('tvl')));
		} catch (DslException $e) {
			return 0;
		}
	}//end specialPayment()

	/**
	 * A step's value, or 0 when the pack does not declare it (a third-party
	 * pack need not carry the special-rate steps).
	 *
	 * @param PackRunResult $out The interpreter output.
	 * @param string        $id  The step id.
	 *
	 * @return int|float
	 *
	 * @spec openspec/changes/payroll-reservation-payouts/specs/payroll-reservation-payouts/spec.md#requirement-the-engine-shall-tax-a-special-payment-at-the-special-rate-req-rsp-001
	 */
	private static function optionalStep(PackRunResult $out, string $id): int|float {
		try {
			return $out->step($id);
		} catch (DslException $e) {
			return 0;
		}
	}//end optionalStep()

}//end class
