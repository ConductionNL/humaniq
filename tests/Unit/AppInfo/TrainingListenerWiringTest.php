<?php

/**
 * TrainingListenerWiringTest
 *
 * The caller side of TrainingRecordListener and LearniqCredentialListener:
 * Application subscribes the first to every create and update event of a
 * TrainingRecord, and the second to created credentials in learniq's
 * register. Without this the listeners' own tests would stay green while
 * nothing ever called them.
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
 * @spec openspec/specs/training-and-lms-sync/spec.md#REQ-TRN-003
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
	use OCA\Humaniq\Listener\LearniqCredentialListener;
	use OCA\Humaniq\Listener\TrainingRecordListener;
	use OCA\OpenRegister\Event\ObjectEventSubscription;
	use OCP\EventDispatcher\IEventDispatcher;
	use PHPUnit\Framework\TestCase;

	/**
	 * Application wires both training listeners.
	 */
	class TrainingListenerWiringTest extends TestCase {

		/**
		 * The training listener hears every write of a record, the credential
		 * listener only created credentials in learniq's register.
		 *
		 * @return void
		 */
		public function testBothTrainingListenersAreSubscribed(): void {
			if (property_exists(ObjectEventSubscription::class, 'recorded') === false) {
				self::markTestSkipped('The real OpenRegister subscription class is loaded; its registry is not observable here.');
			}

			ObjectEventSubscription::$recorded = [];
			$app = (new \ReflectionClass(Application::class))->newInstanceWithoutConstructor();
			$method = new \ReflectionMethod(Application::class, 'registerTrainingListeners');
			$method->invoke($app, $this->createMock(IEventDispatcher::class));

			$byListener = [];
			foreach (ObjectEventSubscription::$recorded as $entry) {
				$byListener[$entry['listener']][] = $entry;
			}

			self::assertSame(
				[
					'OCA\OpenRegister\Event\ObjectCreatingEvent',
					'OCA\OpenRegister\Event\ObjectUpdatingEvent',
					'OCA\OpenRegister\Event\ObjectCreatedEvent',
					'OCA\OpenRegister\Event\ObjectUpdatedEvent',
				],
				array_column($byListener[TrainingRecordListener::class], 'event')
			);
			self::assertSame([['trainingrecord']], array_values(array_unique(array_column($byListener[TrainingRecordListener::class], 'schemas'), SORT_REGULAR)));

			$credential = $byListener[LearniqCredentialListener::class];
			self::assertCount(1, $credential);
			self::assertSame('OCA\OpenRegister\Event\ObjectCreatedEvent', $credential[0]['event']);
			self::assertSame(['learniq', 'scholiq'], $credential[0]['registers']);
			self::assertSame(['credential'], $credential[0]['schemas']);
		}//end testBothTrainingListenersAreSubscribed()

	}//end class
}
