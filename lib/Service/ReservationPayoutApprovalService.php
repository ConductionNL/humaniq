<?php

/**
 * Reservation Payout Approval Service
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * What approving a payroll run does to the reserves
 * (payroll-reservation-payouts D4, D6, D8): the run's payouts move from
 * calculated to paid, a leaver's offboarding case gets its holiday allowance
 * ticked as settled, each payslip's IKB accrual is added to the employee's
 * budget, and the spend requests the run settled are marked settled and
 * taken off the balance, with extra leave credited as hours. Every write is
 * keyed so that a replayed approval writes nothing twice: a paid payout is
 * left alone, a budget remembers the runs it accrued, and only an approved
 * request is settled.
 *
 * @category Service
 * @package  OCA\Humaniq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/payroll-reservation-payouts/specs/payroll-reservation-payouts/spec.md#requirement-holiday-allowance-shall-be-paid-out-on-the-administrations-schedule-and-on-leaving-req-rsp-002
 */

declare(strict_types=1);

namespace OCA\Humaniq\Service;

/**
 * Paid payouts, settled offboarding, IKB accrual and settlement on run approval.
 *
 * @spec openspec/changes/payroll-reservation-payouts/specs/payroll-reservation-payouts/spec.md#requirement-holiday-allowance-shall-be-paid-out-on-the-administrations-schedule-and-on-leaving-req-rsp-002
 */
class ReservationPayoutApprovalService {

	/**
	 * @param RecordStoreInterface $records The register rows.
	 * @param InternalWriteMarker  $marker  Marks the writes as humaniq's own.
	 *
	 * @spec openspec/changes/payroll-reservation-payouts/specs/payroll-reservation-payouts/spec.md#requirement-holiday-allowance-shall-be-paid-out-on-the-administrations-schedule-and-on-leaving-req-rsp-002
	 */
	public function __construct(
		private readonly RecordStoreInterface $records,
		private readonly InternalWriteMarker $marker,
	) {
	}//end __construct()

	/**
	 * Apply an approved run to the reserves.
	 *
	 * @param string $runId The approved run.
	 *
	 * @return array{paid: int, offboarding: int, accrued: int, settled: int} What was written.
	 *
	 * @spec openspec/changes/payroll-reservation-payouts/specs/payroll-reservation-payouts/spec.md#requirement-holiday-allowance-shall-be-paid-out-on-the-administrations-schedule-and-on-leaving-req-rsp-002
	 * @spec openspec/changes/payroll-reservation-payouts/specs/payroll-reservation-payouts/spec.md#requirement-the-individual-choice-budget-shall-accrue-and-be-spent-by-approved-request-req-rsp-004
	 */
	public function onRunApproved(string $runId): array {
		$done = ['paid' => 0, 'offboarding' => 0, 'accrued' => 0, 'settled' => 0];
		if ($runId === '') {
			return $done;
		}

		$payouts = array_values(array_filter($this->rows('ReservationPayout'), static fn (array $row): bool => (string)($row['payrollRunId'] ?? '') === $runId));
		$payslips = array_values(array_filter($this->rows('Payslip'), static fn (array $row): bool => (string)($row['payrollRunId'] ?? '') === $runId));
		$budgets = new IkbBudgets(rows: $this->rows('IkbBudget'));

		$done['paid'] = $this->markPaid(payouts: $payouts);
		$done['offboarding'] = $this->settleOffboarding(payouts: $payouts);
		$done['accrued'] = $this->accrue(payslips: $payslips, budgets: $budgets, runId: $runId);
		$done['settled'] = $this->settleRequests(payouts: $payouts, payslips: $payslips, budgets: $budgets, runId: $runId);

		foreach ($budgets->changed() as $uuid => $budget) {
			$this->save(payload: $budget, schema: 'IkbBudget', uuid: ($uuid === '' || str_starts_with($uuid, 'new:') === true ? null : $uuid));
		}

		return $done;
	}//end onRunApproved()

	/**
	 * Calculated payouts become paid; paid ones are left alone.
	 *
	 * @param list<array<string, mixed>> $payouts The run's payouts.
	 *
	 * @return int The payouts marked.
	 */
	private function markPaid(array $payouts): int {
		$marked = 0;
		foreach ($payouts as $payout) {
			if ((string)($payout['status'] ?? '') === 'paid') {
				continue;
			}

			$this->save(payload: array_merge($payout, ['status' => 'paid']), schema: 'ReservationPayout', uuid: self::idOf(row: $payout));
			++$marked;
		}

		return $marked;
	}//end markPaid()

	/**
	 * Tick the holiday allowance as settled on the offboarding case of every
	 * employee whose leaving payout this run paid (D6).
	 *
	 * @param list<array<string, mixed>> $payouts The run's payouts.
	 *
	 * @return int The cases ticked.
	 */
	private function settleOffboarding(array $payouts): int {
		$leavers = [];
		foreach ($payouts as $payout) {
			if (($payout['reason'] ?? null) === 'leaving' && ($payout['kind'] ?? null) === ReservationPayoutService::KIND_HOLIDAY_ALLOWANCE) {
				$leavers[(string)($payout['employeeId'] ?? '')] = true;
			}
		}

		$ticked = 0;
		foreach ($this->rows('Offboarding') as $case) {
			if (isset($leavers[(string)($case['employeeId'] ?? '')]) === false || ($case['vakantiegeldAfgerekend'] ?? false) === true) {
				continue;
			}

			$this->save(payload: array_merge($case, ['vakantiegeldAfgerekend' => true]), schema: 'Offboarding', uuid: self::idOf(row: $case));
			++$ticked;
		}

		return $ticked;
	}//end settleOffboarding()

	/**
	 * Add each payslip's IKB accrual to its employee's budget of the year,
	 * once per run (D8).
	 *
	 * @param list<array<string, mixed>> $payslips The run's payslips.
	 * @param IkbBudgets                 $budgets  The budgets.
	 * @param string                     $runId    The run.
	 *
	 * @return int The payslips accrued.
	 */
	private function accrue(array $payslips, IkbBudgets $budgets, string $runId): int {
		$accrued = 0;
		foreach ($payslips as $payslip) {
			$amount = (float)($payslip['ikbAccrued'] ?? 0);
			if ($amount <= 0) {
				continue;
			}

			$year = (int)substr((string)($payslip['period'] ?? ''), 0, 4);
			if ($budgets->accrue(employeeId: (string)($payslip['employeeId'] ?? ''), year: $year, amount: $amount, runId: $runId, userId: ($payslip['userId'] ?? null)) === true) {
				++$accrued;
			}
		}

		return $accrued;
	}//end accrue()

	/**
	 * Settle the requests this run paid, and the approved leave requests
	 * settling in its period for employees it paid (D8).
	 *
	 * @param list<array<string, mixed>> $payouts  The run's payouts.
	 * @param list<array<string, mixed>> $payslips The run's payslips.
	 * @param IkbBudgets                 $budgets  The budgets.
	 * @param string                     $runId    The run.
	 *
	 * @return int The requests settled.
	 */
	private function settleRequests(array $payouts, array $payslips, IkbBudgets $budgets, string $runId): int {
		$paidRequests = [];
		foreach ($payouts as $payout) {
			$requestId = (string)($payout['ikbSpendRequestId'] ?? '');
			if ($requestId !== '') {
				$paidRequests[$requestId] = true;
			}
		}

		$periodByEmployee = [];
		foreach ($payslips as $payslip) {
			$periodByEmployee[(string)($payslip['employeeId'] ?? '')] = (string)($payslip['period'] ?? '');
		}

		$settled = 0;
		foreach ($this->rows('IkbSpendRequest') as $request) {
			if ((string)($request['status'] ?? '') !== 'approved' || self::settlesNow(request: $request, paid: $paidRequests, periods: $periodByEmployee) === false) {
				continue;
			}

			if (($request['goal'] ?? null) === 'leave') {
				$this->creditLeave(request: $request);
			}

			$budgets->spend(employeeId: (string)($request['employeeId'] ?? ''), year: (int)($request['year'] ?? 0), amount: (float)($request['amount'] ?? 0));
			$this->save(payload: array_merge($request, ['status' => 'settled', 'settledInRunId' => $runId]), schema: 'IkbSpendRequest', uuid: self::idOf(row: $request));
			++$settled;
		}

		return $settled;
	}//end settleRequests()

	/**
	 * Whether this run settles an approved request: it paid it, or it is a
	 * leave request settling in the period of a payslip of its employee.
	 *
	 * @param array<string, mixed>  $request The request.
	 * @param array<string, bool>   $paid    The request ids the run paid.
	 * @param array<string, string> $periods The period per employee the run paid.
	 *
	 * @return bool
	 */
	private static function settlesNow(array $request, array $paid, array $periods): bool {
		if (isset($paid[self::idOf(row: $request)]) === true) {
			return true;
		}

		$period = ($periods[(string)($request['employeeId'] ?? '')] ?? null);

		return ($request['goal'] ?? null) === 'leave' && $period !== null && (string)($request['settlementPeriod'] ?? '') === $period;
	}//end settlesNow()

	/**
	 * Credit a leave request's hours to the employee's holiday balance of
	 * the year, above the statutory minimum. No money moves.
	 *
	 * @param array<string, mixed> $request The request.
	 *
	 * @return void
	 */
	private function creditLeave(array $request): void {
		$hours = (float)($request['hours'] ?? 0);
		if ($hours <= 0) {
			return;
		}

		foreach ($this->rows('LeaveBalance') as $balance) {
			if ((string)($balance['employeeId'] ?? '') === (string)($request['employeeId'] ?? '')
				&& (int)($balance['year'] ?? 0) === (int)($request['year'] ?? 0)
				&& (string)($balance['leaveType'] ?? '') === 'holiday'
			) {
				$this->save(payload: array_merge($balance, ['bovenwettelijkHours' => round(((float)($balance['bovenwettelijkHours'] ?? 0) + $hours), 2)]), schema: 'LeaveBalance', uuid: self::idOf(row: $balance));
				return;
			}
		}
	}//end creditLeave()

	/**
	 * Save a row as humaniq's own write.
	 *
	 * @param array<string, mixed> $payload The row.
	 * @param string               $schema  The schema.
	 * @param string|null          $uuid    The uuid, null creates.
	 *
	 * @return void
	 */
	private function save(array $payload, string $schema, ?string $uuid): void {
		unset($payload['id'], $payload['@self']);
		$this->marker->runInternal(fn () => $this->records->save($payload, $schema, $uuid));
	}//end save()

	/**
	 * Every row of a schema; none when the register does not carry it yet.
	 *
	 * @param string $schema The schema.
	 *
	 * @return list<array<string, mixed>>
	 */
	private function rows(string $schema): array {
		try {
			return array_values($this->records->loadAll($schema));
		} catch (\Throwable) {
			return [];
		}
	}//end rows()

	/**
	 * A row's uuid.
	 *
	 * @param array<string, mixed> $row The row.
	 *
	 * @return string
	 */
	private static function idOf(array $row): string {
		return (string)($row['id'] ?? ($row['@self']['id'] ?? ''));
	}//end idOf()
}//end class
