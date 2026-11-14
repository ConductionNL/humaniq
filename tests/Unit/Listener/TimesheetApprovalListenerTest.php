<?php

/**
 * TimesheetApprovalListener tests.
 *
 * An updated object with a resolvable schema is handed to the time-entry event service with its old and new state; a failure there is only a warning.
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

use OCA\Humaniq\Listener\TimesheetApprovalListener;
use OCA\Humaniq\Service\TimeEntryEventService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Updates reach the approval dispatch with their schema slug, old and new data.
 *
 * @covers \OCA\Humaniq\Listener\TimesheetApprovalListener
 */
class TimesheetApprovalListenerTest extends TestCase {

	/** @var TimeEntryEventService&MockObject */
	private TimeEntryEventService $events;

	/** @var LoggerInterface&MockObject */
	private LoggerInterface $logger;

	protected function setUp(): void {
		$this->events = $this->createMock(TimeEntryEventService::class);
		$this->logger = $this->createMock(LoggerInterface::class);
	}

	private function listener(?string $slug): TimesheetApprovalListener {
		$container = $this->createMock(ContainerInterface::class);
		if ($slug === null) {
			$container->method('get')->willThrowException(new RuntimeException('no mapper'));
		} else {
			$schema = new class($slug) {
				public function __construct(private string $slug) {
				}

				public function getSlug(): string {
					return $this->slug;
				}
			};
			$mapper = new class($schema) {
				public function __construct(private object $schema) {
				}

				public function find(string $id): object {
					return $this->schema;
				}
			};
			$container->method('get')->with('OCA\OpenRegister\Db\SchemaMapper')->willReturn($mapper);
		}

		return new TimesheetApprovalListener($this->events, $container, $this->logger);
	}

	private static function entity(array $data): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setSchema('12');
		$entity->setObject($data);

		return $entity;
	}

	public function testAnUpdateIsHandedOnWithItsOldAndNewState(): void {
		$this->events->expects($this->once())->method('maybeDispatchApproved')->with('timesheet', ['status' => 'submitted'], ['status' => 'approved']);

		$this->listener('timesheet')->handle(new ObjectUpdatedEvent(self::entity(['status' => 'approved']), self::entity(['status' => 'submitted'])));
	}

	public function testAnUpdateWithoutAnOldStateIsHandedOnWithNone(): void {
		$this->events->expects($this->once())->method('maybeDispatchApproved')->with('timeentry', null, ['status' => 'approved']);

		$this->listener('timeentry')->handle(new ObjectUpdatedEvent(self::entity(['status' => 'approved'])));
	}

	public function testAnUnresolvableSchemaOrAnotherEventIsIgnored(): void {
		$this->events->expects($this->never())->method('maybeDispatchApproved');

		$this->listener(null)->handle(new ObjectUpdatedEvent(self::entity(['status' => 'approved'])));
		$this->listener('timesheet')->handle(new ObjectCreatedEvent(self::entity(['status' => 'approved'])));
	}

	public function testAFailingDispatchIsOnlyAWarning(): void {
		$this->events->method('maybeDispatchApproved')->willThrowException(new RuntimeException('bus down'));
		$this->logger->expects($this->once())->method('warning')->with(
			'humaniq: TimesheetApprovalListener could not process an ObjectUpdatedEvent',
			['exception' => 'bus down']
		);

		$this->listener('timesheet')->handle(new ObjectUpdatedEvent(self::entity(['status' => 'approved'])));
	}
}
