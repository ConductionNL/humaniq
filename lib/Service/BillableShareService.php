<?php

/**
 * Billable Share Service
 *
 * The billable share of approved hours per org unit and per employee for one
 * month (REQ-DSI-010), next to the one ratio per administration of
 * REQ-DSI-002. The hours are those of approved timesheets of the month: each
 * booking counts as billable or not by its own `billable` flag, and a
 * timesheet without bookings counts its own hours by the timesheet's flag. A
 * unit's hours are those of everyone placed in the unit or a unit under it
 * during the month.
 *
 * Who reads what follows AnalyticsAccess: HR and the accountant role read
 * every unit of their active administration; anyone else reads only the
 * units they lead and the people placed in them. A refused read is null.
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
 * @spec openspec/specs/humaniq-dashboard-steering-indicators/spec.md
 */

declare(strict_types=1);

namespace OCA\Humaniq\Service;

use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Billable share per team and per person.
 *
 * @spec openspec/specs/humaniq-dashboard-steering-indicators/spec.md#requirement-the-billable-share-shall-be-readable-per-team-and-per-person-req-dsi-010
 */
final class BillableShareService {

	/**
	 * The schemas the figures read.
	 */
	private const SCHEMAS = ['OrgUnit', 'OrgAssignment', 'Employee', 'Timesheet', 'TimeEntry'];

	/**
	 * Constructor.
	 *
	 * @param AnalyticsService $analytics  Reads the administration's rows.
	 * @param UnitMembership   $membership Unit trees, placements and managed units.
	 * @param AnalyticsAccess  $access     Who may read which units.
	 *
	 * @spec openspec/specs/humaniq-dashboard-steering-indicators/spec.md#requirement-the-billable-share-shall-be-readable-per-team-and-per-person-req-dsi-010
	 */
	public function __construct(
		private readonly AnalyticsService $analytics,
		private readonly UnitMembership $membership,
		private readonly AnalyticsAccess $access,
	) {

	}//end __construct()

	/**
	 * One row per unit the caller may read: its approved and billable hours
	 * and the billable share. Null when the caller has no administration.
	 *
	 * @param string $userId The caller.
	 * @param string $period The month, `YYYY-MM`.
	 *
	 * @return array{period: string, units: list<array<string, mixed>>}|null
	 *
	 * @throws InvalidArgumentException For a period that is not `YYYY-MM`.
	 *
	 * @spec openspec/specs/humaniq-dashboard-steering-indicators/spec.md#requirement-the-billable-share-shall-be-readable-per-team-and-per-person-req-dsi-010
	 */
	public function perUnit(string $userId, string $period): ?array {
		$window = self::window(period: $period);
		$administrationId = $this->access->activeAdministration($userId);
		if ($administrationId === null) {
			return null;
		}

		$data = $this->load(administrationId: $administrationId);
		$unitIds = $this->membership->managedUnitIds($userId, $data['OrgUnit'], $data['Employee']);
		if ($this->access->fullReaderAdministration($userId) !== null) {
			$unitIds = array_map(fn (array $unit): string => $this->membership->rowId($unit), $data['OrgUnit']);
		}

		$hours = self::hoursByEmployee(data: $data, period: $period);
		$rows = [];
		foreach (array_values(array_unique(array_filter($unitIds))) as $unitId) {
			$people = $this->peopleOf(unitId: $unitId, data: $data, window: $window);
			$rows[] = array_merge(
				['id' => $unitId, 'name' => $this->unitName(unitId: $unitId, units: $data['OrgUnit']), 'people' => count($people)],
				self::figures(hours: array_intersect_key($hours, $people))
			);
		}

		usort($rows, static fn (array $a, array $b): int => strcmp((string)$a['name'], (string)$b['name']));

		return ['period' => $period, 'units' => $rows];
	}//end perUnit()

	/**
	 * One row per person placed in the unit (or a unit under it) during the
	 * month. Null when the caller may not read the unit.
	 *
	 * @param string $userId    The caller.
	 * @param string $orgUnitId The unit.
	 * @param string $period    The month, `YYYY-MM`.
	 *
	 * @return array{period: string, orgUnitId: string, name: string, people: list<array<string, mixed>>}|null
	 *
	 * @throws InvalidArgumentException For a period that is not `YYYY-MM`.
	 *
	 * @spec openspec/specs/humaniq-dashboard-steering-indicators/spec.md#requirement-the-billable-share-shall-be-readable-per-team-and-per-person-req-dsi-010
	 */
	public function perEmployee(string $userId, string $orgUnitId, string $period): ?array {
		$window = self::window(period: $period);
		$orgUnitId = trim($orgUnitId);
		$access = $this->access->unitReader($userId, $orgUnitId);
		if ($orgUnitId === '' || $access === null) {
			return null;
		}

		$data = $this->load(administrationId: $access['administrationId']);
		$hours = self::hoursByEmployee(data: $data, period: $period);
		$employees = [];
		foreach ($data['Employee'] as $employee) {
			$employees[$this->membership->rowId($employee)] = $employee;
		}

		$rows = [];
		foreach (array_keys($this->peopleOf(unitId: $orgUnitId, data: $data, window: $window)) as $employeeId) {
			$employee = ($employees[$employeeId] ?? []);
			$rows[] = array_merge(
				[
					'id' => $employeeId,
					'name' => trim((string)($employee['firstName'] ?? '') . ' ' . (string)($employee['lastName'] ?? '')),
					'employeeNumber' => (string)($employee['employeeNumber'] ?? ''),
				],
				self::figures(hours: [$employeeId => ($hours[$employeeId] ?? ['approved' => 0.0, 'billable' => 0.0])])
			);
		}

		usort($rows, static fn (array $a, array $b): int => strcmp((string)$a['name'], (string)$b['name']));

		return ['period' => $period, 'orgUnitId' => $orgUnitId, 'name' => $this->unitName(unitId: $orgUnitId, units: $data['OrgUnit']), 'people' => $rows];
	}//end perEmployee()

	/**
	 * Approved and billable hours per employee in the month's approved timesheets.
	 *
	 * @param array<string, list<array<string, mixed>>> $data   The rows.
	 * @param string                                    $period The month.
	 *
	 * @return array<string, array{approved: float, billable: float}>
	 */
	private static function hoursByEmployee(array $data, string $period): array {
		$sheets = [];
		foreach ($data['Timesheet'] as $sheet) {
			if ((string)($sheet['status'] ?? '') === 'approved' && (string)($sheet['period'] ?? '') === $period) {
				$sheets[(string)($sheet['id'] ?? $sheet['@self']['id'] ?? '')] = $sheet;
			}
		}

		$entries = [];
		foreach ($data['TimeEntry'] as $entry) {
			$sheetId = (string)($entry['timesheetId'] ?? '');
			if ($sheetId !== '' && isset($sheets[$sheetId]) === true) {
				$entries[$sheetId][] = $entry;
			}
		}

		$hours = [];
		foreach ($sheets as $sheetId => $sheet) {
			$employeeId = trim((string)($sheet['employeeId'] ?? ''));
			$hours[$employeeId] ??= ['approved' => 0.0, 'billable' => 0.0];
			$bookings = ($entries[$sheetId] ?? [['hours' => ($sheet['hours'] ?? 0), 'billable' => ($sheet['billable'] ?? false)]]);
			foreach ($bookings as $booking) {
				$worked = (float)($booking['hours'] ?? 0);
				$hours[$employeeId]['approved'] += $worked;
				if (($booking['billable'] ?? false) === true) {
					$hours[$employeeId]['billable'] += $worked;
				}
			}
		}

		return $hours;
	}//end hoursByEmployee()

	/**
	 * Summed hours and the billable share as a percentage to one decimal;
	 * null without approved hours, which is not the same as 0%.
	 *
	 * @param array<string, array{approved: float, billable: float}> $hours Hours per employee.
	 *
	 * @return array{approvedHours: float, billableHours: float, billableShare: float|null}
	 */
	private static function figures(array $hours): array {
		$approved = round(array_sum(array_column($hours, 'approved')), 2);
		$billable = round(array_sum(array_column($hours, 'billable')), 2);

		return [
			'approvedHours' => $approved,
			'billableHours' => $billable,
			'billableShare' => ($approved <= 0.0) ? null : round((($billable / $approved) * 100), 1),
		];
	}//end figures()

	/**
	 * The people placed in the unit or a unit under it during the window.
	 *
	 * @param string                                       $unitId The unit.
	 * @param array<string, list<array<string, mixed>>>    $data   The rows.
	 * @param array{0: DateTimeImmutable, 1: DateTimeImmutable} $window The month.
	 *
	 * @return array<string, float> Employee id to share of the month.
	 */
	private function peopleOf(string $unitId, array $data, array $window): array {
		$subtree = $this->membership->subtree($unitId, $data['OrgUnit']);

		return array_filter($this->membership->sharesInWindow($subtree, $data['OrgAssignment'], $window[0], $window[1]), static fn (float $share): bool => $share > 0.0);
	}//end peopleOf()

	/**
	 * A unit's name, or its id when the unit is unknown.
	 *
	 * @param string                     $unitId The unit.
	 * @param list<array<string, mixed>> $units  OrgUnit records.
	 *
	 * @return string
	 */
	private function unitName(string $unitId, array $units): string {
		foreach ($units as $unit) {
			if ($this->membership->rowId($unit) === $unitId) {
				return (string)($unit['name'] ?? $unitId);
			}
		}

		return $unitId;
	}//end unitName()

	/**
	 * The first and last day of the month.
	 *
	 * @param string $period The month, `YYYY-MM`.
	 *
	 * @return array{0: DateTimeImmutable, 1: DateTimeImmutable}
	 *
	 * @throws InvalidArgumentException For a period that is not `YYYY-MM`.
	 */
	private static function window(string $period): array {
		if (preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $period) !== 1) {
			throw new InvalidArgumentException('Invalid period');
		}

		$start = new DateTimeImmutable($period . '-01');

		return [$start, $start->modify('last day of this month')];
	}//end window()

	/**
	 * The rows the figures read, for the administration.
	 *
	 * @param string $administrationId The authorised administration.
	 *
	 * @return array<string, list<array<string, mixed>>>
	 */
	private function load(string $administrationId): array {
		$data = [];
		foreach (self::SCHEMAS as $schema) {
			$data[$schema] = array_values($this->analytics->rowsFor($schema, $administrationId));
		}

		return $data;
	}//end load()
}//end class
