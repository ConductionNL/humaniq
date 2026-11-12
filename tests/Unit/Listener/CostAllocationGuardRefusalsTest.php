<?php

/**
 * Golden tests for CostAllocationGuardListener's refusal messages.
 *
 * Pins the exact refusal of each guard: an empty or mis-summed fixed split
 * (with the summed percentage as written), the overlap message naming the
 * stored start (or `?` when it has none), that only the row being updated
 * is skipped, that an incomplete payload is never compared, and that an
 * update of another schema is left alone.
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

/**
 * CostAllocationGuardListener refusal messages.
 */
class CostAllocationGuardRefusalsTest extends TestCase {

	/**
	 * The listener over stored rows, with the schema resolved to $slug.
	 *
	 * @param array<int, array<string, mixed>>|null $stored The rows findFiltered() answers, null when it must not be called.
	 * @param string $slug The schema slug the gateway resolves.
	 *
	 * @return CostAllocationGuardListener
	 */
	private function listener(?array $stored, string $slug = 'CostAllocation'): CostAllocationGuardListener {
		$gateway = $this->getMockBuilder(HoursRegisterGateway::class)
			->disableOriginalConstructor()
			->onlyMethods(['findFiltered', 'resolveSchemaSlug'])
			->getMock();
		$gateway->method('resolveSchemaSlug')->willReturn($slug);
		if ($stored === null) {
			$gateway->expects(self::never())->method('findFiltered');
		} else {
			$gateway->method('findFiltered')->willReturn($stored);
		}

		return new CostAllocationGuardListener(gateway: $gateway, logger: new NullLogger());
	}//end listener()

	/**
	 * The refusal message a create of $payload gets, '' when accepted.
	 *
	 * @param array<string, mixed> $payload The allocation.
	 * @param array<int, array<string, mixed>>|null $stored The stored rows, null when they must not be read.
	 *
	 * @return string
	 */
	private function refusalOfCreate(array $payload, ?array $stored): string {
		$entity = new ObjectEntity();
		$entity->setSchema('CostAllocation');
		$entity->setObject($payload);
		$event = new ObjectCreatingEvent($entity);

		$this->listener($stored)->handle($event);

		return ($event->isPropagationStopped() === true ? (string)($event->getErrors()['message'] ?? '') : '');
	}//end refusalOfCreate()

	/**
	 * Fixed splits: none, or not summing to 100, are refused with the sum.
	 *
	 * @return void
	 */
	public function testFixedSplitRefusalsNameTheSum(): void {
		self::assertSame(
			'Een vaste kostenverdeling heeft minstens een kostenplaats met een percentage nodig.',
			$this->refusalOfCreate(['employeeId' => 'emp-1', 'startDate' => '2026-01-01'], null)
		);
		self::assertSame(
			'De percentages tellen op tot 99.99%, niet tot 100%.',
			$this->refusalOfCreate(['basis' => 'fixed', 'splits' => [['percentage' => 33.33], ['percentage' => '33.33'], ['percentage' => 33.33]]], null)
		);
		self::assertSame(
			'De percentages tellen op tot 120%, niet tot 100%.',
			$this->refusalOfCreate(['basis' => 'fixed', 'splits' => [['percentage' => 70], ['percentage' => 50], ['costCenter' => 'CC-1']]], null)
		);
		self::assertSame('', $this->refusalOfCreate(['basis' => 'hours', 'splits' => []], null));
	}//end testFixedSplitRefusalsNameTheSum()

	/**
	 * The overlap refusal names the stored start, or `?` without one; an
	 * incomplete payload is never compared.
	 *
	 * @return void
	 */
	public function testOverlapRefusalNamesTheStoredStart(): void {
		$payload = ['employeeId' => ' emp-1 ', 'basis' => 'hours', 'startDate' => '2026-03-01', 'endDate' => '2026-03-31'];

		self::assertSame(
			'Deze medewerker heeft al een kostenverdeling vanaf 2026-02-01 die deze periode overlapt. Beëindig die eerst.',
			$this->refusalOfCreate($payload, [
				['id' => 'ca-1', 'startDate' => '2025-01-01', 'endDate' => '2025-12-31'],
				['id' => 'ca-2', 'startDate' => '2026-02-01', 'endDate' => null],
				['id' => 'ca-3', 'startDate' => '2026-03-15', 'endDate' => null],
			])
		);
		self::assertSame(
			'Deze medewerker heeft al een kostenverdeling vanaf ? die deze periode overlapt. Beëindig die eerst.',
			$this->refusalOfCreate($payload, [['id' => 'ca-1', 'endDate' => '2026-03-10']])
		);
		self::assertSame('', $this->refusalOfCreate($payload, [['id' => 'ca-1', 'startDate' => '2026-04-01']]));
		self::assertSame('', $this->refusalOfCreate(['employeeId' => 'emp-1', 'basis' => 'hours', 'startDate' => ' '], null));
		self::assertSame('', $this->refusalOfCreate(['basis' => 'hours', 'startDate' => '2026-03-01'], null));
	}//end testOverlapRefusalNamesTheStoredStart()

	/**
	 * An update skips only its own stored row, and an update of another
	 * schema is left alone.
	 *
	 * @return void
	 */
	public function testAnUpdateSkipsOnlyItsOwnRow(): void {
		$entity = new ObjectEntity();
		$entity->setSchema('CostAllocation');
		$entity->setUuid('ca-2');
		$entity->setObject(['employeeId' => 'emp-1', 'basis' => 'hours', 'startDate' => '2026-03-01']);
		$event = new ObjectUpdatingEvent($entity, new ObjectEntity());

		$this->listener([['id' => 'ca-2', 'startDate' => '2026-01-01'], ['id' => 'ca-9', 'startDate' => '2026-05-01']])->handle($event);

		self::assertSame(
			'Deze medewerker heeft al een kostenverdeling vanaf 2026-05-01 die deze periode overlapt. Beëindig die eerst.',
			(string)($event->getErrors()['message'] ?? '')
		);

		$other = new ObjectEntity();
		$other->setSchema('Employee');
		$other->setObject(['splits' => []]);
		$ignored = new ObjectUpdatingEvent($other, new ObjectEntity());
		$this->listener(null, 'Employee')->handle($ignored);
		self::assertFalse($ignored->isPropagationStopped());
	}//end testAnUpdateSkipsOnlyItsOwnRow()
}//end class
