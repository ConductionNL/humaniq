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
		$stored = [];
		if ($event instanceof ObjectCreatingEvent) {
			$entity = $event->getObject();
		} else if ($event instanceof ObjectUpdatingEvent) {
			$entity = $event->getNewObject();
			$stored = ($event->getOldObject()?->getObject() ?? []);
		} else {
			return;
		}

		$record = ($entity->getObject() ?? []);
		$writer = trim((string)($this->userSession->getUser()?->getUID() ?? ''));
		$stamps = [];
		if (trim((string)($record['managerUserId'] ?? '')) === '' && $writer !== '') {
			$stamps['managerUserId'] = $writer;
			$record['managerUserId'] = $writer;
		}

		$refusal = ($this->deputies->authorityRefusal(writer: $writer, record: $record, stored: $stored) ?? $this->deputies->refusal(record: $record));
		if ($refusal !== null) {
			$event->setErrors(['message' => $refusal]);
			$event->stopPropagation();
			return;
		}

		if ($stamps !== []) {
			$event->setModifiedData($stamps);
		}
	}//end handle()

}//end class
