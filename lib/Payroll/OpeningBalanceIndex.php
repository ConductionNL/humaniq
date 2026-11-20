<?php

/**
 * OpeningBalanceIndex.
 *
 * Opening balances by employee and year, and each employee's first humaniq period of a year.
 *
 * @category Payroll
 * @package  OCA\Humaniq\Payroll
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
 * @spec openspec/changes/payroll-period-edge-cases/specs/payroll-period-edge-cases/spec.md#REQ-PPE-003
 */

declare(strict_types=1);

namespace OCA\Humaniq\Payroll;

use InvalidArgumentException;

/**
 * The context NlOpeningBalanceChecks reads (payroll-period-edge-cases D6):
 * opening balances by employee and year, and each employee's first humaniq
 * period of a year.
 *
 * @SuppressWarnings(PHPMD.StaticAccess) PayPeriod::parse is the pure value-object factory every period reader uses.
 *
 * @spec openspec/changes/payroll-period-edge-cases/specs/payroll-period-edge-cases/spec.md#REQ-PPE-003
 */
final class OpeningBalanceIndex {

	/**
	 * Index the balances and payslips.
	 *
	 * @param iterable<array<string, mixed>> $balances Every PayrollOpeningBalance.
	 * @param iterable<array<string, mixed>> $payslips Every Payslip.
	 *
	 * @return array{byEmployeeYear: array<string, array<int, array<string, mixed>>>, firstPeriod: array<string, array<int, string>>}
	 *
	 * @spec openspec/changes/payroll-period-edge-cases/specs/payroll-period-edge-cases/spec.md#REQ-PPE-003
	 */
	public function build(iterable $balances, iterable $payslips): array {
		$byEmployeeYear = [];
		foreach ($balances as $balance) {
			$employeeId = trim((string)($balance['employeeId'] ?? ''));
			$year = (int)($balance['year'] ?? 0);
			if ($employeeId !== '' && $year !== 0) {
				$byEmployeeYear[$employeeId][$year] = $balance;
			}
		}

		return ['byEmployeeYear' => $byEmployeeYear, 'firstPeriod' => $this->firstPeriods(payslips: $payslips)];
	}//end build()

	/**
	 * Each employee's earliest payslip period per year.
	 *
	 * @param iterable<array<string, mixed>> $payslips Every Payslip.
	 *
	 * @return array<string, array<int, string>>
	 *
	 * @spec openspec/changes/payroll-period-edge-cases/specs/payroll-period-edge-cases/spec.md#REQ-PPE-003
	 */
	private function firstPeriods(iterable $payslips): array {
		$firstPeriod = [];
		$firstDay = [];
		foreach ($payslips as $payslip) {
			$employeeId = trim((string)($payslip['employeeId'] ?? ''));
			try {
				$period = PayPeriod::parse((string)($payslip['period'] ?? ''));
			} catch (InvalidArgumentException $e) {
				continue;
			}

			$year = (int)substr($period->id, 0, 4);
			if ($employeeId === '' || (isset($firstDay[$employeeId][$year]) === true && $firstDay[$employeeId][$year] <= $period->firstDay)) {
				continue;
			}

			$firstDay[$employeeId][$year] = $period->firstDay;
			$firstPeriod[$employeeId][$year] = $period->id;
		}

		return $firstPeriod;
	}//end firstPeriods()
}//end class
