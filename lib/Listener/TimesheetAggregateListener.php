<?php

/**
 * Humaniq TimesheetAggregateListener
 *
 * Post-save listener on OpenRegister's `ObjectCreatedEvent` /
 * `ObjectUpdatedEvent` / `ObjectDeletedEvent` for the `TimeEntry` schema
 * (hours-process-redesign Decision 3, REQ-TEC-004): every entry write
 * triggers a recompute of the affected parent Timesheet's aggregates — on a
 * reparent BOTH the old and the new parent — through the shared
 * {@see TimesheetAggregationService}, the same code path the migration
 * repair step invokes, so runtime and migration can never produce different
 * totals.
 *
 * Loop safety: this listener reacts only to `timeentry` events and writes
 * only Timesheet objects, so it cannot re-trigger itself. The Timesheet
 * write it performs does trigger TimesheetApprovalListener, which no-ops
 * (status unchanged; the approval check is edge-triggered). humaniq's own
 * internal writes (migration synthesis under the InternalWriteMarker) are
 * skipped — the repair step invokes the recompute directly, once, instead
 * of once per synthesized entry.
 *
 * Post-save and fire-and-forget: a recompute failure is logged and swallowed
 * — it must never fail the entry write that triggered it; the next entry
 * write self-heals the total (recompute-from-truth).
 *
 * @category Listener
 * @package  OCA\Humaniq\Listener
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
 * @spec openspec/changes/humaniq-hours-process-redesign/specs/time-entry-capture/spec.md#Requirement:-A-time-entry's-parent-timesheet-aggregates-its-entries-(REQ-TEC-004)
 */

declare(strict_types=1);

namespace OCA\Humaniq\Listener;

use OCA\Humaniq\Service\HoursRegisterGateway;
use OCA\Humaniq\Service\InternalWriteMarker;
use OCA\Humaniq\Service\TimesheetAggregationService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;

/**
 * Recomputes parent-Timesheet aggregates on every TimeEntry write.
 *
 * @implements IEventListener<Event>
 *
 * @spec openspec/changes/humaniq-hours-process-redesign/specs/time-entry-capture/spec.md#Requirement:-A-time-entry's-parent-timesheet-aggregates-its-entries-(REQ-TEC-004)
 */
class TimesheetAggregateListener implements IEventListener {

	/**
	 * Lower-cased slug of the schema whose writes trigger the recompute.
	 *
	 * @var string
	 */
	public const TIMEENTRY_SLUG = 'timeentry';

	/**
	 * Constructor.
	 *
	 * @param TimesheetAggregationService $aggregationService The shared recompute.
	 * @param InternalWriteMarker $marker The request-scoped internal-writer marker.
	 * @param HoursRegisterGateway $gateway The shared register plumbing (schema-slug resolution).
	 * @param LoggerInterface $logger The logger.
	 */
	public function __construct(
		private readonly TimesheetAggregationService $aggregationService,
		private readonly InternalWriteMarker $marker,
		private readonly HoursRegisterGateway $gateway,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * Handle a post-save event for a TimeEntry write.
	 *
	 * @param Event $event The dispatched event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/humaniq-hours-process-redesign/specs/time-entry-capture/spec.md#Requirement:-A-time-entry's-parent-timesheet-aggregates-its-entries-(REQ-TEC-004)
	 */
	public function handle(Event $event): void {
		if ($this->marker->isInternal() === true) {
			// Migration synthesis — the repair step recomputes once, directly.
			return;
		}

		try {
			foreach ($this->affectedTimesheetIds($event) as $timesheetId) {
				$this->aggregationService->recomputeForTimesheet($timesheetId);
			}
		} catch (\Throwable $e) {
			// Never break the save path — the next entry write self-heals.
			$this->logger->warning(
				'humaniq: TimesheetAggregateListener could not recompute aggregates',
				['exception' => $e->getMessage()]
			);
		}
	}//end handle()

	/**
	 * The distinct parent timesheet ids affected by this event — for an
	 * update both the old and the new parent (reparent recomputes both).
	 * Empty for foreign events and non-TimeEntry schemas.
	 *
	 * @param Event $event The dispatched event.
	 *
	 * @return array<int, string> Distinct timesheet uuids.
	 *
	 * @spec openspec/changes/humaniq-hours-process-redesign/specs/time-entry-capture/spec.md#Requirement:-A-time-entry's-parent-timesheet-aggregates-its-entries-(REQ-TEC-004)
	 */
	private function affectedTimesheetIds(Event $event): array {
		$ids = [];
		foreach ($this->subjectEntities($event) as $entity) {
			if (strtolower($this->gateway->resolveSchemaSlug((string)$entity->getSchema())) !== self::TIMEENTRY_SLUG) {
				continue;
			}

			$data = $entity->getObject();
			$timesheetId = trim((string)((is_array($data) === true ? ($data['timesheetId'] ?? '') : '')));
			if ($timesheetId !== '' && in_array($timesheetId, $ids, true) === false) {
				$ids[] = $timesheetId;
			}
		}

		return $ids;
	}//end affectedTimesheetIds()

	/**
	 * The event's subject entities: one for create/delete, both sides for
	 * an update (reparents affect two parents), none for foreign events.
	 *
	 * @param Event $event The dispatched event.
	 *
	 * @return array<int, object> The non-null entities.
	 */
	private function subjectEntities(Event $event): array {
		if ($event instanceof \OCA\OpenRegister\Event\ObjectCreatedEvent
			|| $event instanceof \OCA\OpenRegister\Event\ObjectDeletedEvent
		) {
			return [$event->getObject()];
		}

		if ($event instanceof \OCA\OpenRegister\Event\ObjectUpdatedEvent) {
			return array_values(array_filter([$event->getNewObject(), $event->getOldObject()]));
		}

		return [];
	}//end subjectEntities()

}//end class
