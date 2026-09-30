<?php

/**
 * A cost allocation whose splits do not add up, or that overlaps another, is
 * refused on save.
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
 * @spec openspec/specs/payroll-cost-allocation/spec.md#REQ-PCA-001
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Listener;

use OCA\Humaniq\Listener\CostAllocationGuardListener;
use OCA\Humaniq\Service\HoursRegisterGateway;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * The two save guards of design D1.
 */
class CostAllocationGuardListenerTest extends TestCase {

	/**
	 * The allocation already stored: fixed, from 1 January, open-ended.
	 *
	 * @var array<string, mixed>
	 */
	private const STORED = [
		'id' => 'ca-running',
		'employeeId' => 'emp-devries',
		'basis' => 'fixed',
		'startDate' => '2026-01-01',
		'endDate' => null,
		'splits' => [['costCenter' => 'CC-100', 'percentage' => 100]],
	];

	/**
	 * A listener whose gateway answers one set of stored allocations.
	 *
	 * @param list<array<string, mixed>> $stored The allocations in the register.
	 * @param bool                       $throws Whether the lookup fails.
	 *
	 * @return CostAllocationGuardListener
	 */
	private function listener(array $stored, bool $throws = false): CostAllocationGuardListener {
		$gateway = $this->getMockBuilder(HoursRegisterGateway::class)
			->disableOriginalConstructor()
			->onlyMethods(['findFiltered', 'resolveSchemaSlug'])
			->getMock();
		$gateway->method('resolveSchemaSlug')->willReturn('CostAllocation');
		if ($throws === true) {
			$gateway->method('findFiltered')->willThrowException(new RuntimeException('register unavailable'));
		} else {
			$gateway->method('findFiltered')->willReturn($stored);
		}

		return new CostAllocationGuardListener(gateway: $gateway, logger: new NullLogger());
	}//end listener()

	/**
	 * A create event for one payload.
	 *
	 * @param array<string, mixed> $payload The incoming allocation.
	 *
	 * @return ObjectCreatingEvent
	 */
	private function createEvent(array $payload): ObjectCreatingEvent {
		$entity = new ObjectEntity();
		$entity->setSchema('CostAllocation');
		$entity->setObject($payload);

		return new ObjectCreatingEvent($entity);
	}//end createEvent()

	/**
	 * Splits of 50 and 40 percent are refused because they add up to 90.
	 *
	 * @return void
	 */
	public function testSplitsOfNinetyPercentAreRefused(): void {
		$event = $this->createEvent(['employeeId' => 'emp-jansen', 'basis' => 'fixed', 'startDate' => '2026-01-01', 'splits' => [['costCenter' => 'CC-100', 'percentage' => 50], ['costCenter' => 'CC-200', 'percentage' => 40]]]);

		$this->listener(stored: [])->handle($event);

		self::assertTrue($event->isPropagationStopped());
		self::assertStringContainsString('90', (string)($event->getErrors()['message'] ?? ''));
	}//end testSplitsOfNinetyPercentAreRefused()

	/**
	 * The control: 60 and 40 are accepted, and so are 33.33 + 33.33 + 33.34.
	 *
	 * @return void
	 */
	public function testSplitsOfAHundredAreAccepted(): void {
		foreach ([[60, 40], [33.33, 33.33, 33.34]] as $percentages) {
			$splits = array_map(static fn (float|int $p): array => ['costCenter' => 'CC-' . $p, 'percentage' => $p], $percentages);
			$event = $this->createEvent(['employeeId' => 'emp-jansen', 'basis' => 'fixed', 'startDate' => '2026-01-01', 'splits' => $splits]);

			$this->listener(stored: [])->handle($event);

			self::assertFalse($event->isPropagationStopped(), implode('+', $percentages));
		}
	}//end testSplitsOfAHundredAreAccepted()

	/**
	 * A fixed allocation without splits is refused; an hours allocation
	 * needs none.
	 *
	 * @return void
	 */
	public function testAFixedAllocationNeedsSplitsAndAnHoursOneDoesNot(): void {
		$fixed = $this->createEvent(['employeeId' => 'emp-jansen', 'basis' => 'fixed', 'startDate' => '2026-01-01', 'splits' => []]);
		$this->listener(stored: [])->handle($fixed);
		self::assertTrue($fixed->isPropagationStopped());

		$hours = $this->createEvent(['employeeId' => 'emp-jansen', 'basis' => 'hours', 'startDate' => '2026-01-01']);
		$this->listener(stored: [])->handle($hours);
		self::assertFalse($hours->isPropagationStopped());
	}//end testAFixedAllocationNeedsSplitsAndAnHoursOneDoesNot()

	/**
	 * A second open-ended allocation for the same employee is refused; one
	 * starting after the running one ended is accepted.
	 *
	 * @return void
	 */
	public function testAnOverlappingAllocationIsRefused(): void {
		$payload = ['employeeId' => 'emp-devries', 'basis' => 'hours', 'startDate' => '2026-06-01'];

		$overlap = $this->createEvent($payload);
		$this->listener(stored: [self::STORED])->handle($overlap);
		self::assertTrue($overlap->isPropagationStopped());
		self::assertNotSame('', (string)($overlap->getErrors()['message'] ?? ''));

		$adjacent = $this->createEvent($payload);
		$this->listener(stored: [array_merge(self::STORED, ['endDate' => '2026-05-31'])])->handle($adjacent);
		self::assertFalse($adjacent->isPropagationStopped());
	}//end testAnOverlappingAllocationIsRefused()

	/**
	 * Editing the stored allocation is not an overlap with itself.
	 *
	 * @return void
	 */
	public function testAnUpdateDoesNotOverlapItself(): void {
		$entity = new ObjectEntity();
		$entity->setSchema('CostAllocation');
		$entity->setUuid('ca-running');
		$entity->setObject(array_merge(self::STORED, ['splits' => [['costCenter' => 'CC-100', 'percentage' => 70], ['costCenter' => 'CC-200', 'percentage' => 30]]]));
		$event = new ObjectUpdatingEvent($entity, new ObjectEntity());

		$this->listener(stored: [self::STORED])->handle($event);

		self::assertFalse($event->isPropagationStopped());
	}//end testAnUpdateDoesNotOverlapItself()

	/**
	 * When the stored allocations cannot be read, the save is refused.
	 *
	 * @return void
	 */
	public function testAnUncheckableSaveIsRefused(): void {
		$event = $this->createEvent(['employeeId' => 'emp-jansen', 'basis' => 'hours', 'startDate' => '2026-01-01']);

		$this->listener(stored: [], throws: true)->handle($event);

		self::assertTrue($event->isPropagationStopped());
	}//end testAnUncheckableSaveIsRefused()

	/**
	 * Another schema's write is left alone.
	 *
	 * @return void
	 */
	public function testAnotherSchemaIsIgnored(): void {
		$gateway = $this->getMockBuilder(HoursRegisterGateway::class)->disableOriginalConstructor()->onlyMethods(['findFiltered', 'resolveSchemaSlug'])->getMock();
		$gateway->method('resolveSchemaSlug')->willReturn('Employee');
		$gateway->expects(self::never())->method('findFiltered');
		$event = $this->createEvent(['splits' => [['costCenter' => 'X', 'percentage' => 1]]]);

		(new CostAllocationGuardListener(gateway: $gateway, logger: new NullLogger()))->handle($event);

		self::assertFalse($event->isPropagationStopped());
	}//end testAnotherSchemaIsIgnored()

}//end class
