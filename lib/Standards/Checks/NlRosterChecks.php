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
 * @spec openspec/specs/rostering/spec.md#REQ-ROST-004
 */

declare(strict_types=1);

namespace OCA\Humaniq\Standards\Checks;

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
				'nl-atw-dagelijkse-rust' => static fn (array $o, array $context): bool => WorkingTimeRules::dailyRestSatisfied(self::plannedClock($o), ($context['rostering']['plannedClockByEmployeeDate'] ?? null)),
				// ATW art. 5:7 lid 1 — <= 12h per dienst, from the projected planned-clock fields.
				'nl-atw-max-werkdag' => static fn (array $o, array $context): bool => WorkingTimeRules::maxShiftSatisfied(self::plannedClock($o)),
				// ATW art. 5:4 lid 1 — statutory break tiers, read from rule parameters.
				'nl-atw-pauze' => static fn (array $o, array $context): bool => WorkingTimeRules::pauzeSatisfied(self::plannedClock($o), NlAttendanceChecks::breakTiers()),
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
	 * A RosterAssignment's planned fields in the clock shape the working-time
	 * rules read.
	 *
	 * @param array<string, mixed> $o The RosterAssignment.
	 *
	 * @return array<string, mixed>
	 */
	private static function plannedClock(array $o): array {
		return [
			'employeeId' => ($o['employeeId'] ?? null),
			'date' => ($o['date'] ?? null),
			'clockIn' => ($o['plannedStart'] ?? null),
			'clockOut' => ($o['plannedEnd'] ?? null),
			'breakMinutes' => ($o['plannedBreakMinutes'] ?? 0),
		];
	}//end plannedClock()
}//end class
