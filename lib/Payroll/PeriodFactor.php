<?php

/**
 * Period Factor
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The share of a wage period an employee is employed (payroll-engine-port-from-shillinq D3,
 * decision O2: calendar days). An employee who starts after the period's first day or leaves
 * before its last day is paid that share of the periodic salary; per-day, per-hour and
 * per-trip components are not scaled.
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
 * @spec openspec/changes/payroll-engine-port-from-shillinq/specs/payroll-partial-period/spec.md#REQ-PPR-001
 */

declare(strict_types=1);

namespace OCA\Humaniq\Payroll;

use DateTimeImmutable;

/**
 * Days employed in a period over the days in the period.
 *
 * @spec openspec/changes/payroll-engine-port-from-shillinq/specs/payroll-partial-period/spec.md#REQ-PPR-001
 */
final class PeriodFactor {

	/**
	 * @param float  $factor        Days employed over days in the period, 0 to 1, four decimals.
	 * @param int    $daysEmployed  Calendar days employed in the period.
	 * @param int    $daysInPeriod  Calendar days in the period.
	 * @param string $employedFrom  First day employed in the period (Y-m-d).
	 * @param string $employedUntil Last day employed in the period (Y-m-d).
	 */
	private function __construct(
		public readonly float $factor,
		public readonly int $daysEmployed,
		public readonly int $daysInPeriod,
		public readonly string $employedFrom,
		public readonly string $employedUntil,
	) {
	}//end __construct()

	/**
	 * The factor for an employment from `$startDate` to `$endDate` (open when
	 * empty) in the period from `$periodStart` to `$periodEnd`. An unreadable
	 * date counts as the period's own edge, so it never lowers the pay.
	 *
	 * @param string            $startDate   The employment start (Y-m-d), or ''.
	 * @param string            $endDate     The employment end (Y-m-d), or '' while open.
	 * @param DateTimeImmutable $periodStart The period's first day.
	 * @param DateTimeImmutable $periodEnd   The period's last day.
	 *
	 * @return self
	 *
	 * @spec openspec/changes/payroll-engine-port-from-shillinq/specs/payroll-partial-period/spec.md#REQ-PPR-001
	 */
	public static function of(string $startDate, string $endDate, DateTimeImmutable $periodStart, DateTimeImmutable $periodEnd): self {
		$periodStart = $periodStart->setTime(0, 0);
		$periodEnd = $periodEnd->setTime(0, 0);
		$from = max($periodStart, (self::day(date: $startDate, like: $periodStart) ?? $periodStart));
		$until = min($periodEnd, (self::day(date: $endDate, like: $periodEnd) ?? $periodEnd));

		$daysInPeriod = (self::daysBetween(from: $periodStart, until: $periodEnd) + 1);
		$daysEmployed = 0;
		if ($until >= $from) {
			$daysEmployed = (self::daysBetween(from: $from, until: $until) + 1);
		}

		return new self(
			factor: round(($daysEmployed / $daysInPeriod), 4),
			daysEmployed: $daysEmployed,
			daysInPeriod: $daysInPeriod,
			employedFrom: $from->format('Y-m-d'),
			employedUntil: $until->format('Y-m-d')
		);
	}//end of()

	/**
	 * Whether the employee is employed for only part of the period.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/payroll-engine-port-from-shillinq/specs/payroll-partial-period/spec.md#REQ-PPR-001
	 */
	public function isPartial(): bool {
		return $this->daysEmployed < $this->daysInPeriod;
	}//end isPartial()

	/**
	 * A periodic amount in cents scaled by the exact share of days, so the
	 * rounded factor never moves a cent: 3,000.00 for 15 of 30 days is 1,500.00.
	 *
	 * @param int $cents The amount for the whole period.
	 *
	 * @return int
	 *
	 * @spec openspec/changes/payroll-engine-port-from-shillinq/specs/payroll-partial-period/spec.md#REQ-PPR-001
	 */
	public function scale(int $cents): int {
		if ($this->isPartial() === false) {
			return $cents;
		}

		return (int)round(($cents * $this->daysEmployed / $this->daysInPeriod));
	}//end scale()

	/**
	 * A Y-m-d date at midnight, or null when empty or unreadable.
	 *
	 * @param string            $date The date.
	 * @param DateTimeImmutable $like A period edge, whose time zone the day takes.
	 *
	 * @return DateTimeImmutable|null
	 */
	private static function day(string $date, DateTimeImmutable $like): ?DateTimeImmutable {
		$date = trim($date);
		if ($date === '') {
			return null;
		}

		$day = DateTimeImmutable::createFromFormat('!Y-m-d', substr($date, 0, 10), $like->getTimezone());

		return ($day === false ? null : $day);
	}//end day()

	/**
	 * Whole calendar days from one midnight to another.
	 *
	 * @param DateTimeImmutable $from  The first day.
	 * @param DateTimeImmutable $until The later day.
	 *
	 * @return int
	 */
	private static function daysBetween(DateTimeImmutable $from, DateTimeImmutable $until): int {
		return (int)$from->diff($until)->days;
	}//end daysBetween()
}//end class
