<?php

/**
 * Humaniq RightToWorkCheckListener
 *
 * Decides every right-to-work check as it is saved, and carries a pass onto
 * the onboarding case (people-dossier-completeness D4).
 *
 * On ObjectCreatingEvent and ObjectUpdatingEvent the stated rule sets the
 * result, reason and method from what HR entered, so a result typed by hand
 * never survives the save, and the machine-readable zone is cleared so no
 * document number is stored. Only HR (or an administrator) may record a
 * check; a system write with no user, such as the seed import, is decided the
 * same way. On ObjectCreatedEvent and ObjectUpdatedEvent a pass ticks the
 * case's WID check and files a residence document with its expiry.
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
 * @spec openspec/changes/people-dossier-completeness/specs/dossier-completeness/spec.md#REQ-DCP-003
 */

declare(strict_types=1);

namespace OCA\Humaniq\Listener;

use OCA\Humaniq\Service\HoursRegisterGateway;
use OCA\Humaniq\Service\HumaniqRoles;
use OCA\Humaniq\Service\InternalWriteMarker;
use OCA\Humaniq\Service\RightToWorkRecorder;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Decides and follows up right-to-work checks.
 *
 * @template-implements IEventListener<Event>
 *
 * @spec openspec/changes/people-dossier-completeness/specs/dossier-completeness/spec.md#REQ-DCP-003
 */
class RightToWorkCheckListener implements IEventListener {

	public const CHECK_SLUG = 'righttoworkcheck';

	/**
	 * Constructor.
	 *
	 * @param HoursRegisterGateway $gateway Schema slug resolution.
	 * @param RightToWorkRecorder $recorder The stamp and the follow-up.
	 * @param HumaniqRoles $roles Whether the caller is HR.
	 * @param IUserSession $userSession The caller.
	 * @param InternalWriteMarker $marker humaniq's own writes are not re-decided.
	 * @param LoggerInterface $logger Logger.
	 */
	public function __construct(
		private readonly HoursRegisterGateway $gateway,
		private readonly RightToWorkRecorder $recorder,
		private readonly HumaniqRoles $roles,
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
	 * @spec openspec/changes/people-dossier-completeness/specs/dossier-completeness/spec.md#REQ-DCP-003
	 */
	public function handle(Event $event): void {
		if ($this->marker->isInternal() === true) {
			return;
		}

		$entity = $this->entityOf(event: $event);
		if ($entity === null || strtolower($this->gateway->resolveSchemaSlug((string)$entity->getSchema())) !== self::CHECK_SLUG) {
			return;
		}

		$data = ($entity->getObject() ?? []);
		if ($event instanceof \OCA\OpenRegister\Event\ObjectCreatingEvent || $event instanceof \OCA\OpenRegister\Event\ObjectUpdatingEvent) {
			$this->beforeSave(event: $event, data: $data);
			return;
		}

		try {
			$this->marker->runInternal(fn () => $this->recorder->recordPass($data));
		} catch (\Throwable $e) {
			$this->logger->warning('humaniq: a passing right-to-work check could not be carried onto its onboarding case', ['exception' => $e->getMessage()]);
		}
	}//end handle()

	/**
	 * Refuse a caller outside HR, else stamp the decision.
	 *
	 * @param \OCA\OpenRegister\Event\ObjectCreatingEvent|\OCA\OpenRegister\Event\ObjectUpdatingEvent $event The pre-save event.
	 * @param array<string, mixed> $data The payload.
	 *
	 * @return void
	 */
	private function beforeSave(object $event, array $data): void {
		$uid = trim((string)($this->userSession->getUser()?->getUID() ?? ''));
		if ($uid !== '' && $this->roles->isHr($uid) === false) {
			$event->setErrors(['message' => 'Only HR can record a right-to-work check.']);
			$event->stopPropagation();
			return;
		}

		$event->setModifiedData($this->recorder->stamp(check: $data, userId: $uid, today: date('Y-m-d')));
	}//end beforeSave()

	/**
	 * The saved or to-be-saved entity of a create or update event.
	 *
	 * @param Event $event The event.
	 *
	 * @return object|null
	 */
	private function entityOf(Event $event): ?object {
		if ($event instanceof \OCA\OpenRegister\Event\ObjectCreatingEvent || $event instanceof \OCA\OpenRegister\Event\ObjectCreatedEvent) {
			return $event->getObject();
		}

		if ($event instanceof \OCA\OpenRegister\Event\ObjectUpdatingEvent || $event instanceof \OCA\OpenRegister\Event\ObjectUpdatedEvent) {
			return $event->getNewObject();
		}

		return null;
	}//end entityOf()

}//end class
