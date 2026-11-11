<?php

/**
 * Manager Deputy Listener
 *
 * Judges a ManagerDeputy record before it is saved (self-service-approvals-inbox
 * D2): a record filed without a manager is the writer's own; only the manager
 * or HR may write it; a manager cannot be their own deputy, and the last day
 * cannot come before the first. A refused write is stopped with the reason.
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
 * @spec openspec/specs/approvals-inbox/spec.md#REQ-API-002
 */

declare(strict_types=1);

namespace OCA\Humaniq\Listener;

use OCA\Humaniq\Service\ManagerDeputies;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IUserSession;

/**
 * Pre-save checks on a deputy record.
 *
 * @template-implements IEventListener<Event>
 *
 * @spec openspec/specs/approvals-inbox/spec.md#REQ-API-002
 */
class ManagerDeputyListener implements IEventListener {

	/**
	 * Constructor.
	 *
	 * @param ManagerDeputies $deputies The rules.
	 * @param IUserSession $userSession The writer.
	 */
	public function __construct(
		private readonly ManagerDeputies $deputies,
		private readonly IUserSession $userSession,
	) {

	}//end __construct()

	/**
	 * Check the record and stop the save when it is refused.
	 *
	 * @param Event $event The pre-save event.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/approvals-inbox/spec.md#REQ-API-002
	 */
	public function handle(Event $event): void {
		if (($event instanceof ObjectCreatingEvent) === false && ($event instanceof ObjectUpdatingEvent) === false) {
			return;
		}

		$record = ($this->entityOf($event)->getObject() ?? []);
		$writer = trim((string)($this->userSession->getUser()?->getUID() ?? ''));
		$stamps = $this->stampsFor(record: $record, writer: $writer);
		$record = array_merge($record, $stamps);

		$refusal = ($this->deputies->authorityRefusal(writer: $writer, record: $record, stored: $this->storedOf($event)) ?? $this->deputies->refusal(record: $record));
		if ($refusal !== null) {
			$this->refuse(event: $event, message: $refusal);
			return;
		}

		$this->applyStamps(event: $event, stamps: $stamps);
	}//end handle()

	/**
	 * The object being written.
	 *
	 * @param ObjectCreatingEvent|ObjectUpdatingEvent $event The pre-save event.
	 *
	 * @return ObjectEntity
	 */
	private function entityOf(ObjectCreatingEvent|ObjectUpdatingEvent $event): ObjectEntity {
		if ($event instanceof ObjectUpdatingEvent) {
			return $event->getNewObject();
		}

		return $event->getObject();
	}//end entityOf()

	/**
	 * The record as stored before this write; empty on a create.
	 *
	 * @param ObjectCreatingEvent|ObjectUpdatingEvent $event The pre-save event.
	 *
	 * @return array<string, mixed>
	 */
	private function storedOf(ObjectCreatingEvent|ObjectUpdatingEvent $event): array {
		if (($event instanceof ObjectUpdatingEvent) === false) {
			return [];
		}

		return ($event->getOldObject()?->getObject() ?? []);
	}//end storedOf()

	/**
	 * The writer becomes the manager when the record names none.
	 *
	 * @param array<string, mixed> $record The record being written.
	 * @param string               $writer The writer's user id.
	 *
	 * @return array<string, string>
	 */
	private function stampsFor(array $record, string $writer): array {
		if (trim((string)($record['managerUserId'] ?? '')) !== '' || $writer === '') {
			return [];
		}

		return ['managerUserId' => $writer];
	}//end stampsFor()

	/**
	 * Stop the save with the refusal message.
	 *
	 * @param ObjectCreatingEvent|ObjectUpdatingEvent $event   The pre-save event.
	 * @param string                                  $message The refusal.
	 *
	 * @return void
	 */
	private function refuse(ObjectCreatingEvent|ObjectUpdatingEvent $event, string $message): void {
		$event->setErrors(['message' => $message]);
		$event->stopPropagation();
	}//end refuse()

	/**
	 * Write the stamped fields into the save.
	 *
	 * @param ObjectCreatingEvent|ObjectUpdatingEvent $event  The pre-save event.
	 * @param array<string, string>                   $stamps The fields to add.
	 *
	 * @return void
	 */
	private function applyStamps(ObjectCreatingEvent|ObjectUpdatingEvent $event, array $stamps): void {
		if ($stamps === []) {
			return;
		}

		$event->setModifiedData($stamps);
	}//end applyStamps()

}//end class
