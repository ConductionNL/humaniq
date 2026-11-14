<?php

/**
 * Who sees an announcement, and who confirmed it (self-service-announcements-and-digest D1, D2).
 *
 * An announcement is published to the whole administration or to chosen
 * departments, which include their teams. An employee sees it while it is
 * published and inside its period, when they are in the administration or
 * have an assignment today in one of the chosen departments. Unit membership
 * is not a manifest filter, which is why the list goes through this service.
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
 * @spec openspec/specs/announcements-and-digest/spec.md#REQ-AND-001
 */

declare(strict_types=1);

namespace OCA\Humaniq\Service;

/**
 * Audience matching and read confirmations for announcements.
 *
 * @spec openspec/specs/announcements-and-digest/spec.md#REQ-AND-001
 */
class AnnouncementService {

	public const ANNOUNCEMENT = 'Announcement';

	public const CONFIRMATION = 'AnnouncementConfirmation';

	public const CONFIRMATION_SLUG = 'announcementconfirmation';

	private const PUBLISHED = 'gepubliceerd';

	/**
	 * Constructor.
	 *
	 * @param HoursRegisterGateway $gateway    Register reads and writes, unscoped.
	 * @param UnitMembership       $membership Unit trees.
	 * @param OrgResolutionService $orgResolution Whether an assignment is active on a day.
	 */
	public function __construct(
		private readonly HoursRegisterGateway $gateway,
		private readonly UnitMembership $membership,
		private readonly OrgResolutionService $orgResolution,
	) {

	}//end __construct()

	/**
	 * The published announcements the caller is in the audience of today,
	 * newest first, each with the caller's own confirmation.
	 *
	 * @param string $uid   The caller.
	 * @param string $today The day, `YYYY-MM-DD`.
	 *
	 * @return array<int, array<string, mixed>> The rows.
	 *
	 * @spec openspec/specs/announcements-and-digest/spec.md#REQ-AND-001
	 */
	public function mine(string $uid, string $today): array {
		$employee = $this->gateway->findEmployeeByUserId($uid);
		if ($employee === null) {
			return [];
		}

		$reader = $this->readerOf(employee: $employee, units: $this->unitsOf(employeeId: $this->membership->rowId($employee), today: $today));
		$reader->today = $today;
		$rows = $this->rowsFor(reader: $reader, confirmed: $this->confirmationsOf(employeeId: $reader->employeeId));
		usort($rows, static fn (array $a, array $b): int => (string)($b['publishFrom'] ?? '') <=> (string)($a['publishFrom'] ?? ''));

		return $rows;
	}//end mine()

	/**
	 * An employee as a reader, with the units of their live placements.
	 *
	 * @param array<string, mixed> $employee The Employee.
	 * @param array<int, string>   $units    The units of the employee's live assignments.
	 *
	 * @return AnnouncementReader
	 */
	private function readerOf(array $employee, array $units): AnnouncementReader {
		$reader = new AnnouncementReader();
		$reader->employee = $employee;
		$reader->employeeId = $this->membership->rowId($employee);
		$reader->units = $units;

		return $reader;
	}//end readerOf()

	/**
	 * The published announcements that reach the reader on the day, each
	 * with the reader's confirmation.
	 *
	 * @param AnnouncementReader   $reader    The reader and the day.
	 * @param array<string, mixed> $confirmed The reader's confirmations, confirmedAt by announcement id.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function rowsFor(AnnouncementReader $reader, array $confirmed): array {
		$rows = [];
		foreach ($this->gateway->findFiltered(self::ANNOUNCEMENT, ['status' => self::PUBLISHED]) as $announcement) {
			if ($this->reaches(announcement: $announcement, reader: $reader) === true) {
				$rows[] = $this->readerRow(announcement: $announcement, confirmed: $confirmed);
			}
		}

		return $rows;
	}//end rowsFor()

	/**
	 * One announcement as the reader lists it.
	 *
	 * @param array<string, mixed> $announcement The announcement.
	 * @param array<string, mixed> $confirmed    The reader's confirmations, confirmedAt by announcement id.
	 *
	 * @return array<string, mixed>
	 */
	private function readerRow(array $announcement, array $confirmed): array {
		$id = $this->membership->rowId($announcement);

		return [
			'id' => $id,
			'title' => (string)($announcement['title'] ?? ''),
			'body' => (string)($announcement['body'] ?? ''),
			'publishFrom' => ($announcement['publishFrom'] ?? null),
			'publishUntil' => ($announcement['publishUntil'] ?? null),
			'requiresConfirmation' => (($announcement['requiresConfirmation'] ?? false) === true),
			'confirmed' => isset($confirmed[$id]),
			'confirmedAt' => ($confirmed[$id] ?? null),
		];
	}//end readerRow()

	/**
	 * Confirm, as the caller, that they read an announcement.
	 *
	 * @param string $announcementId The announcement.
	 * @param string $uid            The caller.
	 * @param string $now            The moment, ISO 8601.
	 *
	 * @return array{status: int, message: ?string, confirmation: ?array<string, mixed>} 201, or 404/409 with the reason.
	 *
	 * @spec openspec/specs/announcements-and-digest/spec.md#REQ-AND-002
	 */
	public function confirm(string $announcementId, string $uid, string $now): array {
		$row = null;
		foreach ($this->mine(uid: $uid, today: substr($now, 0, 10)) as $candidate) {
			if ($candidate['id'] === $announcementId) {
				$row = $candidate;
			}
		}

		if ($row === null) {
			return ['status' => 404, 'message' => 'Announcement not found.', 'confirmation' => null];
		}

		if ($row['requiresConfirmation'] === false) {
			return ['status' => 409, 'message' => 'This announcement does not ask for a confirmation.', 'confirmation' => null];
		}

		if ($row['confirmed'] === true) {
			return ['status' => 409, 'message' => 'You already confirmed this announcement.', 'confirmation' => null];
		}

		$employee = (array)$this->gateway->findEmployeeByUserId($uid);
		$confirmation = [
			'announcementId' => $announcementId,
			'employeeId' => $this->membership->rowId($employee),
			'userId' => $uid,
			'confirmedAt' => $now,
			'administrationId' => ($employee['administrationId'] ?? null),
		];
		$this->gateway->save($confirmation, self::CONFIRMATION);

		return ['status' => 201, 'message' => null, 'confirmation' => $confirmation];
	}//end confirm()

	/**
	 * Who in the audience confirmed an announcement and who did not, those
	 * who did not first.
	 *
	 * @param string $announcementId The announcement.
	 * @param string $today          The day the audience is taken on.
	 *
	 * @return array<string, mixed>|null The overview, or null when it does not exist.
	 *
	 * @spec openspec/specs/announcements-and-digest/spec.md#REQ-AND-002
	 */
	public function overview(string $announcementId, string $today): ?array {
		$announcement = $this->gateway->findObjectData($announcementId, self::ANNOUNCEMENT);
		if ($announcement === null) {
			return null;
		}

		$assignments = $this->gateway->loadAll('OrgAssignment');
		$confirmed   = $this->confirmationTimes(announcementId: $announcementId);

		$readers = array_map(fn (array $employee): AnnouncementReader => $this->readerOf(employee: $employee, units: $this->activeUnits(employeeId: $this->membership->rowId($employee), assignments: $assignments, today: $today)), $this->gateway->loadAll('Employee'));
		$audience = array_filter($readers, fn (AnnouncementReader $reader): bool => $this->reaches(announcement: $announcement, reader: $reader));
		$rows = array_map(fn (AnnouncementReader $reader): array => $this->confirmationRow(reader: $reader, confirmed: $confirmed), array_values($audience));

		return $this->overviewOf(announcementId: $announcementId, rows: $rows);
	}//end overview()

	/**
	 * One person of the audience in the overview: whether and when they confirmed.
	 *
	 * @param AnnouncementReader   $reader    The person.
	 * @param array<string, mixed> $confirmed When each employee confirmed, keyed by employee id.
	 *
	 * @return array{id: string, name: string, confirmed: bool, confirmedAt: mixed}
	 */
	private function confirmationRow(AnnouncementReader $reader, array $confirmed): array {
		return [
			'id' => $reader->employeeId,
			'name' => $this->nameOf(employee: $reader->employee),
			'confirmed' => array_key_exists($reader->employeeId, $confirmed),
			'confirmedAt' => ($confirmed[$reader->employeeId] ?? null),
		];
	}//end confirmationRow()

	/**
	 * When each employee confirmed the announcement, keyed by employee id;
	 * a confirmation without a time is still a confirmation.
	 *
	 * @param string $announcementId The announcement.
	 *
	 * @return array<string, mixed>
	 */
	private function confirmationTimes(string $announcementId): array {
		$confirmed = [];
		foreach ($this->gateway->findFiltered(self::CONFIRMATION, ['announcementId' => $announcementId]) as $confirmation) {
			$confirmed[(string)($confirmation['employeeId'] ?? '')] = ($confirmation['confirmedAt'] ?? null);
		}

		return $confirmed;
	}//end confirmationTimes()

	/**
	 * The overview of the audience rows, those who did not confirm first, then by name.
	 *
	 * @param string                           $announcementId The announcement.
	 * @param array<int, array<string, mixed>> $rows           One row per employee in the audience.
	 *
	 * @return array<string, mixed>
	 */
	private function overviewOf(string $announcementId, array $rows): array {
		usort($rows, fn (array $a, array $b): int => [$a['confirmed'], $this->sortName($a['name'])] <=> [$b['confirmed'], $this->sortName($b['name'])]);

		return [
			'announcementId' => $announcementId,
			'total' => count($rows),
			'confirmed' => count(array_filter($rows, static fn (array $row): bool => $row['confirmed'] === true)),
			'rows' => $rows,
		];
	}//end overviewOf()

	/**
	 * Place a confirmation created past the endpoint on the caller, or refuse
	 * it when the employee already confirmed that announcement.
	 *
	 * @param array<string, mixed> $confirmation The confirmation being created.
	 * @param string               $uid          The caller.
	 *
	 * @return array{stamps: array<string, mixed>, error: ?string}
	 *
	 * @spec openspec/specs/announcements-and-digest/spec.md#REQ-AND-002
	 */
	public function place(array $confirmation, string $uid): array {
		$employeeId = trim((string)($confirmation['employeeId'] ?? ''));
		$employee = ($employeeId === '') ? $this->gateway->findEmployeeByUserId($uid) : $this->gateway->findObjectData($employeeId, 'Employee');
		if ($employee === null) {
			return ['stamps' => [], 'error' => 'There is no employee record for this confirmation.'];
		}

		$employeeId = ($employeeId !== '') ? $employeeId : $this->membership->rowId($employee);
		$announcementId = trim((string)($confirmation['announcementId'] ?? ''));
		if ($this->gateway->findFiltered(self::CONFIRMATION, ['announcementId' => $announcementId, 'employeeId' => $employeeId]) !== []) {
			return ['stamps' => [], 'error' => 'This employee already confirmed this announcement.'];
		}

		$owner = trim((string)($employee['nextcloudUserId'] ?? ''));

		return [
			'stamps' => [
				'employeeId' => $employeeId,
				'userId' => ($owner !== '' ? $owner : null),
				'administrationId' => ($confirmation['administrationId'] ?? ($employee['administrationId'] ?? null)),
			],
			'error' => null,
		];
	}//end place()

	/**
	 * Whether an announcement reaches an employee: published and in period on
	 * the day (skipped when the day is empty), in the employee's
	 * administration, and to everyone or to a department they are in.
	 *
	 * @param array<string, mixed> $announcement The announcement.
	 * @param AnnouncementReader   $reader       The employee, the units of their live assignments and the day ('' for audience only).
	 *
	 * @return bool
	 */
	private function reaches(array $announcement, AnnouncementReader $reader): bool {
		if ($reader->today !== '' && $this->inPeriod(announcement: $announcement, today: $reader->today) === false) {
			return false;
		}

		if (self::hasLeft(reader: $reader) === true || self::inAdministration(announcement: $announcement, employee: $reader->employee) === false) {
			return false;
		}

		return $this->inAudience(announcement: $announcement, reader: $reader);
	}//end reaches()

	/**
	 * Whether the reader is in the announcement's audience: everyone, or a
	 * department (or a unit under it) the reader is placed in.
	 *
	 * @param array<string, mixed> $announcement The announcement.
	 * @param AnnouncementReader   $reader       The units of the reader's live assignments.
	 *
	 * @return bool
	 */
	private function inAudience(array $announcement, AnnouncementReader $reader): bool {
		if (($announcement['audience'] ?? 'administration') !== 'orgUnits') {
			return true;
		}

		return array_intersect($reader->units, $this->audienceUnits(announcement: $announcement)) !== [];
	}//end inAudience()

	/**
	 * Whether the employee left before the day (or before today when the
	 * day is empty).
	 *
	 * @param AnnouncementReader $reader The employee and the day.
	 *
	 * @return bool
	 */
	private static function hasLeft(AnnouncementReader $reader): bool {
		$ended = trim((string)($reader->employee['endDate'] ?? ''));

		return $ended !== '' && $ended < ($reader->today !== '' ? $reader->today : gmdate('Y-m-d'));
	}//end hasLeft()

	/**
	 * Whether the employee is in the announcement's administration; an
	 * announcement without one is for every administration.
	 *
	 * @param array<string, mixed> $announcement The announcement.
	 * @param array<string, mixed> $employee     The employee.
	 *
	 * @return bool
	 */
	private static function inAdministration(array $announcement, array $employee): bool {
		$administration = trim((string)($announcement['administrationId'] ?? ''));

		return $administration === '' || $administration === trim((string)($employee['administrationId'] ?? ''));
	}//end inAdministration()

	/**
	 * Whether an announcement is published and shown on a day.
	 *
	 * @param array<string, mixed> $announcement The announcement.
	 * @param string               $today        The day.
	 *
	 * @return bool
	 */
	private function inPeriod(array $announcement, string $today): bool {
		if (($announcement['status'] ?? '') !== self::PUBLISHED) {
			return false;
		}

		$from = trim((string)($announcement['publishFrom'] ?? ''));
		$until = trim((string)($announcement['publishUntil'] ?? ''));

		return ($from === '' || $from <= $today) && ($until === '' || $until >= $today);
	}//end inPeriod()

	/**
	 * The chosen departments and every unit under them.
	 *
	 * @param array<string, mixed> $announcement The announcement.
	 *
	 * @return array<int, string>
	 */
	private function audienceUnits(array $announcement): array {
		$units = $this->gateway->loadAll('OrgUnit');
		$out = [];
		foreach ((array)($announcement['orgUnitIds'] ?? []) as $unitId) {
			$out = array_merge($out, $this->membership->subtree((string)$unitId, $units));
		}

		return array_values(array_unique($out));
	}//end audienceUnits()

	/**
	 * The units of an employee's assignments active on a day.
	 *
	 * @param string $employeeId The employee.
	 * @param string $today      The day.
	 *
	 * @return array<int, string>
	 */
	private function unitsOf(string $employeeId, string $today): array {
		return $this->activeUnits(employeeId: $employeeId, assignments: $this->gateway->findFiltered('OrgAssignment', ['employeeId' => $employeeId]), today: $today);
	}//end unitsOf()

	/**
	 * The units of an employee's assignments, among the given ones, active on a day.
	 *
	 * @param string                           $employeeId  The employee.
	 * @param array<int, array<string, mixed>> $assignments The assignments.
	 * @param string                           $today       The day.
	 *
	 * @return array<int, string>
	 */
	private function activeUnits(string $employeeId, array $assignments, string $today): array {
		$units = [];
		foreach ($assignments as $assignment) {
			if ((string)($assignment['employeeId'] ?? '') === $employeeId && $this->orgResolution->isActiveOn($assignment, $today) === true) {
				$units[] = (string)($assignment['orgUnitId'] ?? '');
			}
		}

		return $units;
	}//end activeUnits()

	/**
	 * The employee's confirmations, confirmedAt by announcement id.
	 *
	 * @param string $employeeId The employee.
	 *
	 * @return array<string, mixed>
	 */
	private function confirmationsOf(string $employeeId): array {
		$out = [];
		foreach ($this->gateway->findFiltered(self::CONFIRMATION, ['employeeId' => $employeeId]) as $confirmation) {
			$out[(string)($confirmation['announcementId'] ?? '')] = ($confirmation['confirmedAt'] ?? null);
		}

		return $out;
	}//end confirmationsOf()

	/**
	 * The display name of an employee.
	 *
	 * @param array<string, mixed> $employee The employee.
	 *
	 * @return string
	 */
	private function nameOf(array $employee): string {
		return trim((string)($employee['firstName'] ?? '') . ' ' . (string)($employee['lastName'] ?? ''));
	}//end nameOf()

	/**
	 * The last name first, for sorting a list of people.
	 *
	 * @param string $name The display name.
	 *
	 * @return string
	 */
	private function sortName(string $name): string {
		$parts = explode(' ', $name);

		return strtolower((string)end($parts) . ' ' . $name);
	}//end sortName()

}//end class
