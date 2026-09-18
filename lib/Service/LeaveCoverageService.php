<?php

/**
 * Leave Coverage Service
 *
 * Says what approving one leave request would leave the department with.
 *
 * WHY IT WARNS AND NEVER REFUSES
 * ------------------------------
 * A manager who knows the counter is closed that week, or that a colleague is
 * coming back early, needs the approval to land. A hard block on a number HR
 * administered months ago would be overruled by a phone call and a manual
 * status edit, and the trail would be worse than no rule at all.
 *
 * So this produces a warning with the dates, the count, the minimum and the
 * names, and the approver decides (REQ-LVM-S02). What they accepted is
 * recorded on the request, which is the part a later reader needs.
 *
 * WHY NAMES, NOT JUST A NUMBER
 * ----------------------------
 * "Two present, minimum three" is a fact nobody can act on. "Jansen and
 * De Vries are already away that Monday" is a fact a manager can act on,
 * because the next step is a conversation with one of them.
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
 * @spec openspec/specs/leave-management/spec.md
 */

declare(strict_types=1);

namespace OCA\Humaniq\Service;

use DateInterval;
use DateTimeImmutable;

/**
 * Computes the coverage warning for approving one leave request.
 *
 * @spec openspec/specs/leave-management/spec.md#REQ-LVM-S02
 */
class LeaveCoverageService {

	/**
	 * The `OrgUnit` property holding each weekday's administered minimum.
	 *
	 * @var array<string, string>
	 */
	public const MINIMUM_PROPERTIES = [
		'monday' => 'minimumPresentMonday',
		'tuesday' => 'minimumPresentTuesday',
		'wednesday' => 'minimumPresentWednesday',
		'thursday' => 'minimumPresentThursday',
		'friday' => 'minimumPresentFriday',
		'saturday' => 'minimumPresentSaturday',
		'sunday' => 'minimumPresentSunday',
	];

	/**
	 * Constructor.
	 *
	 * @param DepartmentLeaveScheduleService $schedule The schedule this measures over.
	 */
	public function __construct(
		private readonly DepartmentLeaveScheduleService $schedule = new DepartmentLeaveScheduleService(),
	) {

	}//end __construct()

	/**
	 * The warning approving one request would earn, or a warning with no dates
	 * when it earns none.
	 *
	 * @param array<string, mixed> $request The LeaveRequest about to be approved.
	 * @param array<string, mixed> $orgUnit The OrgUnit the requester is placed in.
	 * @param array<int, string> $memberEmployeeIds The employees placed in that unit.
	 * @param array<array<string, mixed>> $otherRequests Every other LeaveRequest, any status.
	 *
	 * @return array{belowMinimum: bool, dates: array<int, array<string, mixed>>, message: string}
	 *
	 * @spec openspec/specs/leave-management/spec.md#REQ-LVM-S02
	 */
	public function warning(
		array $request,
		array $orgUnit,
		array $memberEmployeeIds,
		array $otherRequests,
	): array {
		$start = $this->date(value: ($request['startDate'] ?? null));
		$end = $this->date(value: ($request['endDate'] ?? null));
		if ($start === null || $end === null || $start > $end) {
			return $this->noWarning();
		}

		$requestId = trim((string)($request['id'] ?? ''));
		$standing = [];
		foreach ($otherRequests as $other) {
			if (trim((string)($other['id'] ?? '')) === $requestId && $requestId !== '') {
				continue;
			}

			$standing[] = $other;
		}

		// The request under consideration counts as approved, because the
		// question is what the unit looks like AFTER approving it.
		$standing[] = array_merge($request, ['status' => 'approved']);

		$entries = $this->schedule->compose(
			requests: $standing,
			memberEmployeeIds: $memberEmployeeIds,
			from: $start,
			to: $end
		);

		$dates = [];
		$cursor = $start;
		$step = new DateInterval('P1D');
		while ($cursor <= $end) {
			$minimum = $this->minimumOn(orgUnit: $orgUnit, date: $cursor);
			if ($minimum === null) {
				$cursor = $cursor->add($step);
				continue;
			}

			$presence = $this->schedule->presenceOn(
				entries: $entries,
				memberEmployeeIds: $memberEmployeeIds,
				date: $cursor
			);

			if ($presence['present'] < $minimum) {
				$requester = trim((string)($request['employeeId'] ?? ''));
				$dates[] = [
					'date' => $cursor->format('Y-m-d'),
					'present' => $presence['present'],
					'minimum' => $minimum,
					'othersAway' => array_values(
						array_filter(
							$presence['away'],
							static function (string $employeeId) use ($requester): bool {
								return ($employeeId !== $requester);
							}
						)
					),
				];
			}

			$cursor = $cursor->add($step);
		}

		if ($dates === []) {
			return $this->noWarning();
		}

		return [
			'belowMinimum' => true,
			'dates' => $dates,
			'message' => $this->message(dates: $dates),
		];
	}//end warning()

	/**
	 * The administered minimum for one date, or null when the unit has none
	 * for that weekday.
	 *
	 * @param array<string, mixed> $orgUnit The OrgUnit.
	 * @param DateTimeImmutable $date The date asked about.
	 *
	 * @return int|null The minimum, or null.
	 *
	 * @spec openspec/specs/leave-management/spec.md#REQ-LVM-S02
	 */
	public function minimumOn(array $orgUnit, DateTimeImmutable $date): ?int {
		$weekday = strtolower($date->format('l'));
		$value = ($orgUnit[(self::MINIMUM_PROPERTIES[$weekday] ?? '')] ?? null);
		if (is_numeric($value) === false) {
			return null;
		}

		$minimum = (int)$value;
		if ($minimum <= 0) {
			// Zero is "no minimum administered", not "nobody need be present":
			// a warning on every single approval is a warning nobody reads.
			return null;
		}

		return $minimum;
	}//end minimumOn()

	/**
	 * The warning an approver reads.
	 *
	 * @param array<int, array<string, mixed>> $dates The dates below the minimum.
	 *
	 * @return string The message.
	 *
	 * @spec openspec/specs/leave-management/spec.md#REQ-LVM-S02
	 */
	private function message(array $dates): string {
		$lines = [];
		foreach ($dates as $date) {
			$line = $date['date'] . ': ' . $date['present'] . ' aanwezig, minimaal ' . $date['minimum'] . '.';
			if ($date['othersAway'] !== []) {
				$line .= ' Ook afwezig: ' . implode(', ', $date['othersAway']) . '.';
			}

			$lines[] = $line;
		}

		return 'Goedkeuren laat de afdeling onder de bezetting die is afgesproken. ' . implode(' ', $lines)
			. ' Goedkeuren kan wel; wat u hier leest wordt op de aanvraag vastgelegd.';
	}//end message()

	/**
	 * The answer when nothing is below the minimum.
	 *
	 * @return array{belowMinimum: false, dates: array<int, array<string, mixed>>, message: string}
	 *
	 * @spec openspec/specs/leave-management/spec.md#REQ-LVM-S02
	 */
	private function noWarning(): array {
		return [
			'belowMinimum' => false,
			'dates' => [],
			'message' => '',
		];
	}//end noWarning()

	/**
	 * Narrow a raw value to a date.
	 *
	 * @param mixed $value The raw value.
	 *
	 * @return DateTimeImmutable|null The date, or null when unusable.
	 *
	 * @spec openspec/specs/leave-management/spec.md#REQ-LVM-S02
	 */
	private function date(mixed $value): ?DateTimeImmutable {
		if (is_string($value) === false || trim($value) === '') {
			return null;
		}

		try {
			return new DateTimeImmutable(substr(trim($value), 0, 10));
		} catch (\Throwable $e) {
			return null;
		}
	}//end date()
}//end class
