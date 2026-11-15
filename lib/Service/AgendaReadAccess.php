<?php

/**
 * Agenda Read Access
 *
 * Who may read which agenda, and how much of it. An employee's agenda is open
 * to the employee, their managers, HR and the colleagues placed in a unit with
 * them. An org unit's agenda is open to its members, its manager, the managers
 * of the units above it, a deputy standing in for one of those managers today,
 * and HR. Interviews and secondment hours stay with the employee, their
 * managers and HR: a colleague sees the other entries without them. The
 * availability and capacity answers follow the same rule.
 *
 * The viewer is the session user, and every role and placement is read from
 * the register and the Nextcloud groups, never from the request. Placements
 * count when they are active today, whatever window the agenda covers, and a
 * placement whose start or end date cannot be read does not count.
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
	 * Active placements and an employee's managers; stateless rules.
	 *
	 * @var OrgResolutionService
	 */
	private readonly OrgResolutionService $orgResolution;

	/**
	 * Constructor.
	 *
	 * @param IUserSession $userSession The signed-in user.
	 * @param RecordStoreInterface $store Employees, units and placements.
	 * @param HumaniqRoles $roles The HR check.
	 * @param ManagerDeputies $deputies The managers a user stands in for today.
	 */
	public function __construct(
		private readonly IUserSession $userSession,
		private readonly RecordStoreInterface $store,
		private readonly HumaniqRoles $roles,
		private readonly ManagerDeputies $deputies,
	) {
		$this->orgResolution = new OrgResolutionService();

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
		$viewer = $this->viewer();
		if ($viewer === '') {
			return null;
		}

		if ($subjectType === 'resource' || $this->roles->isHr($viewer) === true) {
			return ['all' => true, 'full' => []];
		}

		$org = $this->view($viewer);
		if ($subjectType === 'employee') {
			return $this->employeeGrant(employeeId: $subjectId, org: $org);
		}

		return $this->unitGrant(orgUnitId: $subjectId, org: $org);
	}//end grant()

	/**
	 * The employees an availability or capacity answer may cover for the
	 * session user, and those it covers in full. With a unit: its members,
	 * when the user may read the unit's agenda. Without one: everybody for HR,
	 * otherwise the employees whose agenda the user may read. Null when that
	 * leaves nobody.
	 *
	 * @param string $orgUnitId The unit asked for, or '' for none.
	 * @param DateTimeImmutable $onDate The date a unit placement must be active on.
	 *
	 * @return array{ids: array<int, string>, all: bool, full: array<int, string>}|null The scope, or null when refused.
	 *
	 * @spec openspec/specs/agenda-and-resource-booking/spec.md#REQ-AGD-008
	 */
	public function employeeScope(string $orgUnitId, DateTimeImmutable $onDate): ?array {
		if ($orgUnitId !== '') {
			$grant = $this->grant(subjectType: 'orgUnit', subjectId: $orgUnitId);

			return $grant === null ? null : (['ids' => $this->members(orgUnitId: $orgUnitId, onDate: $onDate)] + $grant);
		}

		$viewer = $this->viewer();
		if ($viewer === '') {
			return null;
		}

		$org = $this->view($viewer);
		if ($this->roles->isHr($viewer) === true) {
			return ['ids' => $org->employeeIds(), 'all' => true, 'full' => []];
		}

		$scope = ['ids' => [], 'all' => false, 'full' => []];
		foreach ($org->employeeIds() as $employeeId) {
			$grant = $this->employeeGrant(employeeId: $employeeId, org: $org);
			if ($grant !== null) {
				$scope['ids'][] = $employeeId;
				$scope['full'] = array_merge($scope['full'], $grant['full']);
			}
		}

		return $scope['ids'] === [] ? null : $scope;
	}//end employeeScope()

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
	 * The source rows an hours answer may count for this scope: for an
	 * employee not covered in full, no secondment and no interview.
	 *
	 * @param array<string, mixed> $sources The rows the answer reads.
	 * @param array{ids: array<int, string>, all: bool, full: array<int, string>} $scope What {@see employeeScope()} answered.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/specs/agenda-and-resource-booking/spec.md#REQ-AGD-008
	 */
	public function visibleSources(array $sources, array $scope): array {
		if ($scope['all'] === true) {
			return $sources;
		}

		return (new AgendaHoursSources($scope['full']))->trim($sources);
	}//end visibleSources()

	/**
	 * The employees placed in one org unit on one date. Anyone but HR gets
	 * only those placed there today as well, so an early window does not
	 * bring back former members.
	 *
	 * @param string $orgUnitId The org unit.
	 * @param DateTimeImmutable $onDate The date the placement must be active on.
	 *
	 * @return array<int, string> The employee ids.
	 *
	 * @spec openspec/specs/agenda-and-resource-booking/spec.md#REQ-AGD-001
	 */
	public function members(string $orgUnitId, DateTimeImmutable $onDate): array {
		$day = $onDate->format('Y-m-d');
		$members = [];
		foreach ($this->store->findFiltered('OrgAssignment', ['orgUnitId' => $orgUnitId]) as $assignment) {
			$employeeId = trim((string)($assignment['employeeId'] ?? ''));
			if ($employeeId !== '' && $this->orgResolution->isActiveOn($assignment, $day) === true) {
				$members[$employeeId] = $employeeId;
			}
		}

		$viewer = $this->viewer();
		if ($this->roles->isHr($viewer) === true) {
			return array_values($members);
		}

		return array_values(array_intersect($members, $this->view($viewer)->placedInToday($orgUnitId)));
	}//end members()

	/**
	 * Self and managers see an employee in full, a colleague in a shared unit
	 * sees the trimmed agenda, anyone else nothing. A subject without an
	 * Employee record is nobody's to see.
	 *
	 * @param string $employeeId The employee asked for.
	 * @param AgendaOrgView $org The viewer's view of the organisation.
	 *
	 * @return array{all: bool, full: array<int, string>}|null
	 *
	 * @spec openspec/specs/agenda-and-resource-booking/spec.md#REQ-AGD-007
	 */
	private function employeeGrant(string $employeeId, AgendaOrgView $org): ?array {
		if ($org->hasEmployee($employeeId) === false) {
			return null;
		}

		if ($org->isSelfOrManagerOf($employeeId) === true) {
			return ['all' => false, 'full' => [$employeeId]];
		}

		return $org->isColleagueOf($employeeId) === true ? ['all' => false, 'full' => []] : null;
	}//end employeeGrant()

	/**
	 * The managers of the unit and of the units above it, and a deputy
	 * standing in for one of them today, see it in full. A member sees it
	 * with their own and their reports' entries in full. Anyone else nothing.
	 *
	 * @param string $orgUnitId The unit asked for.
	 * @param AgendaOrgView $org The viewer's view of the organisation.
	 *
	 * @return array{all: bool, full: array<int, string>}|null
	 *
	 * @spec openspec/specs/agenda-and-resource-booking/spec.md#REQ-AGD-007
	 */
	private function unitGrant(string $orgUnitId, AgendaOrgView $org): ?array {
		$accounts = $this->deputies->managersCoveredBy($this->viewer(), $org->today());
		$accounts[] = $this->viewer();
		if ($org->isManagedBy(orgUnitId: $orgUnitId, accounts: $accounts) === true) {
			return ['all' => true, 'full' => []];
		}

		if ($org->isMemberOf($orgUnitId) === false) {
			return null;
		}

		$full = [];
		foreach ($this->members(orgUnitId: $orgUnitId, onDate: new DateTimeImmutable($org->today())) as $memberId) {
			if ($org->isSelfOrManagerOf($memberId) === true) {
				$full[] = $memberId;
			}
		}

		return ['all' => false, 'full' => $full];
	}//end unitGrant()

	/**
	 * The session user's id, '' when nobody is signed in.
	 *
	 * @return string
	 */
	private function viewer(): string {
		return (string)$this->userSession->getUser()?->getUID();
	}//end viewer()

	/**
	 * The viewer's view of the organisation, read once per question.
	 *
	 * @param string $viewer The session user.
	 *
	 * @return AgendaOrgView
	 */
	private function view(string $viewer): AgendaOrgView {
		$rows = [];
		foreach (['Employee', 'OrgUnit', 'OrgAssignment'] as $schema) {
			$rows[$schema] = $this->store->loadAll($schema);
		}

		return new AgendaOrgView(viewer: $viewer, rows: $rows);
	}//end view()

}//end class
