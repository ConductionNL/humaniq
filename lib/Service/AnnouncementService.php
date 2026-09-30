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

		$employeeId = $this->membership->rowId($employee);
		$units = $this->unitsOf(employeeId: $employeeId, today: $today);
		$confirmed = $this->confirmationsOf(employeeId: $employeeId);

		$rows = [];
		foreach ($this->gateway->findFiltered(self::ANNOUNCEMENT, ['status' => self::PUBLISHED]) as $announcement) {
			if ($this->reaches(announcement: $announcement, employee: $employee, units: $units, today: $today) === false) {
				continue;
			}

			$id = $this->membership->rowId($announcement);
			$rows[] = [
				'id' => $id,
				'title' => (string)($announcement['title'] ?? ''),
				'body' => (string)($announcement['body'] ?? ''),
				'publishFrom' => ($announcement['publishFrom'] ?? null),
				'publishUntil' => ($announcement['publishUntil'] ?? null),
				'requiresConfirmation' => (($announcement['requiresConfirmation'] ?? false) === true),
				'confirmed' => isset($confirmed[$id]),
				'confirmedAt' => ($confirmed[$id] ?? null),
			];
		}

		usort($rows, static fn (array $a, array $b): int => (string)($b['publishFrom'] ?? '') <=> (string)($a['publishFrom'] ?? ''));

		return $rows;
	}//end mine()

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
		$confirmed = [];
		foreach ($this->gateway->findFiltered(self::CONFIRMATION, ['announcementId' => $announcementId]) as $confirmation) {
			$confirmed[(string)($confirmation['employeeId'] ?? '')] = ($confirmation['confirmedAt'] ?? null);
		}

		$rows = [];
		foreach ($this->gateway->loadAll('Employee') as $employee) {
			$employeeId = $this->membership->rowId($employee);
			$units = $this->activeUnits(employeeId: $employeeId, assignments: $assignments, today: $today);
			if ($this->reaches(announcement: $announcement, employee: $employee, units: $units, today: '') === false) {
				continue;
			}

			$rows[] = [
				'id' => $employeeId,
				'name' => $this->nameOf(employee: $employee),
				'confirmed' => array_key_exists($employeeId, $confirmed),
				'confirmedAt' => ($confirmed[$employeeId] ?? null),
			];
		}

		usort($rows, fn (array $a, array $b): int => [$a['confirmed'], $this->sortName($a['name'])] <=> [$b['confirmed'], $this->sortName($b['name'])]);

		return [
			'announcementId' => $announcementId,
			'total' => count($rows),
			'confirmed' => count(array_filter($rows, static fn (array $row): bool => $row['confirmed'] === true)),
			'rows' => $rows,
		];
	}//end overview()

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
	 * @param array<string, mixed> $employee     The employee.
	 * @param array<int, string>   $units        The units of the employee's live assignments.
	 * @param string               $today        The day, or '' for audience only.
	 *
	 * @return bool
	 */
	private function reaches(array $announcement, array $employee, array $units, string $today): bool {
		if ($today !== '' && $this->inPeriod(announcement: $announcement, today: $today) === false) {
			return false;
		}

		$ended = trim((string)($employee['endDate'] ?? ''));
		if ($ended !== '' && $ended < ($today !== '' ? $today : gmdate('Y-m-d'))) {
			return false;
		}

		$administration = trim((string)($announcement['administrationId'] ?? ''));
		if ($administration !== '' && $administration !== trim((string)($employee['administrationId'] ?? ''))) {
			return false;
		}

		if (($announcement['audience'] ?? 'administration') !== 'orgUnits') {
			return true;
		}

		return array_intersect($units, $this->audienceUnits(announcement: $announcement)) !== [];
	}//end reaches()

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
