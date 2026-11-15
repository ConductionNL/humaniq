<?php

/**
 * Humaniq TransitionPaymentCase
 *
 * The inputs of one transition payment inside TransitionPaymentCalculator:
 * the employee, their contracts and payslips, and the last working day.
 *
 * @category Service
 * @package  OCA\Humaniq\Service
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
 * @spec openspec/specs/offboarding-completion/spec.md#REQ-OFC-004
 */

declare(strict_types=1);

namespace OCA\Humaniq\Service;

use DateTimeImmutable;

/**
 * One transition payment being calculated.
 *
 * @spec openspec/specs/offboarding-completion/spec.md#REQ-OFC-004
 */
final class TransitionPaymentCase {

	/**
	 * The employee: grossMonthlySalary, startDate.
	 *
	 * @var array<string, mixed>
	 */
	public array $employee = [];

	/**
	 * The employee's contracts: startDate, endDate, hoursPerWeek, hourlyWage.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	public array $contracts = [];

	/**
	 * The employee's payslips: period, grossPay, vakantiegeldRate.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	public array $payslips = [];

	/**
	 * The last working day.
	 *
	 * @var DateTimeImmutable
	 */
	public DateTimeImmutable $end;
}//end class
