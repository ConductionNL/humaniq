<?php

/**
 * ChangeRequestListenerWiringTest
 *
 * The caller side of ChangeRequestListener and EmployeeGuardedFieldListener:
 * Application subscribes the first to every create and update event of an
 * EmployeeChangeRequest, and the second to employee updates before they are
 * saved. Without this the listeners' own tests would stay green while nothing
 * ever called them.
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
 * @spec openspec/specs/employee-change-approval/spec.md#REQ-ECR-002
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
	use OCA\Humaniq\Listener\ChangeRequestListener;
	use OCA\Humaniq\Listener\EmployeeGuardedFieldListener;
	use OCA\Humaniq\Listener\FieldAccessListener;
	use OCA\Humaniq\Listener\RightToWorkCheckListener;
	use OCA\OpenRegister\Event\ObjectEventSubscription;
	use OCP\EventDispatcher\IEventDispatcher;
	use PHPUnit\Framework\TestCase;

	/**
	 * Application wires both change request listeners.
	 */
	class ChangeRequestListenerWiringTest extends TestCase {

		/**
		 * The request listener hears every write of a request, the field guard
		 * every employee update before it is saved.
		 *
		 * @return void
		 */
		public function testBothChangeRequestListenersAreSubscribed(): void {
			if (property_exists(ObjectEventSubscription::class, 'recorded') === false) {
				self::markTestSkipped('The real OpenRegister subscription class is loaded; its registry is not observable here.');
			}

			ObjectEventSubscription::$recorded = [];
			$app = (new \ReflectionClass(Application::class))->newInstanceWithoutConstructor();
			$method = new \ReflectionMethod(Application::class, 'registerChangeRequestListeners');
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
				array_column($byListener[ChangeRequestListener::class], 'event')
			);
			self::assertSame(['employeechangerequest'], $byListener[ChangeRequestListener::class][0]['schemas']);
			self::assertSame(['OCA\OpenRegister\Event\ObjectUpdatingEvent'], array_column($byListener[EmployeeGuardedFieldListener::class], 'event'));
			self::assertSame(['employee'], $byListener[EmployeeGuardedFieldListener::class][0]['schemas']);
		}//end testBothChangeRequestListenersAreSubscribed()

		/**
		 * REQ-RFA-002: the field access listener hears every create and
		 * update of the four schemas with field-level authorization.
		 *
		 * @return void
		 */
		public function testTheFieldAccessListenerIsSubscribed(): void {
			if (property_exists(ObjectEventSubscription::class, 'recorded') === false) {
				self::markTestSkipped('The real OpenRegister subscription class is loaded; its registry is not observable here.');
			}

			ObjectEventSubscription::$recorded = [];
			$app = (new \ReflectionClass(Application::class))->newInstanceWithoutConstructor();
			$method = new \ReflectionMethod(Application::class, 'registerFieldAccessListener');
			$method->invoke($app, $this->createMock(IEventDispatcher::class));

			self::assertSame(
				['OCA\OpenRegister\Event\ObjectCreatingEvent', 'OCA\OpenRegister\Event\ObjectUpdatingEvent'],
				array_column(ObjectEventSubscription::$recorded, 'event')
			);
			self::assertSame([FieldAccessListener::class, FieldAccessListener::class], array_column(ObjectEventSubscription::$recorded, 'listener'));
			self::assertSame(['employee', 'employmentcontract', 'payslip', 'performancereview'], ObjectEventSubscription::$recorded[0]['schemas']);

			$boot = (string)file_get_contents(dirname(__DIR__, 3) . '/lib/AppInfo/Application.php');
			self::assertStringContainsString('$this->registerFieldAccessListener($dispatcher);', $boot);
		}//end testTheFieldAccessListenerIsSubscribed()

		/**
		 * REQ-DCP-003: the right-to-work listener hears every write of a check,
		 * before it is saved (the decision) and after (the follow-up).
		 *
		 * @return void
		 */
		public function testTheRightToWorkListenerIsSubscribed(): void {
			if (property_exists(ObjectEventSubscription::class, 'recorded') === false) {
				self::markTestSkipped('The real OpenRegister subscription class is loaded; its registry is not observable here.');
			}

			ObjectEventSubscription::$recorded = [];
			$app = (new \ReflectionClass(Application::class))->newInstanceWithoutConstructor();
			$method = new \ReflectionMethod(Application::class, 'registerDossierListeners');
			$method->invoke($app, $this->createMock(IEventDispatcher::class));

			self::assertSame(
				[
					'OCA\OpenRegister\Event\ObjectCreatingEvent',
					'OCA\OpenRegister\Event\ObjectUpdatingEvent',
					'OCA\OpenRegister\Event\ObjectCreatedEvent',
					'OCA\OpenRegister\Event\ObjectUpdatedEvent',
				],
				array_column(ObjectEventSubscription::$recorded, 'event')
			);
			self::assertSame([RightToWorkCheckListener::class], array_values(array_unique(array_column(ObjectEventSubscription::$recorded, 'listener'))));
			self::assertSame(['righttoworkcheck'], ObjectEventSubscription::$recorded[0]['schemas']);

			$boot = (string)file_get_contents(dirname(__DIR__, 3) . '/lib/AppInfo/Application.php');
			self::assertStringContainsString('$this->registerDossierListeners($dispatcher);', $boot);
		}//end testTheRightToWorkListenerIsSubscribed()

	}//end class
}
