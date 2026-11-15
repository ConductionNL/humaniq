<?php

/**
 * Pre-Save Events
 *
 * The two things the humaniq object listeners do with an OpenRegister object
 * event besides their own rule: find the object the event is about, and
 * refuse a pre-save write with a message the caller sees.
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
 * @spec openspec/specs/payroll-cost-allocation/spec.md#REQ-PCA-001
 */

declare(strict_types=1);

namespace OCA\Humaniq\Listener;

use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCP\EventDispatcher\Event;

/**
 * Entity lookup and write refusal for OpenRegister object events.
 *
 * @spec openspec/specs/payroll-cost-allocation/spec.md#REQ-PCA-001
 */
final class PreSaveEvents {

	/**
	 * Stop the write and carry the reason back to the caller.
	 *
	 * An event that cannot carry both an error and a stop is left alone.
	 *
	 * @param object $event   The dispatched event.
	 * @param string $message The refusal.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/payroll-cost-allocation/spec.md#REQ-PCA-001
	 */
	public static function refuse(object $event, string $message): void {
		if (method_exists($event, 'setErrors') === false || method_exists($event, 'stopPropagation') === false) {
			return;
		}

		$event->setErrors(['message' => $message]);
		$event->stopPropagation();

	}//end refuse()

	/**
	 * The entity an event is about: the object of a create, the new object of
	 * an update, or null for any other event.
	 *
	 * @param Event $event The event.
	 *
	 * @return object|null
	 *
	 * @spec openspec/specs/payroll-cost-allocation/spec.md#REQ-PCA-001
	 */
	public static function entityOf(Event $event): ?object {
		if ($event instanceof ObjectCreatingEvent || $event instanceof ObjectCreatedEvent) {
			return $event->getObject();
		}

		if ($event instanceof ObjectUpdatingEvent || $event instanceof ObjectUpdatedEvent) {
			return $event->getNewObject();
		}

		return null;

	}//end entityOf()
}//end class
