<?php

/**
 * ChecksPeriodCoverage.
 *
 * Whether a date range covers a wage period: a month, four weeks or a week.
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
use OCA\Humaniq\Payroll\PayPeriod;

/**
 * Whether a date range covers a wage period: a month, four weeks or a week.
 */
trait ChecksPeriodCoverage {
	/**
	 * Whether a start/end date range covers the wage period: startDate on or
	 * before the period's last day AND endDate null/blank or on/after the
	 * period's first day (identical to `PayrollRunService::coversPeriod()`).
	 *
	 * @param string $startDate ISO start date.
	 * @param string $endDate ISO end date, or ''/null-ish while open.
	 * @param string $period Wage period (YYYY-MM, YYYY-Pnn or YYYY-Wnn).
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/payroll-period-edge-cases/spec.md#REQ-PPE-001
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) PayPeriod::parse is the pure value-object factory every period reader uses.
	 */
	private function coversPeriod(string $startDate, string $endDate, string $period): bool {
		try {
			// A month, a four-week period or a week (payroll-period-edge-cases D4).
			$pay = PayPeriod::parse($period);
			$periodStart = $pay->firstDay;
			$periodEnd = $pay->lastDay;
		} catch (\InvalidArgumentException $notAPayPeriod) {
			// Anything else reads as before: the given date to its month's end.
			try {
				$periodStart = new DateTimeImmutable($period . '-01');
			} catch (\Throwable $e) {
				return false;
			}

			$periodEnd = $periodStart->modify('last day of this month');
		}

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
