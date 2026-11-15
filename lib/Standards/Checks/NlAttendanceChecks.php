<?php

/**
 * NL Arbeidstijdenwet Attendance Check Provider
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
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
 * @package  OCA\Humaniq\Standards\Checks
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/time-attendance/spec.md#REQ-TA-004
 */

declare(strict_types=1);

namespace OCA\Humaniq\Standards\Checks;

use OCA\Humaniq\Standards\RuleCatalogue;

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
				'nl-atw-dagelijkse-rust' => static fn (array $o, array $context): bool => WorkingTimeRules::dailyRestSatisfied($o, ($context['attendance']['clockByEmployeeDate'] ?? null)),
				// ATW art. 5:7 lid 1 — <= 12h per dienst, from raw clock fields.
				'nl-atw-max-werkdag' => static fn (array $o, array $context): bool => WorkingTimeRules::maxShiftSatisfied($o),
				// ATW art. 5:4 lid 1 — statutory break tiers, read from rule parameters.
				'nl-atw-pauze' => static fn (array $o, array $context): bool => WorkingTimeRules::pauzeSatisfied($o, self::breakTiers()),
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
	 * The `parameters.breakTiers` list of the `nl-atw-pauze` corpus rule, or
	 * null when the rule/parameters are missing from the catalogue.
	 *
	 * @return array<int, array<string, mixed>>|null
	 */
	public static function breakTiers(): ?array {
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
