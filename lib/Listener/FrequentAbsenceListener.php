<?php

/**
 * Frequent Absence Listener
 *
 * Post-save listener on SickLeaveCase (absence-deadlines-and-signals design.md
 * D4): when a case is created, or reopened as a relapse (`hersteld` to
 * `gemeld`), FrequentAbsenceService counts the employee's cases in the
 * administration's window and stamps the count and the signal on the case.
 * Any other edit is ignored, and so is humaniq's own stamp (InternalWriteMarker),
 * so the listener never counts twice. The notification is declared on the
 * schema; this listener sends nothing itself. A failure is logged and never
 * breaks the save.
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
 * @spec openspec/specs/absence-deadlines-and-signals/spec.md#REQ-ADS-003
 */

declare(strict_types=1);

namespace OCA\Humaniq\Listener;

use OCA\Humaniq\Service\FrequentAbsenceService;
use OCA\Humaniq\Service\HoursRegisterGateway;
use OCA\Humaniq\Service\InternalWriteMarker;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;

/**
 * Counts a new or reopened sickness case.
 *
 * @implements IEventListener<Event>
 *
 * @spec openspec/specs/absence-deadlines-and-signals/spec.md#REQ-ADS-003
 */
class FrequentAbsenceListener implements IEventListener {

	/**
	 * The schema slug this listener reacts to.
	 *
	 * @var string
	 */
	public const SICKLEAVECASE_SLUG = 'sickleavecase';

	/**
	 * @param FrequentAbsenceService $service Counts and stamps.
	 * @param InternalWriteMarker $marker Tells humaniq's own writes apart.
	 * @param HoursRegisterGateway $gateway Resolves the event's schema slug.
	 * @param LoggerInterface $logger Logger.
	 */
	public function __construct(
		private readonly FrequentAbsenceService $service,
		private readonly InternalWriteMarker $marker,
		private readonly HoursRegisterGateway $gateway,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * Count a created or reopened case.
	 *
	 * @param Event $event The OpenRegister object event.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/absence-deadlines-and-signals/spec.md#REQ-ADS-003
	 */
	public function handle(Event $event): void {
		if ($this->marker->isInternal() === true) {
			return;
		}

		$entity = null;
		if ($event instanceof ObjectCreatedEvent) {
			$entity = $event->getObject();
		}

		if ($event instanceof ObjectUpdatedEvent && $this->isReopening($event) === true) {
			$entity = $event->getNewObject();
		}

		if ($entity === null || strtolower($this->gateway->resolveSchemaSlug((string)$entity->getSchema())) !== self::SICKLEAVECASE_SLUG) {
			return;
		}

		try {
			$this->service->stamp((string)$entity->getUuid(), ($entity->getObject() ?? []));
		} catch (\Throwable $e) {
			$this->logger->warning('humaniq: FrequentAbsenceListener could not count a sickness case', ['exception' => $e->getMessage()]);
		}
	}//end handle()

	/**
	 * Whether the update reopens a recovered case (a relapse).
	 *
	 * @param ObjectUpdatedEvent $event The update.
	 *
	 * @return bool
	 */
	private function isReopening(ObjectUpdatedEvent $event): bool {
		$old = ($event->getOldObject()?->getObject() ?? []);
		$new = ($event->getNewObject()?->getObject() ?? []);

		return ($old['status'] ?? null) === 'hersteld' && ($new['status'] ?? null) === 'gemeld';
	}//end isReopening()

}//end class
