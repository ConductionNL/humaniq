<?php

/**
 * Unit tests for ReservationPayoutApprovalService and IkbBudgets.
 *
 * Approving a run marks its payouts paid, ticks a leaver's holiday allowance
 * on the offboarding case, adds the payslips' IKB accrual to the budgets and
 * settles the requests it paid, crediting extra leave as hours. Replaying the
 * approval over the state the first one left writes nothing
 * (payroll-reservation-payouts task 2.3, D8).
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
 * @spec openspec/changes/payroll-reservation-payouts/specs/payroll-reservation-payouts/spec.md#requirement-holiday-allowance-shall-be-paid-out-on-the-administrations-schedule-and-on-leaving-req-rsp-002
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Service;

use OCA\Humaniq\Listener\PayrollRunApprovedListener;
use OCA\Humaniq\Service\HoursRegisterGateway;
use OCA\Humaniq\Service\IkbBudgets;
use OCA\Humaniq\Service\InternalWriteMarker;
use OCA\Humaniq\Service\OvertimeCreditService;
use OCA\Humaniq\Service\ReservationPayoutApprovalService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Paid, ticked, accrued and settled once.
 *
 * @covers \OCA\Humaniq\Service\ReservationPayoutApprovalService
 * @covers \OCA\Humaniq\Service\IkbBudgets
 * @covers \OCA\Humaniq\Listener\PayrollRunApprovedListener
 * @uses \OCA\Humaniq\Service\InternalWriteMarker
 */
class ReservationPayoutApprovalServiceTest extends TestCase {

	/**
	 * The rows, updated in place by every save.
	 *
	 * @var array<string, list<array<string, mixed>>>
	 */
	private array $rows = [];

	/**
	 * Every save: schema, uuid and payload.
	 *
	 * @var list<array{schema: string, uuid: ?string, payload: array<string, mixed>}>
	 */
	private array $saves = [];

	/**
	 * A service over $this->rows, whose saves write back into them.
	 *
	 * @return ReservationPayoutApprovalService
	 */
	private function service(): ReservationPayoutApprovalService {
		$gateway = $this->createMock(HoursRegisterGateway::class);
		$gateway->method('loadAll')->willReturnCallback(fn (string $schema): array => ($this->rows[$schema] ?? []));
		$gateway->method('save')->willReturnCallback(function (array $payload, string $schema, ?string $uuid = null): object {
			$this->saves[] = ['schema' => $schema, 'uuid' => $uuid, 'payload' => $payload];
			$this->store(schema: $schema, uuid: $uuid, payload: $payload);
			return new \stdClass();
		});

		return new ReservationPayoutApprovalService($gateway, new InternalWriteMarker());
	}//end service()

	/**
	 * Write a saved row back, a new one under a fresh id.
	 *
	 * @param string               $schema  The schema.
	 * @param string|null          $uuid    The uuid.
	 * @param array<string, mixed> $payload The row.
	 *
	 * @return void
	 */
	private function store(string $schema, ?string $uuid, array $payload): void {
		foreach (($this->rows[$schema] ?? []) as $i => $row) {
			if ($uuid !== null && ($row['id'] ?? null) === $uuid) {
				$this->rows[$schema][$i] = array_merge($payload, ['id' => $uuid]);
				return;
			}
		}

		$this->rows[$schema][] = array_merge($payload, ['id' => ($uuid ?? 'new-' . count($this->saves))]);
	}//end store()

	/**
	 * A September run: a leaver's holiday allowance payout, an IKB payout
	 * request it paid, a leave request settling in its period, and a payslip
	 * accruing IKB.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->rows = [
			'ReservationPayout' => [
				['id' => 'rp-1', 'employeeId' => 'emp-1', 'kind' => 'vakantiegeld', 'reason' => 'leaving', 'amount' => 121600, 'payrollRunId' => 'run-sep', 'status' => 'calculated'],
				['id' => 'rp-2', 'employeeId' => 'emp-1', 'kind' => 'ikb', 'reason' => 'ikb-request', 'amount' => 30000, 'payrollRunId' => 'run-sep', 'ikbSpendRequestId' => 'req-pay', 'status' => 'calculated'],
				['id' => 'rp-other', 'employeeId' => 'emp-2', 'kind' => 'vakantiegeld', 'reason' => 'scheduled', 'amount' => 1, 'payrollRunId' => 'run-other', 'status' => 'calculated'],
			],
			'Payslip' => [
				['id' => 'ps-1', 'employeeId' => 'emp-1', 'userId' => 'jansen', 'payrollRunId' => 'run-sep', 'period' => '2026-09', 'ikbAccrued' => 627.0],
			],
			'Offboarding' => [
				['id' => 'off-1', 'employeeId' => 'emp-1', 'vakantiegeldAfgerekend' => false],
				['id' => 'off-2', 'employeeId' => 'emp-2', 'vakantiegeldAfgerekend' => false],
			],
			'IkbBudget' => [['id' => 'b-1', 'employeeId' => 'emp-1', 'year' => 2026, 'accrued' => 1500.0, 'spent' => 0.0, 'balance' => 1500.0, 'accruedRunIds' => []]],
			'IkbSpendRequest' => [
				['id' => 'req-pay', 'employeeId' => 'emp-1', 'year' => 2026, 'goal' => 'payout', 'amount' => 300.0, 'status' => 'approved', 'settlementPeriod' => '2026-09'],
				['id' => 'req-leave', 'employeeId' => 'emp-1', 'year' => 2026, 'goal' => 'leave', 'amount' => 480.0, 'hours' => 16, 'status' => 'approved', 'settlementPeriod' => '2026-09'],
				['id' => 'req-other', 'employeeId' => 'emp-1', 'year' => 2026, 'goal' => 'leave', 'amount' => 30.0, 'hours' => 1, 'status' => 'approved', 'settlementPeriod' => '2026-10'],
			],
			'LeaveBalance' => [['id' => 'lb-1', 'employeeId' => 'emp-1', 'year' => 2026, 'leaveType' => 'holiday', 'bovenwettelijkHours' => 8.0]],
		];
	}//end setUp()

	/**
	 * The row of a schema by id.
	 *
	 * @param string $schema The schema.
	 * @param string $id     The id.
	 *
	 * @return array<string, mixed>
	 */
	private function row(string $schema, string $id): array {
		foreach ($this->rows[$schema] as $row) {
			if ($row['id'] === $id) {
				return $row;
			}
		}

		self::fail($schema . ' ' . $id . ' not found');
	}//end row()

	/**
	 * Approval pays, ticks, accrues and settles.
	 *
	 * @return void
	 */
	public function testApprovalPaysTicksAccruesAndSettles(): void {
		$done = $this->service()->onRunApproved('run-sep');

		self::assertSame(['paid' => 2, 'offboarding' => 1, 'accrued' => 1, 'settled' => 2], $done);
		self::assertSame('paid', $this->row('ReservationPayout', 'rp-1')['status']);
		self::assertSame('calculated', $this->row('ReservationPayout', 'rp-other')['status'], 'another run\'s payout is left alone');
		self::assertTrue($this->row('Offboarding', 'off-1')['vakantiegeldAfgerekend']);
		self::assertFalse($this->row('Offboarding', 'off-2')['vakantiegeldAfgerekend']);
		self::assertSame('settled', $this->row('IkbSpendRequest', 'req-pay')['status']);
		self::assertSame('run-sep', $this->row('IkbSpendRequest', 'req-leave')['settledInRunId']);
		self::assertSame('approved', $this->row('IkbSpendRequest', 'req-other')['status'], 'a request for another period waits');
		self::assertSame(24.0, $this->row('LeaveBalance', 'lb-1')['bovenwettelijkHours'], '16 hours credited, no money');

		$budget = $this->row('IkbBudget', 'b-1');
		self::assertSame(2127.0, $budget['accrued']);
		self::assertSame(780.0, $budget['spent']);
		self::assertSame(1347.0, $budget['balance']);
		self::assertSame(['run-sep'], $budget['accruedRunIds']);
	}//end testApprovalPaysTicksAccruesAndSettles()

	/**
	 * A replayed approval writes nothing twice.
	 *
	 * @return void
	 */
	public function testAReplayedApprovalWritesNothing(): void {
		$service = $this->service();
		$service->onRunApproved('run-sep');
		$this->saves = [];

		$done = $service->onRunApproved('run-sep');

		self::assertSame(['paid' => 0, 'offboarding' => 0, 'accrued' => 0, 'settled' => 0], $done);
		self::assertSame([], $this->saves);
	}//end testAReplayedApprovalWritesNothing()

	/**
	 * An accrual for an employee without a budget creates one.
	 *
	 * @return void
	 */
	public function testAMissingBudgetIsCreated(): void {
		$this->rows['IkbBudget'] = [];
		$this->rows['IkbSpendRequest'] = [];

		$this->service()->onRunApproved('run-sep');

		$created = array_values(array_filter($this->saves, static fn (array $save): bool => $save['schema'] === 'IkbBudget'));
		self::assertCount(1, $created);
		self::assertNull($created[0]['uuid']);
		self::assertSame(['employeeId' => 'emp-1', 'userId' => 'jansen', 'year' => 2026, 'accrued' => 627.0, 'spent' => 0.0, 'balance' => 627.0, 'accruedRunIds' => ['run-sep']], $created[0]['payload']);
	}//end testAMissingBudgetIsCreated()

	/**
	 * An empty run id does nothing.
	 *
	 * @return void
	 */
	public function testAnEmptyRunDoesNothing(): void {
		self::assertSame(['paid' => 0, 'offboarding' => 0, 'accrued' => 0, 'settled' => 0], $this->service()->onRunApproved(''));
		self::assertSame([], $this->saves);
	}//end testAnEmptyRunDoesNothing()

	/**
	 * The budgets keep the balance as accrued minus spent and refuse a second accrual of one run.
	 *
	 * @return void
	 */
	public function testBudgetsAccrueOncePerRunAndKeepTheBalance(): void {
		$budgets = new IkbBudgets(rows: [['id' => 'b-1', 'employeeId' => 'emp-1', 'year' => 2026, 'accrued' => 100.0, 'spent' => 0.0, 'balance' => 100.0]]);

		self::assertTrue($budgets->accrue('emp-1', 2026, 50.0, 'run-1'));
		self::assertFalse($budgets->accrue('emp-1', 2026, 50.0, 'run-1'));
		self::assertFalse($budgets->accrue('emp-1', 2026, 0.0, 'run-2'));
		$budgets->spend('emp-1', 2026, 30.0);

		self::assertSame(120.0, $budgets->get('emp-1', 2026)['balance']);
		self::assertSame(['b-1'], array_keys($budgets->changed()));
		self::assertNull($budgets->get('emp-9', 2026));
	}//end testBudgetsAccrueOncePerRunAndKeepTheBalance()

	/**
	 * The run-approved listener applies the run on draft to approved only.
	 *
	 * @return void
	 */
	public function testTheApprovalListenerSettlesTheReservesOnApprovalOnly(): void {
		$listener = new PayrollRunApprovedListener($this->createMock(OvertimeCreditService::class), new NullLogger(), null, $this->service());
		$run = static function (string $status): ObjectEntity {
			$entity = new ObjectEntity();
			$entity->setUuid('run-sep');
			$entity->setSchema('PayrollRun');
			$entity->setObject(['period' => '2026-09', 'status' => $status]);
			return $entity;
		};

		$listener->handle(new ObjectUpdatedEvent($run('approved'), $run('approved')));
		self::assertSame([], $this->saves);

		$listener->handle(new ObjectUpdatedEvent($run('approved'), $run('draft')));
		self::assertSame('paid', $this->row('ReservationPayout', 'rp-1')['status']);
	}//end testTheApprovalListenerSettlesTheReservesOnApprovalOnly()
}//end class
