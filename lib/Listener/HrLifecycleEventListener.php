<?php

/**
 * HR Lifecycle Event Listener
 *
 * The OpenRegister adapter for HrLifecycleEventService
 * (platform-hr-lifecycle-events task 1.4): after a contract, onboarding or
 * offboarding case, placement, leave request or sickness case is saved, it
 * hands the stored and the saved record to the service, which sends an event
 * for each HR moment the write marks. A failure is logged and never breaks
 * the save, as for TimesheetApprovalListener.
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
 * @spec openspec/specs/hr-lifecycle-events/spec.md#REQ-HLE-001
 */

declare(strict_types=1);

namespace OCA\Humaniq\Listener;

use OCA\Humaniq\Service\HoursRegisterGateway;
use OCA\Humaniq\Service\HrLifecycleEventService;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;

/**
 * Hands saved HR records to the lifecycle event service.
 *
 * @template-implements IEventListener<Event>
 *
 * @spec openspec/specs/hr-lifecycle-events/spec.md#REQ-HLE-001
 */
class HrLifecycleEventListener implements IEventListener {

	/**
	 * Constructor.
	 *
	 * @param HrLifecycleEventService $events The moments and their sending.
	 * @param HoursRegisterGateway $gateway Resolves the schema slug.
	 * @param LoggerInterface $logger Logger.
	 */
	public function __construct(
		private readonly HrLifecycleEventService $events,
		private readonly HoursRegisterGateway $gateway,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * Send the moments this save marks.
	 *
	 * @param Event $event The post-save event.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/hr-lifecycle-events/spec.md#REQ-HLE-001
	 */
	public function handle(Event $event): void {
		if (($event instanceof ObjectCreatedEvent) === false && ($event instanceof ObjectUpdatedEvent) === false) {
			return;
		}

		try {
			$entity = ($event instanceof ObjectUpdatedEvent) ? $event->getNewObject() : $event->getObject();
			$old = null;
			if ($event instanceof ObjectUpdatedEvent) {
				$old = ($event->getOldObject()?->getObject() ?? []);
			}

			$new = ($entity->getObject() ?? []);
			if (isset($new['id']) === false && (string)$entity->getUuid() !== '') {
				$new['id'] = (string)$entity->getUuid();
			}

			$slug = strtolower($this->gateway->resolveSchemaSlug((string)$entity->getSchema()));
			$this->events->emit(slug: $slug, old: $old, new: $new);
		} catch (\Throwable $e) {
			$this->logger->warning('humaniq: HrLifecycleEventListener could not send an HR lifecycle event', ['exception' => $e->getMessage()]);
		}
	}//end handle()

}//end class
