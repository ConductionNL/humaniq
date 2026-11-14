<?php

/**
 * Humaniq TimesheetEntryStateListener
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Post-save listener on OpenRegister's `ObjectUpdatedEvent` for the
 * `Timesheet` schema (time-entry-approval-state D3). When a timesheet's
 * `status` moves (submit, approve, reject, reopen), every entry of the
 * timesheet gets the new `approvalState`, and `approvedAt` on `approved`,
 * through the shared {@see TimeEntryApprovalStamper}.
 *
 * Edge-triggered: an update that leaves `status` alone (the aggregate
 * recompute, an edit of a draft) writes nothing. The entry saves run under
 * the InternalWriteMarker, so neither the entry mutability guard nor the
 * aggregate recompute fires for them, and the listener writes only
 * TimeEntry objects, so it cannot re-trigger itself.
 *
 * Fire-and-forget: a failure is logged and never breaks the timesheet
 * transition; the RestampTimeEntryApprovalState repair step heals a missed
 * edge from the timesheet, which stays the authority.
 *
 * @category Listener
 * @package  OCA\Humaniq\Listener
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

namespace OCA\Humaniq\Listener;

use OCA\Humaniq\Service\OrgLookupInterface;
use OCA\Humaniq\Service\TimeEntryApprovalStamper;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;

/**
 * Carries a timesheet's status edge down to its time entries.
 *
 * @implements IEventListener<Event>
 *
 * @spec openspec/changes/time-entry-approval-state/specs/time-entry-approval-state/spec.md#Requirement:-A-time-entry-SHALL-carry-the-approval-state-of-its-timesheet-(REQ-TAS-001)
 */
class TimesheetEntryStateListener implements IEventListener {

	/**
	 * Lower-cased slug of the schema whose edges are carried down.
	 *
	 * @var string
	 */
	public const TIMESHEET_SLUG = 'timesheet';

	/**
	 * Constructor.
	 *
	 * @param TimeEntryApprovalStamper $stamper The shared entry stamp.
	 * @param OrgLookupInterface       $gateway The register plumbing (schema-slug resolution).
	 * @param LoggerInterface          $logger  The logger.
	 */
	public function __construct(
		private readonly TimeEntryApprovalStamper $stamper,
		private readonly OrgLookupInterface $gateway,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * Handle a post-save update of a Timesheet.
	 *
	 * @param Event $event The dispatched event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/time-entry-approval-state/specs/time-entry-approval-state/spec.md#Requirement:-A-time-entry-SHALL-carry-the-approval-state-of-its-timesheet-(REQ-TAS-001)
	 */
	public function handle(Event $event): void {
		if (($event instanceof ObjectUpdatedEvent) === false) {
			return;
		}

		try {
			$new = $event->getNewObject();
			if (strtolower($this->gateway->resolveSchemaSlug((string)$new->getSchema())) !== self::TIMESHEET_SLUG) {
				return;
			}

			$newData = $new->getObject();
			$newData = is_array($newData) === true ? $newData : [];
			$oldData = $event->getOldObject()?->getObject();
			$oldData = is_array($oldData) === true ? $oldData : [];

			if ($this->statusOf(data: $newData) === $this->statusOf(data: $oldData)) {
				return;
			}

			$report = $this->stamper->carryDown(timesheetId: (string)$new->getUuid(), timesheet: $newData);
			if ($report['failed'] > 0) {
				$this->logger->warning(
					'humaniq: some time entries did not get their timesheet\'s approval state; occ maintenance:repair restamps them',
					['timesheet' => (string)$new->getUuid(), 'failed' => $report['failed']]
				);
			}
		} catch (\Throwable $e) {
			// Never break the timesheet transition; the repair step heals it.
			$this->logger->warning(
				'humaniq: TimesheetEntryStateListener could not carry a timesheet edge to its entries',
				['exception' => $e->getMessage()]
			);
		}//end try

	}//end handle()

	/**
	 * The normalised status of a timesheet payload.
	 *
	 * @param array<string, mixed> $data The payload.
	 *
	 * @return string The lower-cased status, '' when absent.
	 */
	private function statusOf(array $data): string {
		return strtolower(trim((string)($data['status'] ?? '')));
	}//end statusOf()

}//end class
