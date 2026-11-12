<?php

/**
 * Humaniq ManagerAccessStampListener
 *
 * Stamps `managerUserId` and `deputyUserIds` on every save of an Employee,
 * SickLeaveCase, LeaveTransaction or CompAdjustment, from the org
 * resolution and the active deputies, so the schema's manager and deputy
 * read rules apply from the first save. Values in the incoming record are
 * replaced. When the resolution fails, an update keeps the stored stamps
 * and a create carries none. humaniq's own writes pass untouched.
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
 * @spec openspec/changes/manager-record-access/specs/mss-team-scope/spec.md#Requirement:-A-manager-and-their-deputies-SHALL-read-their-team's-records-through-stamped-access-fields-(REQ-MSS-009)
 */

declare(strict_types=1);

namespace OCA\Humaniq\Listener;

use OCA\Humaniq\Service\InternalWriteMarker;
use OCA\Humaniq\Service\ManagerAccessStamps;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;

/**
 * Pre-save manager access stamp.
 *
 * @template-implements IEventListener<Event>
 *
 * @spec openspec/changes/manager-record-access/specs/mss-team-scope/spec.md#Requirement:-A-manager-and-their-deputies-SHALL-read-their-team's-records-through-stamped-access-fields-(REQ-MSS-009)
 */
class ManagerAccessStampListener implements IEventListener {

	/**
	 * The schema slugs this listener is subscribed for, with their schema names.
	 *
	 * @var array<string, string>
	 */
	public const SLUGS = [
		'employee' => 'Employee',
		'sickleavecase' => 'SickLeaveCase',
		'leavetransaction' => 'LeaveTransaction',
		'compadjustment' => 'CompAdjustment',
	];

	/**
	 * Constructor.
	 *
	 * @param ManagerAccessStamps $stamps Resolves the schema and the stamps.
	 * @param LoggerInterface $logger Records a failed resolution.
	 * @param InternalWriteMarker $marker Lets humaniq's own writes pass.
	 *
	 * @spec openspec/changes/manager-record-access/specs/mss-team-scope/spec.md#Requirement:-A-manager-and-their-deputies-SHALL-read-their-team's-records-through-stamped-access-fields-(REQ-MSS-009)
	 */
	public function __construct(
		private readonly ManagerAccessStamps $stamps,
		private readonly LoggerInterface $logger,
		private readonly InternalWriteMarker $marker,
	) {

	}//end __construct()

	/**
	 * Stamp the record about to be saved.
	 *
	 * @param Event $event The pre-save event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/manager-record-access/specs/mss-team-scope/spec.md#Requirement:-A-manager-and-their-deputies-SHALL-read-their-team's-records-through-stamped-access-fields-(REQ-MSS-009)
	 */
	public function handle(Event $event): void {
		$entity = SchemaWriteFilter::pendingObject($event);
		if ($entity === null || $this->marker->isInternal() === true) {
			return;
		}

		$schema = $this->stamps->schemaFor(schemaId: (string)$entity->getSchema());
		if ($schema !== null && ($event instanceof ObjectCreatingEvent || $event instanceof ObjectUpdatingEvent)) {
			$this->stamp(event: $event, schema: $schema);
		}
	}//end handle()

	/**
	 * Merge the stamps into the event's modified data.
	 *
	 * @param ObjectCreatingEvent|ObjectUpdatingEvent $event The pre-save event.
	 * @param string $schema The schema name.
	 *
	 * @return void
	 */
	private function stamp(ObjectCreatingEvent|ObjectUpdatingEvent $event, string $schema): void {
		$entity = ($event instanceof ObjectUpdatingEvent) ? $event->getNewObject() : $event->getObject();
		$stored = ($event instanceof ObjectUpdatingEvent) ? ($event->getOldObject()?->getObject() ?? []) : [];
		try {
			$stamps = $this->stamps->stampsFor(record: (['id' => (string)$entity->getUuid()] + ($entity->getObject() ?? [])), schema: $schema);
		} catch (\Throwable $e) {
			$this->logger->info('humaniq: manager access stamp failed; keeping stored values', ['schema' => $schema, 'exception' => $e->getMessage()]);
			$stamps = ['managerUserId' => ($stored['managerUserId'] ?? null), 'deputyUserIds' => ($stored['deputyUserIds'] ?? [])];
		}

		$event->setModifiedData(array_merge($event->getModifiedData(), $stamps));
	}//end stamp()

}//end class
