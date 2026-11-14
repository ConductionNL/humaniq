<?php

/**
 * Administration Access Write Guard
 *
 * Refuses a create, update or delete of an AdministrationAccess row unless
 * the acting user is a Nextcloud administrator or holds a believed `hr` row
 * for every administration the write touches. humaniq's own writes and
 * shipped imports pass.
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
 * @spec openspec/changes/multi-administratie/specs/multi-administratie/spec.md#REQ-MULTI-003
 */

declare(strict_types=1);

namespace OCA\Humaniq\Listener;

use OCA\Humaniq\Service\AdministrationAccessTrust;
use OCA\Humaniq\Service\HoursRegisterGateway;
use OCA\Humaniq\Service\InternalWriteMarker;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectDeletingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Only administrators and HR of the administration write access rows.
 *
 * @template-implements IEventListener<Event>
 *
 * @spec openspec/changes/multi-administratie/specs/multi-administratie/spec.md#REQ-MULTI-003
 */
class AdministrationAccessWriteGuard implements IEventListener {

	/**
	 * Lower-cased slug of the schema this listener guards.
	 *
	 * @var string
	 */
	public const SLUG = 'administrationaccess';

	/**
	 * The refusal the caller sees.
	 *
	 * @var string
	 */
	private const REFUSAL = 'Alleen een beheerder of HR van deze administratie mag toegang tot de administratie toekennen of intrekken.';

	/**
	 * @param HoursRegisterGateway      $gateway Reads every access row and resolves the schema slug.
	 * @param AdministrationAccessTrust $trust   Decides who may grant.
	 * @param InternalWriteMarker       $marker  Marks humaniq's own writes and shipped imports.
	 * @param IUserSession              $session The acting user.
	 * @param LoggerInterface           $logger  The logger.
	 */
	public function __construct(
		private readonly HoursRegisterGateway $gateway,
		private readonly AdministrationAccessTrust $trust,
		private readonly InternalWriteMarker $marker,
		private readonly IUserSession $session,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * Judge one pre-save or pre-delete event.
	 *
	 * @param Event $event The dispatched event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/multi-administratie/specs/multi-administratie/spec.md#REQ-MULTI-003
	 */
	public function handle(Event $event): void {
		if ($this->marker->isInternal() === true || $this->marker->isShippedImport() === true) {
			return;
		}

		$entities = $this->entitiesOf($event);
		if ($entities === [] || strtolower($this->gateway->resolveSchemaSlug((string)$entities[0]->getSchema())) !== self::SLUG) {
			return;
		}

		$administrationIds = [];
		foreach ($entities as $entity) {
			$administrationIds[] = trim((string)(($entity->getObject() ?? [])['administrationId'] ?? ''));
		}

		$userId = (string)($this->session->getUser()?->getUID() ?? '');

		try {
			$rows = $this->gateway->loadAll('AdministrationAccess');
			foreach (array_unique($administrationIds) as $administrationId) {
				if ($this->trust->mayGrant(userId: $userId, administrationId: $administrationId, rows: $rows) === false) {
					PreSaveEvents::refuse($event, self::REFUSAL);
					return;
				}
			}
		} catch (\Throwable $e) {
			$this->logger->warning('humaniq: AdministrationAccessWriteGuard could not check a write', ['exception' => $e->getMessage()]);
			PreSaveEvents::refuse($event, self::REFUSAL);
		}

	}//end handle()

	/**
	 * The stored and incoming objects the event touches: the new object of a
	 * create, the old and new object of an update, the object of a delete.
	 *
	 * @param Event $event The event.
	 *
	 * @return array<int, object>
	 */
	private function entitiesOf(Event $event): array {
		if ($event instanceof ObjectCreatingEvent || $event instanceof ObjectDeletingEvent) {
			return [$event->getObject()];
		}

		if ($event instanceof ObjectUpdatingEvent) {
			return array_values(array_filter([$event->getNewObject(), $event->getOldObject()]));
		}

		return [];

	}//end entitiesOf()
}//end class
