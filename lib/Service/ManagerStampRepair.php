<?php

/**
 * Humaniq ManagerStampRepair
 *
 * Restamps `managerUserId` on open records that were stamped when the
 * record was created: change requests awaiting a decision, open relations
 * cases, reported side activities, submitted commuting arrangements and
 * submitted expense claims of every category. A stamp is rewritten only
 * when it differs from what the shared resolver gives today for the
 * record's own reference date, which covers a stamp naming the employee
 * themself. Closed and decided records keep their stamp.
 *
 * Every page of every schema is read, and the org lookups are read once
 * per run. When a lookup read was cut off, no stamp is rewritten: every
 * record that would change counts as failed. A record that fails is logged and counted, and the pass carries on.
 *
 * Idempotent: a second run finds nothing to change. Used by the
 * RestampOpenManagerRecords repair step and the
 * `occ humaniq:manager:restamp` command.
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
 * @spec openspec/changes/restamp-open-manager-records/specs/mss-team-scope/spec.md#Requirement:-Open-records-SHALL-be-restamped-with-the-manager-the-shared-resolution-gives-today-(REQ-MSS-007)
 */

declare(strict_types=1);

namespace OCA\Humaniq\Service;

use Psr\Log\LoggerInterface;

/**
 * Rewrites outdated manager stamps on open records.
 *
 * @spec openspec/changes/restamp-open-manager-records/specs/mss-team-scope/spec.md#Requirement:-Open-records-SHALL-be-restamped-with-the-manager-the-shared-resolution-gives-today-(REQ-MSS-007)
 */
class ManagerStampRepair {

	/**
	 * Per schema: which records are open, and the field holding the date
	 * the stamp is resolved for ('' for the record's creation date).
	 *
	 * `open` lists the open states; `closed` lists the closed ones, every
	 * other state being open. `default` is the state of a record without
	 * one.
	 *
	 * @var array<string, array{date: string, default: string, open?: list<string>, closed?: list<string>}>
	 */
	private const SCHEMAS = [
		'EmployeeChangeRequest' => ['date' => '', 'default' => 'ingediend', 'open' => ['ingediend']],
		'SideActivity' => ['date' => '', 'default' => 'gemeld', 'open' => ['gemeld']],
		'EmployeeRelationsCase' => ['date' => 'openedOn', 'default' => 'geopend', 'closed' => ['afgesloten']],
		'CommuteArrangement' => ['date' => 'startDate', 'default' => 'draft', 'open' => ['submitted']],
		'Expense' => ['date' => 'expenseDate', 'default' => 'draft', 'open' => ['submitted']],
	];

	/**
	 * The org lookups of the current run, read once.
	 *
	 * @var array{assignments: array<string, array<int, array<string, mixed>>>, units: array<string, array<string, mixed>>, employees: array<string, array<string, mixed>>, complete: bool}
	 */
	private array $lookups = ['assignments' => [], 'units' => [], 'employees' => [], 'complete' => true];

	/**
	 * Whether the current run only counts.
	 *
	 * @var bool
	 */
	private bool $dryRun = true;

	/**
	 * Constructor.
	 *
	 * @param HoursRegisterGateway $gateway The register.
	 * @param InternalWriteMarker $marker Marks the writes as humaniq's own.
	 * @param LoggerInterface $logger Records every rewritten stamp and every failure.
	 * @param OrgLookups $org The read-once org lookups and the manager resolution.
	 *
	 * @spec openspec/changes/restamp-open-manager-records/specs/mss-team-scope/spec.md#Requirement:-Open-records-SHALL-be-restamped-with-the-manager-the-shared-resolution-gives-today-(REQ-MSS-007)
	 */
	public function __construct(
		private readonly HoursRegisterGateway $gateway,
		private readonly InternalWriteMarker $marker,
		private readonly LoggerInterface $logger,
		private readonly OrgLookups $org,
	) {

	}//end __construct()

	/**
	 * Restamp every open record, or only count them on a dry run.
	 *
	 * @param bool $dryRun True to count without writing.
	 *
	 * @return array<string, array{inspected: int, open: int, changed: int, failed: int}> Counts per schema.
	 *
	 * @spec openspec/changes/restamp-open-manager-records/specs/mss-team-scope/spec.md#Requirement:-Open-records-SHALL-be-restamped-with-the-manager-the-shared-resolution-gives-today-(REQ-MSS-007)
	 */
	public function run(bool $dryRun): array {
		$this->dryRun = $dryRun;
		$this->lookups = $this->org->read();
		$report = [];
		foreach (array_keys(self::SCHEMAS) as $schema) {
			$report[$schema] = $this->restampSchema(schema: $schema);
		}

		return $report;
	}//end run()

	/**
	 * Restamp the open records of one schema.
	 *
	 * @param string $schema The schema slug.
	 *
	 * @return array{inspected: int, open: int, changed: int, failed: int}
	 */
	private function restampSchema(string $schema): array {
		$counts = ['inspected' => 0, 'open' => 0, 'changed' => 0, 'failed' => 0];
		$rows = $this->gateway->loadAll($schema);
		if (count($rows) >= HoursRegisterGateway::MAX_ROWS) {
			$counts['failed']++;
			$this->logger->error('humaniq: manager restamp read was cut off', ['schema' => $schema, 'rows' => count($rows)]);
		}

		foreach ($rows as $row) {
			$counts['inspected']++;
			if ($this->isOpen(row: $row, schema: $schema) === false) {
				continue;
			}

			$counts['open']++;
			$outcome = $this->restampRecord(row: $row, schema: $schema);
			if ($outcome !== '') {
				$counts[$outcome]++;
			}
		}

		return $counts;
	}//end restampSchema()

	/**
	 * Restamp one open record: 'changed', 'failed', or '' when it stays.
	 *
	 * @param array<string, mixed> $row The record.
	 * @param string $schema The schema slug.
	 *
	 * @return string
	 */
	private function restampRecord(array $row, string $schema): string {
		try {
			$change = $this->stampChange(row: $row, schema: $schema);
			if ($change === null) {
				return '';
			}

			$change['schema'] = $schema;
			if ($this->lookups['complete'] === false) {
				$this->logger->error('humaniq: manager stamp not restamped, a lookup read was cut off', ['schema' => $schema, 'id' => $change['id']]);
				return 'failed';
			}

			if ($this->dryRun === true) {
				$this->logChange(message: 'humaniq: manager stamp would be restamped (dry run)', change: $change + ['dryRun' => true]);
				return 'changed';
			}

			$this->write(record: array_merge($row, ['managerUserId' => $change['new']]), schema: $schema);
			$this->logChange(message: 'humaniq: manager stamp restamped', change: $change + ['dryRun' => false]);

			return 'changed';
		} catch (\Throwable $e) {
			$this->logger->error(
				'humaniq: manager stamp could not be restamped',
				['schema' => $schema, 'id' => trim((string)($row['id'] ?? ($row['@self']['id'] ?? ''))), 'error' => $e->getMessage()]
			);

			return 'failed';
		}//end try
	}//end restampRecord()

	/**
	 * Log one change with its schema, id, old and new stamp.
	 *
	 * @param string $message The message.
	 * @param array{id: string, old: string, new: string|null, schema: string, dryRun: bool} $change The change.
	 *
	 * @return void
	 */
	private function logChange(string $message, array $change): void {
		$this->logger->info(
			$message,
			['schema' => $change['schema'], 'id' => $change['id'], 'old' => $change['old'], 'new' => $change['new'], 'dryRun' => $change['dryRun']]
		);
	}//end logChange()

	/**
	 * Whether a record is still open: awaiting a decision, or not closed.
	 *
	 * @param array<string, mixed> $row The record.
	 * @param string $schema The schema slug.
	 *
	 * @return bool
	 */
	private function isOpen(array $row, string $schema): bool {
		$rule = self::SCHEMAS[$schema];
		$state = trim((string)($row['status'] ?? ''));
		if ($state === '') {
			$state = $rule['default'];
		}

		if (isset($rule['closed']) === true) {
			return in_array($state, $rule['closed'], true) === false;
		}

		return in_array($state, $rule['open'], true);
	}//end isOpen()

	/**
	 * The stamp a record should carry when it differs from the one it has,
	 * or null when it is current, has no stamp, or names no employee.
	 *
	 * @param array<string, mixed> $row The record.
	 * @param string $schema The schema slug.
	 *
	 * @return array{id: string, old: string, new: string|null}|null
	 */
	private function stampChange(array $row, string $schema): ?array {
		$id = trim((string)($row['id'] ?? ($row['@self']['id'] ?? '')));
		$old = trim((string)($row['managerUserId'] ?? ''));
		$employeeId = trim((string)($row['employeeId'] ?? ''));
		if ($id === '' || $old === '' || $employeeId === '') {
			return null;
		}

		$new = $this->org->managerOf($this->lookups, $employeeId, $this->referenceDate(row: $row, field: self::SCHEMAS[$schema]['date']));
		if ($new === $old) {
			return null;
		}

		return ['id' => $id, 'old' => $old, 'new' => $new];
	}//end stampChange()

	/**
	 * The date a record's stamp is resolved for: the named field, else the
	 * record's creation date, else today. Only the `YYYY-MM-DD` part counts.
	 *
	 * @param array<string, mixed> $row The record.
	 * @param string $field The date field, '' for the creation date.
	 *
	 * @return string
	 */
	private function referenceDate(array $row, string $field): string {
		$candidates = [($row['@self']['created'] ?? null)];
		if ($field !== '') {
			array_unshift($candidates, ($row[$field] ?? null));
		}

		foreach ($candidates as $candidate) {
			$text = trim((string)$candidate);
			if (preg_match('/^\d{4}-\d{2}-\d{2}/', $text) === 1) {
				return substr($text, 0, 10);
			}
		}

		return gmdate('Y-m-d');
	}//end referenceDate()

	/**
	 * Save a record with its new stamp, outside the pre-save listeners.
	 *
	 * @param array<string, mixed> $record The record with its new stamp.
	 * @param string $schema The schema slug.
	 *
	 * @return void
	 */
	private function write(array $record, string $schema): void {
		$uuid = trim((string)($record['id'] ?? ($record['@self']['id'] ?? '')));
		unset($record['@self']);
		$this->marker->runInternal(fn () => $this->gateway->save($record, $schema, $uuid));
	}//end write()

}//end class
