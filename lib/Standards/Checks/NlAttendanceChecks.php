<?php

/**
 * NL Arbeidstijdenwet Attendance Check Provider
 *
 * Executable checks for the three Dutch working-time-law rules
 * (framework `nl-arbeidstijdenwet`, lib/Standards/rules/labour.json,
 * time-attendance-mvp), mapped onto the `AttendanceRecord` object type:
 * `nl-atw-dagelijkse-rust` (ATW art. 5:3 lid 2 — at least 11 hours unbroken
 * rest between consecutive working days), `nl-atw-max-werkdag` (ATW art. 5:7
 * lid 1 — at most 12 hours per dienst), and `nl-atw-pauze` (ATW art. 5:4
 * lid 1 — statutory break tiers).
 *
 * All three predicates compute shift length from the raw `clockIn`/
 * `clockOut` fields (never from the stored, writer-maintained `workedHours`
 * field — design D2, REQ-TA-002) and read `breakMinutes` raw. The daily-rest
 * predicate is the one cross-record check: it reads
 * `$context['attendance']['clockByEmployeeDate']`, the per-employee
 * date-indexed clock sibling index `RuleAuditService::buildAttendanceContext()`
 * builds once per audit run (the same mechanism as
 * `buildRelatedContext()`/`buildGlPostContext()`), rather than re-querying
 * the register itself.
 *
 * Vacuous-pass discipline (the `contractHoursPerWeek` precedent): a record
 * with a null `clockOut` passes `nl-atw-max-werkdag` and `nl-atw-pauze`
 * (shift length not decidable while the day is open); a record whose
 * previous working day has no sibling record, or whose sibling's `clockOut`
 * is null, passes `nl-atw-dagelijkse-rust` (rest cannot be shown to be
 * insufficient). The once-per-7-days 8-hour rest reduction and CAO-pauze
 * variations are deliberately not modeled (Non-Goals) — the checks enforce
 * the strict statutory default.
 *
 * @category Standards
 * @package  OCA\Hrmq\Standards\Checks
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
 * @spec openspec/changes/time-attendance-mvp/specs/time-attendance/spec.md#REQ-TA-004
 */

declare(strict_types=1);

namespace OCA\Hrmq\Standards\Checks;

use OCA\Hrmq\Standards\RuleCatalogue;

/**
 * Dutch Arbeidstijdenwet (working time) executable checks over AttendanceRecord.
 */
final class NlAttendanceChecks implements CheckProvider {

	/**
	 * Minimum unbroken rest, in hours, between two consecutive working days
	 * (ATW art. 5:3 lid 2). The lawful once-per-7-days reduction to 8 hours
	 * is not modeled here.
	 *
	 * Public (rostering MVP, design D4): `NlRosterChecks` reuses this exact
	 * constant for its planned-clock daily-rest predicate over
	 * `RosterAssignment` rather than re-declaring the norm — a change to the
	 * statutory value updates both call sites at once.
	 *
	 * @var float
	 */
	public const MIN_REST_HOURS = 11.0;

	/**
	 * Maximum length of a single dienst, in hours (ATW art. 5:7 lid 1).
	 *
	 * Public (rostering MVP, design D4): reused verbatim by `NlRosterChecks`
	 * for the planned max-werkdag predicate — see MIN_REST_HOURS.
	 *
	 * @var float
	 */
	public const MAX_SHIFT_HOURS = 12.0;

	/**
	 * {@inheritDoc}
	 *
	 * @return array<string, array<string, callable>>
	 */
	public static function checks(): array {
		return [
			'AttendanceRecord' => [
				// ATW art. 5:3 lid 2 — >= 11h unbroken rest between consecutive working days.
				'nl-atw-dagelijkse-rust' => static fn (array $o, array $context): bool => self::dailyRestSatisfied($o, $context),
				// ATW art. 5:7 lid 1 — <= 12h per dienst, from raw clock fields.
				'nl-atw-max-werkdag' => static fn (array $o, array $context): bool => self::maxShiftSatisfied($o),
				// ATW art. 5:4 lid 1 — statutory break tiers, read from rule parameters.
				'nl-atw-pauze' => static fn (array $o, array $context): bool => self::pauzeSatisfied($o),
			],
		];

	}//end checks()

	/**
	 * {@inheritDoc}
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function seedSpec(): array {
		return [];
	}//end seedSpec()

	/**
	 * True when this record's employee has no previous-working-day sibling
	 * in `$context['attendance']['clockByEmployeeDate']` (rest is implied
	 * >= 24h, or not decidable), or when the sibling's `clockOut` is null
	 * (also not decidable), or when the gap between the sibling's `clockOut`
	 * and this record's `clockIn` is >= 11 hours.
	 *
	 * @param array<string, mixed> $o The AttendanceRecord.
	 * @param array<string, mixed> $context Evaluation context; reads `attendance.clockByEmployeeDate`.
	 *
	 * @return bool
	 */
	private static function dailyRestSatisfied(array $o, array $context): bool {
		$index = ($context['attendance']['clockByEmployeeDate'] ?? null);
		if (is_array($index) === false) {
			return true;
		}

		$employeeId = (string)($o['employeeId'] ?? '');
		$date = trim((string)($o['date'] ?? ''));
		$clockIn = strtotime((string)($o['clockIn'] ?? ''));
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
		return $restHours >= self::MIN_REST_HOURS;
	}//end dailyRestSatisfied()

	/**
	 * True while `clockOut` is null (open day, not decidable), or when the
	 * elapsed `clockOut - clockIn` does not exceed 12 hours.
	 *
	 * @param array<string, mixed> $o The AttendanceRecord.
	 *
	 * @return bool
	 */
	private static function maxShiftSatisfied(array $o): bool {
		$elapsedHours = self::elapsedHours($o);
		if ($elapsedHours === null) {
			return true;
		}

		return $elapsedHours <= self::MAX_SHIFT_HOURS;
	}//end maxShiftSatisfied()

	/**
	 * True while `clockOut` is null (open day, not decidable), or when
	 * `breakMinutes` meets or exceeds the required minutes for every
	 * `parameters.breakTiers` tier the elapsed shift length exceeds. Tiers
	 * are read from the `nl-atw-pauze` corpus rule, never hard-coded.
	 *
	 * @param array<string, mixed> $o The AttendanceRecord.
	 *
	 * @return bool
	 */
	private static function pauzeSatisfied(array $o): bool {
		$elapsedHours = self::elapsedHours($o);
		if ($elapsedHours === null) {
			return true;
		}

		$tiers = self::breakTiers();
		if ($tiers === null) {
			return true;
		}

		$breakMinutes = (float)($o['breakMinutes'] ?? 0);

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
	 * @param array<string, mixed> $o The AttendanceRecord.
	 *
	 * @return float|null
	 */
	private static function elapsedHours(array $o): ?float {
		$clockIn = strtotime((string)($o['clockIn'] ?? ''));
		if ($clockIn === false) {
			return null;
		}

		$clockOutRaw = ($o['clockOut'] ?? null);
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

	/**
	 * The `parameters.breakTiers` list of the `nl-atw-pauze` corpus rule, or
	 * null when the rule/parameters are missing from the catalogue.
	 *
	 * @return array<int, array<string, mixed>>|null
	 */
	private static function breakTiers(): ?array {
		foreach (RuleCatalogue::all() as $rule) {
			if ((string)($rule['id'] ?? '') !== 'nl-atw-pauze') {
				continue;
			}

			$parameters = ($rule['parameters'] ?? null);
			if (is_array($parameters) === false) {
				return null;
			}

			$tiers = ($parameters['breakTiers'] ?? null);
			return is_array($tiers) === true ? $tiers : null;
		}

		return null;
	}//end breakTiers()

}//end class
