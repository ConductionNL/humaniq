<?php

/**
 * Leave Coverage Warning
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The coverage warning of one leave request: the org unit its employee is
 * placed in today, that unit's members today and every other request, handed
 * to LeaveCoverageService. One composition for the two places that need it:
 * `GET /api/leave/coverage`, which shows the approver the warning before
 * Approve, and LeaveRequestProcessStampListener, which records on approval the
 * warning that was there to be read (REQ-LVM-S02). Both therefore read the
 * same warning for the same request.
 *
 * It reads through the register gateway, which does not apply the caller's
 * RBAC: a caller's right to see the request itself is checked before this
 * runs (the controller's `findOrNull`, the transition's own authorization),
 * and the warning names colleagues only as LeaveCoverageService decides.
 *
 * @category Service
 * @package  OCA\Humaniq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/leave-management/spec.md#REQ-LVM-S02
 */

declare(strict_types=1);

namespace OCA\Humaniq\Service;

use DateTimeImmutable;
use OCA\Humaniq\Kernel\Contracts\LeaveCoverageWarningInterface;

/**
 * Composes the coverage warning of one leave request.
 *
 * @spec openspec/specs/leave-management/spec.md#REQ-LVM-S02
 */
class LeaveCoverageWarning implements LeaveCoverageWarningInterface {

	/**
	 * Constructor.
	 *
	 * @param RecordStoreInterface $gateway       The shared register plumbing.
	 * @param LeaveCoverageService $coverage      Computes the warning.
	 * @param OrgResolutionService $orgResolution The shared org-chain rules (assignment validity).
	 */
	public function __construct(
		private readonly RecordStoreInterface $gateway,
		private readonly LeaveCoverageService $coverage,
		private readonly OrgResolutionService $orgResolution,
	) {
	}//end __construct()

	/**
	 * The warning for one request, as `GET /api/leave/coverage` answers it.
	 *
	 * A requester with no org unit has no administered minimum, which is said
	 * plainly (`reason: no-org-unit`) rather than answered as "coverage is fine".
	 *
	 * @param array<string, mixed>   $request The LeaveRequest about to be approved.
	 * @param DateTimeImmutable|null $today   The day placements must be active on; today when null.
	 *
	 * @return array<string, mixed> `belowMinimum`, `dates`, `message`, and `reason` when there is no unit.
	 *
	 * @spec openspec/specs/leave-management/spec.md#REQ-LVM-S02
	 */
	public function forRequest(array $request, ?DateTimeImmutable $today = null): array {
		$today = ($today ?? new DateTimeImmutable('today'));
		$employeeId = trim((string)($request['employeeId'] ?? ''));
		$orgUnitId = $this->orgUnitOf(employeeId: $employeeId, onDate: $today);
		if ($orgUnitId === null) {
			return [
				'belowMinimum' => false,
				'dates' => [],
				'message' => '',
				'reason' => 'no-org-unit',
			];
		}

		$orgUnit = ($this->gateway->findObjectData($orgUnitId, 'OrgUnit') ?? []);

		return $this->coverage->warning(
			request: $request,
			orgUnit: $orgUnit,
			memberEmployeeIds: $this->members(orgUnitId: $orgUnitId, onDate: $today),
			otherRequests: $this->gateway->loadAll('LeaveRequest')
		);
	}//end forRequest()

	/**
	 * The warning an approver accepts by approving: its message when the
	 * request takes the unit below its minimum, null when there is none.
	 *
	 * @param array<string, mixed>   $request The LeaveRequest being approved.
	 * @param DateTimeImmutable|null $today   The day placements must be active on; today when null.
	 *
	 * @return string|null
	 *
	 * @spec openspec/specs/leave-management/spec.md#REQ-LVM-S02
	 */
	public function acceptedOnApproval(array $request, ?DateTimeImmutable $today = null): ?string {
		$warning = $this->forRequest(request: $request, today: $today);
		if (($warning['belowMinimum'] ?? false) !== true) {
			return null;
		}

		$message = trim((string)($warning['message'] ?? ''));
		if ($message === '') {
			return null;
		}

		return $message;
	}//end acceptedOnApproval()

	/**
	 * The employees placed in one org unit on one date.
	 *
	 * @param string            $orgUnitId The org unit.
	 * @param DateTimeImmutable $onDate    The date the placement must be active on.
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
	 * The org unit one employee is placed in on one date, or null.
	 *
	 * @param string            $employeeId The employee.
	 * @param DateTimeImmutable $onDate     The date the placement must be active on.
	 *
	 * @return string|null The org unit id, or null.
	 *
	 * @spec openspec/specs/leave-management/spec.md#REQ-LVM-S02
	 */
	private function orgUnitOf(string $employeeId, DateTimeImmutable $onDate): ?string {
		if ($employeeId === '') {
			return null;
		}

		foreach ($this->gateway->findFiltered('OrgAssignment', ['employeeId' => $employeeId]) as $assignment) {
			if ($this->orgResolution->isActiveOn($assignment, $onDate->format('Y-m-d')) === false) {
				continue;
			}

			$orgUnitId = trim((string)($assignment['orgUnitId'] ?? ''));
			if ($orgUnitId !== '') {
				return $orgUnitId;
			}
		}

		return null;
	}//end orgUnitOf()
}//end class
