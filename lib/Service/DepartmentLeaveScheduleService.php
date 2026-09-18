<?php

/**
 * Department Leave Schedule Service
 *
 * One org unit's leave over a period, composed on read.
 *
 * WHY NOTHING IS STORED
 * ---------------------
 * A stored schedule is a second copy of the requests, and a copy drifts: a
 * withdrawn request keeps showing until something syncs, and the approver then
 * decides against a month that is not the month. Composing on read means a
 * withdrawal is gone from the next read with nothing in between (REQ-LVM-S01).
 *
 * WHAT A READER WHO MAY NOT SEE A REQUEST SEES
 * --------------------------------------------
 * That the person is unavailable in that period, and nothing else: no type, no
 * reason, no status. It is the boundary `leave-calendar-nc` already draws for
 * the Nextcloud calendar, and it is drawn here in the same shape so a colleague
 * planning a meeting learns availability without learning that somebody is on
 * zorgverlof.
 *
 * Which requests a reader may see is decided by the existing team-scope rules
 * and handed to this service as a list of ids. It deliberately makes no access
 * decision of its own: a view that invents its own scoping is a second answer
 * to a question `mss-team-scope` already answers.
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

use DateTimeImmutable;

/**
 * Composes one org unit's leave schedule from the requests themselves.
 *
 * @spec openspec/specs/leave-management/spec.md#REQ-LVM-S01
 */
class DepartmentLeaveScheduleService {

	/**
	 * The statuses that stand on a schedule.
	 *
	 * A draft is nobody's plan yet and a rejected or withdrawn request is not a
	 * plan any more, so neither takes a place on the month.
	 *
	 * @var array<int, string>
	 */
	public const SCHEDULED_STATUSES = ['submitted', 'approved'];

	/**
	 * Compose the schedule for one unit over a period.
	 *
	 * @param array<array<string, mixed>> $requests LeaveRequest objects, any employee and any status.
	 * @param array<int, string> $memberEmployeeIds The employees placed in this org unit.
	 * @param DateTimeImmutable $from First day of the period, inclusive.
	 * @param DateTimeImmutable $to Last day of the period, inclusive.
	 * @param array<int, string>|null $visibleRequestIds Ids the reader may see in full, or null when they may see them all.
	 *
	 * @return array<int, array<string, mixed>> The entries, in start-date order.
	 *
	 * @spec openspec/specs/leave-management/spec.md#REQ-LVM-S01
	 */
	public function compose(
		array $requests,
		array $memberEmployeeIds,
		DateTimeImmutable $from,
		DateTimeImmutable $to,
		?array $visibleRequestIds = null,
	): array {
		$members = array_flip(array_values($memberEmployeeIds));
		$windowStart = $from->format('Y-m-d');
		$windowEnd = $to->format('Y-m-d');

		$entries = [];
		foreach ($requests as $request) {
			$employeeId = trim((string)($request['employeeId'] ?? ''));
			if ($employeeId === '' || isset($members[$employeeId]) === false) {
				continue;
			}

			if (in_array(trim((string)($request['status'] ?? '')), self::SCHEDULED_STATUSES, true) === false) {
				continue;
			}

			$start = substr(trim((string)($request['startDate'] ?? '')), 0, 10);
			$end = substr(trim((string)($request['endDate'] ?? '')), 0, 10);
			if ($start === '' || $end === '' || $start > $windowEnd || $end < $windowStart) {
				continue;
			}

			$entries[] = $this->entry(request: $request, visibleRequestIds: $visibleRequestIds);
		}

		usort(
			$entries,
			static function (array $a, array $b): int {
				return ([$a['startDate'], $a['employeeId']] <=> [$b['startDate'], $b['employeeId']]);
			}
		);

		return $entries;
	}//end compose()

	/**
	 * How many of a unit's members are at work on one date, given the
	 * schedule, and who is away.
	 *
	 * Counted over the same entries the schedule shows, redacted or not: an
	 * entry a reader may not see still takes its person off the floor, and a
	 * coverage count that ignored it would be wrong in the reader's favour.
	 *
	 * @param array<int, array<string, mixed>> $entries The composed entries.
	 * @param array<int, string> $memberEmployeeIds The employees placed in this org unit.
	 * @param DateTimeImmutable $date The date asked about.
	 *
	 * @return array{present: int, away: array<int, string>}
	 *
	 * @spec openspec/specs/leave-management/spec.md#REQ-LVM-S02
	 */
	public function presenceOn(array $entries, array $memberEmployeeIds, DateTimeImmutable $date): array {
		$day = $date->format('Y-m-d');
		$away = [];
		foreach ($entries as $entry) {
			if ($entry['startDate'] > $day || $entry['endDate'] < $day) {
				continue;
			}

			$away[(string)$entry['employeeId']] = true;
		}

		$members = array_values(array_unique(array_map('strval', $memberEmployeeIds)));
		$awayMembers = [];
		foreach ($members as $member) {
			if (isset($away[$member]) === true) {
				$awayMembers[] = $member;
			}
		}

		return [
			'present' => (count($members) - count($awayMembers)),
			'away' => $awayMembers,
		];
	}//end presenceOn()

	/**
	 * One entry, in full or redacted to availability.
	 *
	 * @param array<string, mixed> $request The LeaveRequest.
	 * @param array<int, string>|null $visibleRequestIds Ids the reader may see in full, or null for all.
	 *
	 * @return array<string, mixed> The entry.
	 *
	 * @spec openspec/specs/leave-management/spec.md#REQ-LVM-S01
	 */
	private function entry(array $request, ?array $visibleRequestIds): array {
		$id = trim((string)($request['id'] ?? ''));
		$entry = [
			'requestId' => $id,
			'employeeId' => trim((string)($request['employeeId'] ?? '')),
			'startDate' => substr(trim((string)($request['startDate'] ?? '')), 0, 10),
			'endDate' => substr(trim((string)($request['endDate'] ?? '')), 0, 10),
		];

		if ($visibleRequestIds !== null && in_array($id, $visibleRequestIds, true) === false) {
			// Unavailability and nothing further. The key is absent rather than
			// null: a null `leaveType` beside a redacted entry reads as "no
			// type recorded", which is a different and wrong statement.
			$entry['redacted'] = true;

			return $entry;
		}

		$entry['redacted'] = false;
		$entry['status'] = trim((string)($request['status'] ?? ''));
		$entry['leaveType'] = trim((string)($request['leaveType'] ?? ''));

		return $entry;
	}//end entry()
}//end class
