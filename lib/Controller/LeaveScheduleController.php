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

use OCA\Humaniq\AppInfo\Application;
use OCA\Humaniq\Kernel\Contracts\AgendaReadAccessInterface;
use OCA\Humaniq\Kernel\Contracts\DepartmentLeaveScheduleServiceInterface;
use OCA\Humaniq\Kernel\Contracts\LeaveCoverageWarningInterface;
use OCA\Humaniq\Service\RecordStoreInterface;
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
	 * @param DepartmentLeaveScheduleServiceInterface $schedule Composes the schedule on read.
	 * @param LeaveCoverageWarningInterface $warnings The coverage warning of one request.
	 * @param RbacObjectReader $rbac Reads one object under the caller's own ambient RBAC.
	 * @param AgendaReadAccessInterface $access Who may read an org unit's agenda.
	 */
	public function __construct(
		IRequest $request,
		private readonly RecordStoreInterface $gateway,
		private readonly DepartmentLeaveScheduleServiceInterface $schedule,
		private readonly LeaveCoverageWarningInterface $warnings,
		private readonly RbacObjectReader $rbac,
		private readonly AgendaReadAccessInterface $access,
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

		// The composition is shared with the approval stamp, so the warning
		// recorded on approval is the one shown here (REQ-LVM-S02).
		return new JSONResponse($this->warnings->forRequest(request: $request));
	}//end coverage()

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
