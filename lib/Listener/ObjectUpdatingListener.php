<?php

/**
 * Humaniq ObjectUpdatingListener
 *
 * The listeners that act on an OpenRegister object update before it is saved
 * ignore every other event; this base does that once.
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
 * @spec openspec/specs/approvals-inbox/spec.md#REQ-API-001
 */

declare(strict_types=1);

namespace OCA\Humaniq\Listener;

use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;

/**
 * Hands the pre-save update event to the listener and ignores any other.
 *
 * @template-implements IEventListener<Event>
 *
 * @spec openspec/specs/approvals-inbox/spec.md#REQ-API-001
 */
abstract class ObjectUpdatingListener implements IEventListener {

	/**
	 * Act on an object update; ignore any other event.
	 *
	 * @param Event $event The event.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/approvals-inbox/spec.md#REQ-API-001
	 */
	public function handle(Event $event): void {
		if (($event instanceof ObjectUpdatingEvent) === false) {
			return;
		}

		$this->onUpdating($event);
	}//end handle()

	/**
	 * Act on the pre-save update.
	 *
	 * @param ObjectUpdatingEvent $event The pre-save event.
	 *
	 * @return void
	 */
	abstract protected function onUpdating(ObjectUpdatingEvent $event): void;
}//end class
