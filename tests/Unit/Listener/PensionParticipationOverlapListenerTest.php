<?php

/**
 * PensionParticipationOverlapListener tests
 *
 * REQ-PPP-002: two participations of one employee never overlap. Two
 * overlapping and two adjacent participations, as tasks.md 1.2 asks; the
 * adjacent (accepted) case is the control that tells "refused the overlap"
 * apart from "refuses every write".
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
 * @spec openspec/specs/payroll-pension-premium/spec.md#REQ-PPP-002
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Listener;

use OCA\Humaniq\Listener\PensionParticipationOverlapListener;
use OCA\Humaniq\Service\HoursRegisterGateway;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * Unit tests for PensionParticipationOverlapListener.
 */
class PensionParticipationOverlapListenerTest extends TestCase {

	/**
	 * The participation already stored: pfab from 1 January, open-ended.
	 *
	 * @var array<string, mixed>
	 */
	private const STORED = [
		'id' => 'participation-pfab',
		'employeeId' => 'employee-jansen',
		'fund' => 'pfab',
		'startDate' => '2026-01-01',
		'endDate' => null,
	];

	/**
	 * A listener whose gateway answers one set of stored participations.
	 *
	 * @param array<int, array<string, mixed>> $stored The stored participations.
	 * @param bool $throws Whether the lookup fails.
	 *
	 * @return PensionParticipationOverlapListener The subject.
	 */
	private function listener(array $stored, bool $throws=false): PensionParticipationOverlapListener {
		$gateway = $this->getMockBuilder(HoursRegisterGateway::class)
			->disableOriginalConstructor()
			->onlyMethods(['findFiltered', 'resolveSchemaSlug'])
			->getMock();
		$gateway->method('resolveSchemaSlug')->willReturn('PensionParticipation');
		if ($throws === true) {
			$gateway->method('findFiltered')->willThrowException(new RuntimeException('register unavailable'));
		} else {
			$gateway->method('findFiltered')->willReturn($stored);
		}

		return new PensionParticipationOverlapListener(gateway: $gateway, logger: new NullLogger());
	}//end listener()

	/**
	 * A create event carrying one participation.
	 *
	 * @param array<string, mixed> $payload The incoming participation.
	 *
	 * @return ObjectCreatingEvent The event.
	 */
	private function createEvent(array $payload): ObjectCreatingEvent {
		$entity = new ObjectEntity();
		$entity->setSchema('PensionParticipation');
		$entity->setObject($payload);

		return new ObjectCreatingEvent($entity);
	}//end createEvent()

	/**
	 * Adjacent: the stored one ends on 31 May, the new one starts on 1 June.
	 *
	 * @return void
	 */
	public function testAParticipationStartingTheDayAfterTheLastEndsIsAccepted(): void {
		$event = $this->createEvent(['employeeId' => 'employee-jansen', 'fund' => 'abp', 'startDate' => '2026-06-01']);

		$this->listener(stored: [array_merge(self::STORED, ['endDate' => '2026-05-31'])])->handle($event);

		$this->assertFalse($event->isPropagationStopped());
		$this->assertSame([], $event->getErrors());
	}//end testAParticipationStartingTheDayAfterTheLastEndsIsAccepted()

	/**
	 * Adjacent the other way: a closed participation before the stored one.
	 *
	 * @return void
	 */
	public function testAParticipationEndingTheDayBeforeTheStoredOneStartsIsAccepted(): void {
		$event = $this->createEvent(['employeeId' => 'employee-jansen', 'fund' => 'abp', 'startDate' => '2025-01-01', 'endDate' => '2025-12-31']);

		$this->listener(stored: [self::STORED])->handle($event);

		$this->assertFalse($event->isPropagationStopped());
	}//end testAParticipationEndingTheDayBeforeTheStoredOneStartsIsAccepted()

	/**
	 * The spec's scenario: pfab from 1 January open-ended, a second from 1
	 * June is refused with a message naming the existing participation.
	 *
	 * @return void
	 */
	public function testASecondOpenParticipationIsRefusedNamingTheStoredOne(): void {
		$event = $this->createEvent(['employeeId' => 'employee-jansen', 'fund' => 'pfab', 'startDate' => '2026-06-01']);

		$this->listener(stored: [self::STORED])->handle($event);

		$this->assertTrue($event->isPropagationStopped());
		$message = (string)($event->getErrors()['message'] ?? '');
		$this->assertStringContainsString('participation-pfab', $message);
		$this->assertStringContainsString('pfab', $message);
		$this->assertStringContainsString('2026-01-01', $message);
	}//end testASecondOpenParticipationIsRefusedNamingTheStoredOne()

	/**
	 * A closed range that ends inside the stored one overlaps too.
	 *
	 * @return void
	 */
	public function testARangeEndingInsideTheStoredOneIsRefused(): void {
		$event = $this->createEvent(['employeeId' => 'employee-jansen', 'fund' => 'abp', 'startDate' => '2025-06-01', 'endDate' => '2026-01-01']);

		$this->listener(stored: [self::STORED])->handle($event);

		$this->assertTrue($event->isPropagationStopped());
	}//end testARangeEndingInsideTheStoredOneIsRefused()

	/**
	 * Another employee's participation is not an overlap, even if the store
	 * hands it back.
	 *
	 * @return void
	 */
	public function testAnotherEmployeesParticipationIsNotAnOverlap(): void {
		$event = $this->createEvent(['employeeId' => 'employee-devries', 'fund' => 'pfab', 'startDate' => '2026-06-01']);

		$this->listener(stored: [self::STORED])->handle($event);

		$this->assertFalse($event->isPropagationStopped());
	}//end testAnotherEmployeesParticipationIsNotAnOverlap()

	/**
	 * Editing the stored participation does not refuse it against itself.
	 *
	 * @return void
	 */
	public function testAParticipationIsNotItsOwnOverlapOnUpdate(): void {
		$entity = new ObjectEntity();
		$entity->setSchema('PensionParticipation');
		$entity->setUuid('participation-pfab');
		$entity->setObject(['employeeId' => 'employee-jansen', 'fund' => 'pfab', 'startDate' => '2026-01-01', 'endDate' => '2026-12-31']);
		$event = new ObjectUpdatingEvent($entity, null);

		$this->listener(stored: [self::STORED])->handle($event);

		$this->assertFalse($event->isPropagationStopped());
	}//end testAParticipationIsNotItsOwnOverlapOnUpdate()

	/**
	 * A failed lookup refuses the write.
	 *
	 * @return void
	 */
	public function testAFailedLookupFailsClosed(): void {
		$event = $this->createEvent(['employeeId' => 'employee-jansen', 'fund' => 'pfab', 'startDate' => '2026-06-01']);

		$this->listener(stored: [], throws: true)->handle($event);

		$this->assertTrue($event->isPropagationStopped());
	}//end testAFailedLookupFailsClosed()

	/**
	 * The refusal text names an open end as such.
	 *
	 * @return void
	 */
	public function testTheRefusalNamesAnOpenEnd(): void {
		$this->assertStringContainsString('zonder einddatum', PensionParticipationOverlapListener::refusal(self::STORED));
		$this->assertStringContainsString('tot en met 2026-05-31', PensionParticipationOverlapListener::refusal(array_merge(self::STORED, ['endDate' => '2026-05-31'])));
	}//end testTheRefusalNamesAnOpenEnd()
}//end class
