<?php

/**
 * Humaniq SideActivityListener
 *
 * Places a new side activity report on its employee, keeps
 * Employee.nevenwerkzaamhedenGemeld in step with the register after every
 * change, and refuses a hand edit of that attestation
 * (people-secondment-and-side-activities D3, D4).
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
 * @spec openspec/specs/secondment-and-side-activities/spec.md#REQ-SEC-003
 */

declare(strict_types=1);

namespace OCA\Humaniq\Listener;

use OCA\Humaniq\Service\InternalWriteMarker;
use OCA\Humaniq\Service\SideActivityRegister;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Places side activity reports and derives the attestation.
 *
 * @template-implements IEventListener<Event>
 *
 * @spec openspec/specs/secondment-and-side-activities/spec.md#REQ-SEC-003
 */
class SideActivityListener implements IEventListener {

	/**
	 * Constructor.
	 *
	 * @param SideActivityRegister $register The register rules.
	 * @param IUserSession $userSession The caller.
	 * @param InternalWriteMarker $marker humaniq's own writes are not re-checked.
	 * @param LoggerInterface $logger Logger.
	 */
	public function __construct(
		private readonly SideActivityRegister $register,
		private readonly IUserSession $userSession,
		private readonly InternalWriteMarker $marker,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * Handle one object event.
	 *
	 * @param Event $event The event.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/secondment-and-side-activities/spec.md#REQ-SEC-003
	 */
	public function handle(Event $event): void {
		if ($this->marker->isInternal() === true) {
			return;
		}

		$entity = $this->entityOf(event: $event);
		if ($entity === null) {
			return;
		}

		$slug = $this->register->slugOf((string)$entity->getSchema());
		$data = ($entity->getObject() ?? []);
		if ($slug === SideActivityRegister::EMPLOYEE_SLUG && $event instanceof \OCA\OpenRegister\Event\ObjectUpdatingEvent) {
			$this->guardAttestation(event: $event, data: $data, uuid: (string)$entity->getUuid());
			return;
		}

		if ($slug !== SideActivityRegister::ACTIVITY_SLUG) {
			return;
		}

		if ($event instanceof \OCA\OpenRegister\Event\ObjectCreatingEvent) {
			$this->place(event: $event, data: $data);
			return;
		}

		if ($event instanceof \OCA\OpenRegister\Event\ObjectUpdatingEvent) {
			return;
		}

		try {
			$this->marker->runInternal(fn () => $this->register->attest((string)($data['employeeId'] ?? '')));
		} catch (\Throwable $e) {
			$this->logger->warning('humaniq: the side activity attestation could not be updated', ['exception' => $e->getMessage()]);
		}
	}//end handle()

	/**
	 * Stamp a new report on its employee, or refuse it.
	 *
	 * @param \OCA\OpenRegister\Event\ObjectCreatingEvent $event The pre-save event.
	 * @param array<string, mixed> $data The report.
	 *
	 * @return void
	 */
	private function place(object $event, array $data): void {
		$uid = trim((string)($this->userSession->getUser()?->getUID() ?? ''));
		$placed = $this->register->place(report: $data, userId: $uid, today: date('Y-m-d'));
		if ($placed['error'] !== null) {
			$event->setErrors(['message' => $placed['error']]);
			$event->stopPropagation();
			return;
		}

		$event->setModifiedData($placed['stamps']);
	}//end place()

	/**
	 * Put back the register's value when the attestation is changed by hand.
	 *
	 * @param \OCA\OpenRegister\Event\ObjectUpdatingEvent $event The pre-save event.
	 * @param array<string, mixed> $data The employee as it will be saved.
	 * @param string $uuid The employee id.
	 *
	 * @return void
	 */
	private function guardAttestation(object $event, array $data, string $uuid): void {
		$old = ($event->getOldObject()?->getObject() ?? []);
		$value = $this->register->correction(new: $data, old: $old, employeeId: $uuid);
		if ($value !== null) {
			$event->setModifiedData([SideActivityRegister::FLAG => $value]);
		}
	}//end guardAttestation()

	/**
	 * The entity of a create, update or delete event.
	 *
	 * @param Event $event The event.
	 *
	 * @return object|null
	 */
	private function entityOf(Event $event): ?object {
		if ($event instanceof \OCA\OpenRegister\Event\ObjectCreatingEvent
			|| $event instanceof \OCA\OpenRegister\Event\ObjectCreatedEvent
			|| $event instanceof \OCA\OpenRegister\Event\ObjectDeletedEvent
		) {
			return $event->getObject();
		}

		if ($event instanceof \OCA\OpenRegister\Event\ObjectUpdatingEvent || $event instanceof \OCA\OpenRegister\Event\ObjectUpdatedEvent) {
			return $event->getNewObject();
		}

		return null;
	}//end entityOf()

}//end class
