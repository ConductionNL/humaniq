<?php

/**
 * Leave Schedule Controller
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Two guarded reads behind the department schedule: the schedule itself, and
 * the coverage warning an approver is shown before they approve.
 *
 * WHY THE SCHEDULE IS AN ENDPOINT AND NOT A STORED OBJECT
 * -------------------------------------------------------
 * A stored schedule is a copy of the requests, and a copy drifts: a withdrawn
 * request keeps standing on the month until something syncs it away, and the
 * approver decides against a month that is not the month. Composed on read,
 * a withdrawal is gone from the next read with nothing in between
 * (REQ-LVM-S01).
 *
 * HOW ACCESS IS DECIDED
 * ---------------------
 * By the existing team-scope rules, not by this controller. Every request is
 * resolved through OpenRegister's ObjectService under the caller's own ambient
 * RBAC, and whatever that refuses is what the schedule redacts to
 * unavailability. A view that invented its own scoping would be a second
 * answer to a question `mss-team-scope` already answers, and the two would
 * drift apart the first time either changed.
 *
 * @category Controller
 * @package  OCA\Humaniq\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/leave-management/spec.md#REQ-LVM-S01
 */

declare(strict_types=1);

namespace OCA\Humaniq\Controller;

use DateTimeImmutable;
use OCA\Humaniq\AppInfo\Application;
use OCA\Humaniq\Service\AgendaReadAccess;
use OCA\Humaniq\Service\DepartmentLeaveScheduleService;
use OCA\Humaniq\Service\RecordStoreInterface;
use OCA\Humaniq\Service\LeaveCoverageService;
use OCA\Humaniq\Service\OrgResolutionService;
use OCA\Humaniq\Service\RbacObjectReader;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCA\Humaniq\Support\ResolvesAgendaWindow;

/**
 * Reads one org unit's leave schedule, and the coverage warning for approving
 * one request on it.
 *
 * @spec openspec/specs/leave-management/spec.md#REQ-LVM-S01
 */
class LeaveScheduleController extends Controller {

	use ResolvesAgendaWindow;

	/**
	 * @param IRequest $request The request object.
	 * @param RecordStoreInterface $gateway The shared register plumbing.
	 * @param DepartmentLeaveScheduleService $schedule Composes the schedule on read.
	 * @param LeaveCoverageService $coverage Computes the coverage warning.
	 * @param OrgResolutionService $orgResolution The shared org-chain rules (assignment validity).
	 * @param RbacObjectReader $rbac Reads one object under the caller's own ambient RBAC.
	 * @param AgendaReadAccess $access Who may read an org unit's agenda.
	 */
	public function __construct(
		IRequest $request,
		private readonly RecordStoreInterface $gateway,
		private readonly DepartmentLeaveScheduleService $schedule,
		private readonly LeaveCoverageService $coverage,
		private readonly OrgResolutionService $orgResolution,
		private readonly RbacObjectReader $rbac,
		private readonly AgendaReadAccess $access,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);

	}//end __construct()

	/**
	 * `GET /api/leave/schedule` — one org unit's leave over a period.
	 *
	 * @param string|null $orgUnitId The org unit to read.
	 * @param string|null $from First day of the period (ISO date).
	 * @param string|null $to Last day of the period (ISO date).
	 *
	 * Open to whoever may read the unit's agenda: its members, its managers
	 * and their deputies, and HR.
	 *
	 * @return JSONResponse The entries, 400 when the parameters are unusable, 403 when the caller may not read the unit.
	 *
	 * @spec openspec/specs/leave-management/spec.md#REQ-LVM-S01
	 */
	#[NoAdminRequired]
	public function schedule(?string $orgUnitId = null, ?string $from = null, ?string $to = null): JSONResponse {
		$orgUnitId = trim((string)$orgUnitId);
		if ($orgUnitId === '') {
			return new JSONResponse(['error' => 'orgUnitId is verplicht.'], Http::STATUS_BAD_REQUEST);
		}

		$window = $this->window(from: $from, to: $to);
		if ($window === null) {
			return self::unusableWindow();
		}

		$scope = $this->access->employeeScope(orgUnitId: $orgUnitId, onDate: $window[0]);
		if ($scope === null) {
			return new JSONResponse(['error' => 'Je hebt geen toegang tot het verlofrooster van deze afdeling.'], Http::STATUS_FORBIDDEN);
		}

		$members = $scope['ids'];
		$requests = $this->gateway->loadAll('LeaveRequest');

		return new JSONResponse([
			'orgUnitId' => $orgUnitId,
			'from' => $window[0]->format('Y-m-d'),
			'to' => $window[1]->format('Y-m-d'),
			'memberCount' => count($members),
			'entries' => $this->schedule->compose(
				requests: $requests,
				memberEmployeeIds: $members,
				from: $window[0],
				to: $window[1],
				visibleRequestIds: $this->visibleRequestIds(requests: $requests)
			),
		]);
	}//end schedule()

	/**
	 * `GET /api/leave/coverage` — what approving one request would leave the
	 * department with. A warning, never a refusal (REQ-LVM-S02).
	 *
	 * @param string|null $leaveRequestId The request about to be approved.
	 *
	 * @return JSONResponse The warning, or 404 when the request does not resolve for this caller.
	 *
	 * @spec openspec/specs/leave-management/spec.md#REQ-LVM-S02
	 */
	#[NoAdminRequired]
	public function coverage(?string $leaveRequestId = null): JSONResponse {
		$leaveRequestId = trim((string)$leaveRequestId);
		if ($leaveRequestId === '') {
			return new JSONResponse(['error' => 'leaveRequestId is verplicht.'], Http::STATUS_BAD_REQUEST);
		}

		// No-admin-idor guard (ADR-005 Rule 3): the request must resolve under
		// the caller's own RBAC before anything about their department is read.
		// Unknown and unauthorised collapse to the same 404, so existence is
		// never leaked.
		$request = $this->rbac->findOrNull(id: $leaveRequestId, schema: 'LeaveRequest');
		if ($request === null) {
			return new JSONResponse(['error' => 'Verlofaanvraag niet gevonden.'], Http::STATUS_NOT_FOUND);
		}

		$employeeId = trim((string)($request['employeeId'] ?? ''));
		$orgUnitId = $this->orgUnitOf(employeeId: $employeeId);
		if ($orgUnitId === null) {
			// No unit, no administered minimum, so no warning. Said plainly
			// rather than answered as "coverage is fine".
			return new JSONResponse([
				'belowMinimum' => false,
				'dates' => [],
				'message' => '',
				'reason' => 'no-org-unit',
			]);
		}

		$orgUnit = ($this->gateway->findObjectData($orgUnitId, 'OrgUnit') ?? []);

		return new JSONResponse(
			$this->coverage->warning(
				request: $request,
				orgUnit: $orgUnit,
				memberEmployeeIds: $this->members(orgUnitId: $orgUnitId, onDate: new DateTimeImmutable('today')),
				otherRequests: $this->gateway->loadAll('LeaveRequest')
			)
		);
	}//end coverage()

	/**
	 * The employees placed in one org unit on one date.
	 *
	 * @param string $orgUnitId The org unit.
	 * @param DateTimeImmutable $onDate The date the placement must be active on.
	 *
	 * @return array<int, string> The employee ids.
	 *
	 * @spec openspec/specs/leave-management/spec.md#REQ-LVM-S01
	 */
	private function members(string $orgUnitId, DateTimeImmutable $onDate): array {
		$assignments = $this->gateway->findFiltered('OrgAssignment', ['orgUnitId' => $orgUnitId]);

		$members = [];
		foreach ($assignments as $assignment) {
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
	 * The org unit one employee is placed in, or null.
	 *
	 * @param string $employeeId The employee.
	 *
	 * @return string|null The org unit id, or null.
	 *
	 * @spec openspec/specs/leave-management/spec.md#REQ-LVM-S02
	 */
	private function orgUnitOf(string $employeeId): ?string {
		if ($employeeId === '') {
			return null;
		}

		$today = (new DateTimeImmutable('today'))->format('Y-m-d');
		foreach ($this->gateway->findFiltered('OrgAssignment', ['employeeId' => $employeeId]) as $assignment) {
			if ($this->orgResolution->isActiveOn($assignment, $today) === false) {
				continue;
			}

			$orgUnitId = trim((string)($assignment['orgUnitId'] ?? ''));
			if ($orgUnitId !== '') {
				return $orgUnitId;
			}
		}

		return null;
	}//end orgUnitOf()

	/**
	 * The ids of the requests this caller may see in full.
	 *
	 * Resolved one by one through the caller's own ambient RBAC, so the answer
	 * is the team-scope rules' answer rather than this view's. The gateway's
	 * own reads are deliberately NOT used here: they pass `_rbac: false`, which
	 * would make every request visible to everybody and the redaction below a
	 * decoration.
	 *
	 * @param array<array<string, mixed>> $requests The requests on the schedule.
	 *
	 * @return array<int, string> The visible ids.
	 *
	 * @spec openspec/specs/leave-management/spec.md#REQ-LVM-S01
	 */
	private function visibleRequestIds(array $requests): array {
		$visible = [];
		foreach ($requests as $request) {
			$id = trim((string)($request['id'] ?? ''));
			if ($id === '') {
				continue;
			}

			if ($this->rbac->findOrNull(id: $id, schema: 'LeaveRequest') !== null) {
				$visible[] = $id;
			}
		}

		return $visible;
	}//end visibleRequestIds()
}//end class
