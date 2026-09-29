<?php

/**
 * Unit tests for ApprovalsInboxService (self-service-approvals-inbox D1): the three spec scenarios, on the real gateway, deputies and RBAC reader over the in-memory store.
 *
 * @category Test
 * @package  OCA\Humaniq\Tests\Unit\Service
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
 * @spec openspec/specs/approvals-inbox/spec.md#REQ-API-001
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Service;

use OCA\Humaniq\Tests\Unit\Support\ApprovalsFixture;
use PHPUnit\Framework\TestCase;

class ApprovalsInboxServiceTest extends TestCase {

	use ApprovalsFixture;

	protected function setUp(): void {
		parent::setUp();
		$this->seedTeam();
	}//end setUp()

	/**
	 * Scenario: a manager clears the week's requests.
	 */
	public function testAManagerSeesAllSixWaitingRequestsOldestFirst(): void {
		$this->seedWeek();

		$open = $this->inbox()->open('mila', '2026-07-10');

		self::assertSame(['ts-1', 'lv-1', 'ts-2', 'ex-1', 'lv-2', 'ts-3'], array_column($open, 'id'));
		self::assertSame(['hours', 'leave', 'hours', 'expense', 'leave', 'hours'], array_column($open, 'kind'));
		self::assertSame('Sam Jansen', $open[1]['employee']);
		self::assertSame('LeaveRequestDetail', $open[1]['route']);
		self::assertSame(['2026-07-20', '2026-07-24'], [$open[1]['from'], $open[1]['to']]);
		self::assertSame(9, $open[0]['waitingDays']);
		self::assertNull($open[0]['onBehalfOf']);
		self::assertSame(['lv-1', 'lv-2'], array_column($this->inbox()->open('mila', '2026-07-10', 'leave'), 'id'));
	}//end testAManagerSeesAllSixWaitingRequestsOldestFirst()

	public function testDecidedAndOwnAndUnreadableRequestsAreNotWaiting(): void {
		$this->seedWeek();
		$this->request('LeaveRequest', 'lv-done', ['employeeId' => 'emp-sam', 'managerUserId' => 'mila', 'status' => 'approved', 'submittedAt' => '2026-07-01T08:00:00Z']);
		$this->request('LeaveRequest', 'lv-own', ['employeeId' => 'emp-mila', 'userId' => 'mila', 'managerUserId' => 'mila', 'status' => 'submitted', 'submittedAt' => '2026-07-01T08:00:00Z']);
		$this->store->seed('Expense', 'ex-hidden', ['employeeId' => 'emp-sam', 'managerUserId' => 'mila', 'status' => 'submitted', 'submittedAt' => '2026-07-01T08:00:00Z']);

		$ids = array_column($this->inbox()->open('mila', '2026-07-10'), 'id');

		self::assertNotContains('lv-done', $ids);
		self::assertNotContains('lv-own', $ids);
		self::assertNotContains('ex-hidden', $ids);
	}//end testDecidedAndOwnAndUnreadableRequestsAreNotWaiting()

	/**
	 * Scenario: a deputy covers the summer holiday.
	 */
	public function testADeputySeesTheManagersRequestsOnlyDuringThePeriod(): void {
		$this->request('LeaveRequest', 'lv-summer', ['employeeId' => 'emp-sam', 'userId' => 'sam', 'managerUserId' => 'mila', 'leaveType' => 'holiday', 'startDate' => '2026-08-10', 'endDate' => '2026-08-14', 'status' => 'submitted', 'submittedAt' => '2026-07-20T10:00:00Z']);
		$this->request('LeaveTransaction', 'tr-noor', ['employeeId' => 'emp-noor', 'transactionType' => 'sell', 'year' => 2026, 'hours' => 16, 'status' => 'submitted', 'submittedAt' => '2026-07-21T10:00:00Z']);

		$during = $this->inbox()->open('dirk', '2026-07-20');

		self::assertSame(['lv-summer', 'tr-noor'], array_column($during, 'id'));
		self::assertSame('mila', $during[0]['onBehalfOf']);
		self::assertSame('leave-trade', $during[1]['kind']);
		self::assertSame([], $this->inbox()->open('dirk', '2026-08-02'));
		self::assertSame(['lv-summer', 'tr-noor'], array_column($this->inbox()->open('mila', '2026-08-02'), 'id'));
	}//end testADeputySeesTheManagersRequestsOnlyDuringThePeriod()

	/**
	 * Scenario: looking back at a decision.
	 */
	public function testTheDecidedViewShowsWhenWhoAndWhy(): void {
		$this->request('LeaveRequest', 'lv-rejected', ['employeeId' => 'emp-sam', 'userId' => 'sam', 'managerUserId' => 'mila', 'leaveType' => 'holiday', 'startDate' => '2026-07-20', 'endDate' => '2026-07-24', 'status' => 'rejected', 'submittedAt' => '2026-07-01T09:00:00Z', 'approvedBy' => 'mila', 'approvedAt' => '2026-07-03T14:00:00Z', 'rejectionReason' => 'Te veel mensen tegelijk weg']);
		$this->request('Timesheet', 'ts-old', ['employeeId' => 'emp-sam', 'period' => '2026-01', 'status' => 'approved', 'submittedAt' => '2026-02-01T09:00:00Z', 'approvedBy' => 'mila', 'approvedAt' => '2026-02-02T09:00:00Z']);
		$this->request('Expense', 'ex-approved', ['employeeId' => 'emp-sam', 'userId' => 'sam', 'status' => 'reimbursed', 'submittedAt' => '2026-07-05T09:00:00Z', 'approvedBy' => 'mila', 'approvedAt' => '2026-07-06T09:00:00Z']);

		$decided = $this->inbox()->decided('mila', '2026-07-10');

		self::assertSame(['ex-approved', 'lv-rejected'], array_column($decided, 'id'));
		self::assertSame('approved', $decided[0]['verdict']);
		self::assertSame(
			[
				['event' => 'submitted', 'at' => '2026-07-01T09:00:00Z', 'by' => 'sam', 'reason' => null],
				['event' => 'rejected', 'at' => '2026-07-03T14:00:00Z', 'by' => 'mila', 'reason' => 'Te veel mensen tegelijk weg'],
			],
			$decided[1]['timeline']
		);
		self::assertSame([], $this->inbox()->decided('dirk', '2026-07-10'));
	}//end testTheDecidedViewShowsWhenWhoAndWhy()

	/**
	 * Two leave requests, three timesheets and one expense claim waiting for Mila.
	 */
	private function seedWeek(): void {
		$this->request('Timesheet', 'ts-1', ['employeeId' => 'emp-sam', 'userId' => 'sam', 'managerUserId' => 'mila', 'period' => '2026-06', 'hours' => 152, 'status' => 'submitted', 'submittedAt' => '2026-07-01T08:00:00Z']);
		$this->request('LeaveRequest', 'lv-1', ['employeeId' => 'emp-sam', 'userId' => 'sam', 'managerUserId' => 'mila', 'leaveType' => 'holiday', 'startDate' => '2026-07-20', 'endDate' => '2026-07-24', 'hours' => 40, 'status' => 'submitted', 'submittedAt' => '2026-07-02T08:00:00Z']);
		$this->request('Timesheet', 'ts-2', ['employeeId' => 'emp-noor', 'managerUserId' => 'mila', 'period' => '2026-06', 'hours' => 120, 'status' => 'submitted', 'submittedAt' => '2026-07-03T08:00:00Z']);
		$this->request('Expense', 'ex-1', ['employeeId' => 'emp-noor', 'managerUserId' => 'mila', 'title' => 'Treinkaartje', 'amount' => 23.4, 'expenseDate' => '2026-07-02', 'status' => 'submitted', 'submittedAt' => '2026-07-04T08:00:00Z']);
		$this->request('LeaveRequest', 'lv-2', ['employeeId' => 'emp-noor', 'managerUserId' => 'mila', 'leaveType' => 'care', 'startDate' => '2026-07-13', 'endDate' => '2026-07-13', 'hours' => 8, 'status' => 'submitted', 'submittedAt' => '2026-07-06T08:00:00Z']);
		$this->request('Timesheet', 'ts-3', ['employeeId' => 'emp-sam', 'userId' => 'sam', 'managerUserId' => 'mila', 'period' => '2026-07', 'hours' => 40, 'status' => 'submitted', 'submittedAt' => '2026-07-08T08:00:00Z']);
		$this->request('Timesheet', 'ts-other', ['employeeId' => 'emp-x', 'managerUserId' => 'someone-else', 'period' => '2026-06', 'status' => 'submitted', 'submittedAt' => '2026-07-01T07:00:00Z']);
	}//end seedWeek()

}//end class
