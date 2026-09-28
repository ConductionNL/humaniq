<?php

/**
 * Case Manager Resolver
 *
 * The recipients of a sickness case's reminders and its frequent-absence
 * signal (absence-deadlines-and-signals design.md D2): the HR accounts of the
 * case's administration (AdministrationAccess rows with role `hr`) and the
 * employee's managers from their org placement today
 * (OrgResolutionService::resolveManagerUserIds). HR is a role per
 * administration in humaniq, not a Nextcloud group, which is why this one
 * resolver covers both. The employee is never a recipient.
 *
 * Declared as `{kind: expression, resolver: <this FQCN>}` in
 * SickLeaveCase's x-openregister-notifications; OpenRegister resolves it from
 * the server container.
 *
 * @category Notification
 * @package  OCA\Humaniq\Notification
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
 * @spec openspec/specs/absence-deadlines-and-signals/spec.md#REQ-ADS-001
 */

declare(strict_types=1);

namespace OCA\Humaniq\Notification;

use OCA\Humaniq\Service\HoursRegisterGateway;
use OCA\Humaniq\Service\OrgResolutionService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\Notification\RecipientResolverInterface;

/**
 * HR of the administration and the employee's managers.
 *
 * @spec openspec/specs/absence-deadlines-and-signals/spec.md#REQ-ADS-001
 */
class CaseManagerResolver implements RecipientResolverInterface {

	/**
	 * @param HoursRegisterGateway $gateway Reads access rows, placements, units and employees.
	 * @param OrgResolutionService $orgResolution Resolves an employee's managers.
	 */
	public function __construct(
		private readonly HoursRegisterGateway $gateway,
		private readonly OrgResolutionService $orgResolution,
	) {

	}//end __construct()

	/**
	 * The uids to notify about this case.
	 *
	 * @param ObjectEntity $object The SickLeaveCase.
	 * @param array<string, mixed> $context Trigger extras (unused: the case decides).
	 *
	 * @return array<int, string>
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) $context is part of OpenRegister's interface.
	 *
	 * @spec openspec/specs/absence-deadlines-and-signals/spec.md#REQ-ADS-001
	 */
	public function resolve(ObjectEntity $object, array $context): array {
		$case = ($object->getObject() ?? []);
		$employeeId = trim((string)($case['employeeId'] ?? ''));
		$administrationId = trim((string)($case['administrationId'] ?? ''));

		$uids = [];
		if ($administrationId !== '') {
			foreach ($this->gateway->findFiltered('AdministrationAccess', ['administrationId' => $administrationId]) as $access) {
				if (trim((string)($access['role'] ?? '')) === 'hr') {
					$uids[] = trim((string)($access['userId'] ?? ''));
				}
			}
		}

		if ($employeeId !== '') {
			$uids = array_merge($uids, $this->managersOf($employeeId));
		}

		$own = $this->accountOf($employeeId);
		return array_values(array_unique(array_filter($uids, static fn (string $uid): bool => $uid !== '' && $uid !== $own)));
	}//end resolve()

	/**
	 * The employee's managers from their placement today.
	 *
	 * @param string $employeeId The employee.
	 *
	 * @return array<int, string>
	 */
	private function managersOf(string $employeeId): array {
		$assignments = [];
		foreach ($this->gateway->loadAll('OrgAssignment') as $assignment) {
			if (trim((string)($assignment['employeeId'] ?? '')) === $employeeId) {
				$assignments[$employeeId][] = $assignment;
			}
		}

		return $this->orgResolution->resolveManagerUserIds(
			employeeId: $employeeId,
			assignmentsByEmployeeId: $assignments,
			unitsById: $this->indexById($this->gateway->loadAll('OrgUnit')),
			employeesById: $this->indexById($this->gateway->loadAll('Employee')),
			onDate: gmdate('Y-m-d')
		);
	}//end managersOf()

	/**
	 * The employee's own account, so it can be left out.
	 *
	 * @param string $employeeId The employee.
	 *
	 * @return string
	 */
	private function accountOf(string $employeeId): string {
		foreach ($this->gateway->loadAll('Employee') as $employee) {
			if ((string)($employee['id'] ?? '') === $employeeId) {
				return trim((string)($employee['nextcloudUserId'] ?? ''));
			}
		}

		return '';
	}//end accountOf()

	/**
	 * Rows keyed by id.
	 *
	 * @param array<int, array<string, mixed>> $rows The rows.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function indexById(array $rows): array {
		$index = [];
		foreach ($rows as $row) {
			$index[(string)($row['id'] ?? '')] = $row;
		}

		return $index;
	}//end indexById()

}//end class
