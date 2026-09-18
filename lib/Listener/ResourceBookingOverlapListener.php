<?php

/**
 * Resource Booking Overlap Listener
 *
 * Pre-save listener on OpenRegister's `ObjectCreatingEvent` /
 * `ObjectUpdatingEvent` for `ResourceBooking` writes. It refuses a booking that
 * would take a resource past its quantity over an overlapping period, and a
 * booking on a resource that is out of service.
 *
 * WHY IT IS HERE AND NOT IN A CONTROLLER
 * --------------------------------------
 * humaniq's booking pages are declarative, and a consuming app books a room
 * through OpenRegister's object API directly. A clash rule enforced in one
 * controller is a rule the other callers never meet, which is the same as not
 * having it for exactly the callers that matter (design D6).
 *
 * FAIL CLOSED
 * -----------
 * A resource that cannot be read, or a check that throws, refuses the booking.
 * An unchecked booking that lands is discovered by two people arriving at one
 * room; a refused one is retried.
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
 * @spec openspec/specs/agenda-and-resource-booking/spec.md#REQ-AGD-004
 */

declare(strict_types=1);

namespace OCA\Humaniq\Listener;

use OCA\Humaniq\Service\HoursRegisterGateway;
use OCA\Humaniq\Service\ResourceBookingService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;

/**
 * Refuses a resource booking that clashes with the bookings already held.
 *
 * @template-implements IEventListener<Event>
 *
 * @spec openspec/specs/agenda-and-resource-booking/spec.md#REQ-AGD-004
 */
class ResourceBookingOverlapListener implements IEventListener {

	/**
	 * Lower-cased slug of the schema this listener guards.
	 *
	 * @var string
	 */
	public const RESOURCEBOOKING_SLUG = 'resourcebooking';

	/**
	 * Constructor.
	 *
	 * @param HoursRegisterGateway $gateway The shared register plumbing.
	 * @param ResourceBookingService $bookings The clash rule itself.
	 * @param LoggerInterface $logger The logger.
	 */
	public function __construct(
		private readonly HoursRegisterGateway $gateway,
		private readonly ResourceBookingService $bookings,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * Handle a pre-save event for a ResourceBooking write.
	 *
	 * @param Event $event The dispatched event.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/agenda-and-resource-booking/spec.md#REQ-AGD-004
	 */
	public function handle(Event $event): void {
		$incoming = $this->incoming($event);
		if ($incoming === null) {
			return;
		}

		try {
			$refusal = $this->bookings->refusal(
				booking: $incoming,
				resource: $this->gateway->findObjectData(trim((string)($incoming['resourceId'] ?? '')), 'Resource'),
				existing: $this->gateway->findFiltered(
					'ResourceBooking',
					['resourceId' => trim((string)($incoming['resourceId'] ?? ''))]
				)
			);
		} catch (\Throwable $e) {
			$this->logger->warning(
				'humaniq: ResourceBookingOverlapListener could not check a booking',
				['exception' => $e->getMessage()]
			);
			$this->refuse($event, 'De reservering kon niet worden gecontroleerd; probeer het later opnieuw.');
			return;
		}

		if ($refusal !== null) {
			$this->refuse($event, $refusal);
		}
	}//end handle()

	/**
	 * The payload of a ResourceBooking write, or null for any other event or
	 * schema. On an update the stored uuid travels with it, so a booking is
	 * not read as its own clash.
	 *
	 * @param Event $event The dispatched event.
	 *
	 * @return array<string, mixed>|null The payload.
	 *
	 * @spec openspec/specs/agenda-and-resource-booking/spec.md#REQ-AGD-004
	 */
	private function incoming(Event $event): ?array {
		if ($event instanceof \OCA\OpenRegister\Event\ObjectCreatingEvent) {
			$entity = $event->getObject();
			if ($this->isBooking($entity) === false) {
				return null;
			}

			return ($entity->getObject() ?? []);
		}

		if ($event instanceof \OCA\OpenRegister\Event\ObjectUpdatingEvent) {
			$entity = $event->getNewObject();
			if ($this->isBooking($entity) === false) {
				return null;
			}

			$payload = ($entity->getObject() ?? []);
			$uuid = trim((string)$entity->getUuid());
			if ($uuid !== '') {
				$payload['id'] = $uuid;
			}

			return $payload;
		}

		return null;
	}//end incoming()

	/**
	 * Whether the entity is a ResourceBooking (slug gate, defence in depth
	 * under the subscription-level filter).
	 *
	 * @param object $entity The ObjectEntity.
	 *
	 * @return bool True for the ResourceBooking schema.
	 */
	private function isBooking(object $entity): bool {
		return (strtolower($this->gateway->resolveSchemaSlug((string)$entity->getSchema())) === self::RESOURCEBOOKING_SLUG);
	}//end isBooking()

	/**
	 * Stop the write and carry the reason back to the caller.
	 *
	 * @param object $event The dispatched event.
	 * @param string $message The refusal.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/agenda-and-resource-booking/spec.md#REQ-AGD-004
	 */
	private function refuse(object $event, string $message): void {
		if (method_exists($event, 'setErrors') === false || method_exists($event, 'stopPropagation') === false) {
			return;
		}

		$event->setErrors(['message' => $message]);
		$event->stopPropagation();
	}//end refuse()
}//end class
