<?php

/**
 * One confirmation per employee per announcement (self-service-announcements-and-digest D1).
 *
 * The confirm endpoint checks this before it saves; this listener holds the
 * same rule for a confirmation created any other way, such as by HR through
 * the objects API, and places it on the employee.
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
 * @spec openspec/specs/announcements-and-digest/spec.md#REQ-AND-002
 */

declare(strict_types=1);

namespace OCA\Humaniq\Listener;

use OCA\Humaniq\Service\AnnouncementService;
use OCA\Humaniq\Service\InternalWriteMarker;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Refuses a second confirmation and stamps the employee on a new one.
 *
 * @template-implements IEventListener<Event>
 *
 * @spec openspec/specs/announcements-and-digest/spec.md#REQ-AND-002
 */
class AnnouncementConfirmationListener implements IEventListener {

	/**
	 * Constructor.
	 *
	 * @param AnnouncementService $announcements The confirmation rules.
	 * @param IUserSession        $userSession   The caller.
	 * @param InternalWriteMarker $marker        Marks humaniq's own writes.
	 * @param LoggerInterface     $logger        The logger.
	 */
	public function __construct(
		private readonly AnnouncementService $announcements,
		private readonly IUserSession $userSession,
		private readonly InternalWriteMarker $marker,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * Check and stamp a confirmation before it is created.
	 *
	 * @param Event $event The event.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/announcements-and-digest/spec.md#REQ-AND-002
	 */
	public function handle(Event $event): void {
		if ($event instanceof ObjectCreatingEvent === false || $this->marker->isInternal() === true) {
			return;
		}

		$entity = $event->getObject();
		$uid = trim((string)($this->userSession->getUser()?->getUID() ?? ''));
		$placed = $this->announcements->place(confirmation: ($entity->getObject() ?? []), uid: $uid);
		if ($placed['error'] !== null) {
			$this->logger->info('humaniq: an announcement confirmation was refused: ' . $placed['error']);
			$event->setErrors(['message' => $placed['error']]);
			$event->stopPropagation();
			return;
		}

		$event->setModifiedData($placed['stamps']);
	}//end handle()

}//end class
