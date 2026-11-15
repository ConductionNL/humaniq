<?php

/**
 * LeaveApprovalStepGuard::checkTransition(): the step judged on the stored request.
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
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Lifecycle\LifecycleGuardContext;
use OCP\IGroupManager;
use PHPUnit\Framework\TestCase;

/**
 * OpenRegister passes the incoming data, already at the target status, and the stored request.
 * The step must be read from the stored request.
 *
 * @covers \OCA\Humaniq\Lifecycle\LeaveApprovalStepGuard
 * @uses   \OCA\Humaniq\Lifecycle\NoSelfApprovalGuard
 * @uses   \OCA\Humaniq\Service\LeaveTypeResolver
 */
class LeaveApprovalStepGuardTransitionTest extends TestCase {

	private const TYPES = [
		['id' => 't-unpaid', 'code' => 'unpaid', 'requiresHrApproval' => true],
		['id' => 't-special', 'code' => 'special'],
	];

	private function guard(): LeaveApprovalStepGuard {
		$gateway = $this->getMockBuilder(HoursRegisterGateway::class)->disableOriginalConstructor()->onlyMethods(['loadAll'])->getMock();
		$gateway->method('loadAll')->willReturn(self::TYPES);
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('isAdmin')->willReturnCallback(static fn (string $uid): bool => in_array($uid, ['hr-ellen', 'manager-hr'], true));

		return new LeaveApprovalStepGuard(gateway: $gateway, groupManager: $groups);
	}

	/**
	 * The transition of a stored request into the given incoming data.
	 *
	 * @param array<string, mixed> $stored   The stored request.
	 * @param array<string, mixed> $incoming The incoming data, status at the target.
	 * @param string               $action   The action.
	 * @param string               $userId   The caller.
	 *
	 * @return LifecycleGuardContext
	 */
	private static function transition(array $stored, array $incoming, string $action, string $userId): LifecycleGuardContext {
		$entity = new ObjectEntity();
		$entity->setUuid('leave-1');
		$entity->setObject($stored);

		return new LifecycleGuardContext(object: $incoming, stored: $entity, action: $action, userId: $userId);
	}

	private static function request(string $type, string $status, array $decisions = []): array {
		return ['employeeId' => 'emp-1', 'userId' => 'anna', 'leaveType' => $type, 'status' => $status, 'hours' => null, 'decisions' => $decisions];
	}

	public function testAManagerApprovesASubmittedRequestThatNeedsHr(): void {
		$stored = self::request('unpaid', 'submitted');
		$incoming = ['status' => 'manager-approved'] + $stored;

		$this->assertTrue($this->guard()->checkTransition(self::transition($stored, $incoming, 'approve-manager', 'manager1'))->isAllowed());
		$this->assertFalse(
			$this->guard()->check($incoming, 'approve-manager', 'manager1')->isAllowed(),
			'judged on the incoming status alone, the step looks already taken'
		);
	}

	public function testHrApprovesAManagerApprovedRequest(): void {
		$decisions = [['step' => 'manager', 'decision' => 'approved', 'by' => 'manager-hr']];
		$stored = self::request('unpaid', 'manager-approved', $decisions);
		$incoming = ['status' => 'approved', 'decisions' => array_merge($decisions, [['step' => 'hr', 'decision' => 'approved', 'by' => 'hr-ellen']])] + $stored;

		$this->assertTrue($this->guard()->checkTransition(self::transition($stored, $incoming, 'approve', 'hr-ellen'))->isAllowed());
		$this->assertFalse($this->guard()->checkTransition(self::transition($stored, $incoming, 'approve', 'manager-hr'))->isAllowed(), 'the approving manager is not the HR step');
		$this->assertFalse($this->guard()->checkTransition(self::transition($stored, $incoming, 'approve', 'manager1'))->isAllowed(), 'not HR');
	}

	public function testTheStepStillFollowsTheStoredRequestsType(): void {
		$stored = self::request('unpaid', 'submitted');

		$denied = $this->guard()->checkTransition(self::transition($stored, ['status' => 'approved'] + $stored, 'approve', 'manager1'));
		$this->assertFalse($denied->isAllowed(), 'an HR type cannot be approved straight away');
		$this->assertStringContainsString('HR', (string)$denied->getMessage());

		$special = self::request('special', 'submitted');
		$this->assertTrue($this->guard()->checkTransition(self::transition($special, ['status' => 'approved'] + $special, 'approve', 'manager1'))->isAllowed());
	}

	public function testTheApplicantCannotDecideOnTheirOwnRequest(): void {
		$stored = self::request('special', 'submitted');

		$this->assertFalse($this->guard()->checkTransition(self::transition($stored, ['status' => 'approved'] + $stored, 'approve', 'anna'))->isAllowed());
	}
}
