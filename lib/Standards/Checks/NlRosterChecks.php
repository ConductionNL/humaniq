<?php

/**
 * NL Arbeidstijdenwet Roster Check Provider
 *
 * Executable checks for the SAME three Dutch working-time-law rules
 * `NlAttendanceChecks` already enforces over `AttendanceRecord`
 * (framework `nl-arbeidstijdenwet`, lib/Standards/rules/labour.json):
 * `nl-atw-dagelijkse-rust` (ATW art. 5:3 lid 2 — at least 11 hours unbroken
 * rest between consecutive working days), `nl-atw-max-werkdag` (ATW art. 5:7
 * lid 1 — at most 12 hours per dienst), and `nl-atw-pauze` (ATW art. 5:4
 * lid 1 — statutory break tiers), mapped this time onto the `RosterAssignment`
 * object type (rostering MVP).
 *
 * No new rule is added to the corpus and no threshold is re-declared: every
 * predicate here projects `plannedStart`/`plannedEnd`/`plannedBreakMinutes`
 * into the same clock shape `NlAttendanceChecks` reads from
 * `clockIn`/`clockOut`/`breakMinutes`, and reuses
 * `NlAttendanceChecks::MIN_REST_HOURS`/`::MAX_SHIFT_HOURS` plus the corpus
 * `nl-atw-pauze` `parameters.breakTiers` (read via `RuleCatalogue`, never
 * hard-coded) — a change to either updates both call sites at once (design
 * D4, Risks).
 *
 * The daily-rest predicate reads `$context['rostering']['plannedClockByEmployeeDate']`,
 * the per-employee date-indexed sibling index `RuleAuditService::buildRosterContext()`
 * builds from every `gepubliceerd`-roster `RosterAssignment` (the
 * `buildAttendanceContext()` precedent), so `occ humaniq:rules:audit` never
 * raises a mandatory violation for a work-in-progress concept roster (design
 * D4's scope discipline) — those are checked on demand via
 * `RosterCheckService` instead.
 *
 * Vacuous-pass discipline (the NlAttendanceChecks precedent): an assignment
 * with a null `plannedEnd` passes `nl-atw-max-werkdag` and `nl-atw-pauze`
 * (shift length not decidable); an assignment whose previous working day has
 * no sibling assignment, or whose sibling's `plannedEnd` is null, passes
 * `nl-atw-dagelijkse-rust`.
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
 * @spec openspec/changes/rostering/specs/rostering/spec.md#REQ-ROST-004
 */

declare(strict_types=1);

namespace OCA\Humaniq\Standards\Checks;

use OCA\Humaniq\Standards\RuleCatalogue;

/**
 * Dutch Arbeidstijdenwet (working time) executable checks over RosterAssignment,
 * reusing NlAttendanceChecks' constants and the corpus nl-atw-pauze breakTiers.
 */
final class NlRosterChecks implements CheckProvider {

	/**
	 * {@inheritDoc}
	 *
	 * @return array<string, array<string, callable>>
	 */
	public static function checks(): array {
		return [
			'RosterAssignment' => [
				// ATW art. 5:3 lid 2 — >= 11h unbroken rest between consecutive planned working days.
				'nl-atw-dagelijkse-rust' => static fn (array $o, array $context): bool => self::dailyRestSatisfied($o, $context),
				// ATW art. 5:7 lid 1 — <= 12h per dienst, from the projected planned-clock fields.
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
	 * True when this assignment's employee has no previous-working-day
	 * sibling in `$context['rostering']['plannedClockByEmployeeDate']` (rest
	 * is implied >= 24h, or not decidable), or when the sibling's
	 * `plannedEnd` is null (also not decidable), or when the gap between the
	 * sibling's `plannedEnd` and this assignment's `plannedStart` is
	 * >= `NlAttendanceChecks::MIN_REST_HOURS`.
	 *
	 * @param array<string, mixed> $o The RosterAssignment.
	 * @param array<string, mixed> $context Evaluation context; reads `rostering.plannedClockByEmployeeDate`.
	 *
	 * @return bool
	 */
	private static function dailyRestSatisfied(array $o, array $context): bool {
		$index = ($context['rostering']['plannedClockByEmployeeDate'] ?? null);
		if (is_array($index) === false) {
			return true;
		}

		$employeeId = (string)($o['employeeId'] ?? '');
		$date = trim((string)($o['date'] ?? ''));
		$plannedStart = strtotime((string)($o['plannedStart'] ?? ''));
		if ($employeeId === '' || $date === '' || $plannedStart === false) {
			return true;
		}

		$previousDate = self::previousCalendarDay($date);
		if ($previousDate === null) {
			return true;
		}

		$sibling = ($index[$employeeId][$previousDate] ?? null);
		if (is_array($sibling) === false) {
			// No sibling assignment on the immediately preceding calendar
			// day — rest is implied to be >= 24h (or a free day sits in
			// between).
			return true;
		}

		$siblingPlannedEnd = strtotime((string)($sibling['clockOut'] ?? ''));
		if ($siblingPlannedEnd === false) {
			// Sibling shift's planned end not decidable.
			return true;
		}

		$restHours = ($plannedStart - $siblingPlannedEnd) / 3600;
		return $restHours >= NlAttendanceChecks::MIN_REST_HOURS;
	}//end dailyRestSatisfied()

	/**
	 * True while `plannedEnd` is null (not decidable), or when the elapsed
	 * `plannedEnd - plannedStart` does not exceed
	 * `NlAttendanceChecks::MAX_SHIFT_HOURS`.
	 *
	 * @param array<string, mixed> $o The RosterAssignment.
	 *
	 * @return bool
	 */
	private static function maxShiftSatisfied(array $o): bool {
		$elapsedHours = self::elapsedHours($o);
		if ($elapsedHours === null) {
			return true;
		}

		return $elapsedHours <= NlAttendanceChecks::MAX_SHIFT_HOURS;
	}//end maxShiftSatisfied()

	/**
	 * True while `plannedEnd` is null (not decidable), or when
	 * `plannedBreakMinutes` meets or exceeds the required minutes for every
	 * `parameters.breakTiers` tier the elapsed planned shift length exceeds.
	 * Tiers are read from the `nl-atw-pauze` corpus rule, never hard-coded —
	 * the exact same source `NlAttendanceChecks::breakTiers()` reads.
	 *
	 * @param array<string, mixed> $o The RosterAssignment.
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

		$breakMinutes = (float)($o['plannedBreakMinutes'] ?? 0);

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
	 * The elapsed `plannedEnd - plannedStart` duration in hours, or null when
	 * `plannedEnd` is absent/unparseable or `plannedStart` is unparseable.
	 *
	 * @param array<string, mixed> $o The RosterAssignment.
	 *
	 * @return float|null
	 */
	private static function elapsedHours(array $o): ?float {
		$plannedStart = strtotime((string)($o['plannedStart'] ?? ''));
		if ($plannedStart === false) {
			return null;
		}

		$plannedEndRaw = ($o['plannedEnd'] ?? null);
		if ($plannedEndRaw === null || trim((string)$plannedEndRaw) === '') {
			return null;
		}

		$plannedEnd = strtotime((string)$plannedEndRaw);
		if ($plannedEnd === false) {
			return null;
		}

		return ($plannedEnd - $plannedStart) / 3600;
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
	 * null when the rule/parameters are missing from the catalogue. Reads
	 * `RuleCatalogue` directly (the exact same corpus source
	 * `NlAttendanceChecks::breakTiers()` reads) — no threshold is
	 * re-declared here.
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
