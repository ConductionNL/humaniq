<?php

/**
 * Humaniq AwfReviewYear
 *
 * The year to date of one employee being reviewed inside AwfHoursReview: the
 * contracts, the last period counted, the periods charged low, and the paid
 * and contracted hours added up.
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
 * @spec openspec/specs/awf-premium-review/spec.md#REQ-AWF-102
 */

declare(strict_types=1);

namespace OCA\Humaniq\Payroll;

/**
 * One employee's year to date for the AWF review.
 *
 * @spec openspec/specs/awf-premium-review/spec.md#REQ-AWF-102
 */
final class AwfReviewYear {

	/**
	 * Every contract of the employee.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	public array $contracts = [];

	/**
	 * The last period counted (`YYYY-MM`).
	 *
	 * @var string
	 */
	public string $lastPeriod = '';

	/**
	 * The periods charged the low rate, as keys.
	 *
	 * @var array<string, bool>
	 */
	public array $lowPeriods = [];

	/**
	 * The paid hours so far.
	 *
	 * @var float
	 */
	public float $paid = 0.0;

	/**
	 * The contracted hours of every period so far.
	 *
	 * @var float
	 */
	public float $contractAll = 0.0;

	/**
	 * The contracted hours of the low periods so far.
	 *
	 * @var float
	 */
	public float $contractLow = 0.0;
}//end class
