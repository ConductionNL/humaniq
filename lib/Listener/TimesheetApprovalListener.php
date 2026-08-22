<?php

/**
 * Humaniq TimesheetApprovalListener.
 *
 * Thin OpenRegister adapter: on every ObjectUpdatedEvent it resolves the changed
 * object's schema slug and, for a Timesheet crossing into `approved`, delegates
 * to {@see TimeEntryEventService} which emits the
 * `nl.conduction.hrmq.timeentry.approved` CloudEvent. All decision logic (schema
 * gate, transition edge, payload, dispatch) lives in the service so it is unit
 * testable without OpenRegister; this class carries only the OR wiring and never
 * throws into the save path.
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
 * @spec openspec/changes/time-entry-capture/specs/time-entry-capture/spec.md#REQ-TEC-002
 */

declare(strict_types=1);

namespace OCA\Humaniq\Listener;

use OCA\Humaniq\Service\TimeEntryEventService;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Listener that emits the approved-time-entry CloudEvent on Timesheet approval.
 *
 * @implements IEventListener<Event>
 *
 * @spec openspec/changes/time-entry-capture/specs/time-entry-capture/spec.md#REQ-TEC-002
 */
class TimesheetApprovalListener implements IEventListener {

	/**
	 * Constructor.
	 *
	 * @param TimeEntryEventService $eventService The approved-time-entry emitter.
	 * @param ContainerInterface $container The DI container (lazy OpenRegister SchemaMapper lookup).
	 * @param LoggerInterface $logger The logger.
	 */
	public function __construct(
		private readonly TimeEntryEventService $eventService,
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * Handle an ObjectUpdatedEvent.
	 *
	 * @param Event $event The dispatched event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/time-entry-capture/specs/time-entry-capture/spec.md#REQ-TEC-002
	 */
	public function handle(Event $event): void {
		if (($event instanceof ObjectUpdatedEvent) === false) {
			return;
		}

		try {
			$newEntity = $event->getNewObject();
			$oldEntity = $event->getOldObject();
			$schemaSlug = $this->resolveSchemaSlug((string)$newEntity->getSchema());
			if ($schemaSlug === '') {
				return;
			}

			$newData = $newEntity->getObject();
			$oldData = null;
			if ($oldEntity !== null) {
				$oldData = $oldEntity->getObject();
			}

			$this->eventService->maybeDispatchApproved(
				schemaSlug: $schemaSlug,
				oldData: $oldData,
				newData: $newData
			);
		} catch (\Throwable $e) {
			// Never break the save path — a failure to resolve or emit is logged
			// and swallowed (fire-and-forget per REQ-TEC-002).
			$this->logger->warning(
				'humaniq: TimesheetApprovalListener could not process an ObjectUpdatedEvent',
				['exception' => $e->getMessage()]
			);
		}//end try

	}//end handle()

	/**
	 * Resolve a schema id to its slug via OpenRegister's SchemaMapper.
	 *
	 * Returns an empty string when OpenRegister is absent or the schema cannot
	 * be resolved, so the listener no-ops safely.
	 *
	 * @param string $schemaId The OpenRegister schema id/uuid carried by the object.
	 *
	 * @return string The lower-caseable schema slug, or '' when unresolvable.
	 */
	private function resolveSchemaSlug(string $schemaId): string {
		if ($schemaId === '') {
			return '';
		}

		try {
			$schemaMapper = $this->container->get('OCA\OpenRegister\Db\SchemaMapper');
			$schema = $schemaMapper->find($schemaId);
			return (string)$schema->getSlug();
		} catch (\Throwable $e) {
			return '';
		}

	}//end resolveSchemaSlug()

}//end class
