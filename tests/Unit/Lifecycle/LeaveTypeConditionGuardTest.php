<?php

/**
 * LeaveTypeConditionGuard tests
 *
 * Pins where the type's conditions are actually enforced: on the transition,
 * so every surface that submits a request meets them, including the department
 * schedule this change adds.
 *
 * The allowed case comes first, as the control. The gateway double uses
 * `onlyMethods`, so it cannot answer a method HoursRegisterGateway does not
 * have.
 *
 * @category Test
 * @package  OCA\Humaniq\Tests\Unit\Lifecycle
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

namespace OCA\Humaniq\Tests\Unit\Lifecycle;

use OCA\Humaniq\Lifecycle\LeaveTypeConditionGuard;
use OCA\Humaniq\Service\HoursRegisterGateway;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Unit tests for LeaveTypeConditionGuard.
 */
class LeaveTypeConditionGuardTest extends TestCase {

	/**
	 * Build a guard whose register answers one set of leave types.
	 *
	 * @param array<int, array<string, mixed>> $types The administered types.
	 * @param bool $gatewayThrows Whether the type lookup fails.
	 *
	 * @return LeaveTypeConditionGuard The subject.
	 */
	private function guard(array $types, bool $gatewayThrows = false): LeaveTypeConditionGuard {
		$gateway = $this->getMockBuilder(HoursRegisterGateway::class)
			->disableOriginalConstructor()
			->onlyMethods(['loadAll'])
			->getMock();

		if ($gatewayThrows === true) {
			$gateway->method('loadAll')->willThrowException(new RuntimeException('register unavailable'));
		} else {
			$gateway->method('loadAll')->willReturn($types);
		}

		return new LeaveTypeConditionGuard(gateway: $gateway);
	}//end guard()

	/**
	 * The types this instance administers.
	 *
	 * @return array<int, array<string, mixed>> The types.
	 */
	private function types(): array {
		return [
			['id' => 'type-holiday', 'code' => 'holiday', 'label' => 'Vakantie', 'active' => true],
			['id' => 'type-unpaid', 'code' => 'unpaid', 'label' => 'Onbetaald verlof', 'requiresReason' => true, 'active' => true],
		];
	}//end types()

	/**
	 * The control: a request whose type asks nothing submits.
	 *
	 * @return void
	 */
	public function testARequestWithNoConditionsIsAllowed(): void {
		$result = $this->guard(types: $this->types())->check(
			['leaveType' => 'holiday', 'startDate' => '2026-10-01'],
			'submit',
			'jansen'
		);

		$this->assertTrue($result->isAllowed());
	}//end testARequestWithNoConditionsIsAllowed()

	/**
	 * A type that needs a reason denies the submit, and the denial names the
	 * condition rather than saying only that something was wrong.
	 *
	 * @return void
	 */
	public function testAMissingReasonDeniesTheSubmit(): void {
		$result = $this->guard(types: $this->types())->check(
			['leaveType' => 'unpaid', 'reason' => '', 'startDate' => '2026-10-01'],
			'submit',
			'jansen'
		);

		$this->assertFalse($result->isAllowed());
		$this->assertStringContainsString('reden', (string)$result->getMessage());
	}//end testAMissingReasonDeniesTheSubmit()

	/**
	 * An instance that has administered no types at all still submits: the
	 * guard refuses a missed condition, not an empty configuration.
	 *
	 * @return void
	 */
	public function testAnInstanceWithoutAdministeredTypesStillSubmits(): void {
		$result = $this->guard(types: [])->check(
			['leaveType' => 'holiday', 'startDate' => '2026-10-01'],
			'submit',
			'jansen'
		);

		$this->assertTrue($result->isAllowed());
	}//end testAnInstanceWithoutAdministeredTypesStillSubmits()

	/**
	 * A type list that cannot be READ denies, because an unchecked condition
	 * that passes looks exactly like a satisfied one.
	 *
	 * @return void
	 */
	public function testAnUnreadableTypeListDenies(): void {
		$result = $this->guard(types: [], gatewayThrows: true)->check(
			['leaveType' => 'unpaid', 'startDate' => '2026-10-01'],
			'submit',
			'jansen'
		);

		$this->assertFalse($result->isAllowed());
		$this->assertStringContainsString('niet gecontroleerd', (string)$result->getMessage());
	}//end testAnUnreadableTypeListDenies()
}//end class
