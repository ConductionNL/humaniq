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
	 * The day the current resolution is for, YYYY-MM-DD.
	 *
	 * @var string
	 */
	private string $today = '';

	/**
	 * Whether the current pass only counts.
	 *
	 * @var bool
	 */
	private bool $dryRun = true;

	/**
	 * Constructor.
	 *
	 * @param HoursRegisterGateway $gateway The register.
	 * @param InternalWriteMarker $marker Marks the pass's writes as humaniq's own.
	 * @param OrgLookups $org The org lookups, managers and deputies.
	 * @param LoggerInterface $logger Records every failure.
	 *
	 * @spec openspec/changes/manager-record-access/specs/mss-team-scope/spec.md#Requirement:-A-manager-and-their-deputies-SHALL-read-their-team's-records-through-stamped-access-fields-(REQ-MSS-009)
	 */
	public function __construct(
		private readonly HoursRegisterGateway $gateway,
		private readonly InternalWriteMarker $marker,
		private readonly OrgLookups $org,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * The stamps one record should carry today. The record's `id` names an
	 * Employee record's own employee.
	 *
	 * @param array<string, mixed> $record The record as it would be saved.
	 * @param string $schema The schema name, one of SCHEMAS.
	 *
	 * @return array{managerUserId: string|null, deputyUserIds: list<string>}
	 *
	 * @spec openspec/changes/manager-record-access/specs/mss-team-scope/spec.md#Requirement:-A-manager-and-their-deputies-SHALL-read-their-team's-records-through-stamped-access-fields-(REQ-MSS-009)
	 */
	public function stampsFor(array $record, string $schema): array {
		$this->today = gmdate('Y-m-d');
		$this->org->read();

		return $this->resolve(employeeId: $this->employeeIdOf(record: $record, schema: $schema));
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
		$this->dryRun = $dryRun;
		$this->today = $today;
		$complete = $this->org->read()['complete'];
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
				$outcome = $this->restamp(row: $row, schema: $schema, complete: $complete);
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
	 * @param bool $complete Whether every org lookup read reached the end.
	 *
	 * @return string
	 */
	private function restamp(array $row, string $schema, bool $complete): string {
		$uuid = self::idOf(record: $row);
		try {
			$stamps = $this->resolve(employeeId: $this->employeeIdOf(record: $row, schema: $schema));
			if ($uuid === '' || $this->isCurrent(row: $row, stamps: $stamps) === true) {
				return '';
			}

			if ($complete === false) {
				$this->logger->error('humaniq: manager access not restamped, a lookup read was cut off', ['schema' => $schema, 'id' => $uuid]);
				return 'failed';
			}

			if ($this->dryRun === false) {
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
	 * The manager the org resolution gives on the current day and that
	 * manager's deputies, without the employee's own account.
	 *
	 * @param string $employeeId The record's employee.
	 *
	 * @return array{managerUserId: string|null, deputyUserIds: list<string>}
	 */
	private function resolve(string $employeeId): array {
		$manager = ($employeeId === '') ? null : $this->org->managerOf(employeeId: $employeeId, onDate: $this->today);
		if ($manager === null || $manager === '') {
			return ['managerUserId' => null, 'deputyUserIds' => []];
		}

		$own = $this->org->accountOf(employeeId: $employeeId);
		$deputies = array_values(array_filter($this->org->deputiesOf(managerUserId: $manager, today: $this->today), static fn (string $uid): bool => $uid !== $own && $uid !== $manager));
		sort($deputies);

		return ['managerUserId' => $manager, 'deputyUserIds' => $deputies];
	}//end resolve()

	/**
	 * The employee a record is about: an Employee is its own.
	 *
	 * @param array<string, mixed> $record The record.
	 * @param string $schema The schema name.
	 *
	 * @return string
	 */
	private function employeeIdOf(array $record, string $schema): string {
		if ($schema === 'Employee') {
			return self::idOf(record: $record);
		}

		return trim((string)($record['employeeId'] ?? ''));
	}//end employeeIdOf()

	/**
	 * A record's id.
	 *
	 * @param array<string, mixed> $record The record.
	 *
	 * @return string
	 */
	private static function idOf(array $record): string {
		return trim((string)($record['id'] ?? ($record['@self']['id'] ?? '')));
	}//end idOf()

}//end class
