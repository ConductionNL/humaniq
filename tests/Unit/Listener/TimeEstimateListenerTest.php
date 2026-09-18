<?php

/**
 * TimeEstimateListener tests
 *
 * Pins the refusals at the write path rather than in the calculator, because
 * the leaf and a consuming app's own screen both book through OpenRegister's
 * object API and the refusal has to read the same from either.
 *
 * The accepted write comes first as the control, and the last test pins the
 * deliberate fail-OPEN: an estimate list that cannot be read must not stop
 * somebody booking hours they have worked.
 *
 * The gateway double uses `onlyMethods`, so it cannot answer a method
 * HoursRegisterGateway does not have.
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

use OCA\Humaniq\Listener\TimeEstimateListener;
use OCA\Humaniq\Service\HoursRegisterGateway;
use OCA\Humaniq\Service\TimeEstimateService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * Unit tests for TimeEstimateListener.
 */
class TimeEstimateListenerTest extends TestCase {

	/**
	 * The `<app>:<schema>` literal every fixture is booked against.
	 *
	 * @var string
	 */
	private const TYPE = 'dossiq:zaak';

	/**
	 * The host object every fixture is booked against.
	 *
	 * @var string
	 */
	private const REF = 'zaak-1';

	/**
	 * Build a listener over one register state.
	 *
	 * @param array<int, array<string, mixed>> $estimates The stored TimeEstimates.
	 * @param array<int, array<string, mixed>> $entries The stored TimeEntries.
	 * @param bool $gatewayThrows Whether the register reads fail.
	 *
	 * @return TimeEstimateListener The subject.
	 */
	private function listener(array $estimates, array $entries, bool $gatewayThrows = false): TimeEstimateListener {
		$gateway = $this->getMockBuilder(HoursRegisterGateway::class)
			->disableOriginalConstructor()
			->onlyMethods(['loadAll', 'findFiltered', 'resolveSchemaSlug'])
			->getMock();

		$gateway->method('resolveSchemaSlug')->willReturnArgument(0);

		if ($gatewayThrows === true) {
			$gateway->method('loadAll')->willThrowException(new RuntimeException('register unavailable'));
			$gateway->method('findFiltered')->willThrowException(new RuntimeException('register unavailable'));
		} else {
			$gateway->method('loadAll')->willReturn($estimates);
			$gateway->method('findFiltered')->willReturn($entries);
		}

		return new TimeEstimateListener(
			gateway: $gateway,
			estimates: new TimeEstimateService(),
			logger: new NullLogger()
		);
	}//end listener()

	/**
	 * A create event carrying one payload of one schema.
	 *
	 * @param string $schema The schema slug.
	 * @param array<string, mixed> $payload The incoming object.
	 *
	 * @return ObjectCreatingEvent The event.
	 */
	private function createEvent(string $schema, array $payload): ObjectCreatingEvent {
		$entity = new ObjectEntity();
		$entity->setSchema($schema);
		$entity->setObject($payload);

		return new ObjectCreatingEvent($entity);
	}//end createEvent()

	/**
	 * The control: a booking inside an enforced ceiling is written.
	 *
	 * @return void
	 */
	public function testABookingInsideTheCeilingIsAccepted(): void {
		$event = $this->createEvent('timeentry', [
			'domainObjectType' => self::TYPE,
			'domainObjectRef' => self::REF,
			'hours' => 1.0,
			'origin' => 'manual',
		]);

		$this->listener(
			estimates: [['domainObjectType' => self::TYPE, 'domainObjectRef' => self::REF, 'estimatedHours' => 4.0, 'enforced' => true]],
			entries: [['id' => 'e-1', 'domainObjectType' => self::TYPE, 'domainObjectRef' => self::REF, 'hours' => 3.0]]
		)->handle($event);

		$this->assertFalse($event->isPropagationStopped());
	}//end testABookingInsideTheCeilingIsAccepted()

	/**
	 * A booking past an enforced ceiling is stopped, and the caller is told
	 * the numbers.
	 *
	 * @return void
	 */
	public function testABookingPastTheCeilingIsStopped(): void {
		$event = $this->createEvent('timeentry', [
			'domainObjectType' => self::TYPE,
			'domainObjectRef' => self::REF,
			'hours' => 2.0,
			'origin' => 'manual',
		]);

		$this->listener(
			estimates: [['domainObjectType' => self::TYPE, 'domainObjectRef' => self::REF, 'estimatedHours' => 4.0, 'enforced' => true]],
			entries: [['id' => 'e-1', 'domainObjectType' => self::TYPE, 'domainObjectRef' => self::REF, 'hours' => 3.0]]
		)->handle($event);

		$this->assertTrue($event->isPropagationStopped(), 'The write must be stopped, not merely logged');
		$this->assertStringContainsString('1 uur over', (string)$event->getErrors()['message']);
	}//end testABookingPastTheCeilingIsStopped()

	/**
	 * A stopped timer is written past the ceiling, because time already worked
	 * is a fact.
	 *
	 * @return void
	 */
	public function testAStoppedTimerIsNeverStopped(): void {
		$event = $this->createEvent('timeentry', [
			'domainObjectType' => self::TYPE,
			'domainObjectRef' => self::REF,
			'hours' => 5.0,
			'origin' => TimeEstimateService::ORIGIN_TIMER,
		]);

		$this->listener(
			estimates: [['domainObjectType' => self::TYPE, 'domainObjectRef' => self::REF, 'estimatedHours' => 4.0, 'enforced' => true]],
			entries: []
		)->handle($event);

		$this->assertFalse($event->isPropagationStopped());
	}//end testAStoppedTimerIsNeverStopped()

	/**
	 * A second estimate for one object and role is stopped on the write.
	 *
	 * @return void
	 */
	public function testASecondEstimateForOneRoleIsStopped(): void {
		$event = $this->createEvent('timeestimate', [
			'domainObjectType' => self::TYPE,
			'domainObjectRef' => self::REF,
			'estimatedHours' => 4.0,
			'role' => 'juridisch',
		]);

		$this->listener(
			estimates: [
				[
					'id' => 'est-jur',
					'domainObjectType' => self::TYPE,
					'domainObjectRef' => self::REF,
					'estimatedHours' => 6.0,
					'role' => 'juridisch',
				],
			],
			entries: []
		)->handle($event);

		$this->assertTrue($event->isPropagationStopped());
		$this->assertStringContainsString('juridisch', (string)$event->getErrors()['message']);
	}//end testASecondEstimateForOneRoleIsStopped()

	/**
	 * A write of some other schema is not this listener's business.
	 *
	 * @return void
	 */
	public function testAnotherSchemasWriteIsUntouched(): void {
		$event = $this->createEvent('leaverequest', ['employeeId' => 'emp-1']);

		$this->listener(estimates: [], entries: [])->handle($event);

		$this->assertFalse($event->isPropagationStopped());
	}//end testAnotherSchemasWriteIsUntouched()

	/**
	 * An unreadable register lets the booking through, deliberately.
	 *
	 * The other write-path guards in this app fail closed, and this one does
	 * not, on purpose: a ceiling is a plan, and losing a booking somebody
	 * actually worked costs more than letting one past a plan that could not be
	 * read. The miss is logged rather than silent.
	 *
	 * @return void
	 */
	public function testAnUnreadableRegisterDoesNotBlockABooking(): void {
		$event = $this->createEvent('timeentry', [
			'domainObjectType' => self::TYPE,
			'domainObjectRef' => self::REF,
			'hours' => 40.0,
			'origin' => 'manual',
		]);

		$this->listener(estimates: [], entries: [], gatewayThrows: true)->handle($event);

		$this->assertFalse($event->isPropagationStopped());
	}//end testAnUnreadableRegisterDoesNotBlockABooking()
}//end class
