<?php

/**
 * LeaveApprovalStepGuard: which step a leave request is at, and who may take it.
 *
 * @category Tests
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
 *
 * @spec openspec/changes/leave-approval-steps-and-history/specs/leave-management/spec.md
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Lifecycle;

use OCA\Humaniq\Lifecycle\LeaveApprovalStepGuard;
use OCA\Humaniq\Service\HoursRegisterGateway;
use OCP\IGroupManager;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * @covers \OCA\Humaniq\Lifecycle\LeaveApprovalStepGuard
 * @uses   \OCA\Humaniq\Lifecycle\NoSelfApprovalGuard
 * @uses   \OCA\Humaniq\Service\LeaveTypeResolver
 */
class LeaveApprovalStepGuardTest extends TestCase {

	private const TYPES = [
		['id' => 't-unpaid', 'code' => 'unpaid', 'requiresHrApproval' => true],
		['id' => 't-holiday', 'code' => 'holiday', 'hrApprovalAboveHours' => 80],
		['id' => 't-special', 'code' => 'special'],
	];

	private function guard(bool $typesThrow = false): LeaveApprovalStepGuard {
		$gateway = $this->getMockBuilder(HoursRegisterGateway::class)->disableOriginalConstructor()->onlyMethods(['loadAll'])->getMock();
		if ($typesThrow === true) {
			$gateway->method('loadAll')->willThrowException(new RuntimeException('register unavailable'));
		} else {
			$gateway->method('loadAll')->willReturn(self::TYPES);
		}

		$groups = $this->createMock(IGroupManager::class);
		$groups->method('isAdmin')->willReturnCallback(static fn (string $uid): bool => in_array($uid, ['hr-ellen', 'manager-hr'], true));

		return new LeaveApprovalStepGuard(gateway: $gateway, groupManager: $groups);
	}

	private static function request(string $type, string $status = 'submitted', ?float $hours = null, array $decisions = []): array {
		return ['employeeId' => 'emp-1', 'userId' => 'anna', 'leaveType' => $type, 'status' => $status, 'hours' => $hours, 'decisions' => $decisions];
	}

	public function testATypeNeedingHrGoesThroughTheManagerStep(): void {
		$guard = $this->guard();

		$this->assertFalse($guard->check(self::request('unpaid'), 'approve', 'manager1')->isAllowed());
		$this->assertTrue($guard->check(self::request('unpaid'), 'approve-manager', 'manager1')->isAllowed());
		$this->assertFalse($guard->check(self::request('holiday', hours: 120.0), 'approve', 'manager1')->isAllowed(), 'more hours than the threshold need HR');
		$this->assertTrue($guard->check(self::request('holiday', hours: 40.0), 'approve', 'manager1')->isAllowed());
	}

	public function testATypeWithoutAnHrStepRefusesTheManagerStep(): void {
		$guard = $this->guard();

		$this->assertFalse($guard->check(self::request('special'), 'approve-manager', 'manager1')->isAllowed());
		$this->assertTrue($guard->check(self::request('special'), 'approve', 'manager1')->isAllowed());
		$this->assertTrue($guard->check(self::request('special'), 'reject', 'manager1')->isAllowed());
	}

	public function testOnlyHrAndNotTheApprovingManagerTakesTheHrStep(): void {
		$guard = $this->guard();
		$waiting = self::request('unpaid', 'manager-approved', decisions: [['step' => 'manager', 'decision' => 'approved', 'by' => 'manager-hr']]);

		$this->assertFalse($guard->check($waiting, 'approve', 'manager1')->isAllowed(), 'not HR');
		$this->assertFalse($guard->check($waiting, 'approve', 'manager-hr')->isAllowed(), 'HR, but the approving manager');
		$this->assertTrue($guard->check($waiting, 'approve', 'hr-ellen')->isAllowed());
		$this->assertTrue($guard->check($waiting, 'reject', 'hr-ellen')->isAllowed());
		$this->assertFalse($guard->check($waiting, 'reject', 'manager1')->isAllowed());
		$this->assertFalse($guard->check($waiting, 'approve-manager', 'hr-ellen')->isAllowed());
	}

	public function testNobodyDecidesOnTheirOwnRequest(): void {
		$this->assertFalse($this->guard()->check(self::request('special'), 'approve', 'anna')->isAllowed());
	}

	public function testUnreadableTypesApproveNothing(): void {
		$guard = $this->guard(typesThrow: true);

		$this->assertFalse($guard->check(self::request('special'), 'approve', 'manager1')->isAllowed());
		$this->assertFalse($guard->check(self::request('unpaid'), 'approve-manager', 'manager1')->isAllowed());
		$this->assertTrue($guard->check(self::request('special'), 'reject', 'manager1')->isAllowed(), 'a rejection needs no type');
	}
}
