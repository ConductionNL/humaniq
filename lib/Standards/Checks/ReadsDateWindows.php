<?php

/**
 * Reads Date Windows
 *
 * Whether a date falls between today and a number of days ahead, the window
 * arithmetic the HR signals (NlSignalChecks) share between their table
 * derivations and the closures kept as their parity oracle.
 *
 * @category Standards
 * @package  OCA\Humaniq\Standards\Checks
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
 * @spec openspec/changes/rules-onto-or-decision-tables/specs/hrm-rule-engine/spec.md#REQ-RULE-008
 */

declare(strict_types=1);

namespace OCA\Humaniq\Standards\Checks;

use DateTimeImmutable;

/**
 * A forward-looking window of whole days starting today, mixed into the
 * providers that signal an approaching date.
 *
 * @spec openspec/changes/rules-onto-or-decision-tables/specs/hrm-rule-engine/spec.md#REQ-RULE-008
 */
trait ReadsDateWindows {

	/**
	 * Whether the date lies on or after today and on or before today plus
	 * the given days. Null when the date does not read.
	 *
	 * @param string $date The date, in any form strtotime() reads.
	 * @param int    $days The window length, in days.
	 *
	 * @return bool|null
	 *
	 * @spec openspec/changes/rules-onto-or-decision-tables/specs/hrm-rule-engine/spec.md#REQ-RULE-008
	 */
	private static function insideWindow(string $date, int $days): ?bool {
		$at = strtotime($date);
		if ($at === false) {
			return null;
		}

		$today = (new DateTimeImmutable('today'))->getTimestamp();

		return $at >= $today && $at <= strtotime('+' . $days . ' days', $today);

	}//end insideWindow()
}//end trait
