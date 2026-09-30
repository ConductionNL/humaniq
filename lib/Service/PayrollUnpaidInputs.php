<?php

/**
 * The approved inputs of a period that no payroll run pays.
 *
 * Pure: over the timesheet and expense rows it lists each approved
 * timesheet up to the period and each approved payroll-route claim approved
 * up to the period's last day that no run has stamped. The run check turns
 * each into a warning (payroll-run-checks D2).
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
 * @spec openspec/specs/payroll-run-checks/spec.md#REQ-PRK-002
 */

declare(strict_types=1);

namespace OCA\Humaniq\Service;

/**
 * Lists the unpaid approved inputs of a period.
 *
 * @spec openspec/specs/payroll-run-checks/spec.md#REQ-PRK-002
 */
class PayrollUnpaidInputs {

	/**
	 * The unpaid approved timesheets and payroll-route claims.
	 *
	 * @param string                     $period     The run's period, `YYYY-MM`.
	 * @param list<array<string, mixed>> $timesheets Every timesheet.
	 * @param list<array<string, mixed>> $claims     Every expense claim.
	 *
	 * @return list<array{employeeId: string, message: string, ruleId: string}>
	 *
	 * @spec openspec/specs/payroll-run-checks/spec.md#REQ-PRK-002
	 */
	public function find(string $period, array $timesheets, array $claims): array {
		$unpaid = [];
		foreach (array_filter($timesheets, static fn (array $row): bool => self::unpaidTimesheet(timesheet: $row, period: $period)) as $timesheet) {
			$unpaid[] = ['employeeId' => (string)($timesheet['employeeId'] ?? ''), 'message' => 'Een goedgekeurde urenstaat van ' . (string)($timesheet['period'] ?? '') . ' wordt door geen loonrun betaald.', 'ruleId' => 'timesheet'];
		}

		$lastDay = ($period === '' ? '' : date('Y-m-t', (int)strtotime($period . '-01')));
		foreach (array_filter($claims, static fn (array $row): bool => self::unpaidClaim(claim: $row, lastDay: $lastDay)) as $claim) {
			$unpaid[] = ['employeeId' => (string)($claim['employeeId'] ?? ''), 'message' => 'Een goedgekeurde declaratie voor de salarisrun wordt door geen loonrun betaald.', 'ruleId' => 'expense'];
		}

		return $unpaid;
	}//end find()

	/**
	 * Whether a timesheet is approved, up to the period and not paid.
	 *
	 * @param array<string, mixed> $timesheet The timesheet.
	 * @param string               $period    The period.
	 *
	 * @return bool
	 */
	private static function unpaidTimesheet(array $timesheet, string $period): bool {
		return (string)($timesheet['status'] ?? '') === 'approved'
			&& (string)($timesheet['payrollRunId'] ?? '') === ''
			&& (string)($timesheet['period'] ?? '') <= $period;
	}//end unpaidTimesheet()

	/**
	 * Whether a claim is an approved payroll-route claim, approved by the
	 * period's last day and not paid.
	 *
	 * @param array<string, mixed> $claim   The claim.
	 * @param string               $lastDay The period's last day.
	 *
	 * @return bool
	 */
	private static function unpaidClaim(array $claim, string $lastDay): bool {
		$approvedOn = substr((string)($claim['approvedAt'] ?? ''), 0, 10);
		return (string)($claim['status'] ?? '') === 'approved'
			&& (string)($claim['reimbursementRoute'] ?? '') === 'payroll'
			&& (string)($claim['payrollRunId'] ?? '') === ''
			&& $approvedOn !== ''
			&& $approvedOn <= $lastDay;
	}//end unpaidClaim()

}//end class
