<?php

/**
 * TravelListenerWiringTest
 *
 * The caller side of TravelAmountListener: Application subscribes it to the
 * create and update pre-save events of both schemas it stamps. Without this
 * the listener's own tests would stay green while nothing ever called it.
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
 * @spec openspec/specs/expenses-travel-calculation/spec.md#REQ-TRV-001
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
				self::$recorded[] = ['event' => $event, 'listener' => $listener, 'schemas' => $schemas];
			}//end subscribe()

		}//end class
	}//end if
}

namespace OCA\Humaniq\Tests\Unit\AppInfo {

	use OCA\Humaniq\AppInfo\Application;
	use OCA\Humaniq\Listener\TravelAmountListener;
	use OCA\OpenRegister\Event\ObjectEventSubscription;
	use OCP\EventDispatcher\IEventDispatcher;
	use PHPUnit\Framework\TestCase;

	/**
	 * Application subscribes the travel listener.
	 */
	class TravelListenerWiringTest extends TestCase {

		/**
		 * Both pre-save events, both schemas.
		 *
		 * @return void
		 */
		public function testTheTravelListenerIsSubscribedForClaimsAndArrangements(): void {
			if (property_exists(ObjectEventSubscription::class, 'recorded') === false) {
				self::markTestSkipped('The real OpenRegister subscription class is loaded; its registry is not observable here.');
			}

			ObjectEventSubscription::$recorded = [];
			$app = (new \ReflectionClass(Application::class))->newInstanceWithoutConstructor();
			$method = new \ReflectionMethod(Application::class, 'registerTravelListeners');
			$method->invoke($app, $this->createMock(IEventDispatcher::class));

			$events = [];
			foreach (ObjectEventSubscription::$recorded as $entry) {
				self::assertSame(TravelAmountListener::class, $entry['listener']);
				self::assertSame(['expense', 'commutearrangement'], $entry['schemas']);
				$events[] = $entry['event'];
			}

			self::assertSame(['OCA\OpenRegister\Event\ObjectCreatingEvent', 'OCA\OpenRegister\Event\ObjectUpdatingEvent'], $events);
		}//end testTheTravelListenerIsSubscribedForClaimsAndArrangements()

	}//end class
}
