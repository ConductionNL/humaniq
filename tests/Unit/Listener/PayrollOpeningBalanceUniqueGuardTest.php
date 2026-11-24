<?php

/**
 * Unit tests for PayrollOpeningBalanceUniqueGuard.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\Humaniq\Tests\Unit\Listener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/payroll-period-edge-cases/spec.md#REQ-PPE-003
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Listener;

use OCA\Humaniq\Listener\PayrollOpeningBalanceUniqueGuard;
use OCA\Humaniq\Service\HoursRegisterGateway;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * @covers \OCA\Humaniq\Listener\PayrollOpeningBalanceUniqueGuard
 * @uses   \OCA\Humaniq\Listener\PreSaveEvents
 */
class PayrollOpeningBalanceUniqueGuardTest extends TestCase {

	/**
	 * The balance already stored: employee-degroot, ADM-001, 2026.
	 *
	 * @var array<string, mixed>
	 */
	private const STORED = ['id' => 'ob-1', 'employeeId' => 'employee-degroot', 'administrationId' => 'ADM-001', 'year' => 2026];

	/**
	 * A second balance for the same employee, administration and year is refused with the reason.
	 *
	 * @return void
	 */
	public function testASecondBalanceForTheSameYearIsRefused(): void {
		$event = $this->create(['employeeId' => 'employee-degroot', 'administrationId' => 'ADM-001', 'year' => 2026]);

		$this->guard(stored: [self::STORED])->handle($event);

		self::assertTrue($event->isPropagationStopped());
		self::assertSame('Deze medewerker heeft al een beginsaldo voor administratie ADM-001 in 2026.', $event->getErrors()['message']);
	}//end testASecondBalanceForTheSameYearIsRefused()

	/**
	 * Another year or another administration is a balance of its own.
	 *
	 * @return void
	 */
	public function testAnotherYearOrAdministrationIsAccepted(): void {
		foreach ([['ADM-001', 2027], ['ADM-005', 2026]] as [$administrationId, $year]) {
			$event = $this->create(['employeeId' => 'employee-degroot', 'administrationId' => $administrationId, 'year' => $year]);

			$this->guard(stored: [self::STORED])->handle($event);

			self::assertFalse($event->isPropagationStopped(), $administrationId . ' ' . $year);
			self::assertSame([], $event->getErrors());
		}
	}//end testAnotherYearOrAdministrationIsAccepted()

	/**
	 * Editing the stored balance itself is not a duplicate.
	 *
	 * @return void
	 */
	public function testEditingTheBalanceItselfIsAccepted(): void {
		$entity = new ObjectEntity();
		$entity->setSchema('PayrollOpeningBalance');
		$entity->setUuid('ob-1');
		$entity->setObject(array_merge(self::STORED, ['grossWage' => 24000.0]));
		$event = new ObjectUpdatingEvent($entity, clone $entity);

		$this->guard(stored: [self::STORED])->handle($event);

		self::assertFalse($event->isPropagationStopped());
	}//end testEditingTheBalanceItselfIsAccepted()

	/**
	 * A balance that cannot be checked is refused, not let through.
	 *
	 * @return void
	 */
	public function testAnUncheckableBalanceIsRefused(): void {
		$event = $this->create(['employeeId' => 'employee-degroot', 'administrationId' => 'ADM-001', 'year' => 2026]);

		$this->guard(stored: [], throws: true)->handle($event);

		self::assertTrue($event->isPropagationStopped());
		self::assertSame('Het beginsaldo kon niet worden gecontroleerd; probeer het later opnieuw.', $event->getErrors()['message']);
	}//end testAnUncheckableBalanceIsRefused()

	/**
	 * Another schema's write is left alone.
	 *
	 * @return void
	 */
	public function testAnotherSchemaIsIgnored(): void {
		$event = $this->create(self::STORED);

		$this->guard(stored: [self::STORED], slug: 'Payslip')->handle($event);

		self::assertFalse($event->isPropagationStopped());
	}//end testAnotherSchemaIsIgnored()

	/**
	 * The guard over a gateway answering the stored balances.
	 *
	 * @param list<array<string, mixed>> $stored The stored balances.
	 * @param bool                       $throws Whether the lookup fails.
	 * @param string                     $slug   The schema slug the entity resolves to.
	 *
	 * @return PayrollOpeningBalanceUniqueGuard
	 */
	private function guard(array $stored, bool $throws=false, string $slug='PayrollOpeningBalance'): PayrollOpeningBalanceUniqueGuard {
		$gateway = $this->getMockBuilder(HoursRegisterGateway::class)->disableOriginalConstructor()->onlyMethods(['findFiltered', 'resolveSchemaSlug'])->getMock();
		$gateway->method('resolveSchemaSlug')->willReturn($slug);
		if ($throws === true) {
			$gateway->method('findFiltered')->willThrowException(new RuntimeException('register unavailable'));
		} else {
			$gateway->expects(self::atMost(1))->method('findFiltered')->with('PayrollOpeningBalance', ['employeeId' => 'employee-degroot'])->willReturn($stored);
		}

		return new PayrollOpeningBalanceUniqueGuard(gateway: $gateway, logger: new NullLogger());
	}//end guard()

	/**
	 * A create event for a balance.
	 *
	 * @param array<string, mixed> $payload The balance.
	 *
	 * @return ObjectCreatingEvent
	 */
	private function create(array $payload): ObjectCreatingEvent {
		$entity = new ObjectEntity();
		$entity->setSchema('PayrollOpeningBalance');
		$entity->setObject($payload);

		return new ObjectCreatingEvent($entity);
	}//end create()
}//end class
