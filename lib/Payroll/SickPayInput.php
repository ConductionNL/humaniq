<?php

/**
 * Sick Pay Input
 *
 * The pure-value input to `SickPayCalculator::compute()` (design.md D1/D2):
 * the reference wage, the samengesteld/aangepast loon composition, the case's
 * applied percentage, the year-1/year-2 switch, the wachtdag flags, and the
 * part-time inputs the WML floor is scaled by. Zero Nextcloud dependencies —
 * the `CalculationInput` idiom.
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
 * Immutable input to one loondoorbetaling-bij-ziekte calculation.
 */
final class SickPayInput {

	/**
	 * `W` — the reference wage (the employee's full `grossMonthlySalary`), in integer cents.
	 *
	 * @var integer
	 */
	public readonly int $referenceWageCents;

	/**
	 * `A` — the wage still earned from partial work (samengesteld/aangepast loon), in integer cents. `0` when fully sick.
	 *
	 * @var integer
	 */
	public readonly int $aangepastLoonCents;

	/**
	 * `p` — the case's `loondoorbetalingPercentage` (statutory minimum 70, CAOs may set 90/100).
	 *
	 * @var float
	 */
	public readonly float $loondoorbetalingPercentage;

	/**
	 * Whether the run period falls within the first 52 weeks of `firstSickDay`.
	 *
	 * @var boolean
	 */
	public readonly bool $yearOne;

	/**
	 * Whether the case configures a wachtdag (waiting day).
	 *
	 * @var boolean
	 */
	public readonly bool $wachtdag;

	/**
	 * Whether the case's `firstSickDay` falls within the run period (the wachtdag is deducted only once, at case start).
	 *
	 * @var boolean
	 */
	public readonly bool $firstSickDayInPeriod;

	/**
	 * The employee's contracted hours per week, for the WML floor's part-time factor.
	 *
	 * @var float
	 */
	public readonly float $contractHoursPerWeek;

	/**
	 * The full-time hours-per-week basis the WML floor's part-time factor is scaled against.
	 *
	 * @var float
	 */
	public readonly float $fulltimeHoursPerWeek;

	/**
	 * The fields stay flat and in this order: the calculator and the floor
	 * check read them directly.
	 *
	 * @param SickPayWage $wage The reference wage, the partial wage and the percentage.
	 * @param SickPayCircumstances $circumstances Where the period sits in the case, and the contracted and full-time hours.
	 *
	 * @spec openspec/specs/sick-pay-calc/spec.md#REQ-SICK-001
	 */
	public function __construct(SickPayWage $wage, SickPayCircumstances $circumstances) {
		$this->referenceWageCents = $wage->referenceWageCents;
		$this->aangepastLoonCents = $wage->aangepastLoonCents;
		$this->loondoorbetalingPercentage = $wage->loondoorbetalingPercentage;
		$this->yearOne = $circumstances->timing->yearOne;
		$this->wachtdag = $circumstances->timing->wachtdag;
		$this->firstSickDayInPeriod = $circumstances->timing->firstSickDayInPeriod;
		$this->contractHoursPerWeek = $circumstances->hours->contractHoursPerWeek;
		$this->fulltimeHoursPerWeek = $circumstances->hours->fulltimeHoursPerWeek;

	}//end __construct()

}//end class
