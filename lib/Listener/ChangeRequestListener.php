<?php

/**
 * Humaniq ChangeRequestListener
 *
 * Listens on OpenRegister's object events for `EmployeeChangeRequest`
 * (people-record-change-approval D1 and D4) and hands them to
 * ChangeRequestService and ChangeRequestApplier: a new request is placed (or refused) before it is
 * saved, a decision is stamped (or refused) before it is saved, and an
 * approved request is applied to the employee after it is saved.
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
 * @spec openspec/specs/employee-change-approval/spec.md#REQ-ECR-001
 */

declare(strict_types=1);

namespace OCA\Humaniq\Listener;

use OCA\Humaniq\Service\ChangeRequestApplier;
use OCA\Humaniq\Service\ChangeRequestService;
use OCA\Humaniq\Service\HoursRegisterGateway;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;

/**
 * Places, decides and applies change requests on their object events.
 *
 * @implements IEventListener<Event>
 *
 * @spec openspec/specs/employee-change-approval/spec.md#REQ-ECR-001
 */
class ChangeRequestListener implements IEventListener {

	/**
	 * Lower-cased slug of the request schema.
	 *
	 * @var string
	 */
	public const REQUEST_SLUG = 'employeechangerequest';

	/**
	 * Constructor.
	 *
	 * @param HoursRegisterGateway $gateway Resolves slugs.
	 * @param ChangeRequestService $service Places and decides a request.
	 * @param ChangeRequestApplier $applier Applies an approved request.
	 * @param LoggerInterface      $logger  Logger.
	 */
	public function __construct(
		private readonly HoursRegisterGateway $gateway,
		private readonly ChangeRequestService $service,
		private readonly ChangeRequestApplier $applier,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * Route one event to the service.
	 *
	 * @param Event $event The object event.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/employee-change-approval/spec.md#REQ-ECR-001
	 */
	public function handle(Event $event): void {
		$entity = $this->entityOf($event);
		if ($entity === null || strtolower($this->gateway->resolveSchemaSlug((string)$entity->getSchema())) !== self::REQUEST_SLUG) {
			return;
		}

		$request = ($entity->getObject() ?? []);
		if ($event instanceof ObjectCreatingEvent) {
			$this->respond($event, $this->guarded(fn (): array => $this->service->prepare($request)));
			return;
		}

		if ($event instanceof ObjectUpdatingEvent) {
			$old = ($event->getOldObject()?->getObject() ?? []);
			$this->respond($event, $this->guarded(fn (): array => $this->service->decide($old, $request)));
			return;
		}

		try {
			$this->applier->apply((string)$entity->getUuid(), $request);
		} catch (\Throwable $e) {
			$this->logger->warning('humaniq: ChangeRequestListener could not apply a change request', ['exception' => $e->getMessage()]);
		}
	}//end handle()

	/**
	 * Run a pre-save step; a failure refuses the write.
	 *
	 * @param callable(): array{stamps: array<string, mixed>, error: string|null} $step The step.
	 *
	 * @return array{stamps: array<string, mixed>, error: string|null}
	 */
	private function guarded(callable $step): array {
		try {
			return $step();
		} catch (\Throwable $e) {
			$this->logger->warning('humaniq: ChangeRequestListener could not check a change request', ['exception' => $e->getMessage()]);
			return ['stamps' => [], 'error' => 'Het wijzigingsverzoek kon niet worden gecontroleerd en is niet opgeslagen.'];
		}
	}//end guarded()

	/**
	 * Apply a pre-save outcome to the event.
	 *
	 * @param ObjectCreatingEvent|ObjectUpdatingEvent                        $event   The event.
	 * @param array{stamps: array<string, mixed>, error: string|null} $outcome The outcome.
	 *
	 * @return void
	 */
	private function respond(ObjectCreatingEvent|ObjectUpdatingEvent $event, array $outcome): void {
		if ($outcome['error'] !== null) {
			$event->setErrors(['message' => $outcome['error']]);
			$event->stopPropagation();
			return;
		}

		if ($outcome['stamps'] !== []) {
			$event->setModifiedData($outcome['stamps']);
		}
	}//end respond()

	/**
	 * The entity an event is about, or null for any other event.
	 *
	 * @param Event $event The event.
	 *
	 * @return object|null
	 */
	private function entityOf(Event $event): ?object {
		if ($event instanceof ObjectCreatingEvent || $event instanceof ObjectCreatedEvent) {
			return $event->getObject();
		}

		if ($event instanceof ObjectUpdatingEvent || $event instanceof ObjectUpdatedEvent) {
			return $event->getNewObject();
		}

		return null;
	}//end entityOf()

}//end class
