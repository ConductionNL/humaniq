<?php

/**
 * Humaniq AwfDayRange
 *
 * The calendar days, inclusive, that AwfHoursReview counts a contract's days
 * in: the first and the last day.
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

use DateTimeImmutable;

/**
 * A range of calendar days.
 *
 * @spec openspec/specs/awf-premium-review/spec.md#REQ-AWF-102
 */
final class AwfDayRange {

	/**
	 * The first day.
	 *
	 * @var DateTimeImmutable
	 */
	public DateTimeImmutable $from;

	/**
	 * The last day.
	 *
	 * @var DateTimeImmutable
	 */
	public DateTimeImmutable $until;

	/**
	 * The range from the first to the last day.
	 *
	 * @param DateTimeImmutable $from  The first day.
	 * @param DateTimeImmutable $until The last day.
	 *
	 * @return self
	 */
	public static function of(DateTimeImmutable $from, DateTimeImmutable $until): self {
		$range = new self();
		$range->from = $from;
		$range->until = $until;

		return $range;
	}//end of()
}//end class
