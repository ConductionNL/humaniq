<?php

/**
 * Humaniq LearningPeopleFeed
 *
 * The one people feed a learning platform reads (talent-training-and-lms
 * D3): per active employee their name, Nextcloud account, current unit and
 * role, managers, employment dates and when any of that last changed. It is
 * a projection over Employee, OrgAssignment and OrgUnit, so a platform makes
 * one call instead of three reads and a join, and never sees any other
 * employee field: no BSN, no salary, no bank account.
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
 * @spec openspec/specs/training-and-lms-sync/spec.md#REQ-TRN-002
 */

declare(strict_types=1);

namespace OCA\Humaniq\Service;

/**
 * Composes the people feed for learning platforms.
 *
 * @spec openspec/specs/training-and-lms-sync/spec.md#REQ-TRN-002
 */
class LearningPeopleFeed {

	/**
	 * Constructor.
	 *
	 * @param HoursRegisterGateway $gateway       Reads humaniq's register.
	 * @param OrgResolutionService $orgResolution Placements and managers.
	 */
	public function __construct(
		private readonly HoursRegisterGateway $gateway,
		private readonly OrgResolutionService $orgResolution,
	) {

	}//end __construct()

	/**
	 * The active employees on a day, optionally only those changed after a moment.
	 *
	 * @param string      $today         The day, `YYYY-MM-DD`.
	 * @param string|null $modifiedSince Only rows whose employee, placement or unit changed after this moment.
	 *
	 * @return list<array<string, mixed>>
	 *
	 * @spec openspec/specs/training-and-lms-sync/spec.md#REQ-TRN-002
	 */
	public function people(string $today, ?string $modifiedSince=null): array {
		$indexes = $this->indexes(today: $today);
		$since = $this->timestamp($modifiedSince);
		$rows = [];
		foreach (array_keys($indexes->employeesById) as $employeeId) {
			$subject = $this->subjectOf(indexes: $indexes, employeeId: $employeeId);
			if ($subject !== null && $this->changedSince(subject: $subject, since: $since) === true) {
				$rows[] = $this->row(subject: $subject, indexes: $indexes);
			}
		}

		return $rows;
	}//end people()

	/**
	 * The employees, units and placements by id, read in that order, for one day.
	 *
	 * @param string $today The day, `YYYY-MM-DD`.
	 *
	 * @return PeopleFeedIndexes
	 */
	private function indexes(string $today): PeopleFeedIndexes {
		$employeesById = $this->indexById($this->gateway->loadAll('Employee'));
		$unitsById = $this->indexById($this->gateway->loadAll('OrgUnit'));
		$assignmentsByEmployeeId = [];
		foreach ($this->gateway->loadAll('OrgAssignment') as $assignment) {
			$assignmentsByEmployeeId[trim((string)($assignment['employeeId'] ?? ''))][] = $assignment;
		}

		return new PeopleFeedIndexes(employeesById: $employeesById, unitsById: $unitsById, assignmentsByEmployeeId: $assignmentsByEmployeeId, today: $today);
	}//end indexes()

	/**
	 * An active employee with their current placement and its unit, or null for someone not active that day.
	 *
	 * @param PeopleFeedIndexes $indexes The indexes and the day.
	 * @param string $employeeId The employee.
	 *
	 * @return PeopleFeedSubject|null
	 */
	private function subjectOf(PeopleFeedIndexes $indexes, string $employeeId): PeopleFeedSubject|null {
		$employee = $indexes->employeesById[$employeeId];
		if ($this->orgResolution->isActiveOn($employee, $indexes->today) === false) {
			return null;
		}

		$placement = $this->currentPlacement(($indexes->assignmentsByEmployeeId[$employeeId] ?? []), $indexes->today);
		$unit = $placement === null ? null : ($indexes->unitsById[trim((string)($placement['orgUnitId'] ?? ''))] ?? null);

		return new PeopleFeedSubject(employeeId: $employeeId, employee: $employee, placement: $placement, unit: $unit);
	}//end subjectOf()

	/**
	 * Whether the subject's records changed after the cut-off; always true without one, or without a modified time.
	 *
	 * @param PeopleFeedSubject $subject The employee, placement and unit.
	 * @param int|null $since The cut-off, as a timestamp.
	 *
	 * @return bool
	 */
	private function changedSince(PeopleFeedSubject $subject, int|null $since): bool {
		$modified = $this->latest($subject->records());

		return $since === null || $modified === null || ($this->timestamp($modified) ?? 0) > $since;
	}//end changedSince()

	/**
	 * One feed row.
	 *
	 * @param PeopleFeedSubject $subject The employee, the current placement and its unit.
	 * @param PeopleFeedIndexes $indexes The indexed rows and the day, for the managers.
	 *
	 * @return array<string, mixed>
	 */
	private function row(PeopleFeedSubject $subject, PeopleFeedIndexes $indexes): array {
		$employee = $subject->employee;

		return [
			'id' => $subject->employeeId,
			'firstName' => $this->textOrNull($employee['firstName'] ?? null),
			'lastName' => $this->textOrNull($employee['lastName'] ?? null),
			'nextcloudUserId' => $this->textOrNull($employee['nextcloudUserId'] ?? null),
			'orgUnit' => $subject->unit === null ? null : ['id' => (string)$subject->unit['id'], 'name' => $this->textOrNull($subject->unit['name'] ?? null)],
			'role' => $subject->placement === null ? null : $this->textOrNull($subject->placement['role'] ?? null),
			'managerUserIds' => $this->orgResolution->resolveManagerUserIds(
				employeeId: $subject->employeeId,
				assignmentsByEmployeeId: $indexes->assignmentsByEmployeeId,
				unitsById: $indexes->unitsById,
				employeesById: $indexes->employeesById,
				onDate: $indexes->today
			),
			'startDate' => $this->textOrNull($employee['startDate'] ?? null),
			'endDate' => $this->textOrNull($employee['endDate'] ?? null),
			'modified' => $this->latest($subject->records()),
		];
	}//end row()

	/**
	 * The placement active on the day that started last.
	 *
	 * @param list<array<string, mixed>> $assignments The employee's placements.
	 * @param string                     $today       The day.
	 *
	 * @return array<string, mixed>|null
	 */
	private function currentPlacement(array $assignments, string $today): ?array {
		$current = null;
		foreach ($assignments as $assignment) {
			if ($this->orgResolution->isActiveOn($assignment, $today) === false) {
				continue;
			}

			if ($current === null || (string)($assignment['startDate'] ?? '') > (string)($current['startDate'] ?? '')) {
				$current = $assignment;
			}
		}

		return $current;
	}//end currentPlacement()

	/**
	 * The latest `@self.updated` of the given rows.
	 *
	 * @param array<int, array<string, mixed>|null> $rows The employee, placement and unit.
	 *
	 * @return string|null
	 */
	private function latest(array $rows): ?string {
		$latest = null;
		foreach ($rows as $row) {
			$updated = $this->textOrNull($row['@self']['updated'] ?? null);
			if ($updated !== null && ($latest === null || ($this->timestamp($updated) ?? 0) > ($this->timestamp($latest) ?? 0))) {
				$latest = $updated;
			}
		}

		return $latest;
	}//end latest()

	/**
	 * Rows keyed by id.
	 *
	 * @param list<array<string, mixed>> $rows The rows.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function indexById(array $rows): array {
		$index = [];
		foreach ($rows as $row) {
			$id = trim((string)($row['id'] ?? $row['@self']['id'] ?? ''));
			if ($id !== '') {
				$index[$id] = $row;
			}
		}

		return $index;
	}//end indexById()

	/**
	 * A moment as a Unix timestamp, or null when absent or unreadable.
	 *
	 * @param string|null $moment The moment.
	 *
	 * @return int|null
	 */
	private function timestamp(?string $moment): ?int {
		if ($moment === null || trim($moment) === '') {
			return null;
		}

		$time = strtotime($moment);

		return $time === false ? null : $time;
	}//end timestamp()

	/**
	 * A trimmed non-empty string, or null.
	 *
	 * @param mixed $value The raw value.
	 *
	 * @return string|null
	 */
	private function textOrNull(mixed $value): ?string {
		$trimmed = trim((string)($value ?? ''));

		return $trimmed === '' ? null : $trimmed;
	}//end textOrNull()

}//end class
