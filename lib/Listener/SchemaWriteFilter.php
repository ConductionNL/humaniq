<?php

/**
 * Schema Write Filter
 *
 * Decides whether a pre-save listener judges a write at all: a create or an
 * update of an object of its own schema, made by someone other than humaniq
 * itself (humaniq's own writes are marked internal and pass every listener).
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
 * @spec openspec/changes/allowed-expense-categories/specs/humaniq-expenses/spec.md#Requirement:-The-employer-SHALL-choose-which-expense-categories-employees-may-claim
 */

declare(strict_types=1);

namespace OCA\Humaniq\Listener;

use OCA\Humaniq\Service\HoursRegisterGateway;
use OCA\Humaniq\Service\InternalWriteMarker;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCP\EventDispatcher\Event;

/**
 * Which pre-save writes a schema's listener judges.
 *
 * @spec openspec/changes/allowed-expense-categories/specs/humaniq-expenses/spec.md#Requirement:-The-employer-SHALL-choose-which-expense-categories-employees-may-claim
 */
final class SchemaWriteFilter {

	/**
	 * @param HoursRegisterGateway $gateway Resolves the schema slug.
	 * @param InternalWriteMarker  $marker  Marks humaniq's own writes.
	 */
	public function __construct(
		private readonly HoursRegisterGateway $gateway,
		private readonly InternalWriteMarker $marker,
	) {

	}//end __construct()

	/**
	 * Whether the event is an outside create or update of an object of the schema.
	 *
	 * @param Event  $event The dispatched event.
	 * @param string $slug  The schema slug, lower case.
	 *
	 * @return bool
	 *
	 * @phpstan-assert-if-true ObjectCreatingEvent|ObjectUpdatingEvent $event
	 *
	 * @spec openspec/changes/allowed-expense-categories/specs/humaniq-expenses/spec.md#Requirement:-The-employer-SHALL-choose-which-expense-categories-employees-may-claim
	 */
	public function judges(Event $event, string $slug): bool {
		if ($this->marker->isInternal() === true) {
			return false;
		}

		$entity = self::pendingObject($event);

		return $entity !== null && strtolower($this->gateway->resolveSchemaSlug((string)$entity->getSchema())) === $slug;

	}//end judges()

	/**
	 * The object a create or update is about to save, or null for any other event.
	 *
	 * @param Event $event The dispatched event.
	 *
	 * @return object|null
	 *
	 * @spec openspec/changes/allowed-expense-categories/specs/humaniq-expenses/spec.md#Requirement:-The-employer-SHALL-choose-which-expense-categories-employees-may-claim
	 */
	public static function pendingObject(Event $event): ?object {
		if ($event instanceof ObjectCreatingEvent) {
			return $event->getObject();
		}

		if ($event instanceof ObjectUpdatingEvent) {
			return $event->getNewObject();
		}

		return null;

	}//end pendingObject()
}//end class
