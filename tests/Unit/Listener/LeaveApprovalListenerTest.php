<?php

/**
 * LeaveApprovalListener tests.
 *
 * A created or updated leave request is projected onto its balance; anything else is left alone, and a failing projection is only a warning.
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
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Listener;

use OCA\Humaniq\Listener\LeaveApprovalListener;
use OCA\Humaniq\Service\LeaveBalanceProjectionService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCP\EventDispatcher\Event;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Leave requests reach the balance projection; other objects and events do not.
 *
 * @covers \OCA\Humaniq\Listener\LeaveApprovalListener
 */
class LeaveApprovalListenerTest extends TestCase {

	/** @var LeaveBalanceProjectionService&MockObject */
	private LeaveBalanceProjectionService $projection;

	/** @var LoggerInterface&MockObject */
	private LoggerInterface $logger;

	protected function setUp(): void {
		$this->projection = $this->createMock(LeaveBalanceProjectionService::class);
		$this->logger = $this->createMock(LoggerInterface::class);
	}

	/**
	 * The listener over schemas whose ids resolve to the given slugs.
	 *
	 * @param array<string, string|\Throwable> $slugs Slug per schema id, or a Throwable the lookup throws.
	 *
	 * @return LeaveApprovalListener
	 */
	private function listener(array $slugs): LeaveApprovalListener {
		$mapper = new class($slugs) {
			/**
			 * @param array<string, string|\Throwable> $slugs The slugs.
			 */
			public function __construct(private array $slugs) {
			}

			public function find(string $id): object {
				$slug = ($this->slugs[$id] ?? new RuntimeException('no schema ' . $id));
				if ($slug instanceof \Throwable) {
					throw $slug;
				}

				return new class($slug) {
					public function __construct(private string $slug) {
					}

					public function getSlug(): string {
						return $this->slug;
					}
				};
			}
		};
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->with('OCA\OpenRegister\Db\SchemaMapper')->willReturn($mapper);

		return new LeaveApprovalListener($this->projection, $container, $this->logger);
	}

	private static function entity(string $schema, array $data): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setSchema($schema);
		$entity->setObject($data);

		return $entity;
	}

	public function testACreatedLeaveRequestIsProjected(): void {
		$this->projection->expects($this->once())->method('projectForRequest')->with(['id' => 'lr-1', 'status' => 'submitted']);

		$this->listener(['7' => 'LeaveRequest'])->handle(new ObjectCreatedEvent(self::entity('7', ['id' => 'lr-1', 'status' => 'submitted'])));
	}

	public function testAnUpdatedLeaveRequestIsProjectedFromItsNewState(): void {
		$this->projection->expects($this->once())->method('projectForRequest')->with(['id' => 'lr-1', 'status' => 'approved']);

		$this->listener(['7' => 'leaverequest'])->handle(
			new ObjectUpdatedEvent(self::entity('7', ['id' => 'lr-1', 'status' => 'approved']), self::entity('7', ['id' => 'lr-1', 'status' => 'submitted']))
		);
	}

	public function testOtherObjectsAndUnresolvableSchemasAreLeftAlone(): void {
		$this->projection->expects($this->never())->method('projectForRequest');
		$listener = $this->listener(['8' => 'timeentry', '9' => new RuntimeException('mapper down')]);

		$listener->handle(new ObjectCreatedEvent(self::entity('8', ['id' => 'te-1'])));
		$listener->handle(new ObjectCreatedEvent(self::entity('9', ['id' => 'x-1'])));
		$listener->handle(new ObjectCreatedEvent(self::entity('', ['id' => 'x-2'])));
	}

	public function testOtherEventsAreIgnored(): void {
		$this->projection->expects($this->never())->method('projectForRequest');

		$this->listener(['7' => 'leaverequest'])->handle(new Event());
	}

	public function testAFailingProjectionIsOnlyAWarning(): void {
		$this->projection->method('projectForRequest')->willThrowException(new RuntimeException('balance locked'));
		$this->logger->expects($this->once())->method('warning')->with(
			'humaniq: LeaveApprovalListener could not project a leave request onto its balance',
			['exception' => 'balance locked']
		);

		$this->listener(['7' => 'leaverequest'])->handle(new ObjectCreatedEvent(self::entity('7', ['id' => 'lr-1'])));
	}
}
