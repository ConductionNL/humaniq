<?php

/**
 * Leave accrual pass
 *
 * The state of one `LeaveAccrualJob::runAccrual()` pass: the month being
 * accrued, the lookups loaded once for it, the employee being processed and
 * the summary the pass returns. Lives only for the duration of one pass and
 * never leaves the job.
 *
 * @category BackgroundJob
 * @package  OCA\Humaniq\BackgroundJob
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
 * @spec openspec/specs/leave-accrual-job/spec.md#REQ-ACCR-005
 */

declare(strict_types=1);

namespace OCA\Humaniq\BackgroundJob;

/**
 * One accrual pass: month, lookups, current employee and summary.
 *
 * @spec openspec/specs/leave-accrual-job/spec.md#REQ-ACCR-005
 */
final class LeaveAccrualPass {

	/**
	 * The month being accrued: `year`, `period` and `deltaBovenwettelijk`.
	 *
	 * @var array<string, mixed>
	 */
	public array $month = [];

	/**
	 * Employment contracts keyed by employee key.
	 *
	 * @var array<string, array<int, array<string, mixed>>>
	 */
	public array $contractsByEmployeeKey = [];

	/**
	 * This year's holiday LeaveBalances keyed by employeeId.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	public array $balancesByEmployeeId = [];

	/**
	 * The pass summary: {period, year, enabled, provisioned, accrued, skipped, noop}.
	 *
	 * @var array<string, mixed>
	 */
	public array $summary = [];

	/**
	 * The label of the employee being processed.
	 *
	 * @var string
	 */
	private string $employeeLabel = '';

	/**
	 * Start processing the next employee.
	 *
	 * @param string $employeeLabel The employee's display label.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/leave-accrual-job/spec.md#REQ-ACCR-005
	 */
	public function begin(string $employeeLabel): void {
		$this->employeeLabel = $employeeLabel;
	}//end begin()

	/**
	 * The label of the employee being processed.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/leave-accrual-job/spec.md#REQ-ACCR-005
	 */
	public function employeeLabel(): string {
		return $this->employeeLabel;
	}//end employeeLabel()

	/**
	 * Report the current employee as skipped.
	 *
	 * @param string $reason The skip reason.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/leave-accrual-job/spec.md#REQ-ACCR-005
	 */
	public function skip(string $reason): void {
		$this->summary['skipped'][] = ['employee' => $this->employeeLabel, 'reason' => $reason];
	}//end skip()

	/**
	 * Report the current employee's balance as written.
	 *
	 * @param string $outcome `provisioned` or `accrued`.
	 * @param string $leaveBalanceId The saved LeaveBalance's id.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/leave-accrual-job/spec.md#REQ-ACCR-005
	 */
	public function written(string $outcome, string $leaveBalanceId): void {
		$this->summary[$outcome][] = ['employee' => $this->employeeLabel, 'leaveBalanceId' => $leaveBalanceId];
	}//end written()

	/**
	 * Count the current employee as already accrued this month.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/leave-accrual-job/spec.md#REQ-ACCR-005
	 */
	public function noop(): void {
		$this->summary['noop']++;
	}//end noop()
}//end class
