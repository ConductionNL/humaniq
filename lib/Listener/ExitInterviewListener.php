<?php

/**
 * Humaniq ExitInterviewListener
 *
 * Before an exit interview is created it takes its leaver, administration
 * and department from its offboarding case; after it is created the case's
 * exit interview date is set to the day it was held, so the existing audit
 * rule on `exitGesprekDone` keeps working (hiring-offboarding-completion D1).
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
 * @spec openspec/specs/offboarding-completion/spec.md#REQ-OFC-001
 */

declare(strict_types=1);

namespace OCA\Humaniq\Listener;

use OCA\Humaniq\Service\ExitInterviewService;
use OCA\Humaniq\Service\HoursRegisterGateway;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;

/**
 * Fills in and stamps around an exit interview create.
 *
 * @template-implements IEventListener<Event>
 *
 * @spec openspec/specs/offboarding-completion/spec.md#REQ-OFC-001
 */
class ExitInterviewListener implements IEventListener {

	/**
	 * Lower-cased slug of the interview schema.
	 */
	public const SLUG = 'exitinterview';

	/**
	 * Constructor.
	 *
	 * @param ExitInterviewService $interviews The exit interview rules.
	 * @param HoursRegisterGateway $gateway    Resolves the schema slug.
	 * @param LoggerInterface      $logger     The logger.
	 *
	 * @spec openspec/specs/offboarding-completion/spec.md#REQ-OFC-001
	 */
	public function __construct(
		private readonly ExitInterviewService $interviews,
		private readonly HoursRegisterGateway $gateway,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * Fill in before the create, stamp the case after it.
	 *
	 * @param Event $event The event.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/offboarding-completion/spec.md#REQ-OFC-001
	 */
	public function handle(Event $event): void {
		if (($event instanceof ObjectCreatingEvent) === false && ($event instanceof ObjectCreatedEvent) === false) {
			return;
		}

		$entity = $event->getObject();
		if (strtolower($this->gateway->resolveSchemaSlug((string)$entity->getSchema())) !== self::SLUG) {
			return;
		}

		$data = ($entity->getObject() ?? []);
		try {
			if ($event instanceof ObjectCreatingEvent) {
				$stamps = $this->interviews->fillIn(interview: $data);
				if ($stamps !== []) {
					$event->setModifiedData($stamps);
				}

				return;
			}

			$this->interviews->stampCase(interview: $data);
		} catch (\Throwable $e) {
			$this->logger->warning('humaniq: an exit interview could not be filled in or stamped', ['exception' => $e->getMessage()]);
		}
	}//end handle()
}//end class
