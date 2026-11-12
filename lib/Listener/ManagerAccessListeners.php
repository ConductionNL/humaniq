<?php

/**
 * Humaniq ManagerAccessListeners
 *
 * The event subscriptions of the manager access stamps: the pre-save stamp
 * on the four stamped schemas, and the refresh queued after an org or
 * deputy change.
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
 * @spec openspec/changes/manager-record-access/specs/mss-team-scope/spec.md#Requirement:-A-manager-and-their-deputies-SHALL-read-their-team's-records-through-stamped-access-fields-(REQ-MSS-009)
 */

declare(strict_types=1);

namespace OCA\Humaniq\Listener;

use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectDeletedEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;

/**
 * Subscribes the manager access listeners.
 *
 * @spec openspec/changes/manager-record-access/specs/mss-team-scope/spec.md#Requirement:-A-manager-and-their-deputies-SHALL-read-their-team's-records-through-stamped-access-fields-(REQ-MSS-009)
 */
final class ManagerAccessListeners {

	/**
	 * Hand every subscription to the app's filtered subscriber.
	 *
	 * @param callable(string, string, list<string>): void $subscribe Subscribes one listener for one event and its schema slugs.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/manager-record-access/specs/mss-team-scope/spec.md#Requirement:-A-manager-and-their-deputies-SHALL-read-their-team's-records-through-stamped-access-fields-(REQ-MSS-009)
	 */
	public static function register(callable $subscribe): void {
		foreach ([ObjectCreatingEvent::class, ObjectUpdatingEvent::class] as $event) {
			$subscribe($event, ManagerAccessStampListener::class, array_keys(ManagerAccessStampListener::SLUGS));
		}

		foreach ([ObjectCreatedEvent::class, ObjectUpdatedEvent::class, ObjectDeletedEvent::class] as $event) {
			$subscribe($event, ManagerAccessOrgChangeListener::class, ManagerAccessOrgChangeListener::SLUGS);
		}
	}//end register()

}//end class
