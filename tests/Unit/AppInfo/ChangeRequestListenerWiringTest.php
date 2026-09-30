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
	use OCA\Humaniq\Listener\AnnouncementConfirmationListener;
	use OCA\Humaniq\Listener\ApprovalDecisionStampListener;
	use OCA\Humaniq\Service\AnnouncementService;
	use OCA\Humaniq\Listener\ChangeRequestListener;
	use OCA\Humaniq\Listener\ManagerDeputyListener;
	use OCA\Humaniq\Listener\EmployeeGuardedFieldListener;
	use OCA\Humaniq\Listener\FieldAccessListener;
	use OCA\Humaniq\Listener\HrLifecycleEventListener;
	use OCA\Humaniq\Listener\RightToWorkCheckListener;
	use OCA\Humaniq\Listener\ScenarioMutationListener;
	use OCA\Humaniq\Listener\ExitInterviewListener;
	use OCA\Humaniq\Listener\RelationsCaseListener;
	use OCA\Humaniq\Listener\SideActivityListener;
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

		/**
		 * REQ-SEC-003: the side activity listener hears every new report, every
		 * saved or deleted one, and every employee update before it is saved.
		 *
		 * @return void
		 */
		public function testTheSideActivityListenerIsSubscribed(): void {
			if (property_exists(ObjectEventSubscription::class, 'recorded') === false) {
				self::markTestSkipped('The real OpenRegister subscription class is loaded; its registry is not observable here.');
			}

			ObjectEventSubscription::$recorded = [];
			$app = (new \ReflectionClass(Application::class))->newInstanceWithoutConstructor();
			$method = new \ReflectionMethod(Application::class, 'registerSideActivityListeners');
			$method->invoke($app, $this->createMock(IEventDispatcher::class));

			$bySchema = [];
			foreach (ObjectEventSubscription::$recorded as $entry) {
				self::assertSame(SideActivityListener::class, $entry['listener']);
				$bySchema[$entry['schemas'][0]][] = $entry['event'];
			}

			self::assertSame(
				[
					'OCA\OpenRegister\Event\ObjectCreatingEvent',
					'OCA\OpenRegister\Event\ObjectCreatedEvent',
					'OCA\OpenRegister\Event\ObjectUpdatedEvent',
					'OCA\OpenRegister\Event\ObjectDeletedEvent',
				],
				$bySchema['sideactivity']
			);
			self::assertSame(['OCA\OpenRegister\Event\ObjectUpdatingEvent'], $bySchema['employee']);

			$boot = (string)file_get_contents(dirname(__DIR__, 3) . '/lib/AppInfo/Application.php');
			self::assertStringContainsString('$this->registerSideActivityListeners($dispatcher);', $boot);
		}//end testTheSideActivityListenerIsSubscribed()

		/**
		 * REQ-ERC-002: a relations case is stamped before every create and update.
		 *
		 * @return void
		 */
		public function testTheRelationsCaseListenerIsSubscribed(): void {
			if (property_exists(ObjectEventSubscription::class, 'recorded') === false) {
				self::markTestSkipped('The real OpenRegister subscription class is loaded; its registry is not observable here.');
			}

			ObjectEventSubscription::$recorded = [];
			$app = (new \ReflectionClass(Application::class))->newInstanceWithoutConstructor();
			$method = new \ReflectionMethod(Application::class, 'registerRelationsCaseListeners');
			$method->invoke($app, $this->createMock(IEventDispatcher::class));

			$events = [];
			foreach (ObjectEventSubscription::$recorded as $entry) {
				self::assertSame(RelationsCaseListener::class, $entry['listener']);
				self::assertSame([RelationsCaseListener::CASE_SLUG], $entry['schemas']);
				$events[] = $entry['event'];
			}

			self::assertSame(['OCA\OpenRegister\Event\ObjectCreatingEvent', 'OCA\OpenRegister\Event\ObjectUpdatingEvent'], $events);
			$boot = (string)file_get_contents(dirname(__DIR__, 3) . '/lib/AppInfo/Application.php');
			self::assertStringContainsString('$this->registerRelationsCaseListeners($dispatcher);', $boot);
		}//end testTheRelationsCaseListenerIsSubscribed()

		/**
		 * REQ-OFC-001: an exit interview is filled in before it is created and stamps its case after.
		 *
		 * @return void
		 */
		public function testTheExitInterviewListenerIsSubscribed(): void {
			if (property_exists(ObjectEventSubscription::class, 'recorded') === false) {
				self::markTestSkipped('The real OpenRegister subscription class is loaded; its registry is not observable here.');
			}

			ObjectEventSubscription::$recorded = [];
			$app = (new \ReflectionClass(Application::class))->newInstanceWithoutConstructor();
			$method = new \ReflectionMethod(Application::class, 'registerExitInterviewListeners');
			$method->invoke($app, $this->createMock(IEventDispatcher::class));

			$events = [];
			foreach (ObjectEventSubscription::$recorded as $entry) {
				self::assertSame(ExitInterviewListener::class, $entry['listener']);
				self::assertSame([ExitInterviewListener::SLUG], $entry['schemas']);
				$events[] = $entry['event'];
			}

			self::assertSame(['OCA\OpenRegister\Event\ObjectCreatingEvent', 'OCA\OpenRegister\Event\ObjectCreatedEvent'], $events);
			$boot = (string)file_get_contents(dirname(__DIR__, 3) . '/lib/AppInfo/Application.php');
			self::assertStringContainsString('$this->registerExitInterviewListeners($dispatcher);', $boot);
		}//end testTheExitInterviewListenerIsSubscribed()

		/**
		 * REQ-AND-002: a second confirmation is refused before it is saved.
		 *
		 * @return void
		 */
		public function testTheAnnouncementConfirmationListenerIsSubscribed(): void {
			if (property_exists(ObjectEventSubscription::class, 'recorded') === false) {
				self::markTestSkipped('The real OpenRegister subscription class is loaded; its registry is not observable here.');
			}

			ObjectEventSubscription::$recorded = [];
			$app = (new \ReflectionClass(Application::class))->newInstanceWithoutConstructor();
			$method = new \ReflectionMethod(Application::class, 'registerAnnouncementListener');
			$method->invoke($app, $this->createMock(IEventDispatcher::class));

			self::assertCount(1, ObjectEventSubscription::$recorded);
			self::assertSame(AnnouncementConfirmationListener::class, ObjectEventSubscription::$recorded[0]['listener']);
			self::assertSame([AnnouncementService::CONFIRMATION_SLUG], ObjectEventSubscription::$recorded[0]['schemas']);
			self::assertSame('OCA\OpenRegister\Event\ObjectCreatingEvent', ObjectEventSubscription::$recorded[0]['event']);
			$boot = (string)file_get_contents(dirname(__DIR__, 3) . '/lib/AppInfo/Application.php');
			self::assertStringContainsString('$this->registerAnnouncementListener($dispatcher);', $boot);
		}//end testTheAnnouncementConfirmationListenerIsSubscribed()

		/**
		 * REQ-PBS-002: the fixed-scenario refusal hears every mutation write before it is saved.
		 *
		 * @return void
		 */
		public function testTheScenarioMutationListenerIsSubscribed(): void {
			if (property_exists(ObjectEventSubscription::class, 'recorded') === false) {
				self::markTestSkipped('The real OpenRegister subscription class is loaded; its registry is not observable here.');
			}

			ObjectEventSubscription::$recorded = [];
			$app = (new \ReflectionClass(Application::class))->newInstanceWithoutConstructor();
			$method = new \ReflectionMethod(Application::class, 'registerScenarioMutationListener');
			$method->invoke($app, $this->createMock(IEventDispatcher::class));

			self::assertSame(['OCA\OpenRegister\Event\ObjectCreatingEvent', 'OCA\OpenRegister\Event\ObjectUpdatingEvent'], array_column(ObjectEventSubscription::$recorded, 'event'));
			self::assertSame([ScenarioMutationListener::class, ScenarioMutationListener::class], array_column(ObjectEventSubscription::$recorded, 'listener'));
			self::assertSame(['scenariomutation'], ObjectEventSubscription::$recorded[0]['schemas']);

			$boot = (string)file_get_contents(dirname(__DIR__, 3) . '/lib/AppInfo/Application.php');
			self::assertStringContainsString('$this->registerScenarioMutationListener($dispatcher);', $boot);
		}//end testTheScenarioMutationListenerIsSubscribed()

		/**
		 * REQ-API-001/002: a deputy record is judged before it is saved, and
		 * the three approvable schemas without their own stamp listener are
		 * stamped on update.
		 *
		 * @return void
		 */
		public function testTheApprovalsInboxListenersAreSubscribed(): void {
			if (property_exists(ObjectEventSubscription::class, 'recorded') === false) {
				self::markTestSkipped('The real OpenRegister subscription class is loaded; its registry is not observable here.');
			}

			ObjectEventSubscription::$recorded = [];
			$app = (new \ReflectionClass(Application::class))->newInstanceWithoutConstructor();
			$method = new \ReflectionMethod(Application::class, 'registerApprovalsInboxListeners');
			$method->invoke($app, $this->createMock(IEventDispatcher::class));

			$byListener = [];
			foreach (ObjectEventSubscription::$recorded as $entry) {
				$byListener[$entry['listener']][] = $entry;
			}

			self::assertSame(
				['OCA\OpenRegister\Event\ObjectCreatingEvent', 'OCA\OpenRegister\Event\ObjectUpdatingEvent'],
				array_column($byListener[ManagerDeputyListener::class], 'event')
			);
			self::assertSame(['managerdeputy'], $byListener[ManagerDeputyListener::class][0]['schemas']);
			self::assertSame(['OCA\OpenRegister\Event\ObjectUpdatingEvent'], array_column($byListener[ApprovalDecisionStampListener::class], 'event'));
			self::assertSame(['leaverequest', 'expense', 'leavetransaction'], $byListener[ApprovalDecisionStampListener::class][0]['schemas']);

			$boot = (string)file_get_contents(dirname(__DIR__, 3) . '/lib/AppInfo/Application.php');
			self::assertStringContainsString('$this->registerApprovalsInboxListeners($dispatcher);', $boot);
		}//end testTheApprovalsInboxListenersAreSubscribed()

		/**
		 * REQ-HLE-001: the lifecycle listener hears every save of the six schemas that mark an HR moment.
		 *
		 * @return void
		 */
		public function testTheHrLifecycleEventListenerIsSubscribed(): void {
			if (property_exists(ObjectEventSubscription::class, 'recorded') === false) {
				self::markTestSkipped('The real OpenRegister subscription class is loaded; its registry is not observable here.');
			}

			ObjectEventSubscription::$recorded = [];
			$app = (new \ReflectionClass(Application::class))->newInstanceWithoutConstructor();
			$method = new \ReflectionMethod(Application::class, 'registerHrLifecycleEventListener');
			$method->invoke($app, $this->createMock(IEventDispatcher::class));

			self::assertSame(['OCA\OpenRegister\Event\ObjectCreatedEvent', 'OCA\OpenRegister\Event\ObjectUpdatedEvent'], array_column(ObjectEventSubscription::$recorded, 'event'));
			self::assertSame([HrLifecycleEventListener::class], array_values(array_unique(array_column(ObjectEventSubscription::$recorded, 'listener'))));
			self::assertSame(['employmentcontract', 'onboarding', 'offboarding', 'orgassignment', 'leaverequest', 'sickleavecase'], ObjectEventSubscription::$recorded[0]['schemas']);

			$boot = (string)file_get_contents(dirname(__DIR__, 3) . '/lib/AppInfo/Application.php');
			self::assertStringContainsString('$this->registerHrLifecycleEventListener($dispatcher);', $boot);
		}//end testTheHrLifecycleEventListenerIsSubscribed()

	}//end class
}
