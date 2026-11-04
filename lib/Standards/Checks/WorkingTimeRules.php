<?php

/**
 * Working time rules
 *
 * The three Dutch working-time-law predicates (ATW art. 5:3 lid 2, 5:7 lid 1,
 * 5:4 lid 1) over one clock shape. NlAttendanceChecks hands in an
 * AttendanceRecord as it is; NlRosterChecks projects a RosterAssignment's
 * planned fields into the same shape.
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
 * @spec openspec/specs/time-attendance/spec.md#REQ-TA-004
 * @spec openspec/specs/rostering/spec.md#REQ-ROST-004
 */

declare(strict_types=1);

namespace OCA\Humaniq\Standards\Checks;

/**
 * The working-time predicates shared by attendance and roster checks.
 *
 * @spec openspec/specs/time-attendance/spec.md#REQ-TA-004
 * @spec openspec/specs/rostering/spec.md#REQ-ROST-004
 */
final class WorkingTimeRules {

	/**
	 * True when this record's employee has no previous-working-day sibling
	 * in the index (rest is implied >= 24h, or not decidable), or when the sibling's `clockOut` is null
	 * (also not decidable), or when the gap between the sibling's `clockOut`
	 * and this record's `clockIn` is >= 11 hours.
	 *
	 * @param array<string, mixed> $clock The record, with `clockIn`, `clockOut`, `breakMinutes`, `employeeId` and `date`.
	 * @param mixed                $index The employee and date index of sibling clocks, when there is one.
	 *
	 * @return bool
	 */
	public static function dailyRestSatisfied(array $clock, mixed $index): bool {
		if (is_array($index) === false) {
			return true;
		}

		$employeeId = (string)($clock['employeeId'] ?? '');
		$date = trim((string)($clock['date'] ?? ''));
		$clockIn = strtotime((string)($clock['clockIn'] ?? ''));
		if ($employeeId === '' || $date === '' || $clockIn === false) {
			return true;
		}

		$previousDate = self::previousCalendarDay($date);
		if ($previousDate === null) {
			return true;
		}

		$sibling = ($index[$employeeId][$previousDate] ?? null);
		if (is_array($sibling) === false) {
			// No sibling on the immediately preceding calendar day — rest is
			// implied to be >= 24h (or a free day sits in between).
			return true;
		}

		$siblingClockOut = strtotime((string)($sibling['clockOut'] ?? ''));
		if ($siblingClockOut === false) {
			// Sibling day still open — not decidable.
			return true;
		}

		$restHours = ($clockIn - $siblingClockOut) / 3600;
		return $restHours >= NlAttendanceChecks::MIN_REST_HOURS;
	}//end dailyRestSatisfied()

	/**
	 * True while `clockOut` is null (open day, not decidable), or when the
	 * elapsed `clockOut - clockIn` does not exceed 12 hours.
	 *
	 * @param array<string, mixed> $clock The record, with `clockIn`, `clockOut`, `breakMinutes`, `employeeId` and `date`.
	 *
	 * @return bool
	 */
	public static function maxShiftSatisfied(array $clock): bool {
		$elapsedHours = self::elapsedHours($clock);
		if ($elapsedHours === null) {
			return true;
		}

		return $elapsedHours <= NlAttendanceChecks::MAX_SHIFT_HOURS;
	}//end maxShiftSatisfied()

	/**
	 * True while `clockOut` is null (open day, not decidable), or when
	 * `breakMinutes` meets or exceeds the required minutes for every
	 * `parameters.breakTiers` tier the elapsed shift length exceeds. Tiers
	 * are read from the `nl-atw-pauze` corpus rule, never hard-coded.
	 *
	 * @param array<string, mixed> $clock The record, with `clockIn`, `clockOut`, `breakMinutes`, `employeeId` and `date`.
	 * @param array<int, array<string, mixed>>|null $tiers The corpus `parameters.breakTiers`, or null when the rule has none.
	 *
	 * @return bool
	 */
	public static function pauzeSatisfied(array $clock, ?array $tiers): bool {
		$elapsedHours = self::elapsedHours($clock);
		if ($elapsedHours === null) {
			return true;
		}

		if ($tiers === null) {
			return true;
		}

		$breakMinutes = (float)($clock['breakMinutes'] ?? 0);

		foreach ($tiers as $tier) {
			$minHours = ($tier['minHours'] ?? null);
			$required = ($tier['requiredBreakMinutes'] ?? null);
			if (is_numeric($minHours) === false || is_numeric($required) === false) {
				continue;
			}

			if ($elapsedHours > (float)$minHours && $breakMinutes < (float)$required) {
				return false;
			}
		}

		return true;
	}//end pauzeSatisfied()

	/**
	 * The elapsed `clockOut - clockIn` duration in hours, or null when
	 * `clockOut` is absent/unparseable (open day) or `clockIn` is unparseable.
	 *
	 * @param array<string, mixed> $clock The record, with `clockIn`, `clockOut`, `breakMinutes`, `employeeId` and `date`.
	 *
	 * @return float|null
	 */
	private static function elapsedHours(array $clock): ?float {
		$clockIn = strtotime((string)($clock['clockIn'] ?? ''));
		if ($clockIn === false) {
			return null;
		}

		$clockOutRaw = ($clock['clockOut'] ?? null);
		if ($clockOutRaw === null || trim((string)$clockOutRaw) === '') {
			return null;
		}

		$clockOut = strtotime((string)$clockOutRaw);
		if ($clockOut === false) {
			return null;
		}

		return ($clockOut - $clockIn) / 3600;
	}//end elapsedHours()

	/**
	 * The ISO date one calendar day before `$date` (`Y-m-d`), or null when
	 * `$date` is unparseable.
	 *
	 * @param string $date An ISO `Y-m-d` date.
	 *
	 * @return string|null
	 */
	private static function previousCalendarDay(string $date): ?string {
		$timestamp = strtotime($date);
		if ($timestamp === false) {
			return null;
		}

		return date('Y-m-d', strtotime('-1 day', $timestamp));
	}//end previousCalendarDay()
}//end class
