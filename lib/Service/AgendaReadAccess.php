<?php

/**
 * Agenda Read Access
 *
 * Who may read which agenda, and how much of it. An employee's agenda is open
 * to the employee, their managers, HR and the colleagues placed in a unit with
 * them. An org unit's agenda is open to its members, its manager and HR.
 * Interviews and secondment hours stay with the employee, their managers and
 * HR: a colleague sees the other entries without them.
 *
 * The viewer is the session user, and every role and placement is read from
 * the register and the Nextcloud groups, never from the request. Placements
 * count when they are active today, whatever window the agenda covers.
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
 * @spec openspec/specs/agenda-and-resource-booking/spec.md#REQ-AGD-007
 */

declare(strict_types=1);

namespace OCA\Humaniq\Service;

use DateTimeImmutable;
use OCP\IUserSession;

/**
 * Decides whether the session user may read an agenda, and trims what they
 * may not see.
 *
 * @spec openspec/specs/agenda-and-resource-booking/spec.md#REQ-AGD-007
 */
class AgendaReadAccess {

	/**
	 * The kinds a colleague sees. An interview is not one of them, and a kind
	 * added later stays hidden from colleagues until it is listed here.
	 *
	 * @var array<int, string>
	 */
	public const COLLEAGUE_KINDS = ['shift', 'leave', 'absent', 'booking', 'busy', 'secondment'];

	/**
	 * Fields a colleague does not see on an entry they do see.
	 *
	 * @var array<int, string>
	 */
	public const COLLEAGUE_HIDDEN_FIELDS = ['hoursPerWeek'];

	/**
	 * Constructor.
	 *
	 * @param IUserSession $userSession The signed-in user.
	 * @param RecordStoreInterface $store Employees, units and placements.
	 * @param OrgResolutionService $orgResolution Active placements and an employee's managers.
	 * @param HumaniqRoles $roles The HR check.
	 */
	public function __construct(
		private readonly IUserSession $userSession,
		private readonly RecordStoreInterface $store,
		private readonly OrgResolutionService $orgResolution,
		private readonly HumaniqRoles $roles,
	) {

	}//end __construct()

	/**
	 * What the session user may see of one subject's agenda: null when they
	 * may not open it, `['all' => true]` when they see every entry in full,
	 * otherwise the employees whose entries they see in full.
	 *
	 * @param string $subjectType `employee`, `orgUnit` or `resource`.
	 * @param string $subjectId The subject's id.
	 *
	 * @return array{all: bool, full: array<int, string>}|null
	 *
	 * @spec openspec/specs/agenda-and-resource-booking/spec.md#REQ-AGD-007
	 */
	public function grant(string $subjectType, string $subjectId): ?array {
		$viewer = (string)$this->userSession->getUser()?->getUID();
		if ($viewer === '') {
			return null;
		}

		if ($subjectType === 'resource' || $this->roles->isHr($viewer) === true) {
			return ['all' => true, 'full' => []];
		}

		$org = $this->orgFor($viewer);
		if ($subjectType === 'employee') {
			return $this->employeeGrant(employeeId: $subjectId, org: $org);
		}

		return $this->unitGrant(orgUnitId: $subjectId, org: $org);
	}//end grant()

	/**
	 * The entries a grant lets the viewer see: a colleague's interviews and
	 * kinds not listed for colleagues are dropped, and their secondment hours
	 * left out.
	 *
	 * @param array<int, array<string, mixed>> $entries The composed entries.
	 * @param array{all: bool, full: array<int, string>} $grant What {@see grant()} answered.
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @spec openspec/specs/agenda-and-resource-booking/spec.md#REQ-AGD-007
	 */
	public function visibleEntries(array $entries, array $grant): array {
		if ($grant['all'] === true) {
			return $entries;
		}

		$visible = [];
		foreach ($entries as $entry) {
			if (in_array((string)($entry['subjectId'] ?? ''), $grant['full'], true) === true) {
				$visible[] = $entry;
				continue;
			}

			if (in_array((string)($entry['kind'] ?? ''), self::COLLEAGUE_KINDS, true) === true) {
				$visible[] = array_diff_key($entry, array_flip(self::COLLEAGUE_HIDDEN_FIELDS));
			}
		}

		return $visible;
	}//end visibleEntries()

	/**
	 * The employees placed in one org unit on one date.
	 *
	 * @param string $orgUnitId The org unit.
	 * @param DateTimeImmutable $onDate The date the placement must be active on.
	 *
	 * @return array<int, string> The employee ids.
	 *
	 * @spec openspec/specs/agenda-and-resource-booking/spec.md#REQ-AGD-001
	 */
	public function members(string $orgUnitId, DateTimeImmutable $onDate): array {
		$members = [];
		foreach ($this->store->findFiltered('OrgAssignment', ['orgUnitId' => $orgUnitId]) as $assignment) {
			if ($this->orgResolution->isActiveOn($assignment, $onDate->format('Y-m-d')) === false) {
				continue;
			}

			$employeeId = trim((string)($assignment['employeeId'] ?? ''));
			if ($employeeId !== '') {
				$members[$employeeId] = true;
			}
		}

		return array_keys($members);
	}//end members()

	/**
	 * Self and managers see an employee in full, a colleague in a shared unit
	 * sees the trimmed agenda, anyone else nothing.
	 *
	 * @param string $employeeId The employee asked for.
	 * @param array<string, mixed> $org The viewer and the org indexes, from {@see orgFor()}.
	 *
	 * @return array{all: bool, full: array<int, string>}|null
	 *
	 * @spec openspec/specs/agenda-and-resource-booking/spec.md#REQ-AGD-007
	 */
	private function employeeGrant(string $employeeId, array $org): ?array {
		if ($this->seesInFull(employeeId: $employeeId, org: $org) === true) {
			return ['all' => false, 'full' => [$employeeId]];
		}

		$shared = array_intersect(
			$this->unitsOf(employeeIds: [$employeeId], org: $org),
			$this->unitsOf(employeeIds: $org['viewerEmployeeIds'], org: $org)
		);
		if ($shared === []) {
			return null;
		}

		return ['all' => false, 'full' => []];
	}//end employeeGrant()

	/**
	 * The unit's manager sees it in full, a member sees it with their own and
	 * their reports' entries in full, anyone else nothing.
	 *
	 * @param string $orgUnitId The unit asked for.
	 * @param array<string, mixed> $org The viewer and the org indexes, from {@see orgFor()}.
	 *
	 * @return array{all: bool, full: array<int, string>}|null
	 *
	 * @spec openspec/specs/agenda-and-resource-booking/spec.md#REQ-AGD-007
	 */
	private function unitGrant(string $orgUnitId, array $org): ?array {
		$unit = ($org['unitsById'][$orgUnitId] ?? null);
		if (is_array($unit) === false) {
			return null;
		}

		$managerId = trim((string)($unit['managerId'] ?? ''));
		if ($managerId !== '' && in_array($managerId, $org['viewerEmployeeIds'], true) === true) {
			return ['all' => true, 'full' => []];
		}

		if (in_array($orgUnitId, $this->unitsOf(employeeIds: $org['viewerEmployeeIds'], org: $org), true) === false) {
			return null;
		}

		$full = [];
		foreach ($this->members(orgUnitId: $orgUnitId, onDate: $org['today']) as $memberId) {
			if ($this->seesInFull(employeeId: $memberId, org: $org) === true) {
				$full[] = $memberId;
			}
		}

		return ['all' => false, 'full' => $full];
	}//end unitGrant()

	/**
	 * Whether the viewer is the employee or one of the employee's managers.
	 *
	 * @param string $employeeId The employee.
	 * @param array<string, mixed> $org The viewer and the org indexes, from {@see orgFor()}.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/agenda-and-resource-booking/spec.md#REQ-AGD-007
	 */
	private function seesInFull(string $employeeId, array $org): bool {
		if (in_array($employeeId, $org['viewerEmployeeIds'], true) === true) {
			return true;
		}

		$managers = $this->orgResolution->resolveManagerUserIds(
			employeeId: $employeeId,
			assignmentsByEmployeeId: $org['assignmentsByEmployeeId'],
			unitsById: $org['unitsById'],
			employeesById: $org['employeesById'],
			onDate: $org['today']->format('Y-m-d')
		);

		return in_array($org['viewer'], $managers, true);
	}//end seesInFull()

	/**
	 * The units the employees are placed in today.
	 *
	 * @param array<int, string> $employeeIds The employees.
	 * @param array<string, mixed> $org The viewer and the org indexes, from {@see orgFor()}.
	 *
	 * @return array<int, string> The unit ids.
	 *
	 * @spec openspec/specs/agenda-and-resource-booking/spec.md#REQ-AGD-007
	 */
	private function unitsOf(array $employeeIds, array $org): array {
		$units = [];
		foreach ($employeeIds as $employeeId) {
			foreach (($org['assignmentsByEmployeeId'][$employeeId] ?? []) as $assignment) {
				$unitId = trim((string)($assignment['orgUnitId'] ?? ''));
				if ($unitId !== '' && $this->orgResolution->isActiveOn($assignment, $org['today']->format('Y-m-d')) === true) {
					$units[$unitId] = $unitId;
				}
			}
		}

		return array_values($units);
	}//end unitsOf()

	/**
	 * The viewer, their Employee records and the org indexes, read once.
	 *
	 * @param string $viewer The session user.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/specs/agenda-and-resource-booking/spec.md#REQ-AGD-007
	 */
	private function orgFor(string $viewer): array {
		$employeesById = $this->indexById($this->store->loadAll('Employee'));
		$viewerEmployeeIds = [];
		foreach ($employeesById as $id => $employee) {
			if (trim((string)($employee['nextcloudUserId'] ?? '')) === $viewer) {
				$viewerEmployeeIds[] = (string)$id;
			}
		}

		$assignmentsByEmployeeId = [];
		foreach ($this->store->loadAll('OrgAssignment') as $assignment) {
			$assignmentsByEmployeeId[trim((string)($assignment['employeeId'] ?? ''))][] = $assignment;
		}

		return [
			'viewer' => $viewer,
			'viewerEmployeeIds' => $viewerEmployeeIds,
			'employeesById' => $employeesById,
			'unitsById' => $this->indexById($this->store->loadAll('OrgUnit')),
			'assignmentsByEmployeeId' => $assignmentsByEmployeeId,
			'today' => new DateTimeImmutable('today'),
		];
	}//end orgFor()

	/**
	 * Key rows by their id.
	 *
	 * @param array<int, array<string, mixed>> $rows The rows.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function indexById(array $rows): array {
		$index = [];
		foreach ($rows as $row) {
			$index[trim((string)($row['id'] ?? ''))] = $row;
		}

		unset($index['']);

		return $index;
	}//end indexById()

}//end class
