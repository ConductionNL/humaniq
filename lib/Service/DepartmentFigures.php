<?php

/**
 * Department Figures
 *
 * department-figures: the two figures the existing services do not compute
 * for a set of people. The absence frequency (meldingsfrequentie) is the
 * number of sick reports whose first sick day falls in the window, divided
 * by the average number of people in it, annualised (design.md D2). The
 * wage cost of a set sums its payslips' gross pay plus the run's employer
 * charge share, because employer charges are held per run and not per
 * payslip (design.md D1). Pure: the caller loads the records.
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
 * @spec openspec/specs/department-figures/spec.md#REQ-DPF-001
 */

declare(strict_types=1);

namespace OCA\Humaniq\Service;

use DateTimeImmutable;

/**
 * Absence frequency and wage cost of a set of people.
 *
 * @spec openspec/specs/department-figures/spec.md#REQ-DPF-001
 */
class DepartmentFigures {

	/**
	 * Payroll run statuses whose totals are final (the payroll-cost trend's
	 * own list).
	 *
	 * @var array<int, string>
	 */
	private const FINALISED_STATUSES = ['approved', 'posted', 'paid'];

	/**
	 * Sick reports per employee per year in the window, or null when nobody
	 * was in the set (null, never zero, like the absence rate).
	 *
	 * @param list<array<string, mixed>> $cases  SickLeaveCase records.
	 * @param array<string, float>       $shares Employee id to share of the window.
	 * @param DateTimeImmutable          $start  First day.
	 * @param DateTimeImmutable          $end    Last day.
	 *
	 * @return float|null
	 *
	 * @spec openspec/specs/department-figures/spec.md#REQ-DPF-001
	 */
	public function frequency(array $cases, array $shares, DateTimeImmutable $start, DateTimeImmutable $end): ?float {
		$average = array_sum($shares);
		if ($average <= 0.0) {
			return null;
		}

		$from = $start->format('Y-m-d');
		$until = $end->format('Y-m-d');
		$reports = 0;
		foreach ($cases as $case) {
			$day = substr(trim((string)($case['firstSickDay'] ?? '')), 0, 10);
			$employeeId = trim((string)($case['employeeId'] ?? ''));
			if ($day !== '' && $day >= $from && $day <= $until && isset($shares[$employeeId]) === true) {
				$reports++;
			}
		}

		$days = ((int)$start->setTime(0, 0)->diff($end->setTime(0, 0))->days + 1);

		return round((($reports / $average) * (365 / $days)), 2);
	}//end frequency()

	/**
	 * The wage cost of the people in the set for one month: each payslip of a
	 * finalised run of that month, gross pay times one plus the run's employer
	 * charge ratio, times the person's weight. A null weight map counts every
	 * payslip in full. Null when no payslip counted.
	 *
	 * @param list<array<string, mixed>> $runs     PayrollRun records.
	 * @param list<array<string, mixed>> $payslips Payslip records.
	 * @param array<string, float>|null  $weights  Employee id to weight, or null for everyone.
	 * @param string                     $month    `YYYY-MM`.
	 *
	 * @return float|null
	 *
	 * @spec openspec/specs/department-figures/spec.md#REQ-DPF-001
	 */
	public function wageCost(array $runs, array $payslips, ?array $weights, string $month): ?float {
		$ratios = $this->chargeRatios($runs, $month);

		$sum = null;
		foreach ($payslips as $payslip) {
			$runId = trim((string)($payslip['payrollRunId'] ?? ''));
			$employeeId = trim((string)($payslip['employeeId'] ?? ''));
			if (isset($ratios[$runId]) === false || ($weights !== null && isset($weights[$employeeId]) === false)) {
				continue;
			}

			$weight = ($weights === null) ? 1.0 : $weights[$employeeId];
			$sum = (($sum ?? 0.0) + ((float)($payslip['grossPay'] ?? 0) * (1.0 + $ratios[$runId]) * $weight));
		}

		return ($sum === null) ? null : round($sum, 2);
	}//end wageCost()

	/**
	 * Employer charge ratio per finalised run of the month, keyed by run id.
	 *
	 * @param list<array<string, mixed>> $runs  PayrollRun records.
	 * @param string                     $month `YYYY-MM`.
	 *
	 * @return array<string, float>
	 */
	private function chargeRatios(array $runs, string $month): array {
		$ratios = [];
		foreach ($runs as $run) {
			if ((string)($run['period'] ?? '') !== $month
				|| in_array((string)($run['status'] ?? ''), self::FINALISED_STATUSES, true) === false
			) {
				continue;
			}

			$gross = (float)($run['totalGross'] ?? 0);
			$self = (is_array($run['@self'] ?? null) === true) ? $run['@self'] : [];
			$runId = trim((string)($run['id'] ?? ($run['uuid'] ?? ($self['id'] ?? ''))));
			$ratios[$runId] = ($gross > 0.0) ? ((float)($run['totalEmployerCharges'] ?? 0) / $gross) : 0.0;
		}

		return $ratios;
	}//end chargeRatios()

}//end class
