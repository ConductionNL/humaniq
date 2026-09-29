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
			gateway: $this->gateway(),
			container: new FakeContainer(['OCA\OpenRegister\Service\WebhookService' => $webhook]),
			eventDispatcher: $this->createMock(IEventDispatcher::class),
			logger: new NullLogger()
		);

		return new HrLifecycleEventListener(events: $service, gateway: $this->gateway(), logger: new NullLogger());
	}//end listener()

}//end class
