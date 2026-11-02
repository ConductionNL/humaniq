<?php

/**
 * @category Test
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
 * @spec openspec/changes/hr-reopens-an-approved-claim/specs/humaniq-expenses/spec.md#Requirement:-HR-SHALL-be-able-to-reopen-an-approved-expense-claim-that-no-payroll-run-holds
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Lifecycle;

use OCA\Humaniq\Lifecycle\HrReopenGuard;
use OCA\Humaniq\Service\HumaniqRoles;
use OCP\IGroupManager;
use PHPUnit\Framework\TestCase;

/**
 * Who may reopen an approved claim.
 *
 * @covers \OCA\Humaniq\Lifecycle\HrReopenGuard
 * @uses   \OCA\Humaniq\Service\HumaniqRoles
 */
class HrReopenGuardTest extends TestCase {

	/**
	 * Tim's approved claim, approved by Sanne.
	 *
	 * @var array<string, mixed>
	 */
	private const CLAIM = ['employeeId' => 'emp-tim', 'userId' => 'tim', 'status' => 'approved', 'approvedBy' => 'sanne', 'amount' => 34.5];

	/**
	 * The guard, with ilse and sanne in HR.
	 *
	 * @return HrReopenGuard
	 */
	private function guard(): HrReopenGuard {
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('isInGroup')->willReturnCallback(
			static fn (string $uid, string $group): bool => $group === HumaniqRoles::HR_GROUP && in_array($uid, ['ilse', 'sanne', 'tim-hr'], true)
		);

		return new HrReopenGuard(new HumaniqRoles($groups));
	}//end guard()

	/**
	 * HR may reopen an approved claim no run holds.
	 *
	 * @return void
	 */
	public function testHrMayReopen(): void {
		self::assertTrue($this->guard()->check(self::CLAIM, 'reopen', 'ilse')->isAllowed());
		self::assertTrue($this->guard()->check(array_merge(self::CLAIM, ['payrollRunId' => ' ']), 'reopen', 'ilse')->isAllowed());
	}//end testHrMayReopen()

	/**
	 * An unknown account, someone outside HR, the claimant and the approver
	 * may not reopen, even when the latter two are in HR.
	 *
	 * @return void
	 */
	public function testOnlyHrOtherThanClaimantAndApproverMayReopen(): void {
		foreach (['', 'tim', 'kees'] as $uid) {
			$result = $this->guard()->check(self::CLAIM, 'reopen', $uid);
			self::assertFalse($result->isAllowed(), $uid);
			self::assertNotSame('', (string)$result->getMessage(), $uid);
		}

		self::assertFalse($this->guard()->check(self::CLAIM, 'reopen', 'sanne')->isAllowed());
		self::assertFalse($this->guard()->check(array_merge(self::CLAIM, ['userId' => ' tim-hr ']), 'reopen', 'tim-hr')->isAllowed());
		self::assertFalse($this->guard()->check(array_merge(self::CLAIM, ['approvedBy' => ' ilse ']), 'reopen', 'ilse')->isAllowed());
	}//end testOnlyHrOtherThanClaimantAndApproverMayReopen()

	/**
	 * A claim a payroll run holds cannot be reopened.
	 *
	 * @return void
	 */
	public function testAClaimInAPayrollRunCannotBeReopened(): void {
		$result = $this->guard()->check(array_merge(self::CLAIM, ['payrollRunId' => 'run-1']), 'reopen', 'ilse');

		self::assertFalse($result->isAllowed());
		self::assertStringContainsString('salarisrun', (string)$result->getMessage());
	}//end testAClaimInAPayrollRunCannotBeReopened()

}//end class
