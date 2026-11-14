<?php

/**
 * ResourceBookingOverlapListener tests.
 *
 * A booking being created or updated is checked against its resource and the resource's other bookings; a refusal, or a check that cannot run, refuses the write.
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

use OCA\Humaniq\Listener\ResourceBookingOverlapListener;
use OCA\Humaniq\Service\HoursRegisterGateway;
use OCA\Humaniq\Service\ResourceBookingService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Bookings are checked before they are written; other writes are not.
 *
 * @covers \OCA\Humaniq\Listener\ResourceBookingOverlapListener
 * @uses   \OCA\Humaniq\Listener\PreSaveEvents
 */
class ResourceBookingOverlapListenerTest extends TestCase {

	/** @var HoursRegisterGateway&MockObject */
	private HoursRegisterGateway $gateway;

	/** @var ResourceBookingService&MockObject */
	private ResourceBookingService $bookings;

	/** @var LoggerInterface&MockObject */
	private LoggerInterface $logger;

	protected function setUp(): void {
		$this->gateway = $this->createMock(HoursRegisterGateway::class);
		$this->gateway->method('resolveSchemaSlug')->willReturnCallback(static fn (string $id): string => ['3' => 'ResourceBooking'][$id] ?? 'other');
		$this->gateway->method('findObjectData')->willReturnCallback(static fn (string $id, string $schema): ?array => $id === 'room-1' ? ['id' => 'room-1', 'schema' => $schema] : null);
		$this->gateway->method('findFiltered')->willReturn([['id' => 'b-0', 'resourceId' => 'room-1']]);
		$this->bookings = $this->createMock(ResourceBookingService::class);
		$this->logger = $this->createMock(LoggerInterface::class);
	}

	private function listener(): ResourceBookingOverlapListener {
		return new ResourceBookingOverlapListener($this->gateway, $this->bookings, $this->logger);
	}

	private static function entity(string $schema, array $data, string $uuid = ''): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setSchema($schema);
		$entity->setObject($data);
		$entity->setUuid($uuid);

		return $entity;
	}

	public function testANewBookingIsCheckedAgainstItsResourceAndTheOtherBookings(): void {
		$this->gateway->expects($this->once())->method('findFiltered')->with('ResourceBooking', ['resourceId' => 'room-1']);
		$this->bookings->expects($this->once())->method('refusal')
			->with(['resourceId' => ' room-1 ', 'from' => '09:00'], ['id' => 'room-1', 'schema' => 'HrResource'], [['id' => 'b-0', 'resourceId' => 'room-1']])
			->willReturn(null);
		$event = new ObjectCreatingEvent(self::entity('3', ['resourceId' => ' room-1 ', 'from' => '09:00']));

		$this->listener()->handle($event);

		self::assertSame([], $event->getErrors());
		self::assertFalse($event->isPropagationStopped());
	}

	public function testAnUpdatedBookingCarriesItsOwnIdSoItDoesNotOverlapItself(): void {
		$this->bookings->expects($this->once())->method('refusal')
			->with(['resourceId' => 'room-1', 'id' => 'b-7'], $this->anything(), $this->anything())
			->willReturn(null);

		$this->listener()->handle(new ObjectUpdatingEvent(self::entity('3', ['resourceId' => 'room-1'], ' b-7 '), self::entity('3', [])));
	}

	public function testAnOverlapRefusesTheWrite(): void {
		$this->bookings->method('refusal')->willReturn('De ruimte is dan al gereserveerd.');
		$event = new ObjectCreatingEvent(self::entity('3', ['resourceId' => 'room-1']));

		$this->listener()->handle($event);

		self::assertSame(['message' => 'De ruimte is dan al gereserveerd.'], $event->getErrors());
		self::assertTrue($event->isPropagationStopped());
	}

	public function testACheckThatCannotRunRefusesTheWriteAndIsLogged(): void {
		$this->bookings->method('refusal')->willThrowException(new RuntimeException('register down'));
		$this->logger->expects($this->once())->method('warning')->with(
			'humaniq: ResourceBookingOverlapListener could not check a booking',
			['exception' => 'register down']
		);
		$event = new ObjectCreatingEvent(self::entity('3', ['resourceId' => 'room-1']));

		$this->listener()->handle($event);

		self::assertSame(['message' => 'De reservering kon niet worden gecontroleerd; probeer het later opnieuw.'], $event->getErrors());
	}

	public function testOtherSchemasAndFinishedEventsAreNotChecked(): void {
		$this->bookings->expects($this->never())->method('refusal');

		$this->listener()->handle(new ObjectCreatingEvent(self::entity('4', ['resourceId' => 'room-1'])));
		$this->listener()->handle(new ObjectUpdatingEvent(self::entity('4', []), self::entity('3', [])));
		$this->listener()->handle(new ObjectCreatedEvent(self::entity('3', ['resourceId' => 'room-1'])));
	}
}
