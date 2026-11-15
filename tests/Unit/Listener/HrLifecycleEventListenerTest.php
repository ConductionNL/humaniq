<?php

/**
 * Unit tests for HrLifecycleEventListener (platform-hr-lifecycle-events task 1.4).
 *
 * Approving a leave request, as OpenRegister's real ObjectUpdatedEvent carries
 * it, reaches the webhook service once; saving it again reaches it not at
 * all; and a broken service never breaks the save.
 *
 * @category Test
 * @package  OCA\Humaniq\Tests\Unit\Listener
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
 * @spec openspec/specs/hr-lifecycle-events/spec.md#REQ-HLE-001
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Listener;

use OCA\Humaniq\Listener\HrLifecycleEventListener;
use OCA\Humaniq\Service\HrLifecycleEventService;
use OCA\Humaniq\Service\HrLifecycleMoments;
use OCA\Humaniq\Tests\Unit\Support\ApprovalsFixture;
use OCA\Humaniq\Tests\Unit\Support\FakeContainer;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventDispatcher;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class HrLifecycleEventListenerTest extends TestCase {

	use ApprovalsFixture;

	/**
	 * @var array<int, string>
	 */
	public array $sent = [];

	protected function setUp(): void {
		parent::setUp();
		$this->seedTeam();
	}//end setUp()

	public function testApprovingALeaveRequestCallsTheWebhookOnce(): void {
		$submitted = ['employeeId' => 'emp-sam', 'startDate' => '2026-08-03', 'endDate' => '2026-08-07', 'status' => 'submitted'];
		$approved = array_merge($submitted, ['status' => 'approved']);

		$this->listener()->handle(new ObjectUpdatedEvent($this->entity($approved), $this->entity($submitted)));
		$this->listener()->handle(new ObjectUpdatedEvent($this->entity($approved), $this->entity($approved)));

		self::assertSame(['nl.conduction.hrmq.leave.approved'], $this->sent);
	}//end testApprovingALeaveRequestCallsTheWebhookOnce()

	private function entity(array $data): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid('lv-1');
		$entity->setSchema('LeaveRequest');
		$entity->setObject($data);
		return $entity;
	}//end entity()

	private function listener(): HrLifecycleEventListener {
		$test = $this;
		$webhook = new class($test) {

			public function __construct(
				private readonly HrLifecycleEventListenerTest $test,
			) {
			}//end __construct()

			public function dispatchEvent(Event $_event, string $eventName, array $payload): void {
				$this->test->sent[] = $eventName;
			}//end dispatchEvent()

		};
		$service = new HrLifecycleEventService(
			moments: new HrLifecycleMoments($this->gateway()),
			gateway: $this->gateway(),
			container: new FakeContainer(['OCA\OpenRegister\Service\WebhookService' => $webhook]),
			eventDispatcher: $this->createMock(IEventDispatcher::class),
			logger: new NullLogger()
		);

		return new HrLifecycleEventListener(events: $service, gateway: $this->gateway(), logger: new NullLogger());
	}//end listener()

	/**
	 * What the listener hands the lifecycle service for every kind of event:
	 * the slug, the stored record and the saved record with its id, or
	 * nothing at all; a failing service is logged, not thrown.
	 *
	 * @return void
	 */
	public function testWhatEveryEventHandsTheLifecycleService(): void {
		$calls = [];
		$events = $this->createMock(HrLifecycleEventService::class);
		$events->method('emit')->willReturnCallback(
			static function (string $slug, ?array $old, array $new) use (&$calls): int {
				$calls[] = ['slug' => $slug, 'old' => $old, 'new' => $new];
				if ($slug === 'payslip') {
					throw new \RuntimeException('webhook down');
				}

				return 1;
			}
		);
		$gateway = $this->createMock(\OCA\Humaniq\Service\OrgLookupInterface::class);
		$gateway->method('resolveSchemaSlug')->willReturnCallback(static fn (string $schema): string => strtoupper($schema));
		$listener = new HrLifecycleEventListener(events: $events, gateway: $gateway, logger: new NullLogger());
		$entity = static function (string $schema, array $data, string $uuid): ObjectEntity {
			$object = new ObjectEntity();
			$object->setUuid($uuid);
			$object->setSchema($schema);
			$object->setObject($data);
			return $object;
		};

		$listener->handle(new \OCA\OpenRegister\Event\ObjectCreatedEvent($entity('leaverequest', ['status' => 'submitted'], 'lv-1')));
		$listener->handle(new \OCA\OpenRegister\Event\ObjectCreatedEvent($entity('leaverequest', ['id' => 'own-id'], 'lv-2')));
		$listener->handle(new \OCA\OpenRegister\Event\ObjectCreatedEvent($entity('leaverequest', [], '')));
		$listener->handle(new ObjectUpdatedEvent($entity('sickleavecase', ['status' => 'hersteld'], 'sk-1'), $entity('sickleavecase', ['status' => 'gemeld'], 'sk-1')));
		$listener->handle(new ObjectUpdatedEvent($entity('payslip', ['x' => 1], 'ps-1'), $entity('payslip', [], 'ps-1')));
		$listener->handle(new \OCA\OpenRegister\Event\ObjectDeletedEvent($entity('leaverequest', [], 'lv-3')));
		$listener->handle(new \OCA\OpenRegister\Event\ObjectCreatingEvent($entity('leaverequest', [], 'lv-4')));

		self::assertSame(
			[
				['slug' => 'leaverequest', 'old' => null, 'new' => ['status' => 'submitted', 'id' => 'lv-1']],
				['slug' => 'leaverequest', 'old' => null, 'new' => ['id' => 'own-id']],
				['slug' => 'leaverequest', 'old' => null, 'new' => []],
				['slug' => 'sickleavecase', 'old' => ['status' => 'gemeld'], 'new' => ['status' => 'hersteld', 'id' => 'sk-1']],
				['slug' => 'payslip', 'old' => [], 'new' => ['x' => 1, 'id' => 'ps-1']],
			],
			$calls
		);
	}//end testWhatEveryEventHandsTheLifecycleService()

}//end class
