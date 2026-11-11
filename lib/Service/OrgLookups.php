<?php

/**
 * Humaniq OrgLookups
 *
 * Reads the OrgAssignment, OrgUnit and Employee indexes the shared manager
 * resolution needs once per pass, and resolves an employee's one manager
 * from them and that manager's deputies today. Used by the passes that stamp many records in a row.
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
 * @spec openspec/changes/manager-record-access/specs/mss-team-scope/spec.md#Requirement:-A-manager-and-their-deputies-SHALL-read-their-team's-records-through-stamped-access-fields-(REQ-MSS-009)
 */

declare(strict_types=1);

namespace OCA\Humaniq\Service;

/**
 * Read-once org lookups for the manager resolution.
 *
 * @spec openspec/changes/manager-record-access/specs/mss-team-scope/spec.md#Requirement:-A-manager-and-their-deputies-SHALL-read-their-team's-records-through-stamped-access-fields-(REQ-MSS-009)
 */
class OrgLookups {

	/**
	 * Constructor.
	 *
	 * @param HoursRegisterGateway $gateway The register.
	 * @param OrgResolutionService $orgResolution The shared manager resolution.
	 * @param ManagerDeputies $deputies The deputy periods.
	 *
	 * @spec openspec/changes/manager-record-access/specs/mss-team-scope/spec.md#Requirement:-A-manager-and-their-deputies-SHALL-read-their-team's-records-through-stamped-access-fields-(REQ-MSS-009)
	 */
	public function __construct(
		private readonly HoursRegisterGateway $gateway,
		private readonly OrgResolutionService $orgResolution,
		private readonly ManagerDeputies $deputies,
	) {

	}//end __construct()

	/**
	 * The lookups, and whether every read reached the end.
	 *
	 * @return array{assignments: array<string, array<int, array<string, mixed>>>, units: array<string, array<string, mixed>>, employees: array<string, array<string, mixed>>, complete: bool}
	 *
	 * @spec openspec/changes/manager-record-access/specs/mss-team-scope/spec.md#Requirement:-A-manager-and-their-deputies-SHALL-read-their-team's-records-through-stamped-access-fields-(REQ-MSS-009)
	 */
	public function read(): array {
		$assignments = $this->gateway->loadAll('OrgAssignment');
		$units = $this->gateway->loadAll('OrgUnit');
		$employees = $this->gateway->loadAll('Employee');
		$byEmployee = [];
		foreach ($assignments as $assignment) {
			$byEmployee[trim((string)($assignment['employeeId'] ?? ''))][] = $assignment;
		}

		return [
			'assignments' => $byEmployee,
			'units' => self::keyed(rows: $units),
			'employees' => self::keyed(rows: $employees),
			'complete' => (max(count($assignments), count($units), count($employees)) < HoursRegisterGateway::MAX_ROWS),
		];
	}//end read()

	/**
	 * The employee's one resolved manager on a date, or null.
	 *
	 * @param array{assignments: array<string, array<int, array<string, mixed>>>, units: array<string, array<string, mixed>>, employees: array<string, array<string, mixed>>, complete: bool} $lookups Lookups from read().
	 * @param string $employeeId The employee id.
	 * @param string $onDate The reference date, YYYY-MM-DD.
	 *
	 * @return string|null The manager's Nextcloud user id.
	 *
	 * @spec openspec/changes/manager-record-access/specs/mss-team-scope/spec.md#Requirement:-A-manager-and-their-deputies-SHALL-read-their-team's-records-through-stamped-access-fields-(REQ-MSS-009)
	 */
	public function managerOf(array $lookups, string $employeeId, string $onDate): ?string {
		return $this->orgResolution->uniqueOrNull(
			$this->orgResolution->resolveManagerUserIds(
				employeeId: $employeeId,
				assignmentsByEmployeeId: $lookups['assignments'],
				unitsById: $lookups['units'],
				employeesById: $lookups['employees'],
				onDate: $onDate
			)
		);
	}//end managerOf()

	/**
	 * The deputies standing in for a manager on a day.
	 *
	 * @param string $managerUserId The manager's account.
	 * @param string $today The day, YYYY-MM-DD.
	 *
	 * @return list<string>
	 *
	 * @spec openspec/changes/manager-record-access/specs/mss-team-scope/spec.md#Requirement:-A-manager-and-their-deputies-SHALL-read-their-team's-records-through-stamped-access-fields-(REQ-MSS-009)
	 */
	public function deputiesOf(string $managerUserId, string $today): array {
		return $this->deputies->activeDeputiesOf(managerUserId: $managerUserId, today: $today);
	}//end deputiesOf()

	/**
	 * Rows keyed by their id; rows without one are left out.
	 *
	 * @param array<int, array<string, mixed>> $rows The rows.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private static function keyed(array $rows): array {
		$ids = array_map(static fn (array $row): string => trim((string)($row['id'] ?? ($row['@self']['id'] ?? ''))), $rows);

		return array_filter(array_combine($ids, $rows), static fn (int|string $id): bool => $id !== '', ARRAY_FILTER_USE_KEY);
	}//end keyed()

}//end class
