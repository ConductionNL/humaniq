<?php

/**
 * Timesheet identity caches
 *
 * The denormalized `userId` / `managerUserId` / `administrationId` a
 * timesheet should carry, resolved from one migration run's employee,
 * unit and placement indexes.
 *
 * @category Repair
 * @package  OCA\Humaniq\Repair
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
 * @spec openspec/changes/humaniq-hours-process-redesign/specs/mijn-hr-self-service/spec.md#REQ-MHS-002:-Timesheet,-Expense,-LeaveRequest-and-Payslip-SHALL-carry-an-optional-denormalized-userId
 */

declare(strict_types=1);

namespace OCA\Humaniq\Repair;

use OCA\Humaniq\Service\OrgResolutionService;

/**
 * Resolves the identity caches of a timesheet against one run's indexes.
 *
 * @spec openspec/changes/humaniq-hours-process-redesign/specs/mijn-hr-self-service/spec.md#REQ-MHS-002:-Timesheet,-Expense,-LeaveRequest-and-Payslip-SHALL-carry-an-optional-denormalized-userId
 */
final class TimesheetIdentityCaches {

	/**
	 * @param OrgResolutionService $orgResolution The shared org-chain resolver.
	 * @param array<string, array<string, mixed>> $employeesById Employee index.
	 * @param array<string, array<string, mixed>> $unitsById OrgUnit index.
	 * @param array<string, array<int, array<string, mixed>>> $assignmentsByEmployeeId OrgAssignment index.
	 */
	public function __construct(
		private readonly OrgResolutionService $orgResolution,
		private readonly array $employeesById,
		private readonly array $unitsById,
		private readonly array $assignmentsByEmployeeId,
	) {
	}//end __construct()

	/**
	 * The caches the timesheet's employee resolves to today: their own
	 * account and administration, and the one manager account of their
	 * active placements (null when none or several resolve).
	 *
	 * @param array<string, mixed> $timesheet The timesheet payload.
	 *
	 * @return array{userId: string|null, managerUserId: string|null, administrationId: string|null}
	 *
	 * @spec openspec/changes/humaniq-hours-process-redesign/specs/mijn-hr-self-service/spec.md#REQ-MHS-002:-Timesheet,-Expense,-LeaveRequest-and-Payslip-SHALL-carry-an-optional-denormalized-userId
	 */
	public function resolve(array $timesheet): array {
		$employeeId = trim((string)($timesheet['employeeId'] ?? ''));
		$employee = ($this->employeesById[$employeeId] ?? []);

		return [
			'userId' => $this->cacheValue($employee['nextcloudUserId'] ?? null),
			'managerUserId' => $this->orgResolution->uniqueOrNull(
				$this->orgResolution->resolveManagerUserIds(
					employeeId: $employeeId,
					assignmentsByEmployeeId: $this->assignmentsByEmployeeId,
					unitsById: $this->unitsById,
					employeesById: $this->employeesById,
					onDate: gmdate('Y-m-d')
				)
			),
			'administrationId' => $this->cacheValue($employee['administrationId'] ?? null),
		];
	}//end resolve()

	/**
	 * A cache value: the trimmed text, or null when nothing is left.
	 *
	 * @param mixed $value The raw value.
	 *
	 * @return string|null
	 */
	private function cacheValue(mixed $value): ?string {
		$text = trim((string)($value ?? ''));

		return ($text !== '') ? $text : null;
	}//end cacheValue()

}//end class
