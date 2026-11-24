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
	 * Zvw contribution for the period: the employer's levy, or in `inhouding` mode the contribution withheld from net pay.
	 *
	 * @var integer
	 */
	public readonly int $zvwCents;

	/**
	 * `werkgeversheffing` or `inhouding`: the mode the pack ran (payroll-special-regimes D3).
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
	 * The employer's final levy on an early-retirement benefit above the threshold (payroll-special-regimes D4); 0 for any other wage.
	 *
	 * @var integer
	 */
	public readonly int $rvuFinalLevyCents;

	/**
	 * Read every figure off one pack run. `nettoPayCents` and
	 * `employerChargesCents` are the interpreter's incidence folds: read, not
	 * computed here (REQ-JP-003). The fields stay flat and in this order.
	 *
	 * @param PackRunResult|array<string, mixed> $out The pack run's output, or the fields of two results summed by combined().
	 *
	 * @spec openspec/specs/payroll-core-engine/spec.md#REQ-PCE-001
	 * @spec openspec/changes/filings-wage-tax-message/specs/loonaangifte-message/spec.md#REQ-LAM-001
	 * @spec openspec/specs/jurisdiction-packs/spec.md#REQ-JP-003
	 */
	public function __construct(PackRunResult|array $out) {
		if (is_array($out) === true) {
			$this->grossPayCents = (int)$out['grossPayCents'];
			$this->loonheffingCents = (int)$out['loonheffingCents'];
			$this->arbeidskortingCents = (int)$out['arbeidskortingCents'];
			$this->volksverzekeringenCents = (int)$out['volksverzekeringenCents'];
			$this->zvwCents = (int)$out['zvwCents'];
			$this->zvwMode = (string)$out['zvwMode'];
			$this->zvwRate = (float)$out['zvwRate'];
			$this->appliedTaxRate = (float)$out['appliedTaxRate'];
			$this->nettoPayCents = (int)$out['nettoPayCents'];
			$this->vakantiegeldReservedCents = (int)$out['vakantiegeldReservedCents'];
			$this->vakantiegeldRate = (float)$out['vakantiegeldRate'];
			$this->awfCents = (int)$out['awfCents'];
			$this->aofCents = (int)$out['aofCents'];
			$this->wkoCents = (int)$out['wkoCents'];
			$this->whkCents = (int)$out['whkCents'];
			$this->werknemersverzekeringenCents = (int)$out['werknemersverzekeringenCents'];
			$this->employerChargesCents = (int)$out['employerChargesCents'];
			$this->aboveLmax = (bool)$out['aboveLmax'];
			$this->taxableWageCents = (int)$out['taxableWageCents'];
			$this->premiumWageCents = (int)$out['premiumWageCents'];
			$this->specialPaymentCents = (int)($out['specialPaymentCents'] ?? 0);
			$this->specialTaxRate = (float)($out['specialTaxRate'] ?? 0.0);
			$this->specialLoonheffingCents = (int)($out['specialLoonheffingCents'] ?? 0);
			$this->eindejaarsuitkeringReservedCents = (int)($out['eindejaarsuitkeringReservedCents'] ?? 0);
			$this->rvuFinalLevyCents = (int)($out['rvuFinalLevyCents'] ?? 0);

			return;
		}

		$this->grossPayCents = $out->gross();
		$this->loonheffingCents = $out->cents('loonheffing');
		$this->arbeidskortingCents = $out->cents('arbeidskorting');
		$this->volksverzekeringenCents = $out->cents('volksverzekeringen');
		$this->zvwCents = ($out->cents('zvw') + self::optionalStepCents(out: $out, id: 'zvwInhouding'));
		$this->zvwMode = self::zvwModeOf($out);
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
		$this->rvuFinalLevyCents = self::optionalStepCents(out: $out, id: 'rvuEindheffing');

	}//end __construct()

	/**
	 * Two calculations of one payslip as one: the four weeks of an extended
	 * period 13 and its week 53, each taxed with its own tijdvak
	 * (payroll-period-edge-cases D3). Amounts and wages add up; the mode and
	 * rates are the four weeks', and the payslip is above the Lmax when
	 * either part is.
	 *
	 * @param self $fourWeeks The weeks 49 to 52, tijdvak vierweken.
	 * @param self $week53    Week 53, tijdvak week.
	 *
	 * @return self
	 *
	 * @spec openspec/specs/payroll-period-edge-cases/spec.md#REQ-PPE-002
	 */
	public static function combined(self $fourWeeks, self $week53): self {
		$sum = [];
		foreach (['grossPayCents', 'loonheffingCents', 'arbeidskortingCents', 'volksverzekeringenCents', 'zvwCents', 'nettoPayCents', 'vakantiegeldReservedCents', 'awfCents', 'aofCents', 'wkoCents', 'whkCents', 'werknemersverzekeringenCents', 'employerChargesCents', 'taxableWageCents', 'premiumWageCents', 'specialPaymentCents', 'specialLoonheffingCents', 'eindejaarsuitkeringReservedCents', 'rvuFinalLevyCents'] as $field) {
			$sum[$field] = ($fourWeeks->$field + $week53->$field);
		}

		return new self(
			array_merge(
				$sum,
				[
					'zvwMode' => $fourWeeks->zvwMode,
					'zvwRate' => $fourWeeks->zvwRate,
					'appliedTaxRate' => $fourWeeks->appliedTaxRate,
					'specialTaxRate' => $fourWeeks->specialTaxRate,
					'vakantiegeldRate' => $fourWeeks->vakantiegeldRate,
					'aboveLmax' => ($fourWeeks->aboveLmax === true || $week53->aboveLmax === true),
				]
			)
		);
	}//end combined()

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

	/**
	 * A step in cents, or 0 when the pack does not declare it (a third-party
	 * pack need not carry the Dutch special-regime steps).
	 *
	 * @param PackRunResult $out The interpreter output.
	 * @param string        $id  The step id.
	 *
	 * @return int
	 *
	 * @spec openspec/changes/payroll-special-regimes
	 */
	private static function optionalStepCents(PackRunResult $out, string $id): int {
		try {
			return $out->cents($id);
		} catch (DslException $e) {
			return 0;
		}
	}//end optionalStepCents()

	/**
	 * The Zvw mode the pack ran: its `zvwMode` binding, or the employer's levy
	 * for a pack that declares none.
	 *
	 * @param PackRunResult $out The interpreter output.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/payroll-special-regimes
	 */
	private static function zvwModeOf(PackRunResult $out): string {
		try {
			$mode = $out->binding('zvwMode');
		} catch (DslException $e) {
			return 'werkgeversheffing';
		}

		if ($mode === 'inhouding') {
			return 'inhouding';
		}

		return 'werkgeversheffing';
	}//end zvwModeOf()

}//end class
