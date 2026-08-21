<?php

/**
 * Unit tests for NoSelfApprovalGuard.
 *
 * This guard is the app's only separation-of-duties control, shared by
 * Timesheet/Expense approve+reject and PerformanceReview vaststellen — and it
 * had NO test, which is exactly how it came to be inert: it compared
 * `employeeId` (an Employee OBJECT uuid) with the acting Nextcloud ACCOUNT
 * uid, two namespaces that can never produce equal strings, so it allowed
 * every transition while every spec and comment claimed it enforced one. An
 * e2e that actually attempted a self-approval found it.
 *
 * The first test below is the one that fails against that old implementation:
 * a claimant whose account IS the acting user must be denied.
 *
 * @category Test
 * @package  OCA\Hrmq\Tests\Unit\Lifecycle
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Hrmq\Tests\Unit\Lifecycle;

use OCA\Hrmq\Lifecycle\NoSelfApprovalGuard;
use PHPUnit\Framework\TestCase;

/**
 * Separation of duties on approve/reject.
 *
 * @covers \OCA\Hrmq\Lifecycle\NoSelfApprovalGuard
 */
class NoSelfApprovalGuardTest extends TestCase {

	/**
	 * The subject.
	 *
	 * @var NoSelfApprovalGuard
	 */
	private NoSelfApprovalGuard $guard;

	/**
	 * {@inheritDoc}
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->guard = new NoSelfApprovalGuard();
	}//end setUp()

	/**
	 * A timesheet whose claimant's ACCOUNT is the acting user is denied.
	 *
	 * This is the regression test: the old implementation compared the
	 * employee uuid against the uid and allowed this transition.
	 *
	 * @return void
	 */
	public function testDeniesWhenTheClaimantsAccountIsTheActingUser(): void {
		$result = $this->guard->check(
			[
				'employeeId' => '0127394a-be27-48b4-a592-b6a41774b221',
				'userId' => 'admin',
			],
			'approve',
			'admin'
		);

		self::assertFalse($result->isAllowed(), 'Approving your own timesheet must be denied.');
		self::assertStringContainsString('eigen urenstaat', (string)$result->getMessage());
	}//end testDeniesWhenTheClaimantsAccountIsTheActingUser()

	/**
	 * Reject is governed by the same rule as approve.
	 *
	 * @return void
	 */
	public function testDeniesSelfRejectionToo(): void {
		$result = $this->guard->check(
			['employeeId' => 'emp-uuid', 'userId' => 'jansen'],
			'reject',
			'jansen'
		);

		self::assertFalse($result->isAllowed());
	}//end testDeniesSelfRejectionToo()

	/**
	 * Another manager approving someone else's timesheet is allowed.
	 *
	 * @return void
	 */
	public function testAllowsAnotherUserToApprove(): void {
		$result = $this->guard->check(
			['employeeId' => 'emp-uuid', 'userId' => 'jansen'],
			'approve',
			'manager'
		);

		self::assertTrue($result->isAllowed());
		self::assertNull($result->getMessage());
	}//end testAllowsAnotherUserToApprove()

	/**
	 * An identified claimant with no Nextcloud account cannot BE the acting
	 * user, so the transition is allowed — hrmq seeds several such employees.
	 *
	 * @return void
	 */
	public function testAllowsWhenTheClaimantHasNoAccount(): void {
		$result = $this->guard->check(
			['employeeId' => 'emp-uuid', 'userId' => null],
			'approve',
			'manager'
		);

		self::assertTrue($result->isAllowed());
	}//end testAllowsWhenTheClaimantHasNoAccount()

	/**
	 * An unidentified claimant is denied — the guard cannot prove separation
	 * of duties, so it fails closed.
	 *
	 * @return void
	 */
	public function testDeniesWhenTheClaimingEmployeeIsUnknown(): void {
		$result = $this->guard->check(['userId' => 'someone'], 'approve', 'manager');

		self::assertFalse($result->isAllowed());
		self::assertStringContainsString('onbekend', (string)$result->getMessage());
	}//end testDeniesWhenTheClaimingEmployeeIsUnknown()

	/**
	 * An anonymous caller is denied before anything else is considered.
	 *
	 * @return void
	 */
	public function testDeniesAnAnonymousCaller(): void {
		$result = $this->guard->check(
			['employeeId' => 'emp-uuid', 'userId' => 'jansen'],
			'approve',
			''
		);

		self::assertFalse($result->isAllowed());
		self::assertStringContainsString('ingelogd', (string)$result->getMessage());
	}//end testDeniesAnAnonymousCaller()

	/**
	 * Whitespace around the stored account must not defeat the comparison.
	 *
	 * @return void
	 */
	public function testIgnoresSurroundingWhitespaceOnTheStoredAccount(): void {
		$result = $this->guard->check(
			['employeeId' => 'emp-uuid', 'userId' => '  admin  '],
			'approve',
			'admin'
		);

		self::assertFalse($result->isAllowed(), 'A padded stored uid must still match the acting uid.');
	}//end testIgnoresSurroundingWhitespaceOnTheStoredAccount()
}//end class
