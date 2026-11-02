<?php

/**
 * Humaniq ManagerStampRepair
 *
 * Restamps `managerUserId` on open records that were stamped when the
 * record was created: change requests awaiting a decision, open relations
 * cases, reported side activities, submitted commuting arrangements and
 * submitted travel claims. A stamp is rewritten only when it differs from
 * what the shared resolver gives today for the record's own reference date,
 * which covers a stamp naming the employee themself. Closed and decided
 * records keep their stamp.
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
	 * one. `category` limits the schema to one category.
	 *
	 * @var array<string, array{date: string, default: string, open?: list<string>, closed?: list<string>, category?: string}>
	 */
	private const SCHEMAS = [
		'EmployeeChangeRequest' => ['date' => '', 'default' => 'ingediend', 'open' => ['ingediend']],
		'SideActivity' => ['date' => '', 'default' => 'gemeld', 'open' => ['gemeld']],
		'EmployeeRelationsCase' => ['date' => 'openedOn', 'default' => 'geopend', 'closed' => ['afgesloten']],
		'CommuteArrangement' => ['date' => 'startDate', 'default' => 'draft', 'open' => ['submitted']],
		'Expense' => ['date' => 'expenseDate', 'default' => 'draft', 'open' => ['submitted'], 'category' => 'travel'],
	];

	/**
	 * Constructor.
	 *
	 * @param HoursRegisterGateway $gateway The register and the shared manager resolution.
	 * @param InternalWriteMarker $marker Marks the writes as humaniq's own.
	 * @param LoggerInterface $logger Records every rewritten stamp.
	 *
	 * @spec openspec/changes/restamp-open-manager-records/specs/mss-team-scope/spec.md#Requirement:-Open-records-SHALL-be-restamped-with-the-manager-the-shared-resolution-gives-today-(REQ-MSS-007)
	 */
	public function __construct(
		private readonly HoursRegisterGateway $gateway,
		private readonly InternalWriteMarker $marker,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * Restamp every open record, or only count them on a dry run.
	 *
	 * @param bool $dryRun True to count without writing.
	 *
	 * @return array<string, array{inspected: int, open: int, changed: int}> Counts per schema.
	 *
	 * @spec openspec/changes/restamp-open-manager-records/specs/mss-team-scope/spec.md#Requirement:-Open-records-SHALL-be-restamped-with-the-manager-the-shared-resolution-gives-today-(REQ-MSS-007)
	 */
	public function run(bool $dryRun): array {
		$report = [];
		foreach (array_keys(self::SCHEMAS) as $schema) {
			$report[$schema] = $this->restampSchema(schema: $schema, dryRun: $dryRun);
		}

		return $report;
	}//end run()

	/**
	 * Restamp the open records of one schema.
	 *
	 * @param string $schema The schema slug.
	 * @param bool $dryRun True to count without writing.
	 *
	 * @return array{inspected: int, open: int, changed: int}
	 */
	private function restampSchema(string $schema, bool $dryRun): array {
		$counts = ['inspected' => 0, 'open' => 0, 'changed' => 0];
		foreach ($this->gateway->loadAll($schema) as $row) {
			$counts['inspected']++;
			if ($this->isOpen(row: $row, schema: $schema) === false) {
				continue;
			}

			$counts['open']++;
			$change = $this->stampChange(row: $row, schema: $schema);
			if ($change === null) {
				continue;
			}

			$counts['changed']++;
			$this->logger->info(
				'humaniq: manager stamp restamped',
				['schema' => $schema, 'id' => $change['id'], 'old' => $change['old'], 'new' => $change['new'], 'dryRun' => $dryRun]
			);
			if ($dryRun === false) {
				$this->write(record: array_merge($row, ['managerUserId' => $change['new']]), schema: $schema);
			}
		}//end foreach

		return $counts;
	}//end restampSchema()

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
		if (isset($rule['category']) === true && trim((string)($row['category'] ?? '')) !== $rule['category']) {
			return false;
		}

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

		$new = $this->gateway->uniqueManagerUserIdFor(
			$employeeId,
			$this->referenceDate(row: $row, field: self::SCHEMAS[$schema]['date'])
		);
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
