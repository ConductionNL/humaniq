<?php

/**
 * Approved hours and overtime as pay in the payroll run.
 *
 * The run asks three things of this service. Which of an employee's approved
 * timesheets it pays: those up to its period that no other live run has paid
 * (D2). What they come to: the regular hours times the hourly wage for an
 * hourly employee, and every overtime entry at the hourly rate times
 * (100 + the surcharge for the day's category) / 100, or credited as time off
 * at that factor (D1, D3, D4). And, after the payslips are saved, a stamp on
 * each timesheet naming the run that paid it, so no timesheet is paid twice.
 * The surcharge comes from EmploymentTermsResolver; when it does not resolve
 * the hours are settled at the base rate and the payslip says so.
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
 * @spec openspec/specs/time-hours-and-overtime-to-payroll/spec.md#REQ-HTP-001
 * @spec openspec/specs/time-hours-and-overtime-to-payroll/spec.md#REQ-HTP-003
 * @spec openspec/specs/time-hours-and-overtime-to-payroll/spec.md#REQ-HTP-003
 */

declare(strict_types=1);

namespace OCA\Humaniq\Service;

use InvalidArgumentException;

/**
 * Selection, arithmetic and stamping of paid hours.
 *
 * @spec openspec/specs/time-hours-and-overtime-to-payroll/spec.md#REQ-HTP-001
 */
class HoursPayService {

	/**
	 * Constructor.
	 *
	 * @param EmploymentTermsResolver $terms   The overtime surcharge and default settlement.
	 * @param RecordStoreInterface    $gateway Writes the timesheet stamps.
	 * @param InternalWriteMarker     $marker  Marks the stamps as humaniq's own writes.
	 *
	 * @spec openspec/specs/time-hours-and-overtime-to-payroll/spec.md#REQ-HTP-001
	 */
	public function __construct(
		private readonly EmploymentTermsResolver $terms,
		private readonly RecordStoreInterface $gateway,
		private readonly InternalWriteMarker $marker,
	) {
	}//end __construct()

	/**
	 * The employee's approved timesheets this run pays: period on or before
	 * the run's, and not stamped by another run that still exists.
	 *
	 * @param list<array<string, mixed>>          $timesheets Every Timesheet.
	 * @param string                              $employeeId The employee.
	 * @param string                              $period     The run's period, YYYY-MM.
	 * @param string                              $runId      The run.
	 * @param array<string, array<string, mixed>> $runsById   Every PayrollRun by id.
	 *
	 * @return list<array<string, mixed>>
	 *
	 * @spec openspec/specs/time-hours-and-overtime-to-payroll/spec.md#REQ-HTP-001
	 */
	public function timesheetsToPay(array $timesheets, string $employeeId, string $period, string $runId, array $runsById): array {
		$out = [];
		foreach ($timesheets as $timesheet) {
			if (($timesheet['status'] ?? null) !== 'approved'
				|| (string)($timesheet['employeeId'] ?? '') !== $employeeId
				|| substr((string)($timesheet['period'] ?? ''), 0, 7) > $period
			) {
				continue;
			}

			$stamp = trim((string)($timesheet['payrollRunId'] ?? ''));
			if ($stamp === '' || $stamp === $runId || isset($runsById[$stamp]) === false) {
				$out[] = $timesheet;
			}
		}

		return $out;
	}//end timesheetsToPay()

	/**
	 * What the timesheets come to for this employee.
	 *
	 * @param array<string, mixed>       $employee        The Employee.
	 * @param array<string, mixed>       $contract        The covering EmploymentContract.
	 * @param list<array<string, mixed>> $timesheets      The timesheets this run pays.
	 * @param list<array<string, mixed>> $entries         Every TimeEntry (filtered here by timesheet).
	 * @param list<string>|null          $nonWorkingDates The calendar's non-working dates, null when unread.
	 *
	 * @return array{hourlyCents: int, overtimeCents: int, hoursPaid: float, overtimeHours: float, hourlyRate: ?float, surchargeUnresolved: bool, timesheetIds: list<string>, timeCredits: list<array{timesheetId: string, hours: float}>}
	 *
	 * @spec openspec/specs/time-hours-and-overtime-to-payroll/spec.md#REQ-HTP-001
	 * @spec openspec/specs/time-hours-and-overtime-to-payroll/spec.md#REQ-HTP-003
	 */
	public function payFor(array $employee, array $contract, array $timesheets, array $entries, ?array $nonWorkingDates): array {
		$run = $this->startRun(employee: $employee, contract: $contract);
		$run->entries = $entries;
		$run->nonWorkingDates = $nonWorkingDates;
		foreach ($timesheets as $timesheet) {
			$this->payTimesheet(run: $run, timesheet: $timesheet);
		}

		if ($run->salaried === false && $run->rate !== null) {
			$run->pay['hourlyCents'] = (int)round($run->pay['hoursPaid'] * $run->rate * 100);
		}

		$run->pay['hoursPaid'] = round($run->pay['hoursPaid'], 2);
		$run->pay['overtimeHours'] = round($run->pay['overtimeHours'], 2);

		return $run->pay;
	}//end payFor()

	/**
	 * A run for one employee under one contract, with its hourly rate and no pay yet.
	 *
	 * @param array<string, mixed> $employee The Employee.
	 * @param array<string, mixed> $contract The covering EmploymentContract.
	 *
	 * @return HoursPayRun
	 */
	private function startRun(array $employee, array $contract): HoursPayRun {
		$run = new HoursPayRun();
		$run->contract = $contract;
		$run->salaried = (is_numeric($employee['grossMonthlySalary'] ?? null) === true && (float)$employee['grossMonthlySalary'] > 0.0);
		$run->rate = $this->hourlyRate(run: $run, employee: $employee);
		$run->pay = ['hourlyCents' => 0, 'overtimeCents' => 0, 'hoursPaid' => 0.0, 'overtimeHours' => 0.0, 'hourlyRate' => $run->rate, 'surchargeUnresolved' => false, 'timesheetIds' => [], 'timeCredits' => []];

		return $run;
	}//end startRun()

	/**
	 * Add one timesheet: its hours less overtime for an hourly employee, its
	 * overtime settled in money or time off.
	 *
	 * @param HoursPayRun          $run       The pay being added up.
	 * @param array<string, mixed> $timesheet The timesheet.
	 *
	 * @return void
	 */
	private function payTimesheet(HoursPayRun $run, array $timesheet): void {
		$timesheetId = (string)($timesheet['id'] ?? ($timesheet['@self']['id'] ?? ''));
		$run->pay['timesheetIds'][] = $timesheetId;
		$overtime = array_values(array_filter($run->entries, static fn (array $entry): bool => (string)($entry['timesheetId'] ?? '') === $timesheetId && ($entry['overtime'] ?? false) === true));
		$overtimeHours = array_sum(array_map(static fn (array $entry): float => (float)($entry['hours'] ?? 0), $overtime));
		if ($run->salaried === false) {
			$run->pay['hoursPaid'] += max(0.0, (float)($timesheet['hours'] ?? 0) - $overtimeHours);
		}

		$run->pay['overtimeHours'] += $overtimeHours;
		$settled = $this->settleOvertime(run: $run, entries: $overtime);
		$this->addSettlement(run: $run, settled: ($settled + ['timesheetId' => $timesheetId]));
	}//end payTimesheet()

	/**
	 * Add one timesheet's settled overtime to the pay: its cents, whether a
	 * surcharge did not resolve, and its time-off credit when there is one.
	 *
	 * @param HoursPayRun                                                         $run     The pay being added up.
	 * @param array{cents: int, credit: float, unresolved: bool, timesheetId: string} $settled The timesheet's settlement.
	 *
	 * @return void
	 */
	private function addSettlement(HoursPayRun $run, array $settled): void {
		$run->pay['overtimeCents'] += $settled['cents'];
		$run->pay['surchargeUnresolved'] = ($run->pay['surchargeUnresolved'] || $settled['unresolved']);
		if ($settled['credit'] > 0.0) {
			$run->pay['timeCredits'][] = ['timesheetId' => $settled['timesheetId'], 'hours' => round($settled['credit'], 2)];
		}
	}//end addSettlement()

	/**
	 * Stamp the timesheets this run paid, and clear the stamp of one it paid
	 * before but no longer pays (reopened since). The whole timesheet is
	 * written, because an OpenRegister save replaces the object.
	 *
	 * @param list<array<string, mixed>> $timesheets Every Timesheet.
	 * @param array<string, float>       $paid       Timesheet id to the hours credited as time off (0 when none).
	 * @param string                     $runId      The run.
	 * @param string                     $period     The run's period.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/time-hours-and-overtime-to-payroll/spec.md#REQ-HTP-001
	 */
	public function stamp(array $timesheets, array $paid, string $runId, string $period): void {
		foreach ($timesheets as $timesheet) {
			$timesheetId = (string)($timesheet['id'] ?? ($timesheet['@self']['id'] ?? ''));
			$stamp = ['payrollRunId' => null, 'paidInPeriod' => null, 'overtimeCreditHours' => null];
			if (array_key_exists($timesheetId, $paid) === true) {
				$stamp = ['payrollRunId' => $runId, 'paidInPeriod' => $period, 'overtimeCreditHours' => ($paid[$timesheetId] > 0.0 ? $paid[$timesheetId] : null)];
			} else if ((string)($timesheet['payrollRunId'] ?? '') !== $runId) {
				continue;
			}

			$current = [
				'payrollRunId' => ($timesheet['payrollRunId'] ?? null),
				'paidInPeriod' => ($timesheet['paidInPeriod'] ?? null),
				'overtimeCreditHours' => ($timesheet['overtimeCreditHours'] ?? null),
			];
			if ($current['payrollRunId'] === $stamp['payrollRunId'] && $current['paidInPeriod'] === $stamp['paidInPeriod'] && (float)$current['overtimeCreditHours'] === (float)$stamp['overtimeCreditHours']) {
				continue;
			}

			unset($timesheet['id'], $timesheet['@self']);
			$payload = array_merge($timesheet, $stamp);
			$this->marker->runInternal(fn () => $this->gateway->save($payload, 'Timesheet', $timesheetId));
		}//end foreach
	}//end stamp()

	/**
	 * Settle one timesheet's overtime entries: the pay in cents, the hours to
	 * credit as time off, and whether a surcharge did not resolve.
	 *
	 * @param HoursPayRun                $run     The pay being added up: its contract, hourly rate and calendar dates.
	 * @param list<array<string, mixed>> $entries The overtime entries.
	 *
	 * @return array{cents: int, credit: float, unresolved: bool}
	 */
	private function settleOvertime(HoursPayRun $run, array $entries): array {
		$settled = ['cents' => 0, 'credit' => 0.0, 'unresolved' => false];
		$default = $this->terms->overtimeCompensationFor($run->contract);
		foreach ($entries as $entry) {
			$entry['overtimeCompensation'] = ($entry['overtimeCompensation'] ?? $default);
			$one = $this->settleEntry(run: $run, entry: $entry);
			$settled['cents'] += $one['cents'];
			$settled['credit'] += $one['credit'];
			$settled['unresolved'] = ($settled['unresolved'] || $one['unresolved']);
		}

		return $settled;
	}//end settleOvertime()

	/**
	 * Settle one overtime entry at the surcharge factor for its day: in money
	 * at the hourly rate, or as time off when its compensation is time.
	 *
	 * @param HoursPayRun          $run   The pay being added up: its contract, hourly rate and calendar dates.
	 * @param array<string, mixed> $entry The overtime entry, its compensation already defaulted.
	 *
	 * @return array{cents: int, credit: float, unresolved: bool}
	 */
	private function settleEntry(HoursPayRun $run, array $entry): array {
		$hours = (float)($entry['hours'] ?? 0);
		$percentage = $this->surcharge(contract: $run->contract, category: self::categoryOf(date: (string)($entry['date'] ?? ''), nonWorkingDates: $run->nonWorkingDates));
		$unresolved = ($percentage === null);
		$factor = ((100 + ($percentage ?? 0.0)) / 100);
		if ($entry['overtimeCompensation'] === 'time') {
			return ['cents' => 0, 'credit' => ($hours * $factor), 'unresolved' => $unresolved];
		}

		return ['cents' => (int)round($hours * ($run->rate ?? 0.0) * $factor * 100), 'credit' => 0.0, 'unresolved' => $unresolved];
	}//end settleEntry()

	/**
	 * The surcharge percentage for one category, or null when it does not resolve.
	 *
	 * @param array<string, mixed> $contract The contract.
	 * @param string               $category doordeweeks, zaterdag, zondag or feestdag.
	 *
	 * @return float|null
	 */
	private function surcharge(array $contract, string $category): ?float {
		try {
			$addition = $this->terms->overtimeAdditionFor($contract, $category);
		} catch (InvalidArgumentException $e) {
			return null;
		}

		return ($addition === null ? null : (float)$addition['percentageOfWage']);
	}//end surcharge()

	/**
	 * The hourly rate: the salary over the contracted monthly hours for a
	 * salaried employee, the contract's hourly wage otherwise.
	 *
	 * @param HoursPayRun          $run      The pay being added up: its contract and whether the employee is salaried.
	 * @param array<string, mixed> $employee The employee.
	 *
	 * @return float|null Null when neither gives a rate.
	 */
	private function hourlyRate(HoursPayRun $run, array $employee): ?float {
		if ($run->salaried === true) {
			return $this->salariedRate(run: $run, employee: $employee);
		}

		$wage = ($run->contract['hourlyWage'] ?? null);
		return (is_numeric($wage) === true && (float)$wage > 0.0 ? (float)$wage : null);
	}//end hourlyRate()

	/**
	 * A salaried employee's hourly rate: the salary over the contracted monthly hours.
	 *
	 * @param HoursPayRun          $run      The pay being added up: its contract.
	 * @param array<string, mixed> $employee The employee.
	 *
	 * @return float|null Null when the contract has no hours.
	 */
	private function salariedRate(HoursPayRun $run, array $employee): ?float {
		$monthlyHours = ((float)($run->contract['hoursPerWeek'] ?? 0) * 52 / 12);
		return ($monthlyHours > 0.0 ? (float)$employee['grossMonthlySalary'] / $monthlyHours : null);
	}//end salariedRate()

	/**
	 * The overtime category of a day.
	 *
	 * @param string            $date            The day, Y-m-d.
	 * @param list<string>|null $nonWorkingDates The calendar dates.
	 *
	 * @return string
	 */
	private static function categoryOf(string $date, ?array $nonWorkingDates): string {
		if ($nonWorkingDates !== null && in_array($date, $nonWorkingDates, true) === true) {
			return 'feestdag';
		}

		$weekday = (int)gmdate('N', (int)strtotime($date . ' 00:00:00 UTC'));
		if ($weekday === 6) {
			return 'zaterdag';
		}

		return ($weekday === 7 ? 'zondag' : 'doordeweeks');
	}//end categoryOf()

}//end class
