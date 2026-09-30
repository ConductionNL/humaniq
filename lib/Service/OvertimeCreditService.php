<?php

/**
 * Credits overtime taken as time off to the time-off-in-lieu balance.
 *
 * When a payroll run is approved, every timesheet it settled with overtime to
 * be taken off adds those hours, surcharge included, to the employee's
 * `compensation` leave balance for the year, creating the balance when there
 * is none. The credit is keyed on the timesheet: once `overtimeCreditedAt` is
 * set it is never repeated, so a replayed event credits nothing
 * (time-hours-and-overtime-to-payroll D5).
 *
 * @category Service
 * @package  OCA\Humaniq\Service
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
 * @spec openspec/specs/time-hours-and-overtime-to-payroll/spec.md#REQ-HTP-003
 */

declare(strict_types=1);

namespace OCA\Humaniq\Service;

/**
 * The time-off credit of an approved run.
 *
 * @spec openspec/specs/time-hours-and-overtime-to-payroll/spec.md#REQ-HTP-003
 */
class OvertimeCreditService {

	public const LEAVE_TYPE = 'compensation';

	/**
	 * Constructor.
	 *
	 * @param HoursRegisterGateway $gateway Reads timesheets and balances, writes both.
	 * @param InternalWriteMarker  $marker  Marks the writes as humaniq's own.
	 *
	 * @spec openspec/specs/time-hours-and-overtime-to-payroll/spec.md#REQ-HTP-003
	 */
	public function __construct(
		private readonly HoursRegisterGateway $gateway,
		private readonly InternalWriteMarker $marker,
	) {
	}//end __construct()

	/**
	 * Credit every uncredited time-off overtime of one run.
	 *
	 * @param string $runId The approved run.
	 *
	 * @return int The number of timesheets credited.
	 *
	 * @spec openspec/specs/time-hours-and-overtime-to-payroll/spec.md#REQ-HTP-003
	 */
	public function creditForRun(string $runId): int {
		$credited = 0;
		foreach ($this->gateway->findFiltered('Timesheet', ['payrollRunId' => $runId]) as $timesheet) {
			$hours = (float)($timesheet['overtimeCreditHours'] ?? 0);
			if ($hours <= 0.0 || trim((string)($timesheet['overtimeCreditedAt'] ?? '')) !== '') {
				continue;
			}

			$this->marker->runInternal(function () use ($timesheet, $hours): void {
				$this->addToBalance(employeeId: (string)($timesheet['employeeId'] ?? ''), year: (int)substr((string)($timesheet['paidInPeriod'] ?? ($timesheet['period'] ?? '')), 0, 4), hours: $hours);
				$timesheetId = (string)($timesheet['id'] ?? ($timesheet['@self']['id'] ?? ''));
				unset($timesheet['id'], $timesheet['@self']);
				$timesheet['overtimeCreditedAt'] = gmdate('Y-m-d\TH:i:s\Z');
				$this->gateway->save($timesheet, 'Timesheet', $timesheetId);
			});
			$credited++;
		}

		return $credited;
	}//end creditForRun()

	/**
	 * Add hours to the employee's compensation balance for the year.
	 *
	 * @param string $employeeId The employee.
	 * @param int    $year       The year.
	 * @param float  $hours      The hours to add.
	 *
	 * @return void
	 */
	private function addToBalance(string $employeeId, int $year, float $hours): void {
		$balances = $this->gateway->findFiltered('LeaveBalance', ['employeeId' => $employeeId, 'year' => $year, 'leaveType' => self::LEAVE_TYPE]);
		if ($balances === []) {
			$this->gateway->save(['employeeId' => $employeeId, 'year' => $year, 'leaveType' => self::LEAVE_TYPE, 'entitledHours' => round($hours, 2)], 'LeaveBalance');
			return;
		}

		$balance = $balances[0];
		$balanceId = (string)($balance['id'] ?? ($balance['@self']['id'] ?? ''));
		unset($balance['id'], $balance['@self']);
		$balance['entitledHours'] = round((float)($balance['entitledHours'] ?? 0) + $hours, 2);
		$this->gateway->save($balance, 'LeaveBalance', $balanceId);
	}//end addToBalance()

}//end class
