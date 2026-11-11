<?php

/**
 * What one leave request costs, per year and per day.
 *
 * The same arithmetic as the balance projection ({@see LeaveHoursCalculator}
 * with the person's working time), for one request, so the approver sees the
 * hours before approving and each day says why it costs what it costs:
 * a working day from the pattern, a feestdag from openregister's working
 * calendar, a day off, a weekend (leave-hours-from-the-working-pattern D4).
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
 * @spec openspec/specs/leave-hours-from-pattern/spec.md#REQ-LHP-003
 */

declare(strict_types=1);

namespace OCA\Humaniq\Service;

use DateTimeImmutable;
use OCP\IL10N;

/**
 * The cost of one request. *
 * @spec openspec/specs/leave-hours-from-pattern/spec.md#REQ-LHP-003
 */
class LeaveCostService {

	/**
	 * Constructor.
	 *
	 * @param RecordStoreInterface  $gateway  Reads patterns, non-working times and balances.
	 * @param WorkingCalendarReader $calendar openregister's working calendar.
	 * @param IL10N                 $l10n     Day labels.
	 *
	 * @spec openspec/specs/leave-hours-from-pattern/spec.md#REQ-LHP-003
	 */
	public function __construct(
		private readonly RecordStoreInterface $gateway,
		private readonly WorkingCalendarReader $calendar,
		private readonly IL10N $l10n,
	) {
	}//end __construct()

	/**
	 * The cost of a request: the total, per year with its basis, and per day.
	 *
	 * @param array<string, mixed> $request The LeaveRequest, already resolved as the caller.
	 *
	 * @return array{requestId: string, hours: float, basis: string, calendarRead: bool, years: list<array{year: int, hours: float, basis: string, basisLabel: string}>, days: list<array{date: string, hours: float, reason: string, label: string}>}
	 *
	 * @spec openspec/specs/leave-hours-from-pattern/spec.md#REQ-LHP-003
	 */
	public function costOf(array $request): array {
		$employeeId = (string)($request['employeeId'] ?? '');
		$start = substr((string)($request['startDate'] ?? ''), 0, 10);
		$end = substr((string)($request['endDate'] ?? $start), 0, 10);
		$startYear = (int)substr($start, 0, 4);
		$endYear = max($startYear, (int)substr($end, 0, 4));

		$dates = null;
		if ($startYear > 0) {
			$dates = $this->calendar->nonWorkingDates(new DateTimeImmutable($start), new DateTimeImmutable($end))['dates'];
		}

		$workingTime   = $this->workingTimeOf(employeeId: $employeeId, nonWorkingDates: $dates);
		$contractHours = $this->contractHoursByYear(employeeId: $employeeId, leaveType: (string)($request['leaveType'] ?? ''));

		// The balance projection's own rule, referenced as a callable as
		// LeaveAllocationCalculator does, so both answer the same per day.
		$requestHours = [LeaveHoursCalculator::class, 'requestHours'];
		$resolved     = [];
		for ($year = $startYear; $startYear > 0 && $year <= $endYear; $year++) {
			$resolved[$year] = $requestHours($request, ($contractHours[$year] ?? null), $year, $workingTime);
		}

		$tally = $this->tallied(resolvedByYear: $resolved);

		return [
			'requestId' => (string)($request['id'] ?? ''),
			'hours' => $tally['hours'],
			'basis' => $tally['basis'],
			'calendarRead' => $dates !== null,
			'years' => $tally['years'],
			'days' => $tally['days'],
		];
	}//end costOf()

	/**
	 * The employee's patterns and non-working times, read in that order, with the calendar's dates.
	 *
	 * @param string                  $employeeId      The employee.
	 * @param array<int, string>|null $nonWorkingDates The calendar's non-working dates, null when unread.
	 *
	 * @return array{patterns: array<int, array<string, mixed>>, nonWorkingTimes: array<int, array<string, mixed>>, nonWorkingDates: array<int, string>|null}
	 */
	private function workingTimeOf(string $employeeId, ?array $nonWorkingDates): array {
		return [
			'patterns' => $this->gateway->findFiltered('WorkingPattern', ['employeeId' => $employeeId]),
			'nonWorkingTimes' => $this->gateway->findFiltered('NonWorkingTime', ['employeeId' => $employeeId]),
			'nonWorkingDates' => $nonWorkingDates,
		];
	}//end workingTimeOf()

	/**
	 * The year lines, the labelled days, the rounded total and the basis of
	 * the last year (contract-average when no year was costed).
	 *
	 * @param array<int, array{hours: float|int, basis: string, days: array<int, array<string, mixed>>}> $resolvedByYear The calculator's answer per year.
	 *
	 * @return array{hours: float, basis: string, years: array<int, array<string, mixed>>, days: array<int, array<string, mixed>>}
	 */
	private function tallied(array $resolvedByYear): array {
		$tally = ['hours' => 0.0, 'basis' => 'contract-average', 'years' => [], 'days' => []];
		foreach ($resolvedByYear as $year => $resolved) {
			$tally['years'][] = ['year' => $year, 'hours' => (float)$resolved['hours'], 'basis' => $resolved['basis'], 'basisLabel' => $this->basisLabel(basis: $resolved['basis'])];
			$tally['hours']  += (float)$resolved['hours'];
			$tally['basis']   = $resolved['basis'];
			foreach ($resolved['days'] as $day) {
				$tally['days'][] = array_merge($day, ['label' => $this->label(reason: $day['reason'])]);
			}
		}

		$tally['hours'] = round($tally['hours'], 2);

		return $tally;
	}//end tallied()

	/**
	 * Contract hours per week by balance year, for days without a pattern.
	 *
	 * @param string $employeeId The employee.
	 * @param string $leaveType  The leave type.
	 *
	 * @return array<int, float>
	 */
	private function contractHoursByYear(string $employeeId, string $leaveType): array {
		$out = [];
		foreach ($this->gateway->findFiltered('LeaveBalance', ['employeeId' => $employeeId, 'leaveType' => $leaveType]) as $balance) {
			if (is_numeric($balance['contractHoursPerWeek'] ?? null) === true) {
				$out[(int)($balance['year'] ?? 0)] = (float)$balance['contractHoursPerWeek'];
			}
		}

		return $out;
	}//end contractHoursByYear()

	/**
	 * How a year's hours were worked out, for people.
	 *
	 * @param string $basis explicit, pattern, pattern-only or contract-average.
	 *
	 * @return string
	 */
	private function basisLabel(string $basis): string {
		return match ($basis) {
			'explicit' => $this->l10n->t('Hours entered on the request'),
			'pattern' => $this->l10n->t('Working pattern and public holidays'),
			'pattern-only' => $this->l10n->t('Working pattern; public holidays could not be checked'),
			default => $this->l10n->t('Contract hours, spread over five days'),
		};
	}//end basisLabel()

	/**
	 * A day's reason, for people.
	 *
	 * @param string $reason pattern, contract-average, feestdag, vrije-dag or weekend.
	 *
	 * @return string
	 */
	private function label(string $reason): string {
		return match ($reason) {
			'feestdag' => $this->l10n->t('Public holiday'),
			'vrije-dag' => $this->l10n->t('Day off'),
			'weekend' => $this->l10n->t('Weekend'),
			'contract-average' => $this->l10n->t('Working day, contract average'),
			default => $this->l10n->t('Working day'),
		};
	}//end label()

}//end class
