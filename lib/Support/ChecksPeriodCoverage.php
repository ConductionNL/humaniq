<?php

/**
 * ChecksPeriodCoverage.
 *
 * Whether a date range covers a wage period month.
 *
 * @category Support
 * @package  OCA\Humaniq\Support
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
 * @spec exclude helper shared by services and checks, no behavioural spec surface.
 */

declare(strict_types=1);

namespace OCA\Humaniq\Support;

use DateTimeImmutable;

/**
 * Whether a date range covers a wage period month.
 */
trait ChecksPeriodCoverage {
	/**
	 * Whether a start/end date range covers the wage period: startDate on or
	 * before the period's last day AND endDate null/blank or on/after the
	 * period's first day (identical to `PayrollRunService::coversPeriod()`).
	 *
	 * @param string $startDate ISO start date.
	 * @param string $endDate ISO end date, or ''/null-ish while open.
	 * @param string $period Wage period (YYYY-MM).
	 *
	 * @return bool
	 */
	private function coversPeriod(string $startDate, string $endDate, string $period): bool {
		try {
			$periodStart = new DateTimeImmutable($period . '-01');
		} catch (\Throwable $e) {
			return false;
		}

		$periodEnd = $periodStart->modify('last day of this month');

		$start = strtotime(trim($startDate));
		if ($start === false || $start > $periodEnd->getTimestamp()) {
			return false;
		}

		$endDate = trim($endDate);
		if ($endDate === '') {
			return true;
		}

		$end = strtotime($endDate);
		return $end === false || $end >= $periodStart->getTimestamp();
	}//end coversPeriod()
}//end class
