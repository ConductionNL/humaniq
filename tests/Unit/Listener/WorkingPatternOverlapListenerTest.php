<?php

/**
 * WorkingPatternOverlapListener tests
 *
 * Pins the refusal at the write path rather than in the calculator: a second
 * pattern covering days the running one already covers is stopped, an adjacent
 * one is not, and an edit of a pattern does not count as its own overlap.
 *
 * The accepted case is asserted first, as the control. Without it, "the
 * overlap was refused" cannot be told apart from "this listener refuses every
 * write".
 *
 * The gateway double uses `onlyMethods`, so it cannot answer a method
 * HoursRegisterGateway does not have: a double that invents one passes here
 * and 500s in production.
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

use OCA\Humaniq\Listener\WorkingPatternOverlapListener;
use OCA\Humaniq\Service\HoursRegisterGateway;
use OCA\Humaniq\Service\WorkingHoursService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * Unit tests for WorkingPatternOverlapListener.
 */
class WorkingPatternOverlapListenerTest extends TestCase {

	/**
	 * The pattern already stored for this employee: 1 January, open-ended.
	 *
	 * @var array<string, mixed>
	 */
	private const STORED_PATTERN = [
		'id' => 'pattern-running',
		'employeeId' => 'employee-jansen',
		'validFrom' => '2026-01-01',
		'validUntil' => null,
	];

	/**
	 * Build a listener whose gateway answers one set of stored patterns.
	 *
	 * @param array<int, array<string, mixed>> $stored The patterns already in the register.
	 * @param bool $gatewayThrows Whether the stored lookup fails.
	 *
	 * @return WorkingPatternOverlapListener The subject.
	 */
	private function listener(array $stored, bool $gatewayThrows = false): WorkingPatternOverlapListener {
		$gateway = $this->getMockBuilder(HoursRegisterGateway::class)
			->disableOriginalConstructor()
			->onlyMethods(['findFiltered', 'resolveSchemaSlug'])
			->getMock();

		$gateway->method('resolveSchemaSlug')->willReturn('WorkingPattern');
		if ($gatewayThrows === true) {
			$gateway->method('findFiltered')->willThrowException(new RuntimeException('register unavailable'));
		} else {
			$gateway->method('findFiltered')->willReturn($stored);
		}

		return new WorkingPatternOverlapListener(
			gateway: $gateway,
			workingHours: new WorkingHoursService(),
			logger: new NullLogger()
		);
	}//end listener()

	/**
	 * A create event carrying one WorkingPattern payload.
	 *
	 * @param array<string, mixed> $payload The incoming pattern.
	 *
	 * @return ObjectCreatingEvent The event.
	 */
	private function createEvent(array $payload): ObjectCreatingEvent {
		$entity = new ObjectEntity();
		$entity->setSchema('WorkingPattern');
		$entity->setObject($payload);

		return new ObjectCreatingEvent($entity);
	}//end createEvent()

	/**
	 * The control: a pattern starting the day the running one ends is accepted.
	 *
	 * @return void
	 */
	public function testAnAdjacentPatternIsAccepted(): void {
		$ended = array_merge(self::STORED_PATTERN, ['validUntil' => '2026-06-30']);
		$event = $this->createEvent([
			'employeeId' => 'employee-jansen',
			'validFrom' => '2026-07-01',
			'hoursMonday' => 8,
		]);

		$this->listener(stored: [$ended])->handle($event);

		$this->assertFalse($event->isPropagationStopped(), 'A pattern that starts after the last one ended is ordinary');
		$this->assertSame([], $event->getErrors());
	}//end testAnAdjacentPatternIsAccepted()

	/**
	 * A second open-ended pattern written without ending the running one is
	 * refused, and the refusal reaches the caller.
	 *
	 * @return void
	 */
	public function testAnOverlappingPatternIsRefused(): void {
		$event = $this->createEvent([
			'employeeId' => 'employee-jansen',
			'validFrom' => '2026-03-01',
			'hoursMonday' => 8,
		]);

		$this->listener(stored: [self::STORED_PATTERN])->handle($event);

		$this->assertTrue($event->isPropagationStopped(), 'The write must be stopped, not merely logged');
		$this->assertArrayHasKey('message', $event->getErrors());
		$this->assertStringContainsString('employee-jansen', (string)$event->getErrors()['message']);
	}//end testAnOverlappingPatternIsRefused()

	/**
	 * Somebody else's pattern is not this employee's overlap.
	 *
	 * @return void
	 */
	public function testAnotherEmployeesPatternIsNotAnOverlap(): void {
		$event = $this->createEvent([
			'employeeId' => 'employee-fatima',
			'validFrom' => '2026-03-01',
		]);

		$this->listener(stored: [self::STORED_PATTERN])->handle($event);

		$this->assertFalse($event->isPropagationStopped());
	}//end testAnotherEmployeesPatternIsNotAnOverlap()

	/**
	 * Editing a stored pattern does not refuse it for overlapping itself.
	 *
	 * @return void
	 */
	public function testAPatternIsNotItsOwnOverlapOnUpdate(): void {
		$entity = new ObjectEntity();
		$entity->setSchema('WorkingPattern');
		$entity->setUuid('pattern-running');
		$entity->setObject([
			'employeeId' => 'employee-jansen',
			'validFrom' => '2026-01-01',
			'hoursMonday' => 6,
		]);

		$event = new ObjectUpdatingEvent($entity, null);

		$this->listener(stored: [self::STORED_PATTERN])->handle($event);

		$this->assertFalse($event->isPropagationStopped(), 'The row being edited must not count against itself');
	}//end testAPatternIsNotItsOwnOverlapOnUpdate()

	/**
	 * A lookup that fails refuses the write rather than letting an unchecked
	 * pattern through.
	 *
	 * @return void
	 */
	public function testAFailedLookupFailsClosed(): void {
		$event = $this->createEvent([
			'employeeId' => 'employee-jansen',
			'validFrom' => '2026-03-01',
		]);

		$this->listener(stored: [], gatewayThrows: true)->handle($event);

		$this->assertTrue($event->isPropagationStopped(), 'An unchecked pattern is refused, not accepted');
	}//end testAFailedLookupFailsClosed()
}//end class
