<?php

/**
 * Humaniq ManagerAccessStamps
 *
 * Stamps `managerUserId` and `deputyUserIds` on the records a manager
 * reads through the schema's own authorization rules: Employee,
 * SickLeaveCase, LeaveTransaction and CompAdjustment. The manager is the
 * one the shared org resolution gives today for the record's employee; the
 * deputies are those of the ManagerDeputy records active today for that
 * manager. Nothing is taken from the incoming record.
 *
 * `stampsFor()` serves the pre-save listener; `run()` is the pass that
 * restamps every record whose stamp differs, used by the repair step, the
 * scheduled refresh and the refresh queued on an org or deputy change.
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

use Psr\Log\LoggerInterface;

/**
 * Resolves and writes the manager access stamps.
 *
 * @spec openspec/changes/manager-record-access/specs/mss-team-scope/spec.md#Requirement:-A-manager-and-their-deputies-SHALL-read-their-team's-records-through-stamped-access-fields-(REQ-MSS-009)
 */
class ManagerAccessStamps {

	/**
	 * The schemas that carry the stamps.
	 *
	 * @var list<string>
	 */
	public const SCHEMAS = ['Employee', 'SickLeaveCase', 'LeaveTransaction', 'CompAdjustment'];

	/**
	 * Constructor.
	 *
	 * @param HoursRegisterGateway $gateway The register.
	 * @param InternalWriteMarker $marker Marks the pass's writes as humaniq's own.
	 * @param ManagerDeputies $deputies The active deputies of a manager.
	 * @param LoggerInterface $logger Records every failure.
	 *
	 * @spec openspec/changes/manager-record-access/specs/mss-team-scope/spec.md#Requirement:-A-manager-and-their-deputies-SHALL-read-their-team's-records-through-stamped-access-fields-(REQ-MSS-009)
	 */
	public function __construct(
		private readonly HoursRegisterGateway $gateway,
		private readonly InternalWriteMarker $marker,
		private readonly ManagerDeputies $deputies,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * The stamps one record should carry today.
	 *
	 * @param array<string, mixed> $record The record as it would be saved.
	 * @param string $schema The schema name, one of SCHEMAS.
	 * @param string $uuid The record's uuid ('' when not known).
	 * @param string $today The day, YYYY-MM-DD.
	 *
	 * @return array{managerUserId: string|null, deputyUserIds: list<string>}
	 *
	 * @spec openspec/changes/manager-record-access/specs/mss-team-scope/spec.md#Requirement:-A-manager-and-their-deputies-SHALL-read-their-team's-records-through-stamped-access-fields-(REQ-MSS-009)
	 */
	public function stampsFor(array $record, string $schema, string $uuid, string $today): array {
		return $this->resolve(employeeId: $this->employeeIdOf(record: $record, schema: $schema, uuid: $uuid), lookups: $this->gateway->orgLookups(), today: $today);
	}//end stampsFor()

	/**
	 * The schema name of a stamped schema id, or null for any other schema.
	 *
	 * @param string $schemaId The schema id an object carries.
	 *
	 * @return string|null
	 *
	 * @spec openspec/changes/manager-record-access/specs/mss-team-scope/spec.md#Requirement:-A-manager-and-their-deputies-SHALL-read-their-team's-records-through-stamped-access-fields-(REQ-MSS-009)
	 */
	public function schemaFor(string $schemaId): ?string {
		$slug = strtolower($this->gateway->resolveSchemaSlug($schemaId));
		foreach (self::SCHEMAS as $schema) {
			if (strtolower($schema) === $slug) {
				return $schema;
			}
		}

		return null;
	}//end schemaFor()

	/**
	 * Run the pass for today and log a pass that could not run.
	 *
	 * @param string $today The day, YYYY-MM-DD.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/manager-record-access/specs/mss-team-scope/spec.md#Requirement:-A-manager-and-their-deputies-SHALL-read-their-team's-records-through-stamped-access-fields-(REQ-MSS-009)
	 */
	public function refresh(string $today): void {
		try {
			$this->run(false, $today);
		} catch (\Throwable $e) {
			$this->logger->warning('humaniq: manager access refresh failed', ['exception' => $e->getMessage()]);
		}
	}//end refresh()

	/**
	 * Restamp every record whose stamps differ, or only count them on a dry run.
	 *
	 * @param bool $dryRun True to count without writing.
	 * @param string $today The day, YYYY-MM-DD.
	 *
	 * @return array<string, array{inspected: int, changed: int, failed: int}> Counts per schema.
	 *
	 * @spec openspec/changes/manager-record-access/specs/mss-team-scope/spec.md#Requirement:-A-manager-and-their-deputies-SHALL-read-their-team's-records-through-stamped-access-fields-(REQ-MSS-009)
	 */
	public function run(bool $dryRun, string $today): array {
		$lookups = $this->gateway->orgLookups();
		$report = [];
		foreach (self::SCHEMAS as $schema) {
			$counts = ['inspected' => 0, 'changed' => 0, 'failed' => 0];
			$rows = $this->gateway->loadAll($schema);
			if (count($rows) >= HoursRegisterGateway::MAX_ROWS) {
				$counts['failed']++;
				$this->logger->error('humaniq: manager access read was cut off', ['schema' => $schema, 'rows' => count($rows)]);
			}

			foreach ($rows as $row) {
				$counts['inspected']++;
				$outcome = $this->restamp(row: $row, schema: $schema, lookups: $lookups, run: ['dryRun' => $dryRun, 'today' => $today]);
				if ($outcome !== '') {
					$counts[$outcome]++;
				}
			}

			$report[$schema] = $counts;
		}//end foreach

		return $report;
	}//end run()

	/**
	 * Restamp one record: 'changed', 'failed', or '' when it stays.
	 *
	 * @param array<string, mixed> $row The stored record.
	 * @param string $schema The schema name.
	 * @param array{assignments: array<string, array<int, array<string, mixed>>>, units: array<string, array<string, mixed>>, employees: array<string, array<string, mixed>>, complete: bool} $lookups The org lookups.
	 * @param array{dryRun: bool, today: string} $run The run's mode and day.
	 *
	 * @return string
	 */
	private function restamp(array $row, string $schema, array $lookups, array $run): string {
		$uuid = trim((string)($row['id'] ?? ($row['@self']['id'] ?? '')));
		try {
			$stamps = $this->resolve(employeeId: $this->employeeIdOf(record: $row, schema: $schema, uuid: $uuid), lookups: $lookups, today: $run['today']);
			if ($uuid === '' || $this->isCurrent(row: $row, stamps: $stamps) === true) {
				return '';
			}

			if ($lookups['complete'] === false) {
				$this->logger->error('humaniq: manager access not restamped, a lookup read was cut off', ['schema' => $schema, 'id' => $uuid]);
				return 'failed';
			}

			if ($run['dryRun'] === false) {
				$record = array_merge($row, $stamps);
				unset($record['@self']);
				$this->marker->runInternal(fn () => $this->gateway->save($record, $schema, $uuid));
			}

			return 'changed';
		} catch (\Throwable $e) {
			$this->logger->error('humaniq: manager access could not be restamped', ['schema' => $schema, 'id' => $uuid, 'error' => $e->getMessage()]);
			return 'failed';
		}//end try
	}//end restamp()

	/**
	 * Whether a stored record already carries these stamps.
	 *
	 * @param array<string, mixed> $row The stored record.
	 * @param array{managerUserId: string|null, deputyUserIds: list<string>} $stamps The stamps it should carry.
	 *
	 * @return bool
	 */
	private function isCurrent(array $row, array $stamps): bool {
		$manager = trim((string)($row['managerUserId'] ?? ''));
		$deputies = array_values(array_filter(array_map(static fn (mixed $uid): string => trim((string)$uid), (array)($row['deputyUserIds'] ?? [])), static fn (string $uid): bool => $uid !== ''));
		sort($deputies);

		return $manager === (string)$stamps['managerUserId'] && $deputies === $stamps['deputyUserIds'];
	}//end isCurrent()

	/**
	 * The manager the org resolution gives today and that manager's
	 * deputies, without the employee's own account.
	 *
	 * @param string $employeeId The record's employee.
	 * @param array{assignments: array<string, array<int, array<string, mixed>>>, units: array<string, array<string, mixed>>, employees: array<string, array<string, mixed>>, complete: bool} $lookups The org lookups.
	 * @param string $today The day, YYYY-MM-DD.
	 *
	 * @return array{managerUserId: string|null, deputyUserIds: list<string>}
	 */
	private function resolve(string $employeeId, array $lookups, string $today): array {
		if ($employeeId === '') {
			return ['managerUserId' => null, 'deputyUserIds' => []];
		}

		$manager = $this->gateway->uniqueManagerUserIdIn($lookups, $employeeId, $today);
		if ($manager === null || $manager === '') {
			return ['managerUserId' => null, 'deputyUserIds' => []];
		}

		$own = trim((string)($lookups['employees'][$employeeId]['nextcloudUserId'] ?? ''));
		$deputies = array_values(array_filter($this->deputies->activeDeputiesOf(managerUserId: $manager, today: $today), static fn (string $uid): bool => $uid !== $own && $uid !== $manager));
		sort($deputies);

		return ['managerUserId' => $manager, 'deputyUserIds' => $deputies];
	}//end resolve()

	/**
	 * The employee a record is about: an Employee is its own.
	 *
	 * @param array<string, mixed> $record The record.
	 * @param string $schema The schema name.
	 * @param string $uuid The record's uuid.
	 *
	 * @return string
	 */
	private function employeeIdOf(array $record, string $schema, string $uuid): string {
		if ($schema === 'Employee') {
			return trim($uuid);
		}

		return trim((string)($record['employeeId'] ?? ''));
	}//end employeeIdOf()

}//end class
