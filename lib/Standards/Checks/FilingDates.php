<?php

/**
 * Filing Date Helpers
 *
 * The two date comparisons the payroll filing check providers share
 * (`EuUsPayrollChecks`, `NlWageTaxFilingChecks`): a filing date on or before
 * the filing's `deadline`, and records retained a number of years after the
 * end of the filing period's year. Each helper reads the raw fields and never
 * throws; an absent or unparseable date fails the comparison.
 *
 * Not a CheckProvider: `RuleEngine` only registers classes implementing
 * `CheckProvider`, so this class contributes no predicates of its own.
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
 * @spec openspec/specs/hrm-rule-engine/spec.md
 */

declare(strict_types=1);

namespace OCA\Humaniq\Standards\Checks;

/**
 * Deadline and retention date comparisons for payroll filings.
 *
 * @spec openspec/specs/hrm-rule-engine/spec.md
 */
final class FilingDates {

	/**
	 * True when the date at $dateKey is present and on or before the
	 * filing's `deadline`.
	 *
	 * @param array<string, mixed> $o The filing.
	 * @param string $dateKey Actual-date field.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/hrm-rule-engine/spec.md
	 */
	public static function onOrBeforeDeadline(array $o, string $dateKey): bool {
		$date = strtotime((string)($o[$dateKey] ?? ''));
		$limit = strtotime((string)($o['deadline'] ?? ''));
		if ($date === false || $limit === false) {
			return false;
		}

		return $date <= $limit;
	}//end onOrBeforeDeadline()

	/**
	 * True when the retention date is at least $years after the end of the year
	 * of the filing period (period in YYYY or YYYY-... form).
	 *
	 * @param array<string, mixed> $o The filing.
	 * @param int $years Minimum retention years.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/hrm-rule-engine/spec.md
	 */
	public static function retainedYearsAfterPeriod(array $o, int $years): bool {
		$retain = strtotime((string)($o['retainedUntil'] ?? ''));
		$period = (string)($o['period'] ?? '');
		if ($retain === false || preg_match('/^(\d{4})/', $period, $m) !== 1) {
			return false;
		}

		$required = mktime(0, 0, 0, 12, 31, ((int)$m[1] + $years));
		return $retain >= $required;
	}//end retainedYearsAfterPeriod()

}//end class
