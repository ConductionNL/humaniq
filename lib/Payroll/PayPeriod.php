<?php

/**
 * PayPeriod: one payroll period id, of any grain.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
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
 * @spec openspec/changes/payroll-period-edge-cases/specs/payroll-period-edge-cases/spec.md#REQ-PPE-001
 */

declare(strict_types=1);

namespace OCA\Humaniq\Payroll;

use DateTimeImmutable;
use InvalidArgumentException;

/**
 * A pay period parsed once, for every grain (design D1):
 *
 * - `YYYY-MM`: a month, tijdvak `maand`, one twelfth of the year;
 * - `YYYY-Pnn`: four-weekly period n of the ISO year, weeks 4n-3 to 4n, tijdvak
 *   `vierweken`, four fifty-seconds. `P14` is ISO week 53 on its own, tijdvak
 *   `week`, and exists only in a year that has a week 53;
 * - `YYYY-Wnn`: ISO week n, tijdvak `week`, one fifty-second. `W53` exists only in
 *   a year that has a week 53.
 *
 * `week53` says whether week 53 belongs to the period: `W53`, `P14`, and `P13` of a
 * year with a week 53, which an administration extends to weeks 49 to 53 (design
 * D3). Period 13's own dates stay weeks 49 to 52, because those are the weeks its
 * four-weekly tijdvak covers; the run adds week 53 as a separate one-week tijdvak.
 *
 * @spec openspec/changes/payroll-period-edge-cases/specs/payroll-period-edge-cases/spec.md#REQ-PPE-001
 */
final class PayPeriod {

	public const TIJDVAK_MAAND = 'maand';

	public const TIJDVAK_VIERWEKEN = 'vierweken';

	public const TIJDVAK_WEEK = 'week';

	/**
	 * Pay frequencies of an administration, and the tijdvak each one pays in.
	 *
	 * @var array<string, string>
	 */
	public const FREQUENCY_TIJDVAK = [
		'monthly'     => self::TIJDVAK_MAAND,
		'four-weekly' => self::TIJDVAK_VIERWEKEN,
		'weekly'      => self::TIJDVAK_WEEK,
	];

	/**
	 * A four-weekly administration pays week 53 on the period 13 payslip.
	 *
	 * @var string
	 */
	public const WEEK53_EXTEND = 'extend-period-13';

	/**
	 * A four-weekly administration pays week 53 as its own period 14.
	 *
	 * @var string
	 */
	public const WEEK53_SEPARATE = 'separate-period-14';

	/**
	 * Constructor; use parse().
	 *
	 * @param string            $id       The period id as given.
	 * @param string            $tijdvak  maand, vierweken or week.
	 * @param DateTimeImmutable $firstDay The first day the period covers.
	 * @param DateTimeImmutable $lastDay  The last day the period covers.
	 * @param bool              $week53   Whether ISO week 53 belongs to the period.
	 */
	private function __construct(
		public readonly string $id,
		public readonly string $tijdvak,
		public readonly DateTimeImmutable $firstDay,
		public readonly DateTimeImmutable $lastDay,
		public readonly bool $week53,
	) {
	}//end __construct()

	/**
	 * Parse a period id.
	 *
	 * @param string $id `YYYY-MM`, `YYYY-Pnn` or `YYYY-Wnn`.
	 *
	 * @return self
	 *
	 * @throws InvalidArgumentException When the id is not a period, or names a week 53 the year does not have.
	 *
	 * @spec openspec/changes/payroll-period-edge-cases/specs/payroll-period-edge-cases/spec.md#REQ-PPE-001
	 */
	public static function parse(string $id): self {
		if (preg_match('/^(\d{4})-(0[1-9]|1[0-2])$/', $id, $month) === 1) {
			return self::month(id: $id, year: (int)$month[1], month: (int)$month[2]);
		}

		if (preg_match('/^(\d{4})-P(0[1-9]|1[0-4])$/', $id, $fourWeekly) === 1) {
			return self::fourWeekly(id: $id, year: (int)$fourWeekly[1], period: (int)$fourWeekly[2]);
		}

		if (preg_match('/^(\d{4})-W(0[1-9]|[1-4]\d|5[0-3])$/', $id, $week) === 1) {
			return self::week(id: $id, year: (int)$week[1], week: (int)$week[2]);
		}

		throw new InvalidArgumentException(sprintf('"%s" is not a pay period: use YYYY-MM, YYYY-Pnn or YYYY-Wnn.', $id));
	}//end parse()

	/**
	 * Parse a period id and check it belongs to an administration's pay
	 * frequency: a monthly administration takes only months, a four-weekly
	 * one only four-week periods (period 14 only when it pays week 53
	 * separately), a weekly one only weeks.
	 *
	 * @param string $id             The period id.
	 * @param string $frequency      The administration's payFrequency; empty means monthly.
	 * @param string $week53Handling The administration's week53Handling; empty means extend-period-13.
	 *
	 * @return self
	 *
	 * @throws InvalidArgumentException When the id is no period, or not one of that frequency.
	 *
	 * @spec openspec/changes/payroll-period-edge-cases/specs/payroll-period-edge-cases/spec.md#REQ-PPE-001
	 */
	public static function forFrequency(string $id, string $frequency, string $week53Handling=''): self {
		$frequency = ($frequency === '') ? 'monthly' : $frequency;
		if (isset(self::FREQUENCY_TIJDVAK[$frequency]) === false) {
			throw new InvalidArgumentException(sprintf('"%s" is not a pay frequency: use monthly, four-weekly or weekly.', $frequency));
		}

		$period = self::parse(id: $id);
		if ($period->tijdvak !== self::FREQUENCY_TIJDVAK[$frequency]
			&& ($frequency !== 'four-weekly' || str_ends_with($id, '-P14') === false)
		) {
			throw new InvalidArgumentException(sprintf('"%s" is not a %s period: this administration pays %s.', $id, $frequency, $frequency));
		}

		if (str_ends_with($id, '-P14') === true && $week53Handling !== self::WEEK53_SEPARATE) {
			throw new InvalidArgumentException(
				sprintf('"%s" does not exist for this administration: it pays week 53 on the period 13 payslip.', $id)
			);
		}

		return $period;
	}//end forFrequency()

	/**
	 * Whether an ISO year has a week 53.
	 *
	 * @param int $year The ISO year.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/payroll-period-edge-cases/specs/payroll-period-edge-cases/spec.md#REQ-PPE-002
	 */
	public static function yearHasWeek53(int $year): bool {
		return (new DateTimeImmutable(sprintf('%04d-12-28', $year)))->format('W') === '53';
	}//end yearHasWeek53()

	/**
	 * The share of the year the period's tijdvak stands for: 1/12, 4/52 or 1/52.
	 *
	 * @return float
	 *
	 * @spec openspec/changes/payroll-period-edge-cases/specs/payroll-period-edge-cases/spec.md#REQ-PPE-001
	 */
	public function shareOfYear(): float {
		return 1 / $this->periodsPerYear();
	}//end shareOfYear()

	/**
	 * How many periods of this tijdvak make a year: 12, 13 or 52.
	 *
	 * @return int
	 *
	 * @spec openspec/changes/payroll-period-edge-cases/specs/payroll-period-edge-cases/spec.md#REQ-PPE-001
	 */
	public function periodsPerYear(): int {
		return match ($this->tijdvak) {
			self::TIJDVAK_MAAND => 12,
			self::TIJDVAK_VIERWEKEN => 13,
			default => 52,
		};
	}//end periodsPerYear()

	/**
	 * A calendar month.
	 *
	 * @param string $id    The id.
	 * @param int    $year  The year.
	 * @param int    $month The month.
	 *
	 * @return self
	 */
	private static function month(string $id, int $year, int $month): self {
		$first = new DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month));

		return new self(id: $id, tijdvak: self::TIJDVAK_MAAND, firstDay: $first, lastDay: $first->modify('last day of this month'), week53: false);
	}//end month()

	/**
	 * A four-weekly period, or period 14 for week 53 alone.
	 *
	 * @param string $id     The id.
	 * @param int    $year   The ISO year.
	 * @param int    $period 1 to 14.
	 *
	 * @return self
	 *
	 * @throws InvalidArgumentException For period 14 in a year without week 53.
	 */
	private static function fourWeekly(string $id, int $year, int $period): self {
		$hasWeek53 = self::yearHasWeek53(year: $year);
		if ($period === 14) {
			if ($hasWeek53 === false) {
				throw new InvalidArgumentException(sprintf('"%s" does not exist: %d has no ISO week 53.', $id, $year));
			}

			return new self(id: $id, tijdvak: self::TIJDVAK_WEEK, firstDay: self::monday(year: $year, week: 53), lastDay: self::sunday(year: $year, week: 53), week53: true);
		}

		return new self(
			id: $id,
			tijdvak: self::TIJDVAK_VIERWEKEN,
			firstDay: self::monday(year: $year, week: ((4 * $period) - 3)),
			lastDay: self::sunday(year: $year, week: (4 * $period)),
			week53: ($period === 13 && $hasWeek53 === true)
		);
	}//end fourWeekly()

	/**
	 * An ISO week.
	 *
	 * @param string $id   The id.
	 * @param int    $year The ISO year.
	 * @param int    $week 1 to 53.
	 *
	 * @return self
	 *
	 * @throws InvalidArgumentException For week 53 in a year without one.
	 */
	private static function week(string $id, int $year, int $week): self {
		if ($week === 53 && self::yearHasWeek53(year: $year) === false) {
			throw new InvalidArgumentException(sprintf('"%s" does not exist: %d has no ISO week 53.', $id, $year));
		}

		return new self(id: $id, tijdvak: self::TIJDVAK_WEEK, firstDay: self::monday(year: $year, week: $week), lastDay: self::sunday(year: $year, week: $week), week53: ($week === 53));
	}//end week()

	/**
	 * The Monday of an ISO week.
	 *
	 * @param int $year The ISO year.
	 * @param int $week The ISO week.
	 *
	 * @return DateTimeImmutable
	 */
	private static function monday(int $year, int $week): DateTimeImmutable {
		return (new DateTimeImmutable('today'))->setISODate($year, $week, 1)->setTime(0, 0);
	}//end monday()

	/**
	 * The Sunday of an ISO week.
	 *
	 * @param int $year The ISO year.
	 * @param int $week The ISO week.
	 *
	 * @return DateTimeImmutable
	 */
	private static function sunday(int $year, int $week): DateTimeImmutable {
		return (new DateTimeImmutable('today'))->setISODate($year, $week, 7)->setTime(0, 0);
	}//end sunday()
}//end class
