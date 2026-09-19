<?php

/**
 * Roster Assignment Projection Service
 *
 * Composes a `RosterAssignment`'s planned-clock fields
 * (`plannedStart`/`plannedEnd`/`plannedBreakMinutes`) from the assignment's
 * `date` and the referenced `Shift`'s `startTime`/`endTime`/`breakMinutes`
 * (rostering MVP design D2, REQ-ROST-003): `plannedStart` is `date` +
 * `Shift.startTime`; `plannedEnd` is `date` + `Shift.endTime`, rolled to the
 * following calendar day when `endTime` is not after `startTime` (a night
 * shift crossing midnight, the `AttendanceRecord` convention);
 * `plannedBreakMinutes` is copied from `Shift.breakMinutes`.
 *
 * A pure, side-effect-free composition — never a live re-derivation at check
 * or audit time (design D2's "published plan is stable against a later
 * Shift edit"): `RosterCheckService` calls this ONLY to fill in the planned-
 * clock fields for an assignment that does not carry them yet, never to
 * overwrite an already-projected value.
 *
 * @category Service
 * @package  OCA\Humaniq\Service
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
 * @spec openspec/specs/rostering/spec.md#REQ-ROST-003
 */

declare(strict_types=1);

namespace OCA\Humaniq\Service;

/**
 * Composes RosterAssignment planned-clock fields from a Shift + date.
 */
final class RosterAssignmentProjectionService {

	/**
	 * Compose `plannedStart`/`plannedEnd`/`plannedBreakMinutes` from the
	 * given `$shift` (`startTime`/`endTime`/`breakMinutes`) and `$date`
	 * (`Y-m-d`). Returns nulls for `plannedStart`/`plannedEnd` when either
	 * time is unparseable or `$date` is unparseable — never throws (REQ-ROST-003).
	 *
	 * @param array<string, mixed> $shift The Shift (`startTime`, `endTime`, `breakMinutes`).
	 * @param string $date The assignment date (`Y-m-d`).
	 *
	 * @return array{plannedStart: string|null, plannedEnd: string|null, plannedBreakMinutes: float|null}
	 */
	public static function project(array $shift, string $date): array {
		$date = trim($date);

		$startTime = trim((string)($shift['startTime'] ?? ''));
		$endTime = trim((string)($shift['endTime'] ?? ''));

		$plannedStartTimestamp = self::combine($date, $startTime);
		$plannedEndTimestamp = self::combine($date, $endTime);

		if ($plannedStartTimestamp === null || $plannedEndTimestamp === null) {
			return [
				'plannedStart' => null,
				'plannedEnd' => null,
				'plannedBreakMinutes' => self::breakMinutes($shift),
			];
		}

		if ($plannedEndTimestamp <= $plannedStartTimestamp) {
			// endTime <= startTime denotes a night shift crossing midnight —
			// roll plannedEnd to the following calendar day (design D2).
			$plannedEndTimestamp = strtotime('+1 day', $plannedEndTimestamp);
		}

		return [
			'plannedStart' => date('Y-m-d\TH:i:00', $plannedStartTimestamp),
			'plannedEnd' => ($plannedEndTimestamp !== false) ? date('Y-m-d\TH:i:00', $plannedEndTimestamp) : null,
			'plannedBreakMinutes' => self::breakMinutes($shift),
		];

	}//end project()

	/**
	 * The Shift's `breakMinutes`, or null when absent/unparseable.
	 *
	 * @param array<string, mixed> $shift The Shift.
	 *
	 * @return float|null
	 */
	private static function breakMinutes(array $shift): ?float {
		$breakMinutes = ($shift['breakMinutes'] ?? null);
		if (is_numeric($breakMinutes) === false) {
			return null;
		}

		return (float)$breakMinutes;
	}//end breakMinutes()

	/**
	 * Combine an ISO `Y-m-d` date with an `HH:MM` wall-clock time into a
	 * unix timestamp, or null when either is unparseable.
	 *
	 * @param string $date ISO date (`Y-m-d`).
	 * @param string $time Wall-clock time (`HH:MM`).
	 *
	 * @return int|null
	 */
	private static function combine(string $date, string $time): ?int {
		if ($date === '' || $time === '') {
			return null;
		}

		$timestamp = strtotime($date . 'T' . $time . ':00');
		return ($timestamp === false) ? null : $timestamp;
	}//end combine()

	/**
	 * Fill an assignment's `plannedStart`/`plannedEnd`/`plannedBreakMinutes`
	 * from its referenced Shift ONLY when they are missing — an
	 * already-projected assignment is returned unchanged (design D2
	 * stability: never re-derive a value the write path already stored).
	 *
	 * @param array<string, mixed> $assignment The RosterAssignment.
	 * @param array<string, array<string, mixed>> $shiftsById Shift rows keyed by id.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/specs/rostering/spec.md#REQ-ROST-003
	 */
	public static function withProjection(array $assignment, array $shiftsById): array {
		$hasPlannedStart = (trim((string)($assignment['plannedStart'] ?? '')) !== '');
		$hasPlannedEnd = (trim((string)($assignment['plannedEnd'] ?? '')) !== '');
		if ($hasPlannedStart === true && $hasPlannedEnd === true) {
			return $assignment;
		}

		$shiftId = (string)($assignment['shiftId'] ?? '');
		$shift = ($shiftsById[$shiftId] ?? null);
		if (is_array($shift) === false) {
			return $assignment;
		}

		$date = (string)($assignment['date'] ?? '');
		if ($date === '') {
			return $assignment;
		}

		$planned = self::project($shift, $date);

		return array_merge(
			$assignment,
			[
				'plannedStart' => $assignment['plannedStart'] ?? $planned['plannedStart'],
				'plannedEnd' => $assignment['plannedEnd'] ?? $planned['plannedEnd'],
				'plannedBreakMinutes' => $assignment['plannedBreakMinutes'] ?? $planned['plannedBreakMinutes'],
			]
		);

	}//end withProjection()

	/**
	 * Build the `employeeId => [date => ['clockIn' => plannedStart, 'clockOut' => plannedEnd]]`
	 * sibling index from exactly the given (already-projected) assignment
	 * set — the `RuleAuditService::buildRosterContext()` shape, scoped to
	 * this check's own assignments only.
	 *
	 * @param array<int, array<string, mixed>> $assignments The (projected) RosterAssignment rows.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/specs/rostering/spec.md#REQ-ROST-004
	 */
	public static function plannedClockIndex(array $assignments): array {
		$index = [];
		foreach ($assignments as $assignment) {
			$employeeId = (string)($assignment['employeeId'] ?? '');
			$date = (string)($assignment['date'] ?? '');
			if ($employeeId === '' || $date === '') {
				continue;
			}

			$index[$employeeId][$date] = [
				'clockIn' => ($assignment['plannedStart'] ?? null),
				'clockOut' => ($assignment['plannedEnd'] ?? null),
			];
		}

		return $index;
	}//end plannedClockIndex()

}//end class
