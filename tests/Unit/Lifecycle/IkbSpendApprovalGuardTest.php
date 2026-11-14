<?php

/**
 * Unit tests for IkbSpendApprovalGuard.
 *
 * An IKB spend is approved by someone other than the employee and never
 * above the budget's balance; a budget that is missing or unreadable refuses
 * (payroll-reservation-payouts REQ-RSP-004, scenario "A request above the
 * balance is refused").
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
 *
 * @spec openspec/changes/payroll-reservation-payouts/specs/payroll-reservation-payouts/spec.md#requirement-the-individual-choice-budget-shall-accrue-and-be-spent-by-approved-request-req-rsp-004
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Lifecycle;

use OCA\Humaniq\Lifecycle\IkbSpendApprovalGuard;
use OCA\Humaniq\Service\RecordStoreInterface;
use PHPUnit\Framework\TestCase;

/**
 * Separation of duties and the balance.
 *
 * @covers \OCA\Humaniq\Lifecycle\IkbSpendApprovalGuard
 * @uses \OCA\Humaniq\Lifecycle\NoSelfApprovalGuard
 */
class IkbSpendApprovalGuardTest extends TestCase {

	/**
	 * A guard over one budget of emp-1 in 2026 with the balance given.
	 *
	 * @param float|null $balance The balance, null for no budget.
	 * @param bool       $throws  Whether reading the budget fails.
	 *
	 * @return IkbSpendApprovalGuard
	 */
	private function guard(?float $balance, bool $throws = false): IkbSpendApprovalGuard {
		$records = $this->createMock(RecordStoreInterface::class);
		$records->method('findObjectData')->willReturn(['id' => 'emp-1', 'nextcloudUserId' => 'jansen']);
		if ($throws === true) {
			$records->method('findFiltered')->willThrowException(new \RuntimeException('down'));
		} else {
			$records->expects(self::atMost(1))->method('findFiltered')->with('IkbBudget', ['employeeId' => 'emp-1', 'year' => 2026])->willReturn($balance === null ? [] : [['employeeId' => 'emp-1', 'year' => 2026, 'balance' => $balance]]);
		}

		return new IkbSpendApprovalGuard($records);
	}//end guard()

	/**
	 * A request of emp-1.
	 *
	 * @param float $amount The amount.
	 *
	 * @return array<string, mixed>
	 */
	private static function request(float $amount): array {
		return ['employeeId' => 'emp-1', 'userId' => 'jansen', 'year' => 2026, 'goal' => 'payout', 'amount' => $amount];
	}//end request()

	/**
	 * Within the balance a manager approves.
	 *
	 * @return void
	 */
	public function testAManagerApprovesWithinTheBalance(): void {
		self::assertTrue($this->guard(1500.0)->check(self::request(480.0), 'approve', 'manager')->isAllowed());
		self::assertTrue($this->guard(480.0)->check(self::request(480.0), 'approve', 'manager')->isAllowed(), 'the whole balance may be spent');
	}//end testAManagerApprovesWithinTheBalance()

	/**
	 * Above the balance the approval is refused.
	 *
	 * @return void
	 */
	public function testARequestAboveTheBalanceIsRefused(): void {
		$result = $this->guard(300.0)->check(self::request(500.0), 'approve', 'manager');

		self::assertFalse($result->isAllowed());
		self::assertStringContainsString('hoger dan het keuzebudget', (string)$result->getMessage());
	}//end testARequestAboveTheBalanceIsRefused()

	/**
	 * The employee cannot approve their own request, stamped or not.
	 *
	 * @return void
	 */
	public function testTheEmployeeCannotApproveTheirOwnRequest(): void {
		self::assertFalse($this->guard(1500.0)->check(self::request(10.0), 'approve', 'jansen')->isAllowed());

		$unstamped = self::request(10.0);
		unset($unstamped['userId']);
		self::assertFalse($this->guard(1500.0)->check($unstamped, 'approve', 'jansen')->isAllowed(), 'the employee account is checked when userId is not stamped');
	}//end testTheEmployeeCannotApproveTheirOwnRequest()

	/**
	 * No budget, an unreadable one, or no amount refuses.
	 *
	 * @return void
	 */
	public function testNoBudgetOrNoAmountRefuses(): void {
		self::assertSame(IkbSpendApprovalGuard::NO_BUDGET, $this->guard(null)->check(self::request(10.0), 'approve', 'manager')->getMessage());
		self::assertFalse($this->guard(1500.0, throws: true)->check(self::request(10.0), 'approve', 'manager')->isAllowed());
		self::assertFalse($this->guard(1500.0)->check(self::request(0.0), 'approve', 'manager')->isAllowed());
	}//end testNoBudgetOrNoAmountRefuses()
}//end class
