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
use OCA\Humaniq\Service\RecordStoreInterface;
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
	private const CLAIM = ['id' => 'ex-1', 'employeeId' => 'emp-tim', 'userId' => 'tim', 'status' => 'approved', 'approvedBy' => 'sanne', 'amount' => 34.5];

	/**
	 * The stored claims, by id; the guard judges these, not the payload.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private array $stored = ['ex-1' => self::CLAIM];

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

		$store = $this->createMock(RecordStoreInterface::class);
		$store->method('findObjectData')->willReturnCallback(
			fn (string $uuid, string $schema): ?array => ($schema === 'Expense' ? ($this->stored[$uuid] ?? null) : null)
		);

		return new HrReopenGuard(new HumaniqRoles($groups), $store);
	}//end guard()

	/**
	 * HR may reopen an approved claim no run holds.
	 *
	 * @return void
	 */
	public function testHrMayReopen(): void {
		self::assertTrue($this->guard()->check(self::CLAIM, 'reopen', 'ilse')->isAllowed());
		$this->stored['ex-1'] = array_merge(self::CLAIM, ['payrollRunId' => ' ']);
		self::assertTrue($this->guard()->check(self::CLAIM, 'reopen', 'ilse')->isAllowed());
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

		$this->stored['ex-1'] = array_merge(self::CLAIM, ['userId' => ' tim-hr ']);
		self::assertFalse($this->guard()->check(self::CLAIM, 'reopen', 'tim-hr')->isAllowed());

		$this->stored['ex-1'] = array_merge(self::CLAIM, ['approvedBy' => ' ilse ']);
		self::assertFalse($this->guard()->check(self::CLAIM, 'reopen', 'ilse')->isAllowed());
	}//end testOnlyHrOtherThanClaimantAndApproverMayReopen()

	/**
	 * A claim a payroll run holds cannot be reopened.
	 *
	 * @return void
	 */
	public function testAClaimInAPayrollRunCannotBeReopened(): void {
		$this->stored['ex-1'] = array_merge(self::CLAIM, ['payrollRunId' => 'run-1']);
		$result = $this->guard()->check(self::CLAIM, 'reopen', 'ilse');

		self::assertFalse($result->isAllowed());
		self::assertStringContainsString('salarisrun', (string)$result->getMessage());
	}//end testAClaimInAPayrollRunCannotBeReopened()

	/**
	 * RW32's probe: an HR member who is the claimant forges the payload
	 * (another userId, no approver, no run) while a run holds the claim. The
	 * guard judges the stored claim, so it refuses.
	 *
	 * @return void
	 */
	public function testAForgedPayloadIsJudgedByTheStoredClaim(): void {
		$this->stored['ex-1'] = array_merge(self::CLAIM, ['userId' => 'tim-hr', 'approvedBy' => 'dirk', 'payrollRunId' => 'run-1']);
		$forged = array_merge(self::CLAIM, ['userId' => 'someone', 'approvedBy' => '', 'payrollRunId' => '']);

		self::assertFalse($this->guard()->check($forged, 'reopen', 'tim-hr')->isAllowed());

		$this->stored['ex-1'] = array_merge(self::CLAIM, ['payrollRunId' => 'run-1']);
		self::assertFalse($this->guard()->check($forged, 'reopen', 'ilse')->isAllowed());
	}//end testAForgedPayloadIsJudgedByTheStoredClaim()

	/**
	 * A claim that cannot be read, or a payload without an id, is refused;
	 * the id may come from `@self` or `uuid`, and the stored claim must
	 * still be approved.
	 *
	 * @return void
	 */
	public function testTheStoredClaimMustBeReadable(): void {
		self::assertFalse($this->guard()->check(array_merge(self::CLAIM, ['id' => 'ex-gone']), 'reopen', 'ilse')->isAllowed());
		self::assertFalse($this->guard()->check(array_diff_key(self::CLAIM, ['id' => true]), 'reopen', 'ilse')->isAllowed());

		$withoutId = array_diff_key(self::CLAIM, ['id' => true]);
		self::assertTrue($this->guard()->check($withoutId + ['@self' => ['id' => 'ex-1']], 'reopen', 'ilse')->isAllowed());
		self::assertTrue($this->guard()->check($withoutId + ['uuid' => 'ex-1'], 'reopen', 'ilse')->isAllowed());

		$this->stored['ex-1'] = array_merge(self::CLAIM, ['status' => 'reimbursed']);
		self::assertFalse($this->guard()->check(self::CLAIM, 'reopen', 'ilse')->isAllowed());
	}//end testTheStoredClaimMustBeReadable()

}//end class
