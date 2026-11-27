<?php

/**
 * Pay Date
 *
 * The day a monthly wage period is paid: the administration's pay day of the
 * month in that period's month, or the month's last day when the month is
 * shorter. A period that is not a calendar month, or an administration
 * without a pay day, has none.
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
 * @spec openspec/specs/payroll-run-pay-date-and-schedule/spec.md#REQ-PRD-001
 */

declare(strict_types=1);

namespace OCA\Humaniq\Payroll;

use DateTimeImmutable;

/**
 * The pay date of a monthly wage period.
 *
 * @spec openspec/specs/payroll-run-pay-date-and-schedule/spec.md#REQ-PRD-001
 */
final class PayDate {

	/**
	 * The pay date of a period, as Y-m-d, or null when it has none.
	 *
	 * Only a calendar month (`2026-11`) has a day of the month to pay on; a
	 * four-week period or a week has its own rhythm, and gets no pay date
	 * here, so the flow posts it on approval as before.
	 *
	 * @param string $period     The wage period id.
	 * @param mixed  $dayOfMonth The administration's paymentDayOfMonth: 1 to 31, else none.
	 *
	 * @return string|null The pay date.
	 *
	 * @spec openspec/specs/payroll-run-pay-date-and-schedule/spec.md#REQ-PRD-001
	 */
	public function forPeriod(string $period, mixed $dayOfMonth): ?string {
		if (preg_match('/^(\d{4})-(0[1-9]|1[0-2])$/', trim($period), $match) !== 1) {
			return null;
		}

		$isWhole = (is_int($dayOfMonth) === true || (is_string($dayOfMonth) === true && ctype_digit(trim($dayOfMonth)) === true));
		if ($isWhole === false) {
			return null;
		}

		$day = (int)$dayOfMonth;
		if ($day < 1 || $day > 31) {
			return null;
		}

		$first = new DateTimeImmutable($match[1].'-'.$match[2].'-01');
		$last  = (int)$first->format('t');

		return $first->format('Y-m-').sprintf('%02d', min($day, $last));
	}//end forPeriod()

}//end class
