<?php

/**
 * HoursListenerWiringTest
 *
 * The caller side of TimeEntryStampListener: Application subscribes it to the
 * pre-save, pre-delete and pre-move events of the TimeEntry schema.
 *
 * @category Tests
 * @package  OCA\Humaniq\Tests\Unit\AppInfo
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
 * @spec openspec/specs/time-entry-capture/spec.md#Requirement:-Entries-of-a-submitted-or-approved-timesheet-are-immutable-(REQ-TEC-005)
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Event {
	if (class_exists(ObjectEventSubscription::class) === false) {
		/**
		 * Records subscriptions with the real OpenRegister signature.
		 */
		class ObjectEventSubscription {

			/**
			 * The recorded subscriptions.
			 *
			 * @var list<array{event: string, listener: string, schemas: array<int, string>|null}>
			 */
			public static array $recorded = [];

			/**
			 * Record one subscription.
			 *
			 * @param \OCP\EventDispatcher\IEventDispatcher $dispatcher The dispatcher.
			 * @param string                                $event      The event class.
			 * @param string                                $listener   The listener class.
			 * @param array<int, string>|null               $registers  Register slugs.
			 * @param array<int, string>|null               $schemas    Schema slugs.
			 *
			 * @return void
			 */
			public static function subscribe(
				\OCP\EventDispatcher\IEventDispatcher $dispatcher,
				string $event,
				string $listener,
				?array $registers=null,
				?array $schemas=null,
			): void {
				self::$recorded[] = ['event' => $event, 'listener' => $listener, 'registers' => $registers, 'schemas' => $schemas];
			}//end subscribe()

		}//end class
	}//end if
}

namespace OCA\Humaniq\Tests\Unit\AppInfo {

	use OCA\Humaniq\AppInfo\Application;
	use OCA\Humaniq\Listener\TimeEntryStampListener;
	use OCA\OpenRegister\Event\ObjectEventSubscription;
	use OCP\EventDispatcher\IEventDispatcher;
	use PHPUnit\Framework\TestCase;

	/**
	 * Application subscribes the time entry guard.
	 */
	class HoursListenerWiringTest extends TestCase {

		/**
		 * The guard hears a create, an update, a delete and a move of a time entry.
		 *
		 * @return void
		 */
		public function testTheTimeEntryGuardHearsAMoveLikeADelete(): void {
			if (property_exists(ObjectEventSubscription::class, 'recorded') === false) {
				self::markTestSkipped('The real OpenRegister subscription class is loaded; its registry is not observable here.');
			}

			ObjectEventSubscription::$recorded = [];
			$app = (new \ReflectionClass(Application::class))->newInstanceWithoutConstructor();
			$method = new \ReflectionMethod(Application::class, 'registerHoursListeners');
			$method->invoke($app, $this->createMock(IEventDispatcher::class));

			$events = [];
			foreach (ObjectEventSubscription::$recorded as $entry) {
				if ($entry['listener'] !== TimeEntryStampListener::class) {
					continue;
				}

				self::assertSame([TimeEntryStampListener::TIMEENTRY_SLUG], $entry['schemas']);
				$events[] = $entry['event'];
			}

			self::assertSame(
				[
					'OCA\OpenRegister\Event\ObjectCreatingEvent',
					'OCA\OpenRegister\Event\ObjectUpdatingEvent',
					'OCA\OpenRegister\Event\ObjectDeletingEvent',
					'OCA\OpenRegister\Event\ObjectLeavingEvent',
				],
				$events
			);
		}//end testTheTimeEntryGuardHearsAMoveLikeADelete()
	}//end class
}
