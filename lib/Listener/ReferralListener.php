<?php

/**
 * Humaniq ReferralListener
 *
 * Before a referral is saved it is checked and stamped; after it is saved the
 * application HR works with is created; and whenever HR moves a referred
 * application, the referral follows its status (hiring-candidate-assessment
 * D4). The application is written as humaniq's own write, so the referrer
 * needs no right on candidate data.
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
 * @spec openspec/changes/hiring-candidate-assessment/specs/candidate-assessment/spec.md#REQ-CAS-003
 */

declare(strict_types=1);

namespace OCA\Humaniq\Listener;

use OCA\Humaniq\Service\HoursRegisterGateway;
use OCA\Humaniq\Service\InternalWriteMarker;
use OCA\Humaniq\Service\ReferralService;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Checks, stamps and follows referrals.
 *
 * @template-implements IEventListener<Event>
 *
 * @spec openspec/changes/hiring-candidate-assessment/specs/candidate-assessment/spec.md#REQ-CAS-003
 */
class ReferralListener implements IEventListener {

	/**
	 * Lower-cased slug of the referral schema.
	 */
	public const SLUG = 'referral';

	/**
	 * Lower-cased slug of the application schema.
	 */
	public const APPLICATION_SLUG = 'job-application';

	/**
	 * Constructor.
	 *
	 * @param ReferralService      $referrals   The referral rules.
	 * @param HoursRegisterGateway $gateway     Resolves the schema slug.
	 * @param IUserSession         $userSession The signed-in user.
	 * @param InternalWriteMarker  $marker      Marks humaniq's own writes.
	 * @param LoggerInterface      $logger      The logger.
	 *
	 * @spec openspec/changes/hiring-candidate-assessment/specs/candidate-assessment/spec.md#REQ-CAS-003
	 */
	public function __construct(
		private readonly ReferralService $referrals,
		private readonly HoursRegisterGateway $gateway,
		private readonly IUserSession $userSession,
		private readonly InternalWriteMarker $marker,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Route the event to the step it needs.
	 *
	 * @param Event $event The event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/hiring-candidate-assessment/specs/candidate-assessment/spec.md#REQ-CAS-003
	 */
	public function handle(Event $event): void {
		if ($this->marker->isInternal() === true) {
			return;
		}

		if ($event instanceof ObjectCreatingEvent && $this->slugOf(entity: $event->getObject()) === self::SLUG) {
			$this->beforeCreate(event: $event);
			return;
		}

		try {
			if ($event instanceof ObjectCreatedEvent && $this->slugOf(entity: $event->getObject()) === self::SLUG) {
				$entity = $event->getObject();
				$this->marker->runInternal(fn () => $this->referrals->createApplication(referral: ($entity->getObject() ?? []), referralId: (string)$entity->getUuid()));
				return;
			}

			if ($event instanceof ObjectUpdatedEvent && $this->slugOf(entity: $event->getNewObject()) === self::APPLICATION_SLUG) {
				$this->follow(entity: $event->getNewObject());
			}
		} catch (\Throwable $e) {
			$this->logger->warning('humaniq: a referral could not create or follow its application', ['exception' => $e->getMessage()]);
		}
	}//end handle()

	/**
	 * Refuse or stamp a new referral.
	 *
	 * @param ObjectCreatingEvent $event The event.
	 *
	 * @return void
	 */
	private function beforeCreate(ObjectCreatingEvent $event): void {
		$referral = ($event->getObject()->getObject() ?? []);
		$refusal = $this->referrals->refusal(referral: $referral);
		if ($refusal !== null) {
			$event->setErrors(['message' => $refusal]);
			$event->stopPropagation();
			return;
		}

		$event->setModifiedData($this->referrals->stamp(referral: $referral, uid: (string)($this->userSession->getUser()?->getUID() ?? '')));
	}//end beforeCreate()

	/**
	 * Carry a referred application's status onto its referral.
	 *
	 * @param object $entity The application entity.
	 *
	 * @return void
	 */
	private function follow(object $entity): void {
		$application = ($entity->getObject() ?? []);
		if (trim((string)($application['referredByUserId'] ?? '')) === '') {
			return;
		}

		$this->marker->runInternal(fn () => $this->referrals->followStatus(applicationId: (string)$entity->getUuid(), status: (string)($application['status'] ?? '')));
	}//end follow()

	/**
	 * The entity's lower-cased schema slug.
	 *
	 * @param object $entity The object entity.
	 *
	 * @return string
	 */
	private function slugOf(object $entity): string {
		return strtolower($this->gateway->resolveSchemaSlug((string)$entity->getSchema()));
	}//end slugOf()

}//end class
