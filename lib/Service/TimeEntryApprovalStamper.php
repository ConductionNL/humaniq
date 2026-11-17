<?php

/**
 * Humaniq TimeEntryApprovalStamper
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Copies a Timesheet's approval state onto its TimeEntry rows
 * (time-entry-approval-state D1-D3): `approvalState` is the timesheet's
 * `status`, and `approvedAt` is the timesheet's `approvedAt` while that
 * status is `approved`, null otherwise. With the state on the entry, the
 * approved billable hours of one project and period are one TimeEntry query
 * instead of a join across two schemas.
 *
 * The timesheet stays the authority. Two callers use this class: the
 * post-save TimesheetEntryStateListener, which carries one timesheet's edge
 * down to its entries, and the RestampTimeEntryApprovalState repair step,
 * which re-stamps every entry from its parent so a missed edge heals. Both
 * write only the entries whose stamp differs, under the
 * {@see InternalWriteMarker}, so the entry mutability guard and the
 * aggregate recompute stay out of it.
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
 * @spec openspec/changes/time-entry-approval-state/specs/time-entry-approval-state/spec.md#Requirement:-A-time-entry-SHALL-carry-the-approval-state-of-its-timesheet-(REQ-TAS-001)
 */

declare(strict_types=1);

namespace OCA\Humaniq\Service;

use Psr\Log\LoggerInterface;

/**
 * Stamps a timesheet's approval state on its time entries.
 *
 * @spec openspec/changes/time-entry-approval-state/specs/time-entry-approval-state/spec.md#Requirement:-A-time-entry-SHALL-carry-the-approval-state-of-its-timesheet-(REQ-TAS-001)
 */
class TimeEntryApprovalStamper {

	/**
	 * The approval states an entry can carry, the Timesheet status enum.
	 *
	 * @var array<int, string>
	 */
	public const STATES = ['draft', 'submitted', 'approved', 'rejected'];

	/**
	 * Constructor.
	 *
	 * @param HoursRegisterGateway $gateway The shared register plumbing.
	 * @param InternalWriteMarker  $marker  The request-scoped internal-writer marker.
	 * @param LoggerInterface      $logger  The logger.
	 */
	public function __construct(
		private readonly HoursRegisterGateway $gateway,
		private readonly InternalWriteMarker $marker,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * The stamp a timesheet gives its entries.
	 *
	 * An unknown or empty status reads as `draft`, the schema default, so an
	 * entry never carries a state outside the enum.
	 *
	 * @param array<string, mixed> $timesheet The timesheet payload.
	 *
	 * @return array{approvalState: string, approvedAt: string|null}
	 *
	 * @spec openspec/changes/time-entry-approval-state/specs/time-entry-approval-state/spec.md#Requirement:-A-time-entry-SHALL-carry-the-approval-state-of-its-timesheet-(REQ-TAS-001)
	 */
	public function stampFor(array $timesheet): array {
		$status = strtolower(trim((string)($timesheet['status'] ?? '')));
		if (in_array($status, self::STATES, true) === false) {
			$status = 'draft';
		}

		$approvedAt = null;
		if ($status === 'approved') {
			$approvedAt = trim((string)($timesheet['approvedAt'] ?? ''));
			if ($approvedAt === '') {
				$approvedAt = null;
			}
		}

		return ['approvalState' => $status, 'approvedAt' => $approvedAt];
	}//end stampFor()

	/**
	 * Carry one timesheet's state down to its entries.
	 *
	 * @param string               $timesheetId The timesheet uuid.
	 * @param array<string, mixed> $timesheet   The timesheet payload as saved.
	 *
	 * @return array{inspected: int, changed: int, failed: int}
	 *
	 * @spec openspec/changes/time-entry-approval-state/specs/time-entry-approval-state/spec.md#Requirement:-A-time-entry-SHALL-carry-the-approval-state-of-its-timesheet-(REQ-TAS-001)
	 */
	public function carryDown(string $timesheetId, array $timesheet): array {
		$report = ['inspected' => 0, 'changed' => 0, 'failed' => 0];
		$timesheetId = trim($timesheetId);
		if ($timesheetId === '') {
			return $report;
		}

		$stamp = $this->stampFor(timesheet: $timesheet);
		foreach ($this->gateway->findFiltered('TimeEntry', ['timesheetId' => $timesheetId]) as $entry) {
			$report = $this->tally(report: $report, outcome: $this->restampEntry(entry: $entry, stamp: $stamp));
		}

		return $report;
	}//end carryDown()

	/**
	 * Re-stamp every entry from its own timesheet.
	 *
	 * An entry without a timesheet, or whose timesheet is gone, is left alone:
	 * there is no authority to copy from.
	 *
	 * @return array{inspected: int, changed: int, failed: int}
	 *
	 * @spec openspec/changes/time-entry-approval-state/specs/time-entry-approval-state/spec.md#Requirement:-A-time-entry-SHALL-carry-the-approval-state-of-its-timesheet-(REQ-TAS-001)
	 */
	public function restampAll(): array {
		$stamps = [];
		foreach ($this->gateway->loadAll('Timesheet') as $timesheet) {
			$id = self::idOf(row: $timesheet);
			if ($id !== '') {
				$stamps[$id] = $this->stampFor(timesheet: $timesheet);
			}
		}

		$report = ['inspected' => 0, 'changed' => 0, 'failed' => 0];
		foreach ($this->gateway->loadAll('TimeEntry') as $entry) {
			$timesheetId = trim((string)($entry['timesheetId'] ?? ''));
			if (isset($stamps[$timesheetId]) === false) {
				continue;
			}

			$report = $this->tally(report: $report, outcome: $this->restampEntry(entry: $entry, stamp: $stamps[$timesheetId]));
		}

		return $report;
	}//end restampAll()

	/**
	 * Write the stamp on one entry when it differs.
	 *
	 * @param array<string, mixed>                                 $entry The stored entry.
	 * @param array{approvalState: string, approvedAt: string|null} $stamp The stamp.
	 *
	 * @return string '' when already current, 'changed' or 'failed'.
	 */
	private function restampEntry(array $entry, array $stamp): string {
		$uuid = self::idOf(row: $entry);
		if ($uuid === '' || $this->isCurrent(entry: $entry, stamp: $stamp) === true) {
			return '';
		}

		try {
			$record = array_merge($entry, $stamp);
			unset($record['@self']);
			$this->marker->runInternal(fn () => $this->gateway->save($record, 'TimeEntry', $uuid));
		} catch (\Throwable $e) {
			$this->logger->error(
				'humaniq: the approval state of a time entry could not be stamped',
				['id' => $uuid, 'exception' => $e->getMessage()]
			);
			return 'failed';
		}

		return 'changed';
	}//end restampEntry()

	/**
	 * Whether an entry already carries the stamp.
	 *
	 * @param array<string, mixed>                                 $entry The stored entry.
	 * @param array{approvalState: string, approvedAt: string|null} $stamp The stamp.
	 *
	 * @return bool
	 */
	private function isCurrent(array $entry, array $stamp): bool {
		$approvedAt = trim((string)($entry['approvedAt'] ?? ''));

		return ($entry['approvalState'] ?? null) === $stamp['approvalState']
			&& ($approvedAt === '' ? null : $approvedAt) === $stamp['approvedAt'];
	}//end isCurrent()

	/**
	 * Count one outcome.
	 *
	 * @param array{inspected: int, changed: int, failed: int} $report  The running report.
	 * @param string                                           $outcome '', 'changed' or 'failed'.
	 *
	 * @return array{inspected: int, changed: int, failed: int}
	 */
	private function tally(array $report, string $outcome): array {
		$report['inspected']++;
		if ($outcome !== '') {
			$report[$outcome]++;
		}

		return $report;
	}//end tally()

	/**
	 * The uuid of a row as read.
	 *
	 * @param array<string, mixed> $row The row.
	 *
	 * @return string The uuid, or ''.
	 */
	private static function idOf(array $row): string {
		return trim((string)($row['id'] ?? $row['@self']['id'] ?? ''));
	}//end idOf()

}//end class
