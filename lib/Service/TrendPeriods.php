<?php

/**
 * Trend Periods
 *
 * The month windows every trend is bucketed by: a window name (`quarter`,
 * `half-year`, `year`) resolves to that many trailing calendar months ending
 * at the current month, oldest first, because every metric is bucketed
 * monthly (`PayrollRun.period` and `Timesheet.period` are `YYYY-MM`). Moved
 * out of {@see AnalyticsService} unchanged when department-figures added the
 * unit series, so that class stays under phpmd's class-complexity ceiling,
 * and shared with {@see UnitTrends} and {@see DepartmentFiguresService} so the
 * three agree on what a window is.
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
 * @spec openspec/changes/archive/2026-08-20-hrmq-dashboard-steering-indicators/specs/hrmq-dashboard-steering-indicators/spec.md
 * @spec openspec/specs/department-figures/spec.md#REQ-DPF-001
 */

declare(strict_types=1);

namespace OCA\Humaniq\Service;

use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Trailing month windows and their bounds.
 *
 * @spec openspec/specs/department-figures/spec.md#REQ-DPF-001
 */
class TrendPeriods {

	/**
	 * Trailing-window period selectors, mirroring pipelinq's
	 * `AnalyticsService::ALLOWED_PERIODS` shape: each resolves to a number of
	 * trailing calendar-month buckets ending at the current month.
	 *
	 * @var array<string, int>
	 */
	public const PERIOD_MONTHS = ['quarter' => 3, 'half-year' => 6, 'year' => 12];

	/**
	 * The `YYYY-MM` buckets of a window, oldest first.
	 *
	 * @param string $period One of PERIOD_MONTHS' keys.
	 *
	 * @return array<int, string>
	 *
	 * @throws InvalidArgumentException When the period is not recognised.
	 *
	 * @spec openspec/specs/department-figures/spec.md#REQ-DPF-001
	 */
	public function keys(string $period): array {
		if (isset(self::PERIOD_MONTHS[$period]) === false) {
			throw new InvalidArgumentException('Invalid period');
		}

		$keys = [];
		$currentMonth = new DateTimeImmutable('first day of this month');
		for ($offset = (self::PERIOD_MONTHS[$period] - 1); $offset >= 0; $offset--) {
			$keys[] = $currentMonth->modify(sprintf('-%d months', $offset))->format('Y-m');
		}

		return $keys;
	}//end keys()

	/**
	 * First and last day of a `YYYY-MM` period, both at midnight. Built with
	 * the constructor, not the static `createFromFormat()` factory, which
	 * phpmd reports as StaticAccess.
	 *
	 * @param string $period `YYYY-MM`.
	 *
	 * @return array{0: DateTimeImmutable, 1: DateTimeImmutable}
	 *
	 * @spec openspec/specs/department-figures/spec.md#REQ-DPF-001
	 */
	public function bounds(string $period): array {
		$start = new DateTimeImmutable($period . '-01');
		return [$start, $start->modify('last day of this month')];
	}//end bounds()

	/**
	 * Parse a stored date or date-time value.
	 *
	 * @param mixed $value The raw value.
	 *
	 * @return DateTimeImmutable|null Null when absent, blank or unparseable.
	 *
	 * @spec openspec/changes/archive/2026-08-20-hrmq-dashboard-steering-indicators/specs/hrmq-dashboard-steering-indicators/spec.md
	 */
	public function parseDate(mixed $value): ?DateTimeImmutable {
		if (is_string($value) === false || trim($value) === '') {
			return null;
		}

		try {
			return new DateTimeImmutable($value);
		} catch (\Exception) {
			return null;
		}
	}//end parseDate()

}//end class
