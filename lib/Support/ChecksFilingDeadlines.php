<?php

/**
 * ChecksFilingDeadlines.
 *
 * Whether a filing deadline is past without an alert on it.
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
 * Whether a filing deadline is past without an alert on it.
 */
trait ChecksFilingDeadlines {
	/**
	 * True when the filing is already sent, or its deadline is more than 14
	 * days away — false (a violation) once it is within the alert window or
	 * already overdue, evaluated against the current date (the audit run
	 * date).
	 *
	 * @param array<string, mixed> $record The filing.
	 *
	 * @return bool
	 */
	private static function deadlineNotAlerting(array $record): bool {
		if ((string)($record['status'] ?? 'concept') === 'verzonden') {
			return true;
		}

		$deadline = strtotime((string)($record['deadline'] ?? ''));
		if ($deadline === false) {
			return true;
		}

		$today = (new DateTimeImmutable('today'))->getTimestamp();
		$daysRemaining = (int)floor((($deadline - $today)) / 86400);

		return $daysRemaining > self::ALERT_WINDOW_DAYS;
	}//end deadlineNotAlerting()
}//end class
